<?php

namespace App\Services;

class ICalParser
{
    /**
     * Parse an iCal string into an array of events.
     */
    public function parse(string $content): array
    {
        $events = [];
        
        // Normalize line endings
        $content = str_replace(["\r\n", "\r"], "\n", $content);
        
        // Unfold lines (lines starting with space are continuations)
        $content = preg_replace('/\n[ \t]/', '', $content);
        
        // Split into lines
        $lines = explode("\n", $content);
        
        $currentEvent = null;
        
        foreach ($lines as $line) {
            $line = trim($line);
            
            if ($line === 'BEGIN:VEVENT') {
                $currentEvent = [];
                continue;
            }
            
            if ($line === 'END:VEVENT') {
                if ($currentEvent) {
                    $events[] = $this->processEvent($currentEvent);
                }
                $currentEvent = null;
                continue;
            }
            
            if ($currentEvent !== null && str_contains($line, ':')) {
                [$key, $value] = explode(':', $line, 2);
                
                // Handle parameters in key (e.g., DTSTART;VALUE=DATE)
                $keyParts = explode(';', $key);
                $propertyName = array_shift($keyParts);
                
                $currentEvent[$propertyName] = $value;
                
                // Store extra params if needed
                foreach ($keyParts as $param) {
                    [$pKey, $pValue] = explode('=', $param, 2);
                    $currentEvent[$propertyName . '_PARAMS'][$pKey] = $pValue;
                }
            }
        }
        
        return $events;
    }
    
    private function processEvent(array $rawData): array
    {
        $event = [
            'uid' => $rawData['UID'] ?? null,
            'summary' => $rawData['SUMMARY'] ?? '',
            'description' => $rawData['DESCRIPTION'] ?? '',
            'start' => null,
            'end' => null,
            'is_all_day' => false,
        ];
        
        if (isset($rawData['DTSTART'])) {
            $event['start'] = $rawData['DTSTART'];
            // Check if all day
            if (isset($rawData['DTSTART_PARAMS']['VALUE']) && $rawData['DTSTART_PARAMS']['VALUE'] === 'DATE') {
                $event['is_all_day'] = true;
            }
        }
        
        if (isset($rawData['DTEND'])) {
            $event['end'] = $rawData['DTEND'];
        }
        
        return $event;
    }
}

