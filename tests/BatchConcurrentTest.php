<?php

namespace OvhOcr\Tests;

use PHPUnit\Framework\TestCase;
use OvhOcr\OcrClient;
use OvhOcr\Logging\Logger;
use OvhOcr\i18n\Translator;
use OvhOcr\i18n\LocaleLoader;
use OvhOcr\Response\OcrResponse;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Middleware;

/**
 * Audit #16: extractTextBatchConcurrent() sends the first model-tier attempt for every
 * image concurrently, falling back to the fully sequential extractText() only for images
 * whose first attempt failed.
 */
class BatchConcurrentTest extends TestCase
{
    private string $tempDir;
    private Logger $logger;
    private Translator $translator;
    private array $requestHistory = [];

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/ocr_batch_concurrent_test_' . uniqid();
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

    private function createTestImage(string $filename): string
    {
        $imagePath = $this->tempDir . '/' . $filename;
        $image = imagecreatetruecolor(10, 10);
        imagejpeg($image, $imagePath);
        return $imagePath;
    }

    private function makeClient(array $responses, array $extraArgs = []): OcrClient
    {
        $mock = new MockHandler($responses);
        $handlerStack = HandlerStack::create($mock);
        $handlerStack->push(Middleware::history($this->requestHistory));
        $customClient = new Client(['handler' => $handlerStack]);

        $args = array_merge([
            'apiKey' => 'test-key',
            'logger' => $this->logger,
            'translator' => $this->translator,
            'httpClient' => $customClient,
        ], $extraArgs);

        return new OcrClient(...$args);
    }

    public function testAllImagesSucceedOnFirstAttempt(): void
    {
        $client = $this->makeClient(
            [
                new Response(200, [], json_encode(['choices' => [['message' => ['content' => 'Text A']]]])),
                new Response(200, [], json_encode(['choices' => [['message' => ['content' => 'Text B']]]])),
                new Response(200, [], json_encode(['choices' => [['message' => ['content' => 'Text C']]]])),
            ],
            ['modelMap' => ['lite' => 'TestModel'], 'modelPriority' => ['lite']]
        );

        $paths = [
            $this->createTestImage('a.jpg'),
            $this->createTestImage('b.jpg'),
            $this->createTestImage('c.jpg'),
        ];

        $results = $client->extractTextBatchConcurrent($paths);

        $this->assertCount(3, $results);
        $this->assertCount(3, $this->requestHistory, 'No sequential fallback should have been needed');

        foreach ($results as $result) {
            $this->assertInstanceOf(OcrResponse::class, $result);
        }
    }

    public function testFailedFirstAttemptFallsBackToSequentialModelStrategy(): void
    {
        $client = $this->makeClient(
            [
                // Concurrent first attempt (tier "lite") - malformed, triggers fallback.
                new Response(200, [], json_encode(['unexpected' => 'shape'])),
                // Sequential fallback via extractText(): retries "lite" (fails again)...
                new Response(200, [], json_encode(['unexpected' => 'shape'])),
                // ...then falls through to "medium", which succeeds.
                new Response(200, [], json_encode(['choices' => [['message' => ['content' => 'Recovered text']]]])),
            ],
            ['modelMap' => ['lite' => 'Lite', 'medium' => 'Medium'], 'modelPriority' => ['lite', 'medium']]
        );

        $path = $this->createTestImage('needs-fallback.jpg');

        $results = $client->extractTextBatchConcurrent([$path]);

        $this->assertCount(1, $results);
        $this->assertInstanceOf(OcrResponse::class, $results[$path]);
        $this->assertSame('Recovered text', $results[$path]->getText());
        $this->assertSame('medium', $results[$path]->getUsedModel());
        $this->assertCount(3, $this->requestHistory);
    }

    public function testMissingFileReturnsErrorWithoutAnyRequest(): void
    {
        $client = $this->makeClient([], ['modelMap' => ['lite' => 'TestModel'], 'modelPriority' => ['lite']]);

        $results = $client->extractTextBatchConcurrent(['/does/not/exist.jpg']);

        $this->assertArrayHasKey('/does/not/exist.jpg', $results);
        $this->assertArrayHasKey('error', $results['/does/not/exist.jpg']);
        $this->assertCount(0, $this->requestHistory);
    }

    public function testEmptyModelStrategyDelegatesToSequentialBatch(): void
    {
        $client = $this->makeClient([], ['modelMap' => [], 'modelPriority' => []]);
        $path = $this->createTestImage('no-strategy.jpg');

        $results = $client->extractTextBatchConcurrent([$path]);

        $this->assertArrayHasKey('error', $results[$path]);
    }
}
