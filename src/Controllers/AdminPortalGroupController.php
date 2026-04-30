<?php

namespace App\Controllers;

use App\DAO\PortalGroupDAO;
use App\DAO\PropertyDAO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Psr7\Response as SlimResponse;
use Slim\Views\Twig;

class AdminPortalGroupController
{
    public function __construct(
        private readonly Twig $view,
        private readonly PortalGroupDAO $portalGroupDao,
        private readonly PropertyDAO $propertyDao
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $portalGroups = $this->portalGroupDao->findAll();
        foreach ($portalGroups as &$pg) {
            $pg['property_count'] = count($this->portalGroupDao->findProperties((int) $pg['portal_group_id']));
            $pg['config_status'] = $this->configFileStatus((string) $pg['slug']);
        }
        unset($pg);

        return $this->view->render($response, 'admin/portal_groups/index.twig', [
            'portal_groups' => $portalGroups,
        ]);
    }

    public function create(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        return $this->view->render($response, 'admin/portal_groups/form.twig', [
            'portal_group' => null,
            'properties' => $this->propertyDao->findAllWithAllColumns(),
            'assigned_property_ids' => [],
            'error' => $params['error'] ?? null,
        ]);
    }

    public function store(Request $request): Response
    {
        $data = (array) $request->getParsedBody();
        $error = $this->validate($data);
        if ($error !== null) {
            return $this->redirect('/admin/portal-groups/create?error=' . urlencode($error));
        }

        try {
            $id = $this->portalGroupDao->create(
                strtolower(trim((string) $data['slug'])),
                trim((string) $data['name']),
                $this->normalizeHostname($data['guest_hostname'] ?? null),
                !empty($data['is_active'])
            );
            $this->portalGroupDao->setProperties($id, $this->collectPropertyIds($data));
        } catch (\RuntimeException $e) {
            return $this->redirect('/admin/portal-groups/create?error=' . urlencode('Could not create portal group (slug or hostname may already be in use).'));
        }

        return $this->redirect('/admin/portal-groups');
    }

    public function edit(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $portalGroup = $this->portalGroupDao->findById($id);
        if (!$portalGroup) {
            return $this->redirect('/admin/portal-groups');
        }

        $assigned = $this->portalGroupDao->findProperties($id);
        $assignedIds = array_map(static fn ($row) => (int) $row['property_id'], $assigned);
        $params = $request->getQueryParams();

        return $this->view->render($response, 'admin/portal_groups/form.twig', [
            'portal_group' => $portalGroup,
            'properties' => $this->propertyDao->findAllWithAllColumns(),
            'assigned_property_ids' => $assignedIds,
            'config_status' => $this->configFileStatus((string) $portalGroup['slug']),
            'error' => $params['error'] ?? null,
        ]);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $portalGroup = $this->portalGroupDao->findById($id);
        if (!$portalGroup) {
            return $this->redirect('/admin/portal-groups');
        }

        $data = (array) $request->getParsedBody();
        $error = $this->validate($data);
        if ($error !== null) {
            return $this->redirect("/admin/portal-groups/{$id}/edit?error=" . urlencode($error));
        }

        try {
            $this->portalGroupDao->update(
                $id,
                strtolower(trim((string) $data['slug'])),
                trim((string) $data['name']),
                $this->normalizeHostname($data['guest_hostname'] ?? null),
                !empty($data['is_active'])
            );
            $this->portalGroupDao->setProperties($id, $this->collectPropertyIds($data));
        } catch (\RuntimeException $e) {
            return $this->redirect("/admin/portal-groups/{$id}/edit?error=" . urlencode('Could not update portal group (slug or hostname may conflict).'));
        }

        return $this->redirect('/admin/portal-groups');
    }

    public function delete(Request $request, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        try {
            $this->portalGroupDao->deleteById($id);
        } catch (\RuntimeException $e) {
            return $this->redirect('/admin/portal-groups?error=' . urlencode('Could not delete portal group.'));
        }
        return $this->redirect('/admin/portal-groups');
    }

    /**
     * @param array<string, mixed> $data
     */
    private function validate(array $data): ?string
    {
        $slug = strtolower(trim((string) ($data['slug'] ?? '')));
        $name = trim((string) ($data['name'] ?? ''));
        $host = $this->normalizeHostname($data['guest_hostname'] ?? null);

        if ($slug === '' || !preg_match('/^[a-z0-9_-]+$/', $slug)) {
            return 'Slug must contain only lowercase letters, digits, underscores or hyphens.';
        }
        if ($name === '') {
            return 'Name is required.';
        }
        if ($host !== null && !preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $host)) {
            return 'Guest hostname must be a valid domain (or left blank).';
        }
        return null;
    }

    /**
     * @return int[]
     */
    private function collectPropertyIds(array $data): array
    {
        $ids = $data['property_ids'] ?? [];
        if (!is_array($ids)) {
            return [];
        }
        return array_values(array_unique(array_map(
            static fn ($v) => (int) $v,
            array_filter($ids, static fn ($v) => is_numeric($v) && (int) $v > 0)
        )));
    }

    private function normalizeHostname(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = strtolower(trim($value));
        return $value === '' ? null : $value;
    }

    /**
     * @return array{exists: bool, error: ?string}
     */
    private function configFileStatus(string $slug): array
    {
        if (!preg_match('/^[a-z0-9_-]+$/', $slug)) {
            return ['exists' => false, 'error' => 'Invalid slug'];
        }
        $path = BASE_DIR . '/config/portals/' . $slug . '.php';
        if (!is_file($path)) {
            return ['exists' => false, 'error' => 'config/portals/' . $slug . '.php does not exist'];
        }
        return ['exists' => true, 'error' => null];
    }

    private function redirect(string $location): Response
    {
        $response = new SlimResponse();
        return $response->withHeader('Location', $location)->withStatus(302);
    }
}
