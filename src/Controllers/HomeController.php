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

        return $this->view->render($response, 'dashboard.twig', [
            'user' => $user,
        ]);
    }
}

