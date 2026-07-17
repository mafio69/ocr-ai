<?php

namespace OvhOcr\Tests;

use PHPUnit\Framework\TestCase;
use OvhOcr\OcrClient;
use OvhOcr\Logging\Logger;
use OvhOcr\i18n\Translator;
use OvhOcr\i18n\LocaleLoader;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Middleware;

/**
 * Audit #12: temperature/max_tokens used to be hardcoded in tryOvhModel().
 * These tests check that the actual payload sent to OVH contains the configured values.
 */
class ModelConfigurationTest extends TestCase
{
    private string $tempDir;
    private Logger $logger;
    private Translator $translator;
    private array $requestHistory = [];

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/ocr_model_config_test_' . uniqid();
        mkdir($this->tempDir, 0755, true);

        $this->logger = new Logger($this->tempDir . '/test.log', true);
        $this->translator = new Translator('pl', 'en');
        $loader = new LocaleLoader(__DIR__ . '/../resources/locales');
        $loader->loadAll($this->translator);
        $this->requestHistory = [];
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

    private function makeClientWithMock(array $extraArgs = []): OcrClient
    {
        $mock = new MockHandler([
            new Response(200, [], json_encode([
                'choices' => [['message' => ['content' => 'Test']]],
            ])),
        ]);

        $handlerStack = HandlerStack::create($mock);
        $handlerStack->push(Middleware::history($this->requestHistory));
        $customClient = new Client(['handler' => $handlerStack]);

        // Argument unpacking (...) cannot be combined with explicit named arguments
        // in the same call - so we build one named-args array and unpack it as a whole.
        $args = array_merge([
            'apiKey' => 'test-key',
            'logger' => $this->logger,
            'translator' => $this->translator,
            'modelMap' => ['lite' => 'TestModel'],
            'modelPriority' => ['lite'],
            'httpClient' => $customClient,
        ], $extraArgs);

        return new OcrClient(...$args);
    }

    private function createTestImage(): string
    {
        $imagePath = $this->tempDir . '/test.jpg';
        $image = imagecreatetruecolor(10, 10);
        imagejpeg($image, $imagePath);
        return $imagePath;
    }

    public function testDefaultTemperatureAndMaxTokensAreSentInPayload(): void
    {
        $client = $this->makeClientWithMock();
        $client->extractText($this->createTestImage());

        $body = json_decode((string) $this->requestHistory[0]['request']->getBody(), true);

        $this->assertSame(0.1, $body['temperature']);
        $this->assertSame(8192, $body['max_tokens']);
    }

    public function testCustomTemperatureAndMaxTokensAreSentInPayload(): void
    {
        $client = $this->makeClientWithMock(['temperature' => 0.7, 'maxTokens' => 2048]);
        $client->extractText($this->createTestImage());

        $body = json_decode((string) $this->requestHistory[0]['request']->getBody(), true);

        $this->assertSame(0.7, $body['temperature']);
        $this->assertSame(2048, $body['max_tokens']);
    }

    public function testThrowsForTemperatureBelowZero(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Temperature must be between 0.0 and 2.0');

        $this->makeClientWithMock(['temperature' => -0.1]);
    }

    public function testThrowsForTemperatureAboveTwo(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Temperature must be between 0.0 and 2.0');

        $this->makeClientWithMock(['temperature' => 2.1]);
    }

    public function testThrowsForNonPositiveMaxTokens(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('maxTokens must be a positive integer');

        $this->makeClientWithMock(['maxTokens' => 0]);
    }
}
