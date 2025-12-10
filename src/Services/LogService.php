<?php

namespace App\Services;

class LogService
{
    private static ?bool $debugMode = null;
    private static ?string $logFile = null;

    private static function isDebugMode(): bool
    {
        if (self::$debugMode === null) {
            self::$debugMode = (bool) ConfigService::get('app.debug', false);
        }
        return self::$debugMode;
    }

    private static function getLogFile(): string
    {
        if (self::$logFile === null) {
            $logDir = BASE_DIR . '/logs';
            if (!is_dir($logDir)) {
                @mkdir($logDir, 0755, true);
            }
            self::$logFile = $logDir . '/app.log';
        }
        return self::$logFile;
    }

    /**
     * Log a debug message (only when debug mode is enabled)
     */
    public static function debug(string $message, array $context = []): void
    {
        if (!self::isDebugMode()) {
            return;
        }
        self::log('DEBUG', $message, $context);
    }

    /**
     * Log an info message
     */
    public static function info(string $message, array $context = []): void
    {
        self::log('INFO', $message, $context);
    }

    /**
     * Log a warning message
     */
    public static function warning(string $message, array $context = []): void
    {
        self::log('WARNING', $message, $context);
    }

    /**
     * Log an error message
     */
    public static function error(string $message, array $context = []): void
    {
        self::log('ERROR', $message, $context);
    }

    /**
     * Internal log method
     */
    private static function log(string $level, string $message, array $context = []): void
    {
        $timestamp = date('Y-m-d H:i:s');
        $contextStr = !empty($context) ? ' ' . json_encode($context) : '';
        $logMessage = sprintf("[%s] [%s] %s%s\n", $timestamp, $level, $message, $contextStr);
        
        // Write to file
        @file_put_contents(self::getLogFile(), $logMessage, FILE_APPEND | LOCK_EX);
        
        // Also write to error_log if it's an error or warning
        if (in_array($level, ['ERROR', 'WARNING'], true)) {
            error_log($logMessage);
        }
    }

    /**
     * Log an exception with full stack trace
     */
    public static function exception(\Throwable $e, string $context = ''): void
    {
        $message = sprintf(
            "%s: %s in %s:%d\nStack trace:\n%s",
            get_class($e),
            $e->getMessage(),
            $e->getFile(),
            $e->getLine(),
            $e->getTraceAsString()
        );
        
        if ($context) {
            $message = "$context - $message";
        }
        
        self::error($message);
    }

    /**
     * Get the last N lines from the log file
     */
    public static function getRecentLogs(int $lines = 100): array
    {
        $logFile = self::getLogFile();
        if (!file_exists($logFile)) {
            return [];
        }

        $file = new \SplFileObject($logFile);
        $file->seek(PHP_INT_MAX);
        $lastLine = $file->key();
        $startLine = max(0, $lastLine - $lines);
        
        $logs = [];
        $file->seek($startLine);
        while (!$file->eof()) {
            $line = trim($file->fgets());
            if ($line) {
                $logs[] = $line;
            }
        }
        
        return $logs;
    }
}


