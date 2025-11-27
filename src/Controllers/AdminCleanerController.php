<?php

namespace App\Controllers;

use App\DAO\CleanerDAO;
use App\DAO\CleaningDAO;
use App\DAO\PropertyDAO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Psr7\Response as SlimResponse;
use Slim\Views\Twig;

class AdminCleanerController
{
    public function __construct(
        private readonly Twig $view,
        private readonly CleanerDAO $cleanerDao,
        private readonly CleaningDAO $cleaningDao,
        private readonly PropertyDAO $propertyDao
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $cleaners = $this->cleanerDao->findAllWithAllColumns();

        return $this->view->render($response, 'admin/cleaners/index.twig', [
            'cleaners' => $cleaners,
        ]);
    }

    public function create(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();

        return $this->view->render($response, 'admin/cleaners/form.twig', [
            'cleaner' => null,
            'error' => $params['error'] ?? null,
        ]);
    }

    public function store(Request $request): Response
    {
        $data = (array) $request->getParsedBody();
        $name = trim($data['cleaner_name'] ?? '');
        $initials = strtoupper(trim($data['cleaner_initials'] ?? ''));
        $phone = trim($data['phone'] ?? '');

        if (!$name || !$initials) {
            return $this->redirectWithError('/admin/cleaners/create', 'Cleaner name and initials are required');
        }

        if (strlen($initials) > 5) {
            return $this->redirectWithError('/admin/cleaners/create', 'Initials must be 5 characters or less');
        }

        try {
            $this->cleanerDao->create($name, $initials, $phone ?: null);

            $response = new SlimResponse();
            return $response
                ->withHeader('Location', '/admin/cleaners')
                ->withStatus(302);
        } catch (\Exception $e) {
            if ($e->getCode() == 23000) { // Duplicate entry
                return $this->redirectWithError('/admin/cleaners/create', 'A cleaner with these initials already exists');
            }
            return $this->redirectWithError('/admin/cleaners/create', 'Failed to create cleaner');
        }
    }

    public function edit(Request $request, Response $response, array $args): Response
    {
        $cleanerId = (int) ($args['id'] ?? 0);
        $params = $request->getQueryParams();

        $cleaner = $this->cleanerDao->findById($cleanerId);

        if (!$cleaner) {
            $response = new SlimResponse();
            return $response
                ->withHeader('Location', '/admin/cleaners')
                ->withStatus(302);
        }

        return $this->view->render($response, 'admin/cleaners/form.twig', [
            'cleaner' => $cleaner,
            'error' => $params['error'] ?? null,
        ]);
    }

    public function update(Request $request, array $args): Response
    {
        $cleanerId = (int) ($args['id'] ?? 0);
        $data = (array) $request->getParsedBody();
        $name = trim($data['cleaner_name'] ?? '');
        $initials = strtoupper(trim($data['cleaner_initials'] ?? ''));
        $phone = trim($data['phone'] ?? '');

        if (!$name || !$initials) {
            return $this->redirectWithError("/admin/cleaners/{$cleanerId}/edit", 'Cleaner name and initials are required');
        }

        if (strlen($initials) > 5) {
            return $this->redirectWithError("/admin/cleaners/{$cleanerId}/edit", 'Initials must be 5 characters or less');
        }

        try {
            $this->cleanerDao->update($cleanerId, $name, $initials, $phone ?: null);

            $response = new SlimResponse();
            return $response
                ->withHeader('Location', '/admin/cleaners')
                ->withStatus(302);
        } catch (\Exception $e) {
            if ($e->getCode() == 23000) { // Duplicate entry
                return $this->redirectWithError("/admin/cleaners/{$cleanerId}/edit", 'A cleaner with these initials already exists');
            }
            return $this->redirectWithError("/admin/cleaners/{$cleanerId}/edit", 'Failed to update cleaner');
        }
    }

    public function delete(Request $request, array $args): Response
    {
        $cleanerId = (int) ($args['id'] ?? 0);

        try {
            $this->cleanerDao->deleteById($cleanerId);

            $response = new SlimResponse();
            return $response
                ->withHeader('Location', '/admin/cleaners')
                ->withStatus(302);
        } catch (\Exception $e) {
            return $this->redirectWithError('/admin/cleaners', 'Failed to delete cleaner');
        }
    }

    public function schedule(Request $request, Response $response): Response
    {
        return $this->view->render($response, 'admin/cleaners/schedule.twig', []);
    }

    public function getScheduleData(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $cleanerId = $params['cleaner_id'] ?? null;
        $weekStart = $params['week_start'] ?? null;

        if (!$cleanerId || !$weekStart) {
            $response = new SlimResponse();
            $response->getBody()->write(json_encode(['error' => 'Missing required parameters']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        // Calculate week end (6 days after start)
        $weekEnd = date('Y-m-d', strtotime($weekStart . ' +6 days'));

        // Get all cleaning events for this cleaner in the week
        $cleaningEvents = $this->cleaningDao->findByDateRange($weekStart, $weekEnd);
        
        // Filter by cleaner
        $cleaningEvents = array_filter($cleaningEvents, function($event) use ($cleanerId) {
            return $event['cleaner_id'] == $cleanerId;
        });

        // Get property details for all events
        $propertyIds = array_unique(array_column($cleaningEvents, 'property_id'));
        $properties = [];
        foreach ($propertyIds as $propertyId) {
            $property = $this->propertyDao->findById($propertyId);
            if ($property) {
                $properties[$propertyId] = $property;
            }
        }

        // Group events by date
        $schedule = [];
        foreach ($cleaningEvents as $event) {
            $date = $event['cleaning_date'];
            if (!isset($schedule[$date])) {
                $schedule[$date] = [];
            }
            $event['property_name'] = $properties[$event['property_id']]['property_name'] ?? 'Unknown Property';
            $schedule[$date][] = $event;
        }

        $response = new SlimResponse();
        $response->getBody()->write(json_encode([
            'schedule' => $schedule,
            'week_start' => $weekStart,
            'week_end' => $weekEnd
        ]));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function getAvailableWeeks(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $cleanerId = $params['cleaner_id'] ?? null;

        if (!$cleanerId) {
            $response = new SlimResponse();
            $response->getBody()->write(json_encode(['weeks' => []]));
            return $response->withHeader('Content-Type', 'application/json');
        }

        // Get cleaning events for this cleaner from today onwards (next 6 months)
        $startDate = date('Y-m-d');
        $endDate = date('Y-m-d', strtotime('+6 months'));
        
        $cleaningEvents = $this->cleaningDao->findByDateRange($startDate, $endDate);
        
        // Filter by cleaner
        $cleaningEvents = array_filter($cleaningEvents, function($event) use ($cleanerId) {
            return $event['cleaner_id'] == $cleanerId;
        });

        // Get unique weeks (Monday as start of week)
        $weeks = [];
        foreach ($cleaningEvents as $event) {
            $date = new \DateTime($event['cleaning_date']);
            // Get Monday of the week
            $dayOfWeek = $date->format('N'); // 1 (Monday) to 7 (Sunday)
            $daysToSubtract = $dayOfWeek - 1;
            $monday = clone $date;
            $monday->modify("-{$daysToSubtract} days");
            $weekStart = $monday->format('Y-m-d');
            
            if (!in_array($weekStart, $weeks)) {
                $weeks[] = $weekStart;
            }
        }

        sort($weeks);

        $response = new SlimResponse();
        $response->getBody()->write(json_encode(['weeks' => $weeks]));
        return $response->withHeader('Content-Type', 'application/json');
    }

    private function redirectWithError(string $location, string $message): Response
    {
        $response = new SlimResponse();
        return $response
            ->withHeader('Location', $location . '?error=' . urlencode($message))
            ->withStatus(302);
    }
}



