<?php

namespace App\Controllers;

use App\DAO\PropertyCalendarImportLinkDAO;
use App\DAO\PropertyDAO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Psr7\Response as SlimResponse;
use Slim\Views\Twig;

class AdminPropertyLinkController
{
    private const SYNC_PARTNERS = [
        'AirBNB',
    ];

    public function __construct(
        private readonly Twig $view,
        private readonly PropertyCalendarImportLinkDAO $linkDao,
        private readonly PropertyDAO $propertyDao
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $links = $this->linkDao->findAllWithPropertyNames();

        return $this->view->render($response, 'admin/property_links/index.twig', [
            'links' => $links,
        ]);
    }

    public function create(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();

        // Get properties for dropdowns
        $properties = $this->propertyDao->findAll();

        return $this->view->render($response, 'admin/property_links/form.twig', [
            'link' => null,
            'properties' => $properties,
            'partners' => self::SYNC_PARTNERS,
            'error' => $params['error'] ?? null,
        ]);
    }

    public function store(Request $request): Response
    {
        $data = (array) $request->getParsedBody();
        $propertyId = (int) ($data['property_id'] ?? 0);
        $partnerName = trim($data['sync_partner_name'] ?? '');
        $url = trim($data['import_link_url'] ?? '');
        $isActive = isset($data['is_active']) && $data['is_active'] === '1';

        if (!$propertyId || !$partnerName || !$url) {
            return $this->redirectWithError('/admin/property-links/create', 'Property, sync partner, and URL are required');
        }

        if (!in_array($partnerName, self::SYNC_PARTNERS)) {
            return $this->redirectWithError('/admin/property-links/create', 'Invalid sync partner selected');
        }

        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return $this->redirectWithError('/admin/property-links/create', 'Invalid URL format');
        }

        try {
            $this->linkDao->create($propertyId, $partnerName, $url, $isActive);

            $response = new SlimResponse();
            return $response
                ->withHeader('Location', '/admin/property-links')
                ->withStatus(302);
        } catch (\Exception $e) {
            if ($e->getCode() == 23000) { // Duplicate entry
                return $this->redirectWithError('/admin/property-links/create', 'A link for this property and sync partner combination already exists');
            }
            return $this->redirectWithError('/admin/property-links/create', 'Failed to create property link');
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
