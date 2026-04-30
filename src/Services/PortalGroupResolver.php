<?php

namespace App\Services;

use App\DAO\PortalGroupDAO;
use App\Exceptions\PortalConfigException;
use App\Exceptions\PortalNotFoundException;
use App\Models\PortalGroup;

/**
 * Resolves the active guest PortalGroup for an incoming request hostname.
 * Used by HostnameRoutingMiddleware to decide which "face" of the app
 * (guest vs admin) handles the request, and by guest controllers via
 * the request attribute set by that middleware.
 *
 * Caches resolutions per-process so repeated calls within a single
 * request (or test run) don't re-read the DB / config file.
 */
class PortalGroupResolver
{
    /** @var array<string, PortalGroup|null> */
    private array $byHostnameCache = [];

    /** @var array<string, PortalGroup> */
    private array $bySlugCache = [];

    public function __construct(
        private readonly PortalGroupDAO $portalGroupDao,
        private readonly PortalGroupConfigSchema $schema
    ) {
    }

    /**
     * Strip port and lowercase the hostname.
     */
    public static function normalizeHostname(string $hostHeader): string
    {
        $host = strtolower(trim($hostHeader));
        // Strip port if present (handles both "host:80" and "[::1]:80")
        $host = preg_replace('/:\d+$/', '', $host) ?? $host;
        // Strip surrounding brackets from IPv6 literal hosts
        $host = trim($host, '[]');
        return $host;
    }

    /**
     * Returns the active PortalGroup for this guest hostname, or null
     * if the hostname doesn't match any active guest portal. Does NOT
     * throw on "not found" — the caller decides whether to fall back to
     * the admin face.
     */
    public function resolveGuestByHostname(string $hostHeader): ?PortalGroup
    {
        $hostname = self::normalizeHostname($hostHeader);
        if ($hostname === '') {
            return null;
        }

        if (array_key_exists($hostname, $this->byHostnameCache)) {
            return $this->byHostnameCache[$hostname];
        }

        $row = $this->portalGroupDao->findByGuestHostname($hostname);
        if (!$row || !(int) $row['is_active']) {
            return $this->byHostnameCache[$hostname] = null;
        }

        return $this->byHostnameCache[$hostname] = $this->buildPortalGroup($row);
    }

    /**
     * Resolve by slug for admin-side previews / utilities. Throws if the
     * slug doesn't match an active group, or if the config file is missing.
     */
    public function resolveBySlug(string $slug): PortalGroup
    {
        if (isset($this->bySlugCache[$slug])) {
            return $this->bySlugCache[$slug];
        }

        $row = $this->portalGroupDao->findBySlug($slug);
        if (!$row) {
            throw new PortalNotFoundException(sprintf('Portal group "%s" not found', $slug));
        }
        if (!(int) $row['is_active']) {
            throw new PortalNotFoundException(sprintf('Portal group "%s" is inactive', $slug));
        }

        return $this->bySlugCache[$slug] = $this->buildPortalGroup($row);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function buildPortalGroup(array $row): PortalGroup
    {
        $slug = (string) $row['slug'];
        if (!preg_match('/^[a-z0-9_-]+$/', $slug)) {
            throw new PortalConfigException(sprintf(
                'Portal group slug "%s" is invalid (expected ^[a-z0-9_-]+$)',
                $slug
            ));
        }

        $config = $this->loadConfig($slug);
        $this->schema->validate($slug, $config);

        $properties = $this->portalGroupDao->findProperties((int) $row['portal_group_id']);

        return new PortalGroup(
            (int) $row['portal_group_id'],
            $slug,
            (string) $row['name'],
            $row['guest_hostname'] !== null ? (string) $row['guest_hostname'] : null,
            (bool) $row['is_active'],
            $properties,
            $config
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function loadConfig(string $slug): array
    {
        $path = BASE_DIR . '/config/portals/' . $slug . '.php';
        if (!is_file($path)) {
            throw new PortalConfigException(sprintf(
                'Portal config file is missing: config/portals/%s.php',
                $slug
            ));
        }

        $config = require $path;
        if (!is_array($config)) {
            throw new PortalConfigException(sprintf(
                'Portal config file did not return an array: config/portals/%s.php',
                $slug
            ));
        }

        return $config;
    }
}
