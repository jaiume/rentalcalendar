<?php

namespace App\Controllers;

use App\DAO\PortalGroupDAO;
use App\DAO\SupplyRequestDAO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Psr7\Response as SlimResponse;
use Slim\Views\Twig;

class AdminSupplyRequestController
{
    public function __construct(
        private readonly Twig $view,
        private readonly SupplyRequestDAO $supplyRequestDao,
        private readonly PortalGroupDAO $portalGroupDao
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $filters = [
            'portal_group_id' => isset($params['portal_group_id']) ? (int) $params['portal_group_id'] : null,
            'status' => isset($params['status']) && $params['status'] !== '' ? (string) $params['status'] : null,
            'from' => isset($params['from']) && $params['from'] !== '' ? (string) $params['from'] : null,
            'to' => isset($params['to']) && $params['to'] !== '' ? (string) $params['to'] : null,
        ];
        $filters = array_filter($filters, static fn ($v) => $v !== null && $v !== 0);

        $requests = $this->supplyRequestDao->findForAudit($filters);
        $portalGroups = $this->portalGroupDao->findAll();

        return $this->view->render($response, 'admin/supply_requests/index.twig', [
            'supply_requests' => $requests,
            'portal_groups' => $portalGroups,
            'statuses' => SupplyRequestDAO::VALID_STATUSES,
            'filters' => $filters,
        ]);
    }

    public function updateStatus(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $body = (array) $request->getParsedBody();
        $status = (string) ($body['status'] ?? '');

        try {
            $this->supplyRequestDao->updateStatus($id, $status);
        } catch (\InvalidArgumentException $e) {
            return $this->redirect('/admin/supply-requests?error=' . urlencode($e->getMessage()));
        } catch (\RuntimeException $e) {
            return $this->redirect('/admin/supply-requests?error=' . urlencode('Could not update status.'));
        }

        $referer = $request->getHeaderLine('Referer');
        return $this->redirect($referer !== '' ? $referer : '/admin/supply-requests');
    }

    private function redirect(string $location): Response
    {
        $response = new SlimResponse();
        return $response->withHeader('Location', $location)->withStatus(302);
    }
}
