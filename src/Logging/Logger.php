<?php

namespace OvhOcr\Logging;

class Logger
{
    private string $logFile;
    private bool $enabled;

    public function __construct(string $logFile, bool $enabled = true)
    {
        $this->logFile = $logFile;
        $this->enabled = $enabled;
        
        $this->ensureLogDirectory();
    }

    public function error(string $message, array $context = []): void
    {
        $this->log('ERROR', $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->log('WARNING', $message, $context);
    }

    public function info(string $message, array $context = []): void
    {
        $this->log('INFO', $message, $context);
    }

    public function success(string $message, array $context = []): void
    {
        $this->log('SUCCESS', $message, $context);
    }

    public function debug(string $message, array $context = []): void
    {
        $this->log('DEBUG', $message, $context);
    }

    private function log(string $level, string $message, array $context = []): void
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
