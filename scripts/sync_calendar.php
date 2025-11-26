<?php

use App\Services\SyncService;
use DI\ContainerBuilder;

require __DIR__ . '/../vendor/autoload.php';

define('BASE_DIR', __DIR__ . '/..');

// Instantiate PHP-DI ContainerBuilder
$containerBuilder = new ContainerBuilder();

// Add definitions
$containerBuilder->addDefinitions(BASE_DIR . '/config/container.php');

// Build Container
$container = $containerBuilder->build();

// Get SyncService
$syncService = $container->get(SyncService::class);

try {
    // Check for force flag
    $force = in_array('--force', $argv);
    
    $results = $syncService->syncAll($force);
    
    // Output JSON for logging
    echo json_encode([
        'timestamp' => date('Y-m-d H:i:s'),
        'results' => $results
    ], JSON_PRETTY_PRINT) . "\n";
    
} catch (Exception $e) {
    echo json_encode([
        'timestamp' => date('Y-m-d H:i:s'),
        'error' => $e->getMessage()
    ], JSON_PRETTY_PRINT) . "\n";
    exit(1);
}

