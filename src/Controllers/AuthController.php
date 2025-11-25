<?php

namespace App\Controllers;

use App\Services\AuthenticationService;
use App\Services\ConfigService;
use App\Services\UtilityService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Psr7\Response as SlimResponse;
use Slim\Views\Twig;

class AuthController
{
    public function __construct(
        private readonly Twig $view,
        private readonly AuthenticationService $authService,
        private readonly ConfigService $config,
        private readonly UtilityService $utility
    ) {
    }

    public function showLogin(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();

        return $this->view->render($response, 'login.twig', [
            'baseUrl' => $this->utility->getBaseUrl(),
            'error' => $params['error'] ?? null,
        ]);
    }

    public function handleLogin(Request $request): Response
    {
        $data = (array) $request->getParsedBody();
        $email = $data['email'] ?? '';

        if (!$email) {
            return $this->redirectWithError('/login', 'Email is required');
        }

        $token = $this->authService->generateToken($email);
        if (!$token) {
            return $this->redirectWithError('/login', 'Unable to authenticate with provided email');
        }

        $cookieName = $this->config::get('auth.cookie_name', 'auth_token');
        $cookieParams = [
            'expires' => time() + (int) $this->config::get('auth.token_expiry', 604800),
            'path' => '/',
            'httponly' => true,
            'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
            'samesite' => 'Lax',
        ];

        $response = new SlimResponse();
        setcookie($cookieName, $token, $cookieParams);

        return $response
            ->withHeader('Location', '/dashboard')
            ->withStatus(302);
    }

    public function logout(): Response
    {
        $cookieName = $this->config::get('auth.cookie_name', 'auth_token');
        $token = $_COOKIE[$cookieName] ?? null;

        if ($token) {
            $this->authService->deleteToken($token);
        }

        setcookie($cookieName, '', [
            'expires' => time() - 3600,
            'path' => '/',
        ]);

        $response = new SlimResponse();
        return $response
            ->withHeader('Location', '/login')
            ->withStatus(302);
    }

    private function redirectWithError(string $location, string $message): Response
    {
        $response = new SlimResponse();
        return $response
            ->withHeader('Location', $location . '?error=' . urlencode($message))
            ->withStatus(302);
    }
}

