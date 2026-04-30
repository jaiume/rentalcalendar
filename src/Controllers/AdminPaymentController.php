<?php

namespace App\Controllers;

use App\DAO\PaymentDAO;
use App\DAO\PortalGroupDAO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

class AdminPaymentController
{
    public function __construct(
        private readonly Twig $view,
        private readonly PaymentDAO $paymentDao,
        private readonly PortalGroupDAO $portalGroupDao
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();

        $filters = [
            'portal_group_id' => isset($params['portal_group_id']) ? (int) $params['portal_group_id'] : null,
            'item_type' => isset($params['item_type']) && $params['item_type'] !== '' ? (string) $params['item_type'] : null,
            'status' => isset($params['status']) && $params['status'] !== '' ? (string) $params['status'] : null,
            'from' => isset($params['from']) && $params['from'] !== '' ? (string) $params['from'] : null,
            'to' => isset($params['to']) && $params['to'] !== '' ? (string) $params['to'] : null,
        ];
        $filters = array_filter($filters, static fn ($v) => $v !== null && $v !== 0);

        $payments = $this->paymentDao->findForAudit($filters);
        $portalGroups = $this->portalGroupDao->findAll();

        return $this->view->render($response, 'admin/payments/index.twig', [
            'payments' => $payments,
            'portal_groups' => $portalGroups,
            'item_types' => $this->paymentDao->distinctItemTypes(),
            'statuses' => PaymentDAO::VALID_STATUSES,
            'filters' => $filters,
        ]);
    }
}
