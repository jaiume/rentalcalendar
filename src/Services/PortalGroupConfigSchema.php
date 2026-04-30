<?php

namespace App\Services;

use App\Exceptions\PortalConfigException;

/**
 * Validates the structure of a per-portal config file
 * (config/portals/<slug>.php). Allow-lists top-level keys and asserts
 * that required sub-keys are present when a section is enabled.
 *
 * Failure throws PortalConfigException; the caller (PortalGroupResolver)
 * lets that bubble up to HostnameRoutingMiddleware which maps it to 503.
 */
class PortalGroupConfigSchema
{
    private const ALLOWED_TOP_LEVEL_KEYS = ['laundry', 'supplies', 'branding'];

    /**
     * @param array<string, mixed> $config
     */
    public function validate(string $slug, array $config): void
    {
        foreach (array_keys($config) as $key) {
            if (!in_array($key, self::ALLOWED_TOP_LEVEL_KEYS, true)) {
                throw new PortalConfigException(sprintf(
                    'Portal "%s" config has unknown top-level key "%s"; allowed keys: %s',
                    $slug,
                    (string) $key,
                    implode(', ', self::ALLOWED_TOP_LEVEL_KEYS)
                ));
            }
        }

        if (isset($config['laundry'])) {
            $this->validateLaundry($slug, $config['laundry']);
        }

        if (isset($config['supplies'])) {
            $this->validateSupplies($slug, $config['supplies']);
        }

        if (isset($config['branding'])) {
            $this->validateBranding($slug, $config['branding']);
        }
    }

    /**
     * @param mixed $section
     */
    private function validateBranding(string $slug, $section): void
    {
        if (!is_array($section)) {
            throw new PortalConfigException(sprintf('Portal "%s" branding config must be an array', $slug));
        }
        if (array_key_exists('logo_url', $section)) {
            $logo = $section['logo_url'];
            if ($logo !== null && (!is_string($logo) || trim($logo) === '')) {
                throw new PortalConfigException(sprintf(
                    'Portal "%s" branding.logo_url must be a non-empty string or null',
                    $slug
                ));
            }
        }
        if (array_key_exists('tagline', $section)) {
            $tagline = $section['tagline'];
            if ($tagline !== null && !is_string($tagline)) {
                throw new PortalConfigException(sprintf(
                    'Portal "%s" branding.tagline must be a string or null',
                    $slug
                ));
            }
        }
    }

    /**
     * @param mixed $section
     */
    private function validateLaundry(string $slug, $section): void
    {
        if (!is_array($section)) {
            throw new PortalConfigException(sprintf('Portal "%s" laundry config must be an array', $slug));
        }
        if (!($section['enabled'] ?? false)) {
            return;
        }

        $requireKeys = ['price_cents', 'currency', 'padlock_combination', 'padlock_instructions_html'];
        foreach ($requireKeys as $key) {
            if (!array_key_exists($key, $section)) {
                throw new PortalConfigException(sprintf(
                    'Portal "%s" laundry config is enabled but missing key "%s"',
                    $slug,
                    $key
                ));
            }
        }

        if (!is_int($section['price_cents']) || $section['price_cents'] <= 0) {
            throw new PortalConfigException(sprintf('Portal "%s" laundry.price_cents must be a positive integer', $slug));
        }
        if (!is_string($section['currency']) || !preg_match('/^[A-Z]{3}$/', $section['currency'])) {
            throw new PortalConfigException(sprintf('Portal "%s" laundry.currency must be a 3-letter ISO 4217 code', $slug));
        }
        if (!is_string($section['padlock_combination']) || trim($section['padlock_combination']) === '') {
            throw new PortalConfigException(sprintf('Portal "%s" laundry.padlock_combination must be a non-empty string', $slug));
        }
        if (!is_string($section['padlock_instructions_html'])) {
            throw new PortalConfigException(sprintf('Portal "%s" laundry.padlock_instructions_html must be a string', $slug));
        }
    }

    /**
     * @param mixed $section
     */
    private function validateSupplies(string $slug, $section): void
    {
        if (!is_array($section)) {
            throw new PortalConfigException(sprintf('Portal "%s" supplies config must be an array', $slug));
        }
        if (!($section['enabled'] ?? false)) {
            return;
        }

        if (!isset($section['item_suggestions']) || !is_array($section['item_suggestions'])) {
            throw new PortalConfigException(sprintf('Portal "%s" supplies.item_suggestions must be an array', $slug));
        }
        foreach ($section['item_suggestions'] as $idx => $item) {
            if (!is_string($item) || trim($item) === '') {
                throw new PortalConfigException(sprintf(
                    'Portal "%s" supplies.item_suggestions[%s] must be a non-empty string',
                    $slug,
                    (string) $idx
                ));
            }
        }
    }
}
