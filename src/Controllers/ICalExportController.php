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

        // Add reservations
        foreach ($reservations as $reservation) {
            $lines[] = 'BEGIN:VEVENT';
            $lines[] = 'UID:' . $reservation['reservation_guid'];
            $lines[] = 'SUMMARY:' . $this->escapeICalText($reservation['reservation_name']);
            
            if ($reservation['reservation_description']) {
                $lines[] = 'DESCRIPTION:' . $this->escapeICalText($reservation['reservation_description']);
            }
            
            // Calculate start datetime with pre-reservation buffer
            // For internal reservations, subtract pre_reservation_days from start date
            $startDateObj = new \DateTime($reservation['reservation_start_date'], new \DateTimeZone($property['timezone']));
            if ($preReservationDays > 0) {
                $startDateObj->modify('-' . $preReservationDays . ' days');
            }
            $adjustedStartDate = $startDateObj->format('Y-m-d');
            
            $startTime = $reservation['reservation_start_time'] === 'early' ? $earlyStart : $standardStart;
            $startDateTime = $this->formatICalDateTime($adjustedStartDate, $startTime, $property['timezone']);
            $lines[] = 'DTSTART:' . $startDateTime;
            
            // Calculate end datetime with post-reservation buffer
            // Add post_reservation_days + 1 day to end date for AirBNB compatibility (DTEND is exclusive)
            $endDateObj = new \DateTime($reservation['reservation_end_date'], new \DateTimeZone($property['timezone']));
            $endDateObj->modify('+' . ($postReservationDays + 1) . ' days');
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
            $lines[] = 'UID:maintenance-' . $maint['property_maintenance_id'];
            $lines[] = 'SUMMARY:' . $this->escapeICalText($maint['maintenance_description']);
            
            if (!empty($maint['maintenance_type'])) {
                $lines[] = 'DESCRIPTION:' . $this->escapeICalText($maint['maintenance_type']);
            }
            
            // Add properties to signal this blocks availability
            $lines[] = 'TRANSP:OPAQUE';  // Mark as busy/unavailable (blocks time)
            $lines[] = 'CLASS:PUBLIC';   // Event is public
            $lines[] = 'X-MICROSOFT-CDO-BUSYSTATUS:OOF';  // Out of facility (Microsoft extension)
            
            // Maintenance is all-day events
            $startDate = $this->formatICalDate($maint['maintenance_start_date']);
            $endDate = $this->formatICalDate($maint['maintenance_end_date']);
            
            // For all-day events, DTEND is exclusive (next day)
            $endDateObj = new \DateTime($maint['maintenance_end_date']);
            $endDateObj->modify('+1 day');
            $endDate = $this->formatICalDate($endDateObj->format('Y-m-d'));
            
            $lines[] = 'DTSTART;VALUE=DATE:' . $startDate;
            $lines[] = 'DTEND;VALUE=DATE:' . $endDate;
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

