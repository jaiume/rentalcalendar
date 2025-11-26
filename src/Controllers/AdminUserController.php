<?php

namespace App\Controllers;

use App\DAO\UserDAO;
use App\DAO\PropertyDAO;
use App\DAO\UserPropertyPermissionDAO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Psr7\Response as SlimResponse;
use Slim\Views\Twig;

class AdminUserController
{
    public function __construct(
        private readonly Twig $view,
        private readonly UserDAO $userDao,
        private readonly PropertyDAO $propertyDao,
        private readonly UserPropertyPermissionDAO $permissionDao
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

    public function permissions(Request $request, Response $response, array $args): Response
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

        // Admins have full access, so we shouldn't edit their permissions
        if ($user['is_admin']) {
            return $this->redirectWithError('/admin/users', 'Admins have full access to all properties');
        }

        $properties = $this->propertyDao->findAll();
        $currentPermissions = $this->permissionDao->getPermissionsForUser($userId);

        return $this->view->render($response, 'admin/users/permissions.twig', [
            'user' => $user,
            'properties' => $properties,
            'permissions' => $currentPermissions,
            'success' => $params['success'] ?? null,
            'error' => $params['error'] ?? null,
        ]);
    }

    public function updatePermissions(Request $request, Response $response, array $args): Response
    {
        $userId = (int) ($args['id'] ?? 0);
        $data = (array) $request->getParsedBody();

        $user = $this->userDao->findByIdWithDetails($userId);

        if (!$user) {
            return $this->redirectWithError('/admin/users', 'User not found');
        }

        // Don't allow editing admin permissions
        if ($user['is_admin']) {
            return $this->redirectWithError('/admin/users', 'Cannot edit permissions for admin users');
        }

        try {
            // Parse the permissions from the form data
            // Format: permissions[property_id][permission_type] = 1
            $permissions = [];
            if (isset($data['permissions']) && is_array($data['permissions'])) {
                foreach ($data['permissions'] as $propertyId => $perms) {
                    $permissions[(int)$propertyId] = [
                        'can_view_calendar' => isset($perms['can_view_calendar']),
                        'can_create_reservation' => isset($perms['can_create_reservation']),
                        'can_add_cleaning' => isset($perms['can_add_cleaning']),
                        'can_add_maintenance' => isset($perms['can_add_maintenance']),
                    ];
                }
            }

            $this->permissionDao->updateAllPermissionsForUser($userId, $permissions);

            $response = new SlimResponse();
            return $response
                ->withHeader('Location', "/admin/users/{$userId}/permissions?success=" . urlencode('Permissions updated successfully'))
                ->withStatus(302);
        } catch (\Exception $e) {
            return $this->redirectWithError("/admin/users/{$userId}/permissions", 'Failed to update permissions');
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

