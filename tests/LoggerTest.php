<?php

namespace OvhOcr\Tests;

use PHPUnit\Framework\TestCase;
use OvhOcr\Logging\Logger;
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
        $logger = new Logger($nestedFile, true);
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
}
