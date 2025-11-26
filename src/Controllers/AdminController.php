<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

class AdminController
{
    public function __construct(
        private readonly Twig $view
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');

        return $this->view->render($response, 'admin/index.twig', [
            'user' => $user,
        ]);
    }
}

