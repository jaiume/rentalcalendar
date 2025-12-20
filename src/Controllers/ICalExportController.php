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
        for ($i = 0; $i < $reservationCount; $i++) {
            $reservation = $reservations[$i];
            $effectivePre = $effectiveBuffers[$i]['pre'];
            $effectivePost = $effectiveBuffers[$i]['post'];
            
            $lines[] = 'BEGIN:VEVENT';
            $lines[] = 'UID:' . $reservation['reservation_guid'];
            $lines[] = 'SUMMARY:' . $this->escapeICalText($reservation['reservation_name']);
            
            if ($reservation['reservation_description']) {
                $lines[] = 'DESCRIPTION:' . $this->escapeICalText($reservation['reservation_description']);
            }
            
            // Calculate start datetime with effective pre-reservation buffer
            $startDateObj = new \DateTime($reservation['reservation_start_date'], new \DateTimeZone($property['timezone']));
            if ($effectivePre > 0) {
                $startDateObj->modify('-' . $effectivePre . ' days');
            }
            $adjustedStartDate = $startDateObj->format('Y-m-d');
            
            $startTime = $reservation['reservation_start_time'] === 'early' ? $earlyStart : $standardStart;
            $startDateTime = $this->formatICalDateTime($adjustedStartDate, $startTime, $property['timezone']);
            $lines[] = 'DTSTART:' . $startDateTime;
            
            // Calculate end datetime with effective post-reservation buffer
            // For date-time format, DTEND is the exact checkout moment (no +1 needed like VALUE=DATE)
            $endDateObj = new \DateTime($reservation['reservation_end_date'], new \DateTimeZone($property['timezone']));
            if ($effectivePost > 0) {
                $endDateObj->modify('+' . $effectivePost . ' days');
            }
            $adjustedEndDate = $endDateObj->format('Y-m-d');
            
            if ($reservation['reservation_end_time'] === 'standard') {
                // Standard end is 12:00 PM (checkout time) on the adjusted end date
                $endDateTime = $this->formatICalDateTime($adjustedEndDate, $standardEnd, $property['timezone']);
            } else {
                // Late end is 10:00 PM on the adjusted end date
                $endDateTime = $this->formatICalDateTime($adjustedEndDate, $lateEnd, $property['timezone']);
            }
            $lines[] = 'DTEND:' . $endDateTime;
            
            $lines[] = 'STATUS:CONFIRMED';
            $lines[] = 'END:VEVENT';
        }

        // Add maintenance
        foreach ($maintenance as $maint) {
            $lines[] = 'BEGIN:VEVENT';
            
            // Generate a consistent GUID for this maintenance event (looks like a real reservation)
            // Use md5 hash of maintenance ID to create a stable, reservation-like UID
            $maintenanceGuid = md5('maintenance-' . $maint['property_maintenance_id']);
            $lines[] = 'UID:' . $maintenanceGuid;
            
            $lines[] = 'SUMMARY:' . $this->escapeICalText($maint['maintenance_description']);
            
            if (!empty($maint['maintenance_type'])) {
                $lines[] = 'DESCRIPTION:' . $this->escapeICalText($maint['maintenance_type']);
            }
            
            // AirBNB ignores VALUE=DATE all-day events. Use date-time format instead.
            // Format maintenance like reservations: start at check-in time, end at checkout time
            // This makes them look identical to actual bookings to AirBNB
            $startDateTime = $this->formatICalDateTime($maint['maintenance_start_date'], $standardStart, $property['timezone']);
            
            // End at checkout time on the maintenance end date
            // (Property is available for check-in same day at 3pm, just like after a reservation checkout)
            $endDateTime = $this->formatICalDateTime($maint['maintenance_end_date'], $standardEnd, $property['timezone']);
            
            $lines[] = 'DTSTART:' . $startDateTime;
            $lines[] = 'DTEND:' . $endDateTime;
            $lines[] = 'STATUS:CONFIRMED';
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

