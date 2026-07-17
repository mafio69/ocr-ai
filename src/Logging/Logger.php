<?php

namespace OvhOcr\Logging;

use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Stringable;

/**
 * Simple file-based logger. Implements Psr\Log\LoggerInterface so it can be
 * used anywhere a standard PSR-3 logger is expected (e.g. third-party
 * libraries) - and so it can be swapped for Monolog (or vice versa) in the
 * future without changing calling code.
 */
class Logger implements LoggerInterface
{
    private string $logFile;
    private bool $enabled;

    public function __construct(string $logFile, bool $enabled = true)
    {
        $this->logFile = $logFile;
        $this->enabled = $enabled;

        $this->ensureLogDirectory();
    }

    public function emergency(string|Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::EMERGENCY, $message, $context);
    }

    public function alert(string|Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::ALERT, $message, $context);
    }

    public function critical(string|Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::CRITICAL, $message, $context);
    }

    public function error(string|Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::ERROR, $message, $context);
    }

    public function warning(string|Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::WARNING, $message, $context);
    }

    public function notice(string|Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::NOTICE, $message, $context);
    }

    public function info(string|Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::INFO, $message, $context);
    }

    public function debug(string|Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::DEBUG, $message, $context);
    }

    /**
     * A level outside PSR-3, kept for backward compatibility with existing
     * usage in OcrClient (e.g. "model X succeeded"). Not part of LoggerInterface.
     */
    public function success(string|Stringable $message, array $context = []): void
    {
        $this->writeLine('SUCCESS', (string) $message, $context);
    }

    /**
     * @param mixed $level Psr\Log\LogLevel::* or any level string
     */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->writeLine(strtoupper((string) $level), (string) $message, $context);
    }

    private function writeLine(string $level, string $message, array $context = []): void
    {
        if (!$this->enabled) {
            return;
        }

        $timestamp = date('Y-m-d H:i:s');
        $contextJson = !empty($context) ? json_encode($context, JSON_UNESCAPED_UNICODE) : '';

        $logLine = "[{$timestamp}] [{$level}] {$message}";
        if ($contextJson) {
            $logLine .= " | {$contextJson}";
        }
        $logLine .= "\n";

        file_put_contents($this->logFile, $logLine, FILE_APPEND);
    }

    private function ensureLogDirectory(): void
    {
        $dir = dirname($this->logFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }

    public function getLogs(int $lines = 50): array
    {
        if (!file_exists($this->logFile)) {
            return [];
        }

        $allLines = file($this->logFile);
        return array_slice($allLines, -$lines);
    }
}
