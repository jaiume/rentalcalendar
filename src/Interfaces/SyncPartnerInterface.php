<?php

namespace App\Interfaces;

interface SyncPartnerInterface
{
    public function getName(): string;
    
    /**
     * Sync reservations for a property from a given URL.
     * 
     * @param int $propertyId
     * @param string $url
     * @return array Result stats (added, updated, skipped, errors)
     */
    public function sync(int $propertyId, string $url): array;
}

