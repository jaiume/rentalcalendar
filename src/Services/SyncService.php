<?php

namespace App\Services;

use App\DAO\PropertyCalendarImportLinkDAO;
use App\Interfaces\SyncPartnerInterface;
use App\Services\SyncPartners\AirBNBHandler;
use DateTime;

class SyncService
{
    private array $handlers = [];

    public function __construct(
        private readonly PropertyCalendarImportLinkDAO $linkDao,
        private readonly ConfigService $config,
        AirBNBHandler $airbnbHandler
    ) {
        $this->registerHandler($airbnbHandler);
    }

    public function registerHandler(SyncPartnerInterface $handler): void
    {
        $this->handlers[$handler->getName()] = $handler;
    }

    /**
     * Sync all active links with progress callback.
     * 
     * @param bool $force Force sync even if interval hasn't passed
     * @param callable|null $progressCallback Callback function($index, $total, $result) for progress updates
     * @return array Final results
     */
    public function syncAll(bool $force = false, ?callable $progressCallback = null): array
    {
        $rows = $this->linkDao->findAllActive();
        $total = count($rows);
        $results = [];
        
        foreach ($rows as $index => $row) {
            $partnerName = $row['sync_partner_name'];
            $lastFetch = $row['last_fetch_at'];
            
            // Check recheck interval
            if (!$force && $lastFetch) {
                $interval = (int) $this->config::get($partnerName . '.recheck_interval', 3600);
                $lastFetchTime = new DateTime($lastFetch);
                $nextFetchTime = (clone $lastFetchTime)->modify("+{$interval} seconds");
                
                if (new DateTime() < $nextFetchTime) {
                    $result = [
                        'property_id' => $row['property_id'],
                        'partner' => $partnerName,
                        'status' => 'skipped',
                        'message' => 'Interval not met'
                    ];
                    $results[] = $result;
                    
                    if ($progressCallback) {
                        $progressCallback($index + 1, $total, $result);
                    }
                    continue;
                }
            }
            
            if (isset($this->handlers[$partnerName])) {
                try {
                    $stats = $this->handlers[$partnerName]->sync(
                        (int) $row['property_id'],
                        $row['import_link_url']
                    );
                    $result = [
                        'property_id' => $row['property_id'],
                        'partner' => $partnerName,
                        'status' => 'success',
                        'stats' => $stats
                    ];
                    $results[] = $result;
                    
                    if ($progressCallback) {
                        $progressCallback($index + 1, $total, $result);
                    }
                } catch (\Exception $e) {
                    $result = [
                        'property_id' => $row['property_id'],
                        'partner' => $partnerName,
                        'status' => 'error',
                        'message' => $e->getMessage()
                    ];
                    $results[] = $result;
                    
                    if ($progressCallback) {
                        $progressCallback($index + 1, $total, $result);
                    }
                }
            } else {
                $result = [
                    'property_id' => $row['property_id'],
                    'partner' => $partnerName,
                    'status' => 'skipped',
                    'message' => 'No handler found'
                ];
                $results[] = $result;
                
                if ($progressCallback) {
                    $progressCallback($index + 1, $total, $result);
                }
            }
        }
        
        return $results;
    }
}
