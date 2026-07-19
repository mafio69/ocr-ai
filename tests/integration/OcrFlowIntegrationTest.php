<?php

namespace OvhOcr\Tests\Integration;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use OvhOcr\i18n\LocaleLoader;
use OvhOcr\i18n\Translator;
use OvhOcr\Logging\Logger;
use OvhOcr\OcrClient;
use PHPUnit\Framework\TestCase;

/**
 * Integration test: full OCR flow with mocked HTTP responses.
 * Tests the complete pipeline from image upload to text extraction.
 */
class OcrFlowIntegrationTest extends TestCase
{
    private string $tempDir;
    private Logger $logger;
    private Translator $translator;
    private array $requestHistory = [];

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/ocr_integration_' . uniqid();
        mkdir($this->tempDir, 0755, true);

        $this->logger     = new Logger($this->tempDir . '/test.log', true);
        $this->translator = new Translator('pl', 'en');
        $loader           = new LocaleLoader(__DIR__ . '/../../resources/locales');
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

    private function createMockClient(array $responses): Client
    {
        $mock         = new MockHandler($responses);
        $handlerStack = HandlerStack::create($mock);
        $handlerStack->push(Middleware::history($this->requestHistory));

        return new Client(['handler' => $handlerStack]);
    }

    private function createTestImage(): string
    {
        $imagePath = $this->tempDir . '/test.jpg';
        $image     = imagecreatetruecolor(100, 50);
        $bg        = imagecolorallocate($image, 255, 255, 255);
        imagefilledrectangle($image, 0, 0, 99, 49, $bg);
        $text = imagecolorallocate($image, 0, 0, 0);
        imagestring($image, 5, 10, 15, 'Test OCR', $text);
        imagejpeg($image, $imagePath);

        return $imagePath;
    }

    public function testFullOcrFlowWithSuccessfulResponse(): void
    {
        $mockResponse = new Response(200, [], json_encode([
            'choices' => [
                [
                    'message' => [
                        'content' => "Extracted text line 1\nExtracted text line 2",
                    ],
                ],
            ],
            'usage' => [
                'prompt_tokens'     => 100,
                'completion_tokens' => 50,
                'total_tokens'      => 150,
            ],
        ]));

        $httpClient = $this->createMockClient([$mockResponse]);

        $client = new OcrClient(
            apiKey: 'test-api-key',
            logger: $this->logger,
            translator: $this->translator,
            modelMap: ['lite' => 'TestModel'],
            modelPriority: ['lite'],
            httpClient: $httpClient,
        );

        $imagePath = $this->createTestImage();
        $response  = $client->extractText($imagePath, 'en');

        $this->assertSame("Extracted text line 1\nExtracted text line 2", $response->getText());
        $this->assertSame('lite', $response->getUsedModel());
        $this->assertCount(1, $this->requestHistory);

        $request = $this->requestHistory[0]['request'];
        $this->assertSame('POST', $request->getMethod());
        $this->assertStringContainsString('chat/completions', (string) $request->getUri());

        $body = json_decode((string) $request->getBody(), true);
        $this->assertSame('TestModel', $body['model']);
        $this->assertArrayHasKey('messages', $body);
        $this->assertSame('user', $body['messages'][0]['role']);
    }

    public function testFallbackToNextModelOnFailure(): void
    {
        $failResponse = new Response(500, [], json_encode([
            'error' => ['message' => 'Model overloaded'],
        ]));

        $successResponse = new Response(200, [], json_encode([
            'choices' => [
                [
                    'message' => ['content' => 'Fallback text'],
                ],
            ],
        ]));

        $httpClient = $this->createMockClient([$failResponse, $successResponse]);

        $client = new OcrClient(
            apiKey: 'test-api-key',
            logger: $this->logger,
            translator: $this->translator,
            modelMap: [
                'lite'   => 'LiteModel',
                'medium' => 'MediumModel',
            ],
            modelPriority: ['lite', 'medium'],
            httpClient: $httpClient,
        );

        $imagePath = $this->createTestImage();
        $response  = $client->extractText($imagePath, 'en');

        $this->assertSame('Fallback text', $response->getText());
        $this->assertSame('medium', $response->getUsedModel());
        $this->assertCount(2, $this->requestHistory);
    }

    public function testBatchProcessingMultipleImages(): void
    {
        $response1 = new Response(200, [], json_encode([
            'choices' => [['message' => ['content' => 'Text from image 1']]],
        ]));

        $response2 = new Response(200, [], json_encode([
            'choices' => [['message' => ['content' => 'Text from image 2']]],
        ]));

        $httpClient = $this->createMockClient([$response1, $response2]);

        $client = new OcrClient(
            apiKey: 'test-api-key',
            logger: $this->logger,
            translator: $this->translator,
            modelMap: ['lite' => 'TestModel'],
            modelPriority: ['lite'],
            httpClient: $httpClient,
        );

        $image1 = $this->createTestImage();
        $image2 = $this->tempDir . '/test2.jpg';
        copy($image1, $image2);

        $results = $client->extractTextBatch([$image1, $image2], 'en');

        $this->assertCount(2, $results);
        $this->assertSame('Text from image 1', $results[$image1]->getText());
        $this->assertSame('Text from image 2', $results[$image2]->getText());
    }

    public function testGoogleVisionFallback(): void
    {
        $ovhFail = new Response(503, [], json_encode([
            'error' => ['message' => 'Service unavailable'],
        ]));

        $googleSuccess = new Response(200, [], json_encode([
            'responses' => [
                [
                    'textAnnotations' => [
                        ['description' => 'Google Vision text'],
                    ],
                ],
            ],
        ]));

        $httpClient = $this->createMockClient([$ovhFail, $googleSuccess]);

        $client = new OcrClient(
            apiKey: 'test-api-key',
            logger: $this->logger,
            translator: $this->translator,
            modelMap: ['lite' => 'TestModel'],
            modelPriority: ['lite', 'google_vision'],
            googleEnabled: true,
            googleCredentialsPath: '/tmp/test-credentials.json',
            httpClient: $httpClient,
            googleTokenProvider: fn () => 'test-token',
        );

        $imagePath = $this->createTestImage();
        $response  = $client->extractText($imagePath, 'en');

        $this->assertSame('Google Vision text', $response->getText());
        $this->assertSame('google_vision', $response->getUsedModel());
    }
}
