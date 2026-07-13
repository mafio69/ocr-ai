<?php

namespace OvhOcr\Tests;

use PHPUnit\Framework\TestCase;
use OvhOcr\Logging\Logger;
use OvhOcr\i18n\Translator;
use OvhOcr\i18n\LocaleLoader;
use OvhOcr\Exceptions\OcrException;

class FileReadErrorTest extends TestCase
{
    private string $tempDir;
    private Logger $logger;
    private Translator $translator;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/ocr_read_test_' . uniqid();
        mkdir($this->tempDir, 0755, true);
        
        $this->logger = new Logger($this->tempDir . '/test.log', true);
        $this->translator = new Translator('pl', 'en');
        $loader = new LocaleLoader(__DIR__ . '/../resources/locales');
        $loader->loadAll($this->translator);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }

    public function testFileReadErrorTranslationExists(): void
    {
        $result = $this->translator->trans('errors.file_read_error');
        $this->assertNotSame('errors.file_read_error', $result);
        $this->assertStringContainsString('pliku', $result);
    }

    public function testFileReadErrorTranslationEnglish(): void
    {
        $this->translator->setLocale('en');
        $result = $this->translator->trans('errors.file_read_error');
        $this->assertNotSame('errors.file_read_error', $result);
        $this->assertStringContainsString('file', $result);
    }

    public function testOcrExceptionWithFileReadError(): void
    {
        OcrException::setTranslator($this->translator);
        
        $exception = new OcrException(
            message: 'Failed to read file: /path/to/file.jpg',
            userMessageKey: 'errors.file_read_error',
            context: ['file' => '/path/to/file.jpg'],
            code: 500
        );
        
        $this->assertSame('errors.file_read_error', $exception->getUserMessageKey());
        $this->assertSame(500, $exception->getCode());
        $this->assertArrayHasKey('file', $exception->getContext());
        
        $userMessage = $exception->getUserMessage();
        $this->assertStringContainsString('pliku', $userMessage);
    }
}
