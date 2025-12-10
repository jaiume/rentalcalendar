<?php

namespace App\Services\SyncPartners;

use App\DAO\ReservationDAO;
use App\DAO\PropertyCalendarImportLinkDAO;
use App\Interfaces\SyncPartnerInterface;
use App\Services\ConfigService;
use App\Services\ICalParser;

class AirBNBHandler implements SyncPartnerInterface
{
    public function __construct(
        private readonly ReservationDAO $reservationDao,
        private readonly PropertyCalendarImportLinkDAO $linkDao,
        private readonly ConfigService $config,
        private readonly ICalParser $parser
    ) {
    }

    public function getName(): string
    {
        return 'AirBNB';
    }

    public function sync(int $propertyId, string $url): array
    {
        $stats = [
            'added' => 0,
            'updated' => 0,
            'skipped' => 0,
            'deleted' => 0,
            'errors' => 0,
        ];

        try {
            // Fetch iCal content
            $content = $this->fetchUrl($url);
            if (!$content) {
                throw new \RuntimeException("Failed to fetch iCal content from $url");
            }

            // Parse events
            $events = $this->parser->parse($content);

            // Track GUIDs from current sync (only for valid reservations, not skipped ones)
            $currentGuids = [];

            // Process each event
            foreach ($events as $event) {
                try {
                    $result = $this->processEvent($propertyId, $event, $currentGuids);
                    if ($result === 'added') {
                        $stats['added']++;
                    } elseif ($result === 'updated') {
                        $stats['updated']++;
                    } else {
                        $stats['skipped']++;
                    }
                } catch (\Exception $e) {
                    $stats['errors']++;
                    // Log error?
                }
            }
            
            // Delete reservations that are no longer in the feed
            // Keep deleted reservations for configured number of days after their end date
            $keepDeletedDays = (int) $this->config::get('AirBNB.keep_deleted_reservations_for', 30);
            $deletedCount = $this->reservationDao->deleteNotInGuidList($propertyId, 'AirBNB', $currentGuids, $keepDeletedDays);
            $stats['deleted'] = $deletedCount;
            
            // Update last fetch status
            $this->updateLinkStatus($propertyId, $url, 'success');

        } catch (\Exception $e) {
            $this->updateLinkStatus($propertyId, $url, 'error: ' . $e->getMessage());
            throw $e;
        }

        return $stats;
    }

    private function fetchUrl(string $url): ?string
    {
        // Basic curl implementation
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_USERAGENT, 'RentalCalendar/1.0');
        
        $content = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !$content) {
            return null;
        }

        return $content;
    }

    /**
     * Process a single event.
     * Returns 'added', 'updated', or 'skipped'
     * 
     * @param array $currentGuids Array reference to track GUIDs from current sync
     */
    private function processEvent(int $propertyId, array $event, array &$currentGuids): string
    {
        if (empty($event['uid']) || empty($event['start'])) {
            return 'skipped';
        }

        // Skip "Airbnb (Not available)" entries
        $summary = trim($event['summary'] ?? '');
        if ($summary === 'Airbnb (Not available)') {
            return 'skipped';
        }

        // Format dates
        $startDate = $this->formatDate($event['start']);
        $endDate = isset($event['end']) ? $this->formatDate($event['end']) : $startDate;

        // Parse AirBNB description to extract reservation code and URL
        $parsed = $this->parseAirBNBDescription($event['description'] ?? '', $event['summary'] ?? '');
        $reservationName = $parsed['name'];
        $reservationDescription = $parsed['description'];
        
        $uid = $event['uid'];

        // Track this GUID as present in current sync
        $currentGuids[] = $uid;

        // Check if reservation exists
        $existing = $this->reservationDao->findByGuid($uid);

        if ($existing) {
            // Update if changed
            if (
                $existing['reservation_start_date'] !== $startDate ||
                $existing['reservation_end_date'] !== $endDate
            ) {
                $this->reservationDao->updateDatesAndDetails(
                    $existing['reservation_id'],
                    $startDate,
                    $endDate,
                    $reservationName,
                    $reservationDescription
                );
                return 'updated';
            }
            
            // Just update check time
            $this->reservationDao->updateLastChecked($existing['reservation_id']);
                
            return 'skipped';
        }

        // Insert new
        $this->reservationDao->create(
            $propertyId,
            null,
            'sync_partner',
            $uid,
            'confirmed',
            $reservationName,
            $reservationDescription,
            $startDate,
            'standard', // Default for sync
            $endDate,
            'standard',   // Default for sync
            'AirBNB'
        );

        return 'added';
    }

    /**
     * Parse AirBNB description to extract reservation code and format description.
     * 
     * For events with "Reservation URL: https://www.airbnb.com/hosting/reservations/details/CODE":
     * - Extract CODE as the reservation name
     * - Format description as "Reservation URL: https://www.airbnb.com/hosting/reservations/details/CODE"
     * 
     * For events without a reservation URL (like "Airbnb (Not available)"), use the summary as the name.
     */
    private function parseAirBNBDescription(string $description, string $summary): array
    {
        // Normalize whitespace (handle line breaks and extra spaces)
        $description = preg_replace('/\s+/', ' ', $description);
        
        // Pattern to match: "Reservation URL: https://www.airbnb.com/hosting/reservations/details/CODE"
        // The reservation code appears to be alphanumeric, typically uppercase
        $pattern = '/Reservation\s+URL:\s*https?:\/\/www\.airbnb\.com\/hosting\/reservations\/details\/([A-Z0-9]+)/i';
        
        if (preg_match($pattern, $description, $matches)) {
            $reservationCode = $matches[1];
            $reservationUrl = "https://www.airbnb.com/hosting/reservations/details/{$reservationCode}";
            
            return [
                'name' => $reservationCode,
                'description' => "Reservation URL: {$reservationUrl}"
            ];
        }
        
        // No reservation URL found, use summary as name (e.g., "Airbnb (Not available)")
        return [
            'name' => substr(trim($summary), 0, 200),
            'description' => null
        ];
    }

    private function formatDate(string $dateStr): string
    {
        // Handle YYYYMMDD
        if (preg_match('/^\d{8}$/', $dateStr)) {
            return substr($dateStr, 0, 4) . '-' . substr($dateStr, 4, 2) . '-' . substr($dateStr, 6, 2);
        }
        // Handle YYYYMMDDTHHMMSSZ
        if (preg_match('/^\d{8}T\d{6}Z?$/', $dateStr)) {
            return substr($dateStr, 0, 4) . '-' . substr($dateStr, 4, 2) . '-' . substr($dateStr, 6, 2);
        }
        
        return $dateStr;
    }
    
    private function updateLinkStatus(int $propertyId, string $url, string $status): void
    {
        $this->linkDao->updateLinkStatus($propertyId, $url, $status);
    }
}
