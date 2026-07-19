<?php

namespace OvhOcr\Tests;

use OvhOcr\Logging\Logger;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class LoggerTest extends TestCase
{
    private string $logFile;

    protected function setUp(): void
    {
        $this->logFile = sys_get_temp_dir() . '/ocr_test_' . uniqid() . '.log';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->logFile)) {
            unlink($this->logFile);
        }
    }

    public function testCreatesLogFile(): void
    {
        $logger = new Logger($this->logFile, true);
        $logger->info('test');
        $this->assertFileExists($this->logFile);
    }

    public function testLogsInfoMessage(): void
    {
        $logger = new Logger($this->logFile, true);
        $logger->info('to jest info');
        $content = file_get_contents($this->logFile);
        $this->assertStringContainsString('[INFO]', $content);
        $this->assertStringContainsString('to jest info', $content);
    }

    public function testLogsWithContext(): void
    {
        $logger = new Logger($this->logFile, true);
        $logger->error('coś', ['key' => 'value']);
        $content = file_get_contents($this->logFile);
        $this->assertStringContainsString('"key":"value"', $content);
    }

    public function testDisabledLoggerDoesNotWrite(): void
    {
        $logger = new Logger($this->logFile, false);
        $logger->info('nie powinno być');
        $this->assertFileDoesNotExist($this->logFile);
    }

    public function testCreatesDirectoryIfMissing(): void
    {
        $nestedFile = sys_get_temp_dir() . '/ocr_test_' . uniqid() . '/deep/log.log';
        $logger     = new Logger($nestedFile, true);
        $logger->info('test');
        $this->assertFileExists($nestedFile);
        // Cleanup
        unlink($nestedFile);
        rmdir(dirname($nestedFile));
        rmdir(dirname(dirname($nestedFile)));
    }

    // --- Audit #13: PSR-3 (Psr\Log\LoggerInterface) ---

    public function testImplementsPsr3LoggerInterface(): void
    {
        $logger = new Logger($this->logFile, true);
        $this->assertInstanceOf(LoggerInterface::class, $logger);
    }

    public function testEmergencyWritesEmergencyLevel(): void
    {
        $logger = new Logger($this->logFile, true);
        $logger->emergency('krytycznie');
        $content = file_get_contents($this->logFile);
        $this->assertStringContainsString('[EMERGENCY]', $content);
    }

    public function testAlertWritesAlertLevel(): void
    {
        $logger = new Logger($this->logFile, true);
        $logger->alert('uwaga');
        $content = file_get_contents($this->logFile);
        $this->assertStringContainsString('[ALERT]', $content);
    }

    public function testCriticalWritesCriticalLevel(): void
    {
        $logger = new Logger($this->logFile, true);
        $logger->critical('powaznie');
        $content = file_get_contents($this->logFile);
        $this->assertStringContainsString('[CRITICAL]', $content);
    }

    public function testNoticeWritesNoticeLevel(): void
    {
        $logger = new Logger($this->logFile, true);
        $logger->notice('info dodatkowe');
        $content = file_get_contents($this->logFile);
        $this->assertStringContainsString('[NOTICE]', $content);
    }

    public function testGenericLogMethodUsesGivenLevel(): void
    {
        $logger = new Logger($this->logFile, true);
        $logger->log(\Psr\Log\LogLevel::WARNING, 'przez generyczny log()');
        $content = file_get_contents($this->logFile);
        $this->assertStringContainsString('[WARNING]', $content);
        $this->assertStringContainsString('przez generyczny log()', $content);
    }

    public function testSuccessStillWorksAsCustomLevel(): void
    {
        // "success" is not part of PSR-3, but stays as a custom extension -
        // used e.g. in OcrClient::extractText() after a successful model attempt.
        $logger = new Logger($this->logFile, true);
        $logger->success('zadzialalo');
        $content = file_get_contents($this->logFile);
        $this->assertStringContainsString('[SUCCESS]', $content);
    }

    // --- Audit #14: log rotation ---

    public function testRotatesLogFileWhenMaxSizeExceeded(): void
    {
        $logger = new Logger($this->logFile, true, maxSizeBytes: 100);

        // Each line is a few dozen bytes - a handful of writes will exceed the 100 byte cap.
        for ($i = 0; $i < 10; $i++) {
            $logger->info("linia numer {$i}");
        }

        $backupFile = $this->logFile . '.1';
        $this->assertFileExists($backupFile, 'Rotation should create a .1 backup once the size cap is exceeded');
        $this->assertFileExists($this->logFile, 'A fresh log file should exist after rotation');

        unlink($backupFile);
    }

    public function testDoesNotRotateBelowMaxSize(): void
    {
        $logger = new Logger($this->logFile, true, maxSizeBytes: 1024 * 1024);
        $logger->info('krotka linia');

        $this->assertFileDoesNotExist($this->logFile . '.1');
    }

    // --- Audit #15: getLogs() tail without loading the whole file ---

    public function testGetLogsReturnsLastNLinesInOrder(): void
    {
        $logger = new Logger($this->logFile, true);

        for ($i = 1; $i <= 20; $i++) {
            $logger->info("linia {$i}");
        }

        $logs = $logger->getLogs(5);

        $this->assertCount(5, $logs);
        $this->assertStringContainsString('linia 16', $logs[0]);
        $this->assertStringContainsString('linia 20', $logs[4]);
    }

    public function testGetLogsWorksAcrossMultipleReadChunks(): void
    {
        // TAIL_CHUNK_SIZE is 4096 bytes internally - write enough lines to force the
        // tail() implementation to read back across more than one chunk.
        $logger = new Logger($this->logFile, true);

        for ($i = 1; $i <= 500; $i++) {
            $logger->info("linia numer {$i} z dodatkowa tresc zeby linia byla dluzsza");
        }

        $logs = $logger->getLogs(3);

        $this->assertCount(3, $logs);
        $this->assertStringContainsString('linia numer 498', $logs[0]);
        $this->assertStringContainsString('linia numer 500', $logs[2]);
    }

    public function testGetLogsReturnsAllLinesWhenFewerThanRequested(): void
    {
        $logger = new Logger($this->logFile, true);
        $logger->info('jedyna linia');

        $logs = $logger->getLogs(50);

        $this->assertCount(1, $logs);
    }
}
