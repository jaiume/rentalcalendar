<?php

namespace App\Controllers;

use App\DAO\UserDAO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Psr7\Response as SlimResponse;
use Slim\Views\Twig;

class AdminUserController
{
    public function __construct(
        private readonly Twig $view,
        private readonly UserDAO $userDao
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $users = $this->userDao->findAll();

        return $this->view->render($response, 'admin/users/index.twig', [
            'users' => $users,
        ]);
    }

    public function create(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();

        return $this->view->render($response, 'admin/users/form.twig', [
            'user' => null,
            'error' => $params['error'] ?? null,
        ]);
    }

    public function store(Request $request): Response
    {
        $data = (array) $request->getParsedBody();
        $email = trim($data['email'] ?? '');
        $displayName = trim($data['display_name'] ?? '');
        $isAdmin = isset($data['is_admin']) && $data['is_admin'] === '1';

        if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->redirectWithError('/admin/users/create', 'Valid email address is required');
        }

        try {
            // No password needed - users login via email codes/links only
            $this->userDao->create($email, $displayName ?: null, $isAdmin, true);

            $response = new SlimResponse();
            return $response
                ->withHeader('Location', '/admin/users')
                ->withStatus(302);
        } catch (\Exception $e) {
            if ($e->getCode() == 23000) { // Duplicate entry
                return $this->redirectWithError('/admin/users/create', 'A user with this email already exists');
            }
            return $this->redirectWithError('/admin/users/create', 'Failed to create user');
        }
    }

    public function edit(Request $request, Response $response, array $args): Response
    {
        $userId = (int) ($args['id'] ?? 0);
        $params = $request->getQueryParams();

        $user = $this->userDao->findByIdWithDetails($userId);

        if (!$user) {
            $response = new SlimResponse();
            return $response
                ->withHeader('Location', '/admin/users')
                ->withStatus(302);
        }

        return $this->view->render($response, 'admin/users/form.twig', [
            'user' => $user,
            'error' => $params['error'] ?? null,
        ]);
    }

    public function update(Request $request, array $args): Response
    {
        $userId = (int) ($args['id'] ?? 0);
        $data = (array) $request->getParsedBody();
        $displayName = trim($data['display_name'] ?? '');
        $isAdmin = isset($data['is_admin']) && $data['is_admin'] === '1';

        try {
            $this->userDao->update($userId, $displayName ?: null, $isAdmin);

            $response = new SlimResponse();
            return $response
                ->withHeader('Location', '/admin/users')
                ->withStatus(302);
        } catch (\Exception $e) {
            return $this->redirectWithError("/admin/users/{$userId}/edit", 'Failed to update user');
        }
    }

    public function toggleActive(Request $request, array $args): Response
    {
        $userId = (int) ($args['id'] ?? 0);

        try {
            $this->userDao->toggleActive($userId);

            $response = new SlimResponse();
            return $response
                ->withHeader('Location', '/admin/users')
                ->withStatus(302);
        } catch (\Exception $e) {
            return $this->redirectWithError('/admin/users', 'Failed to toggle user status');
        }
    }

    public function delete(Request $request, array $args): Response
    {
        $userId = (int) ($args['id'] ?? 0);

        try {
            $this->userDao->deleteById($userId);

            $response = new SlimResponse();
            return $response
                ->withHeader('Location', '/admin/users')
                ->withStatus(302);
        } catch (\Exception $e) {
            return $this->redirectWithError('/admin/users', 'Failed to delete user');
        }
    }

    private function redirectWithError(string $location, string $message): Response
    {
        $response = new SlimResponse();
        return $response
            ->withHeader('Location', $location . '?error=' . urlencode($message))
            ->withStatus(302);
    }
}

