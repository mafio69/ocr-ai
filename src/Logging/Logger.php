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
    private const DEFAULT_MAX_SIZE_BYTES = 5 * 1024 * 1024; // 5 MB
    private const TAIL_CHUNK_SIZE        = 4096;

    // Audit #19: assigned once in the constructor, never mutated afterwards.
    private readonly string $logFile;
    private readonly bool $enabled;
    private readonly int $maxSizeBytes;

    /**
     * @param int $maxSizeBytes Rotate the log file once it reaches this size (audit #14).
     *                          A single backup generation ({logFile}.1) is kept.
     */
    public function __construct(string $logFile, bool $enabled = true, int $maxSizeBytes = self::DEFAULT_MAX_SIZE_BYTES)
    {
        $this->logFile      = $logFile;
        $this->enabled      = $enabled;
        $this->maxSizeBytes = $maxSizeBytes;

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

        $this->rotateIfNeeded();

        $timestamp   = date('Y-m-d H:i:s');
        $contextJson = !empty($context) ? json_encode($context, JSON_UNESCAPED_UNICODE) : '';

        $logLine = "[{$timestamp}] [{$level}] {$message}";
        if ($contextJson) {
            $logLine .= " | {$contextJson}";
        }
        $logLine .= "\n";

        file_put_contents($this->logFile, $logLine, FILE_APPEND);
    }

    /**
     * Audit #14: the log file used to grow indefinitely. Once it reaches maxSizeBytes,
     * the current file is moved to "{logFile}.1" (overwriting any previous backup) and a
     * fresh file is started. Simplest useful rotation - a single backup generation, not a
     * numbered history, since nothing in this project reads old rotated logs.
     */
    private function rotateIfNeeded(): void
    {
        if (!file_exists($this->logFile)) {
            return;
        }

        clearstatcache(true, $this->logFile);
        if (filesize($this->logFile) < $this->maxSizeBytes) {
            return;
        }

        $backupFile = $this->logFile . '.1';
        @unlink($backupFile);
        rename($this->logFile, $backupFile);
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

        return $this->tail($this->logFile, $lines);
    }

    /**
     * Audit #15: getLogs() used to load the entire file into memory via file(), which is
     * wasteful for large logs. This reads backward from the end of the file in fixed-size
     * chunks, stopping as soon as enough newlines have been seen - memory use stays
     * bounded by chunk size + the requested number of lines, not by total file size.
     *
     * @return string[] Lines in original (oldest-first) order, each including its trailing
     *                   "\n" (except possibly the last line of the file) - same shape as
     *                   the previous file()-based implementation.
     */
    private function tail(string $filePath, int $maxLines): array
    {
        if ($maxLines <= 0) {
            return [];
        }

        $handle = fopen($filePath, 'rb');
        if ($handle === false) {
            return [];
        }

        try {
            $buffer = '';
            // clearstatcache jest tu konieczny: writeLine()/rotateIfNeeded() wololy juz
            // filesize() na tej samej sciezce PRZED ostatnim dopisaniem (file_put_contents),
            // wiec bez wyczyszczenia cache PHP potrafi zwrocic rozmiar sprzed ostatniego
            // zapisu i tail() gubi ostatnia linie (zaobserwowane jako flaky testy w CI).
            clearstatcache(true, $filePath);
            $pos = filesize($filePath);

            while ($pos > 0 && substr_count($buffer, "\n") <= $maxLines) {
                $readSize = min(self::TAIL_CHUNK_SIZE, $pos);
                $pos -= $readSize;
                fseek($handle, $pos);
                $buffer = fread($handle, $readSize) . $buffer;
            }
        } finally {
            fclose($handle);
        }

        $lines = preg_split('/(?<=\n)/', $buffer);
        $lines = array_values(array_filter($lines, static fn (string $line): bool => $line !== ''));

        return array_slice($lines, -$maxLines);
    }
}
