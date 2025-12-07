<?php

namespace App\Middleware;

use App\Services\AuthenticationService;
use App\Services\ConfigService;
use App\Services\LogService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Slim\Psr7\Response as SlimResponse;

class AuthenticationMiddleware
{
    public function __construct(
        private readonly AuthenticationService $auth,
        private readonly ConfigService $config
    ) {
    }

    public function __invoke(Request $request, RequestHandler $handler): Response
    {
        $cookieName = $this->config::get('auth.cookie_name', 'auth_token');
        $cookies = $request->getCookieParams();
        $token = $cookies[$cookieName] ?? null;

        $path = $request->getUri()->getPath();
        
        LogService::debug('AuthenticationMiddleware invoked', [
            'path' => $path,
            'has_token' => !empty($token),
            'cookies_present' => array_keys($cookies)
        ]);

        if (!$token) {
            LogService::warning('Authentication failed: no token in cookies', ['path' => $path]);
            return $this->redirectToLogin();
        }

        $userData = $this->auth->verifyToken($token);
        if (!$userData) {
            LogService::warning('Authentication failed: token verification failed', ['path' => $path]);
            return $this->redirectToLogin();
        }

        LogService::debug('Authentication successful', [
            'path' => $path,
            'user_id' => $userData['user_id'],
            'email' => $userData['email']
        ]);

        if (str_starts_with($path, '/admin') && !$userData['is_admin']) {
            LogService::warning('Authorization failed: non-admin accessing admin area', [
                'user_id' => $userData['user_id']
            ]);
            return $this->redirectToHome();
        }

        $this->auth->extendTokenExpiry($token);

        $request = $request->withAttribute('user', $userData);

        return $handler->handle($request);
    }

    private function redirectToLogin(): Response
    {
        $response = new SlimResponse();
        return $response
            ->withHeader('Location', '/login')
            ->withStatus(302);
    }

    private function redirectToHome(): Response
    {
        $response = new SlimResponse();
        return $response
            ->withHeader('Location', '/')
            ->withStatus(302);
    }
}









