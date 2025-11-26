<?php

namespace App\Services;

class ConfigService
{
    private static ?array $config = null;

    public static function get(string $key, mixed $default = null): mixed
    {
        if (self::$config === null) {
            self::$config = parse_ini_file(BASE_DIR . '/config/config.ini', true, INI_SCANNER_TYPED);
        }

        if (strpos($key, '.') !== false) {
            [$section, $name] = explode('.', $key, 2);

            if (
                isset(self::$config[$section][$name]) &&
                is_string(self::$config[$section][$name]) &&
                (self::$config[$section][$name] === 'true' || self::$config[$section][$name] === 'false')
            ) {
                return self::$config[$section][$name] === 'true';
            }

            return self::$config[$section][$name] ?? $default;
        }

        foreach (self::$config as $section) {
            if (isset($section[$key])) {
                return $section[$key];
            }
        }

        return $default;
    }

    /**
     * Get all sync partner bar colors from config
     * Returns an associative array: ['PartnerName' => 'color', ...]
     */
    public static function getSyncPartnerBarColors(): array
    {
        if (self::$config === null) {
            self::$config = parse_ini_file(BASE_DIR . '/config/config.ini', true, INI_SCANNER_TYPED);
        }

        $barColors = [];
        foreach (self::$config as $sectionName => $sectionData) {
            // Skip standard sections that aren't sync partners
            $skipSections = ['app', 'database', 'mail', 'auth', 'colors', 'time_windows'];
            if (in_array($sectionName, $skipSections, true)) {
                continue;
            }

            // If this section has a bar_color, add it to the map
            if (isset($sectionData['bar_color']) && is_string($sectionData['bar_color'])) {
                $barColors[$sectionName] = $sectionData['bar_color'];
            }
        }

        return $barColors;
    }
}



