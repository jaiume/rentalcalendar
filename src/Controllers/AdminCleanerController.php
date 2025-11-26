<?php

namespace App\Controllers;

use App\DAO\CleanerDAO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Psr7\Response as SlimResponse;
use Slim\Views\Twig;

class AdminCleanerController
{
    public function __construct(
        private readonly Twig $view,
        private readonly CleanerDAO $cleanerDao
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $cleaners = $this->cleanerDao->findAllWithAllColumns();

        return $this->view->render($response, 'admin/cleaners/index.twig', [
            'cleaners' => $cleaners,
        ]);
    }

    public function create(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();

        return $this->view->render($response, 'admin/cleaners/form.twig', [
            'cleaner' => null,
            'error' => $params['error'] ?? null,
        ]);
    }

    public function store(Request $request): Response
    {
        $data = (array) $request->getParsedBody();
        $name = trim($data['cleaner_name'] ?? '');
        $initials = strtoupper(trim($data['cleaner_initials'] ?? ''));
        $phone = trim($data['phone'] ?? '');

        if (!$name || !$initials) {
            return $this->redirectWithError('/admin/cleaners/create', 'Cleaner name and initials are required');
        }

        if (strlen($initials) > 5) {
            return $this->redirectWithError('/admin/cleaners/create', 'Initials must be 5 characters or less');
        }

        try {
            $this->cleanerDao->create($name, $initials, $phone ?: null);

            $response = new SlimResponse();
            return $response
                ->withHeader('Location', '/admin/cleaners')
                ->withStatus(302);
        } catch (\Exception $e) {
            if ($e->getCode() == 23000) { // Duplicate entry
                return $this->redirectWithError('/admin/cleaners/create', 'A cleaner with these initials already exists');
            }
            return $this->redirectWithError('/admin/cleaners/create', 'Failed to create cleaner');
        }
    }

    public function edit(Request $request, Response $response, array $args): Response
    {
        $cleanerId = (int) ($args['id'] ?? 0);
        $params = $request->getQueryParams();

        $cleaner = $this->cleanerDao->findById($cleanerId);

        if (!$cleaner) {
            $response = new SlimResponse();
            return $response
                ->withHeader('Location', '/admin/cleaners')
                ->withStatus(302);
        }

        return $this->view->render($response, 'admin/cleaners/form.twig', [
            'cleaner' => $cleaner,
            'error' => $params['error'] ?? null,
        ]);
    }

    public function update(Request $request, array $args): Response
    {
        $cleanerId = (int) ($args['id'] ?? 0);
        $data = (array) $request->getParsedBody();
        $name = trim($data['cleaner_name'] ?? '');
        $initials = strtoupper(trim($data['cleaner_initials'] ?? ''));
        $phone = trim($data['phone'] ?? '');

        if (!$name || !$initials) {
            return $this->redirectWithError("/admin/cleaners/{$cleanerId}/edit", 'Cleaner name and initials are required');
        }

        if (strlen($initials) > 5) {
            return $this->redirectWithError("/admin/cleaners/{$cleanerId}/edit", 'Initials must be 5 characters or less');
        }

        try {
            $this->cleanerDao->update($cleanerId, $name, $initials, $phone ?: null);

            $response = new SlimResponse();
            return $response
                ->withHeader('Location', '/admin/cleaners')
                ->withStatus(302);
        } catch (\Exception $e) {
            if ($e->getCode() == 23000) { // Duplicate entry
                return $this->redirectWithError("/admin/cleaners/{$cleanerId}/edit", 'A cleaner with these initials already exists');
            }
            return $this->redirectWithError("/admin/cleaners/{$cleanerId}/edit", 'Failed to update cleaner');
        }
    }

    public function delete(Request $request, array $args): Response
    {
        $cleanerId = (int) ($args['id'] ?? 0);

        try {
            $this->cleanerDao->deleteById($cleanerId);

            $response = new SlimResponse();
            return $response
                ->withHeader('Location', '/admin/cleaners')
                ->withStatus(302);
        } catch (\Exception $e) {
            return $this->redirectWithError('/admin/cleaners', 'Failed to delete cleaner');
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



