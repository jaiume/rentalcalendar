<?php

namespace App\Middleware;

use App\Exceptions\PortalConfigException;
use App\Exceptions\PortalNotFoundException;
use App\Services\ConfigService;
use App\Services\LogService;
use App\Services\PortalGroupResolver;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Slim\Psr7\Response as SlimResponse;

/**
 * Resolves which "face" (guest or admin) of the application should
 * handle the request, based on the Host header.
 *
 *  - guest hostnames match an active portal_groups.guest_hostname row
 *    and the request gets a `portal_group` PortalGroup value object
 *    plus `portal_face = 'guest'`.
 *  - admin hostnames match `[portal] admin_hostnames` in config.ini
 *    and the request gets `portal_face = 'admin'` (no portal_group).
 *  - unknown hostnames return 404.
 *
 * The middleware is intentionally permissive about which routes are
 * registered on each face; the routes file is what ensures admin
 * routes don't appear on guest hostnames and vice versa.
 */
class HostnameRoutingMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly PortalGroupResolver $resolver,
        private readonly ConfigService $config
    ) {
    }

    public function process(Request $request, RequestHandler $handler): Response
    {
        $hostHeader = $request->getHeaderLine('Host');
        $hostname = PortalGroupResolver::normalizeHostname($hostHeader);

        try {
            $portal = $this->resolver->resolveGuestByHostname($hostname);
        } catch (PortalConfigException $e) {
            LogService::exception($e, 'Portal config error');
            return $this->serviceUnavailable('Portal is temporarily unavailable.');
        } catch (PortalNotFoundException $e) {
            $portal = null;
        }

        if ($portal !== null) {
            $request = $request
                ->withAttribute('portal_face', 'guest')
                ->withAttribute('portal_group', $portal);
            return $handler->handle($request);
        }

        if ($this->isAdminHostname($hostname)) {
            $request = $request->withAttribute('portal_face', 'admin');
            return $handler->handle($request);
        }

        LogService::warning('Unknown hostname rejected', ['hostname' => $hostname]);
        return $this->notFound();
    }

    private function isAdminHostname(string $hostname): bool
    {
        $raw = (string) $this->config::get('portal.admin_hostnames', '');
        if ($raw === '') {
            return false;
        }
        $allowed = array_filter(array_map(
            static fn (string $h): string => strtolower(trim($h)),
            explode(',', $raw)
        ));
        return in_array($hostname, $allowed, true);
    }

    private function notFound(): Response
    {
        $response = new SlimResponse(404);
        $response->getBody()->write('Not Found');
        return $response->withHeader('Content-Type', 'text/plain');
    }

    private function serviceUnavailable(string $message): Response
    {
        $response = new SlimResponse(503);
        $response->getBody()->write($message);
        return $response->withHeader('Content-Type', 'text/plain');
    }
}
