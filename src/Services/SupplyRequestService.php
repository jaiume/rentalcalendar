<?php

namespace App\Services;

use App\DAO\SupplyRequestDAO;
use App\Models\PortalGroup;

/**
 * Validates and persists guest-submitted supply requests. After
 * persisting, fires a single admin notification email via
 * AdminNotificationService. Failures inside the notifier are logged
 * but never propagate — the guest's submission has already been
 * recorded and they get a normal thank-you redirect either way.
 *
 * Per-staff push notifications remain deferred to the future
 * staff-portal session.
 */
class SupplyRequestService
{
    public function __construct(
        private readonly SupplyRequestDAO $supplyRequestDao,
        private readonly AdminNotificationService $adminNotifier
    ) {
    }

    /**
     * @param string[] $checkedItems suggested items the guest ticked
     */
    public function submit(
        PortalGroup $portal,
        int $propertyId,
        array $checkedItems,
        string $extraNotes
    ): int {
        if (!$portal->suppliesEnabled()) {
            throw new \RuntimeException('Supplies are not enabled for this portal');
        }
        if (!$portal->hasProperty($propertyId)) {
            throw new \InvalidArgumentException('Property does not belong to this portal group');
        }

        $allowedSuggestions = $portal->supplyItemSuggestions();
        $accepted = [];
        foreach ($checkedItems as $item) {
            $item = is_string($item) ? trim($item) : '';
            if ($item !== '' && in_array($item, $allowedSuggestions, true)) {
                $accepted[] = $item;
            }
        }
        $accepted = array_values(array_unique($accepted));

        $extra = trim($extraNotes);
        if ($extra === '' && empty($accepted)) {
            throw new \InvalidArgumentException('Please choose at least one item or write a note');
        }

        $requestText = $this->buildRequestText($accepted, $extra);

        $id = $this->supplyRequestDao->create($portal->id(), $propertyId, $requestText);

        LogService::info('Supply request created', [
            'supply_request_id' => $id,
            'portal_group_id' => $portal->id(),
            'portal_slug' => $portal->slug(),
            'property_id' => $propertyId,
            'item_count' => count($accepted),
            'has_notes' => $extra !== '',
        ]);

        $this->adminNotifier->notifySupplyRequest(
            $portal,
            $this->resolvePropertyName($portal, $propertyId),
            $accepted,
            $extra,
            $id
        );

        return $id;
    }

    private function resolvePropertyName(PortalGroup $portal, int $propertyId): string
    {
        foreach ($portal->properties() as $property) {
            if ((int) ($property['property_id'] ?? 0) === $propertyId) {
                return (string) ($property['property_name'] ?? '#' . $propertyId);
            }
        }
        return '#' . $propertyId;
    }

    /**
     * @param string[] $items
     */
    private function buildRequestText(array $items, string $extra): string
    {
        $lines = [];
        if (!empty($items)) {
            $lines[] = 'Items requested:';
            foreach ($items as $item) {
                $lines[] = '- ' . $item;
            }
        }
        if ($extra !== '') {
            if (!empty($lines)) {
                $lines[] = '';
            }
            $lines[] = 'Notes:';
            $lines[] = $extra;
        }
        return implode("\n", $lines);
    }
}
