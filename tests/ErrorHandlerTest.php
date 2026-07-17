<?php

namespace OvhOcr\Tests;

use PHPUnit\Framework\TestCase;
use OvhOcr\Error\ErrorHandler;
use OvhOcr\Error\ErrorResponse;
use OvhOcr\Exceptions\OcrException;
use OvhOcr\Logging\Logger;
use OvhOcr\i18n\Translator;
use OvhOcr\i18n\LocaleLoader;

class ErrorHandlerTest extends TestCase
{
    private string $tempDir;
    private Logger $logger;
    private Translator $translator;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/ocr_error_handler_test_' . uniqid();
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

    public function testHandleOcrExceptionTranslatesUserMessage(): void
    {
        $handler = new ErrorHandler($this->logger, $this->translator, false);
        
        $exception = new OcrException(
            message: 'Technical: file not found at /path/to/file.jpg',
            userMessageKey: 'errors.file_not_found',
            context: ['file' => '/path/to/file.jpg'],
            code: 404
        );
        
        $response = $handler->handle($exception);
        
        $this->assertInstanceOf(ErrorResponse::class, $response);
        $this->assertStringContainsString('zdjęcia', $response->getUserMessage());
        $this->assertStringNotContainsString('Technical', $response->getUserMessage());
    }

    public function testHandleOcrExceptionWithPolishLocale(): void
    {
        $this->translator->setLocale('pl');
        $handler = new ErrorHandler($this->logger, $this->translator, false);
        
        $exception = new OcrException(
            message: 'Technical error',
            userMessageKey: 'errors.file_not_found'
        );
        
        $response = $handler->handle($exception);
        
        $this->assertStringContainsString('zdjęcia', $response->getUserMessage());
    }

    public function testHandleOcrExceptionWithEnglishLocale(): void
    {
        $this->translator->setLocale('en');
        $handler = new ErrorHandler($this->logger, $this->translator, false);
        
        $exception = new OcrException(
            message: 'Technical error',
            userMessageKey: 'errors.file_not_found'
        );
        
        $response = $handler->handle($exception);
        
        $this->assertStringContainsString('image', strtolower($response->getUserMessage()));
        $this->assertStringNotContainsString('zdjęcia', $response->getUserMessage());
    }

    public function testHandleGenericExceptionUsesTranslatorInProduction(): void
    {
        $handler = new ErrorHandler($this->logger, $this->translator, false);
        
        $exception = new \RuntimeException('Some internal error');
        
        $response = $handler->handle($exception);
        
        $this->assertInstanceOf(ErrorResponse::class, $response);
        $userMessage = $response->getUserMessage();
        $this->assertStringContainsString('spróbuj', strtolower($userMessage));
        $this->assertStringNotContainsString('Some internal error', $userMessage);
    }

    public function testHandleGenericExceptionShowsMessageInDevelopment(): void
    {
        $handler = new ErrorHandler($this->logger, $this->translator, true);
        
        $exception = new \RuntimeException('Some internal error');
        
        $response = $handler->handle($exception);
        
        $this->assertInstanceOf(ErrorResponse::class, $response);
        $this->assertStringContainsString('Some internal error', $response->getUserMessage());
    }

    public function testHandleOcrExceptionPreservesContext(): void
    {
        $handler = new ErrorHandler($this->logger, $this->translator, false);
        
        $context = ['file' => 'test.png', 'size' => 12345];
        $exception = new OcrException(
            message: 'Technical error',
            userMessageKey: 'errors.file_not_found',
            context: $context
        );
        
        $response = $handler->handle($exception);
        $debugInfo = $response->getDebugInfo();
        
        $this->assertSame($context, $debugInfo['context']);
    }

    public function testHandleOcrExceptionPreservesCode(): void
    {
        $handler = new ErrorHandler($this->logger, $this->translator, false);
        
        $exception = new OcrException(
            message: 'Technical error',
            userMessageKey: 'errors.file_not_found',
            code: 404
        );
        
        $response = $handler->handle($exception);
        
        $this->assertSame('FILE_NOT_FOUND', $response->getDebugInfo()['code']);
    }

    public function testHandleGenericExceptionReturnsInternalErrorCode(): void
    {
        $handler = new ErrorHandler($this->logger, $this->translator, false);
        
        $exception = new \RuntimeException('Some error');
        
        $response = $handler->handle($exception);
        
        $this->assertSame('INTERNAL_ERROR', $response->getDebugInfo()['code']);
    }

    public function testHandleLogsException(): void
    {
        $handler = new ErrorHandler($this->logger, $this->translator, false);
        
        $exception = new OcrException(
            message: 'Technical error message',
            userMessageKey: 'errors.file_not_found'
        );
        
        $handler->handle($exception);
        
        $logContent = file_get_contents($this->tempDir . '/test.log');
        $this->assertStringContainsString('Technical error message', $logContent);
        $this->assertStringContainsString('ERROR', $logContent);
    }

    public function testHandleDevelopmentModeIncludesInternalDetails(): void
    {
        $handler = new ErrorHandler($this->logger, $this->translator, true);
        
        $exception = new OcrException(
            message: 'Internal technical details',
            userMessageKey: 'errors.file_not_found',
            context: ['file' => 'test.jpg']
        );
        
        $response = $handler->handle($exception);
        $json = json_decode($response->toJson(), true);
        
        $this->assertArrayHasKey('internal', $json['error']);
        $this->assertArrayHasKey('context', $json['error']);
        $this->assertStringContainsString('Internal technical details', $json['error']['internal']);
    }

    public function testHandleProductionModeHidesInternalDetails(): void
    {
        $handler = new ErrorHandler($this->logger, $this->translator, false);
        
        $exception = new OcrException(
            message: 'Internal technical details',
            userMessageKey: 'errors.file_not_found',
            context: ['file' => 'test.jpg']
        );
        
        $response = $handler->handle($exception);
        $json = json_decode($response->toJson(), true);
        
        $this->assertArrayNotHasKey('internal', $json['error']);
        $this->assertArrayNotHasKey('context', $json['error']);
    }
}
