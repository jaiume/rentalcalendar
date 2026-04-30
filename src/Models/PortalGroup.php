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
     * Resolve the Twig template name for a guest-portal page rendered
     * for this portal group. Example: $portal->template('laundry') for
     * the maravalroad group returns "portals/maravalroad/laundry.twig".
     */
    public function template(string $name): string
    {
        return sprintf('portals/%s/%s.twig', $this->slug, $name);
    }
}
