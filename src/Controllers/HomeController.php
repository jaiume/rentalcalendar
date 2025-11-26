<?php

namespace App\Controllers;

use App\DAO\PropertyDAO;
use App\DAO\UserPropertyPermissionDAO;
use App\Services\ConfigService;
use App\Services\UtilityService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

class HomeController
{
    public function __construct(
        private readonly Twig $view,
        private readonly UtilityService $utility,
        private readonly ConfigService $config,
        private readonly PropertyDAO $propertyDao,
        private readonly UserPropertyPermissionDAO $permissionDao
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        return $this->view->render(
            $response,
            'home.twig',
            [
                'appName' => $this->config::get('app.name', 'Rental Calendar'),
                'baseUrl' => $this->utility->getBaseUrl(),
            ]
        );
    }

    public function dashboard(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $userId = $user['user_id'] ?? null;
        $isAdmin = $user['is_admin'] ?? false;

        // Get all sync partner bar colors dynamically from config
        $syncPartnerBarColors = $this->config::getSyncPartnerBarColors();

        // Get user permissions for all properties
        $userPermissions = [];
        if (!$isAdmin && $userId) {
            // Get all properties
            $properties = $this->propertyDao->findAll();
            
            // For each property, check what permissions the user has
            foreach ($properties as $property) {
                $propertyId = $property['property_id'];
                $userPermissions[$propertyId] = [
                    'can_view_calendar' => $this->permissionDao->hasPermission($userId, $propertyId, 'can_view_calendar'),
                    'can_create_reservation' => $this->permissionDao->hasPermission($userId, $propertyId, 'can_create_reservation'),
                    'can_add_cleaning' => $this->permissionDao->hasPermission($userId, $propertyId, 'can_add_cleaning'),
                    'can_add_maintenance' => $this->permissionDao->hasPermission($userId, $propertyId, 'can_add_maintenance'),
                ];
            }
        }

        return $this->view->render($response, 'dashboard.twig', [
            'user' => $user,
            'user_permissions' => $userPermissions,
            'maintenance_color' => $this->config::get('colors.maintenance_color', '#FF8800'),
            'cleaning_color' => $this->config::get('colors.cleaning_color', '#0088FF'),
            'reservation_color' => $this->config::get('colors.reservation_color', '#0d6efd'),
            'sync_partner_bar_colors' => $syncPartnerBarColors,
        ]);
    }
}

