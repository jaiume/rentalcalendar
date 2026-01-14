<?php

namespace App\Controllers;

use App\DAO\PropertyDAO;
use App\DAO\ReservationDAO;
use App\DAO\MaintenanceDAO;
use App\Services\ConfigService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Psr7\Response as SlimResponse;

class ICalExportController
{
    public function __construct(
        private readonly PropertyDAO $propertyDao,
        private readonly ReservationDAO $reservationDao,
        private readonly MaintenanceDAO $maintenanceDao,
        private readonly ConfigService $config
    ) {
    }

    public function export(Request $request, Response $response, array $args): Response
    {
        $guid = $args['guid'] ?? '';
        
        if (empty($guid)) {
            $response = new SlimResponse();
            $response->getBody()->write('Invalid export GUID');
            return $response->withStatus(404);
        }

        // Find property by export GUID
        $property = $this->propertyDao->findByExportGuid($guid);
        
        if (!$property) {
            $response = new SlimResponse();
            $response->getBody()->write('Property not found');
            return $response->withStatus(404);
        }

        // Get internal reservations and maintenance
        $reservations = $this->reservationDao->findInternalForExport($property['property_id']);
        $maintenance = $this->maintenanceDao->findForExport($property['property_id']);

        // Generate iCal content
        $ical = $this->generateICal($property, $reservations, $maintenance);

        $response = new SlimResponse();
        $response->getBody()->write($ical);
        return $response
            ->withHeader('Content-Type', 'text/calendar; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="calendar.ics"');
    }

    private function generateICal(array $property, array $reservations, array $maintenance): string
    {
        $lines = [];
        $lines[] = 'BEGIN:VCALENDAR';
        $lines[] = 'VERSION:2.0';
        $lines[] = 'PRODID:-//Rental Calendar//EN';
        $lines[] = 'CALSCALE:GREGORIAN';
        $lines[] = 'METHOD:PUBLISH';
        
        // Generate DTSTAMP for all events (tells AirBNB this is fresh/updated)
        $dtstamp = (new \DateTime('now', new \DateTimeZone('UTC')))->format('Ymd\THis\Z');
        
        // Get time values from config
        $earlyStart = $this->config::get('time_windows.early_start_time', '06:00:00');
        $standardStart = $this->config::get('time_windows.standard_start', '15:00:00');
        $standardEnd = $this->config::get('time_windows.standard_end', '12:00:00');
        $lateEnd = $this->config::get('time_windows.late_end_time', '22:00:00');
        
        // Get pre/post reservation buffer days for internal reservations
        $preReservationDays = (int) $this->config::get('time_windows.pre_reservation_days', 0);
        $postReservationDays = (int) $this->config::get('time_windows.post_reservation_days', 0);

        // Sort reservations by start date to detect overlaps
        usort($reservations, function($a, $b) {
            return strcmp($a['reservation_start_date'], $b['reservation_start_date']);
        });
        
        // Calculate effective buffers for each reservation (to prevent overlaps)
        $reservationCount = count($reservations);
        $effectiveBuffers = [];
        
        for ($i = 0; $i < $reservationCount; $i++) {
            $reservation = $reservations[$i];
            $effectivePreDays = $preReservationDays;
            $effectivePostDays = $postReservationDays;
            
            // Check if we need to reduce buffers due to adjacent reservations
            if ($i > 0) {
                // Check gap with previous reservation
                $prevReservation = $reservations[$i - 1];
                $prevEndDate = new \DateTime($prevReservation['reservation_end_date']);
                $thisStartDate = new \DateTime($reservation['reservation_start_date']);
                $gapDays = (int) $prevEndDate->diff($thisStartDate)->days;
                
                // If previous end is after this start, gap is 0
                if ($prevEndDate >= $thisStartDate) {
                    $gapDays = 0;
                }
                
                // Available buffer space = gap days
                // Previous reservation's post-buffer gets priority (already committed)
                $prevPostDays = $effectiveBuffers[$i - 1]['post'] ?? $postReservationDays;
                $remainingGap = max(0, $gapDays - $prevPostDays);
                
                // This reservation's pre-buffer is limited to remaining gap
                $effectivePreDays = min($preReservationDays, $remainingGap);
            }
            
            if ($i < $reservationCount - 1) {
                // Check gap with next reservation
                $nextReservation = $reservations[$i + 1];
                $thisEndDate = new \DateTime($reservation['reservation_end_date']);
                $nextStartDate = new \DateTime($nextReservation['reservation_start_date']);
                $gapDays = (int) $thisEndDate->diff($nextStartDate)->days;
                
                // If this end is after next start, gap is 0
                if ($thisEndDate >= $nextStartDate) {
                    $gapDays = 0;
                }
                
                // This reservation's post-buffer is limited by gap and next's pre-buffer need
                // Give this reservation as much post-buffer as possible, remainder goes to next's pre
                $effectivePostDays = min($postReservationDays, $gapDays);
            }
            
            $effectiveBuffers[$i] = [
                'pre' => $effectivePreDays,
                'post' => $effectivePostDays
            ];
        }

        // Add reservations with adjusted buffers
        // Using Airbnb-friendly VALUE=DATE format (all-day events)
        for ($i = 0; $i < $reservationCount; $i++) {
            $reservation = $reservations[$i];
            $effectivePre = $effectiveBuffers[$i]['pre'];
            $effectivePost = $effectiveBuffers[$i]['post'];
            
            $lines[] = 'BEGIN:VEVENT';
            $lines[] = 'UID:' . $reservation['reservation_guid'];
            $lines[] = 'DTSTAMP:' . $dtstamp;
            // Use plain "Blocked" for maximum Airbnb compatibility
            $lines[] = 'SUMMARY:Blocked';
            
            // Put reservation details in DESCRIPTION (visible in Google/Outlook/Apple Calendar)
            $description = $reservation['reservation_name'];
            if ($reservation['reservation_description']) {
                $description .= ' - ' . $reservation['reservation_description'];
            }
            $lines[] = 'DESCRIPTION:' . $this->escapeICalText($description);
            
            // Calculate start date with effective pre-reservation buffer
            $startDateObj = new \DateTime($reservation['reservation_start_date']);
            if ($effectivePre > 0) {
                $startDateObj->modify('-' . $effectivePre . ' days');
            }
            // Airbnb prefers VALUE=DATE format (all-day events, no times)
            $lines[] = 'DTSTART;VALUE=DATE:' . $this->formatICalDate($startDateObj->format('Y-m-d'));
            
            // Calculate end date with effective post-reservation buffer
            // For VALUE=DATE, DTEND is exclusive (first day NOT blocked)
            // So we need the day AFTER the last blocked day (checkout day)
            $endDateObj = new \DateTime($reservation['reservation_end_date']);
            if ($effectivePost > 0) {
                $endDateObj->modify('+' . $effectivePost . ' days');
            }
            // Add 1 day for exclusive DTEND semantics
            $endDateObj->modify('+1 day');
            $lines[] = 'DTEND;VALUE=DATE:' . $this->formatICalDate($endDateObj->format('Y-m-d'));
            // Note: TRANSP and STATUS removed - Airbnb ignores these fields
            $lines[] = 'END:VEVENT';
        }

        // Add maintenance events using Airbnb-friendly VALUE=DATE format
        foreach ($maintenance as $maint) {
            $lines[] = 'BEGIN:VEVENT';
            
            // Generate a consistent GUID for this maintenance event
            // Use md5 hash of maintenance ID to create a stable, reservation-like UID
            $maintenanceGuid = md5('maintenance-' . $maint['property_maintenance_id']);
            $lines[] = 'UID:' . $maintenanceGuid;
            $lines[] = 'DTSTAMP:' . $dtstamp;
            
            // Use plain "Blocked" for maximum Airbnb compatibility
            $lines[] = 'SUMMARY:Blocked';
            
            // Put maintenance details in DESCRIPTION (visible in Google/Outlook/Apple Calendar)
            $description = $maint['maintenance_description'];
            if (!empty($maint['maintenance_type'])) {
                $description .= ' (' . $maint['maintenance_type'] . ')';
            }
            $lines[] = 'DESCRIPTION:' . $this->escapeICalText($description);
            
            // Airbnb prefers VALUE=DATE format (all-day events, no times)
            $lines[] = 'DTSTART;VALUE=DATE:' . $this->formatICalDate($maint['maintenance_start_date']);
            
            // For VALUE=DATE, DTEND is exclusive (first day NOT blocked)
            // So we need the day AFTER the last maintenance day
            $endDateObj = new \DateTime($maint['maintenance_end_date']);
            $endDateObj->modify('+1 day');
            $lines[] = 'DTEND;VALUE=DATE:' . $this->formatICalDate($endDateObj->format('Y-m-d'));
            // Note: TRANSP and STATUS removed - Airbnb ignores these fields
            $lines[] = 'END:VEVENT';
        }

        $lines[] = 'END:VCALENDAR';
        
        return implode("\r\n", $lines);
    }

    private function escapeICalText(string $text): string
    {
        $text = str_replace('\\', '\\\\', $text);
        $text = str_replace(',', '\\,', $text);
        $text = str_replace(';', '\\;', $text);
        $text = str_replace("\n", '\\n', $text);
        return $text;
    }

    private function formatICalDateTime(string $date, string $time, string $timezone = 'UTC'): string
    {
        // Create datetime in property's timezone
        $tz = new \DateTimeZone($timezone);
        $dateTime = new \DateTime($date . ' ' . $time, $tz);
        
        // Convert to UTC for iCal format (YYYYMMDDTHHMMSSZ)
        $dateTime->setTimezone(new \DateTimeZone('UTC'));
        return $dateTime->format('Ymd\THis\Z');
    }

    private function formatICalDate(string $date): string
    {
        // Format: YYYYMMDD
        return str_replace('-', '', $date);
    }
}

