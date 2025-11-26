<?php

namespace App\Controllers;

use App\DAO\PropertyDAO;
use App\Services\UtilityService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Psr7\Response as SlimResponse;
use Slim\Views\Twig;

class AdminPropertyController
{
    public function __construct(
        private readonly Twig $view,
        private readonly PropertyDAO $propertyDao,
        private readonly UtilityService $utility
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $properties = $this->propertyDao->findAllWithAllColumns();

        return $this->view->render($response, 'admin/properties/index.twig', [
            'properties' => $properties,
            'baseUrl' => $this->utility->getBaseUrl(),
        ]);
    }

    public function create(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();

        return $this->view->render($response, 'admin/properties/form.twig', [
            'property' => null,
            'error' => $params['error'] ?? null,
            'timezones' => $this->getTimezones(),
        ]);
    }

    public function store(Request $request): Response
    {
        $data = (array) $request->getParsedBody();
        $name = trim($data['property_name'] ?? '');
        $timezone = trim($data['timezone'] ?? 'UTC');

        if (!$name) {
            return $this->redirectWithError('/admin/properties/create', 'Property name is required');
        }

        try {
            $this->propertyDao->create($name, $timezone);

            $response = new SlimResponse();
            return $response
                ->withHeader('Location', '/admin/properties')
                ->withStatus(302);
        } catch (\Exception $e) {
            return $this->redirectWithError('/admin/properties/create', 'Failed to create property');
        }
    }

    public function edit(Request $request, Response $response, array $args): Response
    {
        $propertyId = (int) ($args['id'] ?? 0);
        $params = $request->getQueryParams();

        $property = $this->propertyDao->findById($propertyId);

        if (!$property) {
            $response = new SlimResponse();
            return $response
                ->withHeader('Location', '/admin/properties')
                ->withStatus(302);
        }

        return $this->view->render($response, 'admin/properties/form.twig', [
            'property' => $property,
            'error' => $params['error'] ?? null,
            'timezones' => $this->getTimezones(),
        ]);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $propertyId = (int) ($args['id'] ?? 0);
        $data = (array) $request->getParsedBody();
        $name = trim($data['property_name'] ?? '');
        $timezone = trim($data['timezone'] ?? 'UTC');

        if (!$name) {
            return $this->redirectWithError("/admin/properties/{$propertyId}/edit", 'Property name is required');
        }

        try {
            $this->propertyDao->update($propertyId, $name, $timezone);

            $response = new SlimResponse();
            return $response
                ->withHeader('Location', '/admin/properties')
                ->withStatus(302);
        } catch (\Exception $e) {
            return $this->redirectWithError("/admin/properties/{$propertyId}/edit", 'Failed to update property');
        }
    }

    public function delete(Request $request, array $args): Response
    {
        $propertyId = (int) ($args['id'] ?? 0);

        try {
            $this->propertyDao->deleteById($propertyId);

            $response = new SlimResponse();
            return $response
                ->withHeader('Location', '/admin/properties')
                ->withStatus(302);
        } catch (\Exception $e) {
            return $this->redirectWithError('/admin/properties', 'Failed to delete property');
        }
    }

    private function redirectWithError(string $location, string $message): Response
    {
        $response = new SlimResponse();
        return $response
            ->withHeader('Location', $location . '?error=' . urlencode($message))
            ->withStatus(302);
    }

    /**
     * Get list of timezones organized by region
     */
    private function getTimezones(): array
    {
        $allTimezones = \DateTimeZone::listIdentifiers();
        $grouped = [];

        foreach ($allTimezones as $timezone) {
            // Split by / to get region and city
            $parts = explode('/', $timezone, 2);
            if (count($parts) === 2) {
                $region = $parts[0];
                $city = $parts[1];
                if (!isset($grouped[$region])) {
                    $grouped[$region] = [];
                }
                $grouped[$region][$timezone] = str_replace('_', ' ', $city);
            } else {
                // Timezones without region (like UTC)
                if (!isset($grouped['Other'])) {
                    $grouped['Other'] = [];
                }
                $grouped['Other'][$timezone] = $timezone;
            }
        }

        // Sort regions
        ksort($grouped);
        
        // Sort cities within each region
        foreach ($grouped as &$cities) {
            asort($cities);
        }

        return $grouped;
    }
}



