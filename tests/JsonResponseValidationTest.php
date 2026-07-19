<?php

namespace OvhOcr\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use OvhOcr\Exceptions\OcrException;
use OvhOcr\i18n\LocaleLoader;
use OvhOcr\i18n\Translator;
use OvhOcr\Logging\Logger;
use OvhOcr\OcrClient;
use PHPUnit\Framework\TestCase;

/**
 * Audit #21: json_decode() errors weren't checked explicitly - malformed JSON silently
 * fell through to the generic "unexpected response structure" exception with no indication
 * that the body wasn't valid JSON at all.
 */
class JsonResponseValidationTest extends TestCase
{
    private string $tempDir;
    private Logger $logger;
    private Translator $translator;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/ocr_json_validation_test_' . uniqid();
        mkdir($this->tempDir, 0755, true);

        $this->logger     = new Logger($this->tempDir . '/test.log', true);
        $this->translator = new Translator('pl', 'en');
        $loader           = new LocaleLoader(__DIR__ . '/../resources/locales');
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

    private function createTestImage(): string
    {
        $imagePath = $this->tempDir . '/test.jpg';
        $image     = imagecreatetruecolor(10, 10);
        imagejpeg($image, $imagePath);

        return $imagePath;
    }

    public function testMalformedJsonFromOvhThrowsWithJsonErrorMessage(): void
    {
        $mock = new MockHandler([
            new Response(200, [], '{not valid json!!'),
        ]);
        $customClient = new Client(['handler' => HandlerStack::create($mock)]);

        $client = new OcrClient(
            apiKey: 'test-key',
            logger: $this->logger,
            translator: $this->translator,
            modelMap: ['lite' => 'TestModel'],
            modelPriority: ['lite'],
            httpClient: $customClient,
        );

        try {
            $client->extractText($this->createTestImage());
            $this->fail('Expected OcrException was not thrown');
        } catch (OcrException $e) {
            $this->assertStringContainsString('All models failed', $e->getMessage());
        }

        // The specific json_last_error_msg() diagnostic is logged per attempt inside
        // extractText()'s fallback loop, even though the final thrown exception is generic.
        $logContent = file_get_contents($this->tempDir . '/test.log');
        $this->assertStringContainsString('Invalid JSON in API response', $logContent);
    }

    public function testMalformedJsonFromGoogleVisionThrowsWithJsonErrorMessage(): void
    {
        $mock = new MockHandler([
            new Response(200, [], 'not even close to json'),
        ]);
        $customClient = new Client(['handler' => HandlerStack::create($mock)]);

        $client = new OcrClient(
            apiKey: 'test-key',
            logger: $this->logger,
            translator: $this->translator,
            modelMap: [],
            modelPriority: ['google_vision'],
            googleEnabled: true,
            googleCredentialsPath: '/tmp/test-credentials.json',
            httpClient: $customClient,
            googleTokenProvider: fn () => 'test-token',
        );

        try {
            $client->extractText($this->createTestImage());
            $this->fail('Expected OcrException was not thrown');
        } catch (OcrException $e) {
            $this->assertStringContainsString('All models failed', $e->getMessage());
        }

        $logContent = file_get_contents($this->tempDir . '/test.log');
        $this->assertStringContainsString('Invalid JSON in API response', $logContent);
    }
}
