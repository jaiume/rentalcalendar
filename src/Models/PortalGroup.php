<?php

namespace App\Models;

/**
 * Immutable value object representing a guest portal group: the DB row
 * (identity, hostname, properties) merged with its per-portal config file
 * (laundry/supplies settings).
 *
 * Instances are produced by App\Services\PortalGroupResolver. Controllers
 * receive one via the request attribute set by HostnameRoutingMiddleware.
 */
class PortalGroup
{
    /**
     * @param array<int, array<string, mixed>> $properties Property rows tied to this group
     * @param array<string, mixed>             $config    Validated per-portal config (laundry/supplies)
     */
    public function __construct(
        private readonly int $id,
        private readonly string $slug,
        private readonly string $name,
        private readonly ?string $guestHostname,
        private readonly bool $isActive,
        private readonly array $properties,
        private readonly array $config
    ) {
    }

    public function id(): int
    {
        return $this->id;
    }

    public function slug(): string
    {
        return $this->slug;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function guestHostname(): ?string
    {
        return $this->guestHostname;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function properties(): array
    {
        return $this->properties;
    }

    public function hasProperty(int $propertyId): bool
    {
        foreach ($this->properties as $property) {
            if ((int) $property['property_id'] === $propertyId) {
                return true;
            }
        }
        return false;
    }

    /**
     * Laundry section of the per-portal config, or an empty array if
     * the section is missing.
     *
     * @return array<string, mixed>
     */
    public function laundry(): array
    {
        return is_array($this->config['laundry'] ?? null) ? $this->config['laundry'] : [];
    }

    public function laundryEnabled(): bool
    {
        return (bool) ($this->laundry()['enabled'] ?? false);
    }

    /**
     * Number of days a paying guest may revisit the laundry page and see
     * the padlock combination again before being asked to pay once more.
     * Defaults to 7. Clamped to [1, 365] so a typo in the per-portal
     * config can't render the cookie effectively permanent or zero-length.
     */
    public function laundryAccessDays(): int
    {
        $raw = $this->laundry()['access_days'] ?? 7;
        if (!is_int($raw)) {
            $raw = 7;
        }
        return max(1, min(365, $raw));
    }

    /**
     * Supplies section of the per-portal config, or an empty array if missing.
     *
     * @return array<string, mixed>
     */
    public function supplies(): array
    {
        return is_array($this->config['supplies'] ?? null) ? $this->config['supplies'] : [];
    }

    public function suppliesEnabled(): bool
    {
        return (bool) ($this->supplies()['enabled'] ?? false);
    }

    /**
     * @return string[]
     */
    public function supplyItemSuggestions(): array
    {
        $items = $this->supplies()['item_suggestions'] ?? [];
        if (!is_array($items)) {
            return [];
        }

        return array_values(array_filter(
            array_map('strval', $items),
            static fn (string $item): bool => $item !== ''
        ));
    }

    /**
     * Branding section of the per-portal config (logo URL, tagline, etc.).
     * Returns an empty array when no branding is configured.
     *
     * @return array<string, mixed>
     */
    public function branding(): array
    {
        return is_array($this->config['branding'] ?? null) ? $this->config['branding'] : [];
    }

    /**
     * Public URL of the portal logo, if configured. Otherwise null
     * (callers should fall back to rendering the portal name).
     */
    public function logoUrl(): ?string
    {
        $url = $this->branding()['logo_url'] ?? null;
        if (!is_string($url) || trim($url) === '') {
            return null;
        }
        return $url;
    }

    /**
     * Optional tagline shown under the portal name in the header.
     * Falls back to a sensible default when unset so the layout always
     * has something to render.
     */
    public function tagline(): string
    {
        $tagline = $this->branding()['tagline'] ?? null;
        if (is_string($tagline) && trim($tagline) !== '') {
            return $tagline;
        }
        return 'Guest services';
    }

    /**
     * Resolve the Twig template name for a guest-portal page rendered
     * for this portal group. Example: $portal->template('laundry') for
     * the maravalroad group returns "portals/maravalroad/laundry.twig".
     */
    public function template(string $name): string
    {
        return sprintf('portals/%s/%s.twig', $this->slug, $name);
    }
}
