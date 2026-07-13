<?php

namespace OvhOcr\Tests;

use PHPUnit\Framework\TestCase;
use OvhOcr\Logging\Logger;

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
}
