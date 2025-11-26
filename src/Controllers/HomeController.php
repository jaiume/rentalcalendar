<?php

namespace App\Controllers;

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
        private readonly ConfigService $config
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

        // Get all sync partner bar colors dynamically from config
        $syncPartnerBarColors = $this->config::getSyncPartnerBarColors();

        return $this->view->render($response, 'dashboard.twig', [
            'user' => $user,
            'maintenance_color' => $this->config::get('colors.maintenance_color', '#FF8800'),
            'cleaning_color' => $this->config::get('colors.cleaning_color', '#0088FF'),
            'reservation_color' => $this->config::get('colors.reservation_color', '#0d6efd'),
            'sync_partner_bar_colors' => $syncPartnerBarColors,
        ]);
    }
}

