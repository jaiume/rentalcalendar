<?php

namespace App\Controllers;

use App\DAO\PropertyDAO;
use App\DAO\CleanerDAO;
use App\DAO\ReservationDAO;
use App\DAO\CleaningDAO;
use App\DAO\MaintenanceDAO;
use App\Services\SyncService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Psr7\Response as SlimResponse;

class DashboardApiController
{
    public function __construct(
        private readonly PropertyDAO $propertyDao,
        private readonly CleanerDAO $cleanerDao,
        private readonly ReservationDAO $reservationDao,
        private readonly CleaningDAO $cleaningDao,
        private readonly MaintenanceDAO $maintenanceDao,
        private readonly SyncService $syncService
    ) {
    }

    public function getProperties(Request $request, Response $response): Response
    {
        $properties = $this->propertyDao->findAll();

        $response = new SlimResponse();
        $response->getBody()->write(json_encode(['properties' => $properties]));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function getCleaners(Request $request, Response $response): Response
    {
        $cleaners = $this->cleanerDao->findAll();

        $response = new SlimResponse();
        $response->getBody()->write(json_encode(['cleaners' => $cleaners]));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function getEvents(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $startDate = $params['start_date'] ?? date('Y-m-d');
        $endDate = $params['end_date'] ?? date('Y-m-d', strtotime('+1 month'));
        $propertyId = $params['property_id'] ?? null;

        $events = [
            'reservations' => $this->reservationDao->findByDateRange($startDate, $endDate, $propertyId),
            'cleaning' => $this->cleaningDao->findByDateRange($startDate, $endDate, $propertyId),
            'maintenance' => $this->maintenanceDao->findByDateRange($startDate, $endDate, $propertyId)
        ];

        $response = new SlimResponse();
        $response->getBody()->write(json_encode($events));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function syncCalendar(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $force = isset($params['force']) && $params['force'] === '1';

        // For SSE, we need to disable output buffering and write directly
        if (ob_get_level()) {
            ob_end_clean();
        }
        
        // Set headers for SSE
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no'); // Disable nginx buffering

        try {
            echo "data: " . json_encode(['status' => 'starting', 'message' => 'Starting calendar sync...']) . "\n\n";
            if (ob_get_level()) {
                ob_flush();
            }
            flush();

            $total = 0;
            $success = 0;
            $errors = 0;
            $skipped = 0;
            $results = [];

            // Create progress callback that streams updates in real-time
            $progressCallback = function (int $current, int $totalCount, array $result) use (&$total, &$success, &$errors, &$skipped, &$results) {
                $total = $totalCount;
                $results[] = $result;
                
                $progress = (int)(($current / $totalCount) * 100);
                
                if ($result['status'] === 'success') {
                    $success++;
                } elseif ($result['status'] === 'error') {
                    $errors++;
                } else {
                    $skipped++;
                }

                $message = sprintf(
                    'Synced property %d/%d: %s - %s',
                    $current,
                    $totalCount,
                    $result['partner'] ?? 'unknown',
                    $result['status'] ?? 'unknown'
                );

                // Send progress update with result status
                echo "data: " . json_encode([
                    'status' => 'progress',
                    'current' => $current,
                    'total' => $totalCount,
                    'progress' => $progress,
                    'message' => $message,
                    'property_id' => $result['property_id'] ?? null,
                    'partner' => $result['partner'] ?? null,
                    'result' => $result
                ]) . "\n\n";
                
                if (ob_get_level()) {
                    ob_flush();
                }
                flush();

                // Also send individual status for JavaScript handlers that expect it
                if ($result['status'] === 'success' || $result['status'] === 'skipped' || $result['status'] === 'error') {
                    echo "data: " . json_encode([
                        'status' => $result['status'],
                        'message' => $message,
                        'result' => $result
                    ]) . "\n\n";
                    
                    if (ob_get_level()) {
                        ob_flush();
                    }
                    flush();
                }
            };

            // Perform sync with real-time progress updates
            $this->syncService->syncAll($force, $progressCallback);

            // Send completion message
            echo "data: " . json_encode([
                'status' => 'complete',
                'progress' => 100,
                'message' => sprintf('Sync complete: %d successful, %d errors, %d skipped', $success, $errors, $skipped),
                'results' => $results,
                'summary' => [
                    'total' => $total,
                    'success' => $success,
                    'errors' => $errors,
                    'skipped' => $skipped
                ]
            ]) . "\n\n";
            
            if (ob_get_level()) {
                ob_flush();
            }
            flush();

        } catch (\Exception $e) {
            echo "data: " . json_encode([
                'status' => 'error',
                'message' => 'Sync failed: ' . $e->getMessage()
            ]) . "\n\n";
            
            if (ob_get_level()) {
                ob_flush();
            }
            flush();
        }

        // Return empty response since we're writing directly to output
        return new SlimResponse();
    }

    public function checkSyncNeeded(Request $request, Response $response): Response
    {
        try {
            $needsSync = $this->syncService->needsSync();
            
            $response = new SlimResponse();
            $response->getBody()->write(json_encode(['needs_sync' => $needsSync]));
            return $response->withHeader('Content-Type', 'application/json');
        } catch (\Exception $e) {
            $response = new SlimResponse();
            $response->getBody()->write(json_encode(['error' => $e->getMessage()]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    }

    public function createReservation(Request $request, Response $response): Response
    {
        $data = (array) $request->getParsedBody();
        $user = $request->getAttribute('user');

        $propertyId = $data['property_id'] ?? null;
        $name = trim($data['reservation_name'] ?? '');
        $startDate = $data['reservation_start_date'] ?? null;
        $endDate = $data['reservation_end_date'] ?? null;
        $startTime = $data['reservation_start_time'] ?? 'standard';
        $endTime = $data['reservation_end_time'] ?? 'standard';
        $description = $data['reservation_description'] ?? null;

        if (!$propertyId || !$name || !$startDate || !$endDate) {
            $response = new SlimResponse();
            $response->getBody()->write(json_encode(['error' => 'Missing required fields']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        // Validate that new reservations cannot be created in the past
        $today = date('Y-m-d');
        if ($startDate < $today) {
            $response = new SlimResponse();
            $response->getBody()->write(json_encode(['error' => 'Cannot create reservations in the past']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        try {
            $guid = bin2hex(random_bytes(16));
            
            $id = $this->reservationDao->create(
                $propertyId,
                $user['user_id'] ?? null,
                'internal',
                $guid,
                'confirmed',
                $name,
                $description,
                $startDate,
                $startTime,
                $endDate,
                $endTime
            );
            
            $response = new SlimResponse();
            $response->getBody()->write(json_encode(['success' => true, 'id' => $id]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(201);
        } catch (\Exception $e) {
            $response = new SlimResponse();
            $response->getBody()->write(json_encode(['error' => 'Failed to create reservation: ' . $e->getMessage()]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    }

    public function createCleaning(Request $request, Response $response): Response
    {
        $data = (array) $request->getParsedBody();
        $user = $request->getAttribute('user');

        $propertyId = $data['property_id'] ?? null;
        $cleaningDate = $data['cleaning_date'] ?? null;
        $cleanerId = $data['cleaner_id'] ?? null;
        $cleaningWindow = $data['cleaning_window'] ?? null;
        $notes = $data['notes'] ?? null;

        if (!$propertyId || !$cleaningDate) {
            $response = new SlimResponse();
            $response->getBody()->write(json_encode(['error' => 'Missing required fields']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        // Validate that new cleaning events cannot be created in the past
        $today = date('Y-m-d');
        if ($cleaningDate < $today) {
            $response = new SlimResponse();
            $response->getBody()->write(json_encode(['error' => 'Cannot create cleaning events in the past']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        try {
            $id = $this->cleaningDao->create(
                $propertyId,
                $cleaningDate,
                $cleanerId ?: null,
                $cleaningWindow,
                $notes,
                $user['user_id'] ?? null
            );
            
            $response = new SlimResponse();
            $response->getBody()->write(json_encode(['success' => true, 'id' => $id]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(201);
        } catch (\Exception $e) {
            $response = new SlimResponse();
            $response->getBody()->write(json_encode(['error' => 'Failed to create cleaning: ' . $e->getMessage()]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    }

    public function createMaintenance(Request $request, Response $response): Response
    {
        $data = (array) $request->getParsedBody();
        $user = $request->getAttribute('user');

        $propertyId = $data['property_id'] ?? null;
        $startDate = $data['maintenance_start_date'] ?? null;
        $endDate = $data['maintenance_end_date'] ?? null;
        $description = trim($data['maintenance_description'] ?? '');
        $maintenanceType = $data['maintenance_type'] ?? null;

        if (!$propertyId || !$startDate || !$endDate || !$description) {
            $response = new SlimResponse();
            $response->getBody()->write(json_encode(['error' => 'Missing required fields']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        // Validate that new maintenance events cannot be created in the past
        $today = date('Y-m-d');
        if ($startDate < $today) {
            $response = new SlimResponse();
            $response->getBody()->write(json_encode(['error' => 'Cannot create maintenance events in the past']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        try {
            $id = $this->maintenanceDao->create(
                $propertyId,
                $startDate,
                $endDate,
                $description,
                $maintenanceType,
                $user['user_id'] ?? null
            );
            
            $response = new SlimResponse();
            $response->getBody()->write(json_encode(['success' => true, 'id' => $id]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(201);
        } catch (\Exception $e) {
            $response = new SlimResponse();
            $response->getBody()->write(json_encode(['error' => 'Failed to create maintenance: ' . $e->getMessage()]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    }

    public function deleteReservation(Request $request, Response $response, array $args): Response
    {
        $id = $args['id'] ?? null;

        if (!$id) {
            $response = new SlimResponse();
            $response->getBody()->write(json_encode(['error' => 'Missing reservation ID']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        try {
            $deleted = $this->reservationDao->deleteById($id);

            if (!$deleted) {
                $response = new SlimResponse();
                $response->getBody()->write(json_encode(['error' => 'Reservation not found']));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
            }

            $response = new SlimResponse();
            $response->getBody()->write(json_encode(['success' => true]));
            return $response->withHeader('Content-Type', 'application/json');
        } catch (\Exception $e) {
            $response = new SlimResponse();
            $response->getBody()->write(json_encode(['error' => 'Failed to delete reservation: ' . $e->getMessage()]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    }

    public function deleteCleaning(Request $request, Response $response, array $args): Response
    {
        $id = $args['id'] ?? null;

        if (!$id) {
            $response = new SlimResponse();
            $response->getBody()->write(json_encode(['error' => 'Missing cleaning ID']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        try {
            $deleted = $this->cleaningDao->deleteById($id);

            if (!$deleted) {
                $response = new SlimResponse();
                $response->getBody()->write(json_encode(['error' => 'Cleaning not found']));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
            }

            $response = new SlimResponse();
            $response->getBody()->write(json_encode(['success' => true]));
            return $response->withHeader('Content-Type', 'application/json');
        } catch (\Exception $e) {
            $response = new SlimResponse();
            $response->getBody()->write(json_encode(['error' => 'Failed to delete cleaning: ' . $e->getMessage()]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    }

    public function deleteMaintenance(Request $request, Response $response, array $args): Response
    {
        $id = $args['id'] ?? null;

        if (!$id) {
            $response = new SlimResponse();
            $response->getBody()->write(json_encode(['error' => 'Missing maintenance ID']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        try {
            $deleted = $this->maintenanceDao->deleteById($id);

            if (!$deleted) {
                $response = new SlimResponse();
                $response->getBody()->write(json_encode(['error' => 'Maintenance not found']));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
            }

            $response = new SlimResponse();
            $response->getBody()->write(json_encode(['success' => true]));
            return $response->withHeader('Content-Type', 'application/json');
        } catch (\Exception $e) {
            $response = new SlimResponse();
            $response->getBody()->write(json_encode(['error' => 'Failed to delete maintenance: ' . $e->getMessage()]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    }
}
