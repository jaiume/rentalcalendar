<?php

namespace App\Controllers;

use App\DAO\PaymentDAO;
use App\Models\PortalGroup;
use App\Services\LogService;
use App\Services\PayPalService;
use App\Services\SupplyRequestService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Psr7\Response as SlimResponse;
use Slim\Views\Twig;

/**
 * Guest-facing pages: home (landing), supplies form, supplies thank-you,
 * laundry page (which loads the PayPal SDK and reveals the combo on
 * successful capture), and the small JSON config endpoint that gives
 * the front-end the PayPal client_id + price.
 *
 * Every action requires a PortalGroup attribute on the request, which
 * HostnameRoutingMiddleware sets when the host matches an active
 * portal_groups.guest_hostname.
 */
class GuestPortalController
{
    public function __construct(
        private readonly Twig $view,
        private readonly SupplyRequestService $supplyRequests,
        private readonly PayPalService $paypal,
        private readonly PaymentDAO $paymentDao
    ) {
    }

    public function home(Request $request, Response $response): Response
    {
        $portal = $this->portal($request);

        return $this->view->render($response, $portal->template('home'), [
            'portal' => $this->portalContext($portal),
        ]);
    }

    public function laundry(Request $request, Response $response): Response
    {
        $portal = $this->portal($request);
        if (!$portal->laundryEnabled()) {
            return $this->notFound();
        }

        $laundry = $portal->laundry();
        $accessDays = $portal->laundryAccessDays();
        $revealedCombination = $this->resolveRevealedCombination($request, $portal);

        return $this->view->render($response, $portal->template('laundry'), [
            'portal' => $this->portalContext($portal),
            'laundry' => [
                'price_cents' => (int) ($laundry['price_cents'] ?? 0),
                'currency' => (string) ($laundry['currency'] ?? 'USD'),
                'price_display' => PayPalService::centsToDecimalString((int) ($laundry['price_cents'] ?? 0)),
                'access_days' => $accessDays,
            ],
            'revealed_combination' => $revealedCombination,
        ]);
    }

    /**
     * If the guest is carrying a `paid_laundry` cookie that points to a
     * still-valid completed payment for this portal, return the combo +
     * instructions so the template can render them server-side without
     * loading the PayPal SDK or asking for payment again.
     *
     * Returns null in every other case (no cookie, unknown order id,
     * different portal, refunded/failed/expired) so the template falls
     * through to the normal payment flow. The combination text is read
     * from the **current** portal config so rotating the padlock combo
     * automatically pushes the new value to returning guests too — we
     * never store the secret in the DB.
     *
     * @return array{combination: string, instructions_html: string}|null
     */
    private function resolveRevealedCombination(Request $request, PortalGroup $portal): ?array
    {
        $cookies = $request->getCookieParams();
        $paidOrderId = isset($cookies['paid_laundry']) ? trim((string) $cookies['paid_laundry']) : '';
        if ($paidOrderId === '') {
            return null;
        }

        try {
            $payment = $this->paymentDao->findActiveCompleted(
                $paidOrderId,
                $portal->id(),
                'laundry_access',
                $portal->laundryAccessDays()
            );
        } catch (\Throwable $e) {
            LogService::exception($e, 'paid_laundry cookie lookup failed');
            return null;
        }

        if ($payment === null) {
            return null;
        }

        $laundry = $portal->laundry();
        return [
            'combination' => (string) ($laundry['padlock_combination'] ?? ''),
            'instructions_html' => (string) ($laundry['padlock_instructions_html'] ?? ''),
        ];
    }

    public function supplies(Request $request, Response $response): Response
    {
        $portal = $this->portal($request);
        if (!$portal->suppliesEnabled()) {
            return $this->notFound();
        }
        $params = $request->getQueryParams();

        return $this->view->render($response, $portal->template('supplies'), [
            'portal' => $this->portalContext($portal),
            'item_suggestions' => $portal->supplyItemSuggestions(),
            'error' => isset($params['error']) ? (string) $params['error'] : null,
        ]);
    }

    public function submitSupplies(Request $request, Response $response): Response
    {
        $portal = $this->portal($request);

        if (!$this->originMatchesHost($request)) {
            return $this->badRequest('Bad request origin.');
        }
        if (!$portal->suppliesEnabled()) {
            return $this->notFound();
        }

        $body = (array) $request->getParsedBody();
        $propertyId = isset($body['property_id']) ? (int) $body['property_id'] : 0;
        $extraNotes = isset($body['notes']) ? (string) $body['notes'] : '';
        $items = $body['items'] ?? [];
        if (!is_array($items)) {
            $items = [];
        }

        try {
            $this->supplyRequests->submit($portal, $propertyId, $items, $extraNotes);
        } catch (\InvalidArgumentException $e) {
            return $this->redirect('/supplies?error=' . urlencode($e->getMessage()));
        } catch (\RuntimeException $e) {
            LogService::exception($e, 'Supply request submission failed');
            return $this->redirect('/supplies?error=' . urlencode('Could not submit your request. Please try again.'));
        }

        return $this->redirect('/supplies/thanks');
    }

    public function suppliesThanks(Request $request, Response $response): Response
    {
        $portal = $this->portal($request);

        return $this->view->render($response, $portal->template('supplies_thanks'), [
            'portal' => $this->portalContext($portal),
        ]);
    }

    public function paypalConfig(Request $request, Response $response): Response
    {
        $portal = $this->portal($request);
        if (!$portal->laundryEnabled()) {
            return $this->jsonResponse(['error' => 'Laundry payments are not available'], 404);
        }

        $laundry = $portal->laundry();
        return $this->jsonResponse([
            'client_id' => $this->paypal->clientId(),
            'env' => $this->paypal->env(),
            'currency' => (string) ($laundry['currency'] ?? 'USD'),
            'amount_cents' => (int) ($laundry['price_cents'] ?? 0),
            'amount' => PayPalService::centsToDecimalString((int) ($laundry['price_cents'] ?? 0)),
        ]);
    }

    private function portal(Request $request): PortalGroup
    {
        $portal = $request->getAttribute('portal_group');
        if (!$portal instanceof PortalGroup) {
            throw new \RuntimeException('Guest controller invoked without a PortalGroup attribute');
        }
        return $portal;
    }

    /**
     * @return array<string, mixed>
     */
    private function portalContext(PortalGroup $portal): array
    {
        return [
            'name' => $portal->name(),
            'slug' => $portal->slug(),
            'properties' => $portal->properties(),
            'laundry_enabled' => $portal->laundryEnabled(),
            'supplies_enabled' => $portal->suppliesEnabled(),
            'logo_url' => $portal->logoUrl(),
            'tagline' => $portal->tagline(),
        ];
    }

    private function originMatchesHost(Request $request): bool
    {
        $origin = $request->getHeaderLine('Origin');
        if ($origin === '') {
            return true;
        }
        $hostHeader = $request->getHeaderLine('Host');
        $expectedHost = strtolower(trim($hostHeader));
        $parsedHost = parse_url($origin, PHP_URL_HOST);
        $parsedScheme = parse_url($origin, PHP_URL_SCHEME);
        if (!is_string($parsedHost) || $parsedHost === '') {
            return false;
        }
        $parsedHost = strtolower($parsedHost);
        $expectedHost = preg_replace('/:\d+$/', '', $expectedHost) ?? $expectedHost;
        // Allow https schemes on production guest hosts; non-https origins
        // can only match in dev where the host header reveals the scheme isn't TLS.
        return $parsedHost === $expectedHost && in_array($parsedScheme, ['http', 'https', null], true);
    }

    private function jsonResponse(array $payload, int $status = 200): Response
    {
        $response = new SlimResponse($status);
        $response->getBody()->write((string) json_encode($payload));
        return $response->withHeader('Content-Type', 'application/json');
    }

    private function redirect(string $location): Response
    {
        $response = new SlimResponse();
        return $response->withHeader('Location', $location)->withStatus(302);
    }

    private function notFound(): Response
    {
        $response = new SlimResponse(404);
        $response->getBody()->write('Not Found');
        return $response->withHeader('Content-Type', 'text/plain');
    }

    private function badRequest(string $message): Response
    {
        $response = new SlimResponse(400);
        $response->getBody()->write($message);
        return $response->withHeader('Content-Type', 'text/plain');
    }
}
