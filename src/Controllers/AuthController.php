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

        return $this->view->render($response, 'login_email.twig', [
            'baseUrl' => $this->utility->getBaseUrl(),
            'error' => $params['error'] ?? null,
            'success' => $params['success'] ?? null,
        ]);
    }

    public function handleLogin(Request $request): Response
    {
        $data = (array) $request->getParsedBody();
        $email = trim($data['email'] ?? '');

        if (!$email) {
            return $this->redirectWithError('/login', 'Email is required');
        }

        // Check if user exists - if not, don't send email
        if (!$this->authService->userExists($email)) {
            // Don't reveal that user doesn't exist - just show success message
            return $this->redirectWithSuccess('/login', 'If an account exists with that email, a login code has been sent.');
        }

        // Generate login code and token
        $loginData = $this->authService->generateLoginCode($email);
        if (!$loginData) {
            return $this->redirectWithError('/login', 'Unable to generate login code. Please try again.');
        }

        // Send email with code and link
        $baseUrl = $this->utility->getBaseUrl();
        $loginLink = $baseUrl . 'login/verify?token=' . urlencode($loginData['token']);
        $emailBody = $this->buildLoginEmail($loginData['code'], $loginLink);

        $emailSent = $this->utility->sendEmail(
            $email,
            'Your Rental Calendar Login Code',
            $emailBody,
            true
        );

        if (!$emailSent) {
            return $this->redirectWithError('/login', 'Failed to send email. Please try again.');
        }

        return $this->redirectWithSuccess('/login', 'Login code sent to your email. Please check your inbox.');
    }

    public function showCodeForm(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $token = $params['token'] ?? null;

        // If token is provided, try direct login
        if ($token) {
            return $this->handleDirectLogin($request, $response, $token);
        }

        return $this->view->render($response, 'login_code.twig', [
            'baseUrl' => $this->utility->getBaseUrl(),
            'error' => $params['error'] ?? null,
        ]);
    }

    public function handleCodeVerification(Request $request): Response
    {
        $data = (array) $request->getParsedBody();
        $code = trim($data['code'] ?? '');

        if (!$code || strlen($code) !== 6 || !ctype_digit($code)) {
            return $this->redirectWithError('/login/verify', 'Please enter a valid 6-digit code.');
        }

        $token = $this->authService->verifyLoginCode($code);
        if (!$token) {
            return $this->redirectWithError('/login/verify', 'Invalid or expired code. Please try again.');
        }

        return $this->setAuthCookieAndRedirect($token);
    }

    public function handleDirectLogin(Request $request, Response $response, ?string $token = null): Response
    {
        if (!$token) {
            $params = $request->getQueryParams();
            $token = $params['token'] ?? null;
        }

        if (!$token) {
            return $this->redirectWithError('/login', 'Invalid login link.');
        }

        $sessionToken = $this->authService->verifyLoginToken($token);
        if (!$sessionToken) {
            return $this->redirectWithError('/login', 'Invalid or expired login link. Please request a new one.');
        }

        return $this->setAuthCookieAndRedirect($sessionToken);
    }

    private function setAuthCookieAndRedirect(string $token): Response
    {
        $cookieName = $this->config::get('auth.cookie_name', 'auth_token');
        $expires = time() + (int) $this->config::get('auth.token_expiry', 604800);
        $secure = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on';
        
        // Build cookie value using standard format
        $cookieValue = urlencode($cookieName) . '=' . urlencode($token);
        $cookieValue .= '; Expires=' . gmdate('D, d M Y H:i:s T', $expires);
        $cookieValue .= '; Path=/';
        $cookieValue .= '; HttpOnly';
        $cookieValue .= '; SameSite=Lax';
        if ($secure) {
            $cookieValue .= '; Secure';
        }

        $response = new SlimResponse();
        return $response
            ->withHeader('Set-Cookie', $cookieValue)
            ->withHeader('Location', '/dashboard')
            ->withStatus(302);
    }

    private function buildLoginEmail(string $code, string $loginLink): string
    {
        $appName = $this->config::get('app.name', 'Rental Calendar');
        
        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .code-box { 
            background: #f8f9fa; 
            border: 2px dashed #dee2e6; 
            border-radius: 8px; 
            padding: 20px; 
            text-align: center; 
            margin: 20px 0;
            font-size: 32px;
            letter-spacing: 8px;
            font-weight: bold;
            color: #0d6efd;
        }
        .button { 
            display: inline-block; 
            padding: 12px 24px; 
            background: #0d6efd; 
            color: white; 
            text-decoration: none; 
            border-radius: 6px; 
            margin: 20px 0;
        }
        .footer { margin-top: 30px; padding-top: 20px; border-top: 1px solid #dee2e6; font-size: 12px; color: #6c757d; }
    </style>
</head>
<body>
    <div class="container">
        <h1>Your {$appName} Login Code</h1>
        <p>Use the code below to sign in to your account:</p>
        <div class="code-box">{$code}</div>
        <p>Or click the link below to sign in directly:</p>
        <p style="text-align: center;">
            <a href="{$loginLink}" class="button">Sign in to {$appName}</a>
        </p>
        <p><strong>This code will expire in 15 minutes.</strong></p>
        <p>If you didn't request this code, you can safely ignore this email.</p>
        <div class="footer">
            <p>This is an automated message from {$appName}. Please do not reply to this email.</p>
        </div>
    </div>
</body>
</html>
HTML;
    }

    private function redirectWithSuccess(string $location, string $message): Response
    {
        $response = new SlimResponse();
        return $response
            ->withHeader('Location', $location . '?success=' . urlencode($message))
            ->withStatus(302);
    }

    public function logout(): Response
    {
        $cookieName = $this->config::get('auth.cookie_name', 'auth_token');
        $token = $_COOKIE[$cookieName] ?? null;

        if ($token) {
            $this->authService->deleteToken($token);
        }

        // Build cookie deletion header
        $cookieValue = urlencode($cookieName) . '=';
        $cookieValue .= '; Expires=' . gmdate('D, d M Y H:i:s T', time() - 3600);
        $cookieValue .= '; Path=/';

        $response = new SlimResponse();
        return $response
            ->withHeader('Set-Cookie', $cookieValue)
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

