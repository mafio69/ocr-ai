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
use ReflectionClass;

class GoogleVisionHeaderTest extends TestCase
{
    private string $tempDir;
    private Logger $logger;
    private Translator $translator;
    private array $requestHistory = [];

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/ocr_google_header_test_' . uniqid();
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

    private function createClientWithMockHandler(array $responses): OcrClient
    {
        $mock = new MockHandler($responses);
        $handlerStack = HandlerStack::create($mock);
        
        $history = Middleware::history($this->requestHistory);
        $handlerStack->push($history);
        
        $httpClient = new Client(['handler' => $handlerStack]);
        
        $client = new OcrClient(
            apiKey: 'test-ovh-key',
            logger: $this->logger,
            translator: $this->translator,
            modelMap: [],
            modelPriority: ['google_vision'],
            googleEnabled: true,
            googleApiKey: 'test-google-api-key-12345'
        );
        
        $reflection = new ReflectionClass($client);
        $property = $reflection->getProperty('httpClient');
        $property->setValue($client, $httpClient);
        
        return $client;
    }

    private function createTestImage(): string
    {
        $imagePath = $this->tempDir . '/test.jpg';
        $image = imagecreatetruecolor(10, 10);
        imagejpeg($image, $imagePath);
        return $imagePath;
    }

    public function testGoogleVisionApiKeyInHeader(): void
    {
        $mockResponse = new Response(200, [], json_encode([
            'responses' => [
                ['textAnnotations' => [['description' => 'Test text']]]
            ]
        ]));
        
        $client = $this->createClientWithMockHandler([$mockResponse]);
        $imagePath = $this->createTestImage();
        
        $response = $client->extractText($imagePath);
        
        $this->assertInstanceOf(OcrResponse::class, $response);
        $this->assertCount(1, $this->requestHistory);
        
        $request = $this->requestHistory[0]['request'];
        
        $this->assertTrue($request->hasHeader('x-goog-api-key'));
        $this->assertSame('test-google-api-key-12345', $request->getHeaderLine('x-goog-api-key'));
    }

    public function testGoogleVisionApiKeyNotInQueryString(): void
    {
        $mockResponse = new Response(200, [], json_encode([
            'responses' => [
                ['textAnnotations' => [['description' => 'Test text']]]
            ]
        ]));
        
        $client = $this->createClientWithMockHandler([$mockResponse]);
        $imagePath = $this->createTestImage();
        
        $client->extractText($imagePath);
        
        $this->assertCount(1, $this->requestHistory);
        
        $request = $this->requestHistory[0]['request'];
        $uri = $request->getUri();
        $query = $uri->getQuery();
        
        $this->assertStringNotContainsString('key=', $query);
        $this->assertStringNotContainsString('test-google-api-key-12345', $query);
        $this->assertStringNotContainsString('test-google-api-key-12345', (string) $uri);
    }

    public function testGoogleVisionRequestUsesCorrectEndpoint(): void
    {
        $mockResponse = new Response(200, [], json_encode([
            'responses' => [
                ['textAnnotations' => [['description' => 'Test text']]]
            ]
        ]));
        
        $client = $this->createClientWithMockHandler([$mockResponse]);
        $imagePath = $this->createTestImage();
        
        $client->extractText($imagePath);
        
        $this->assertCount(1, $this->requestHistory);
        
        $request = $this->requestHistory[0]['request'];
        $uri = (string) $request->getUri();
        
        $this->assertStringContainsString('vision.googleapis.com', $uri);
        $this->assertStringContainsString('images:annotate', $uri);
    }

    public function testGoogleVisionRequestContainsImageContent(): void
    {
        $mockResponse = new Response(200, [], json_encode([
            'responses' => [
                ['textAnnotations' => [['description' => 'Test text']]]
            ]
        ]));
        
        $client = $this->createClientWithMockHandler([$mockResponse]);
        $imagePath = $this->createTestImage();
        
        $client->extractText($imagePath);
        
        $this->assertCount(1, $this->requestHistory);
        
        $request = $this->requestHistory[0]['request'];
        $body = json_decode($request->getBody()->getContents(), true);
        
        $this->assertArrayHasKey('requests', $body);
        $this->assertArrayHasKey('image', $body['requests'][0]);
        $this->assertArrayHasKey('content', $body['requests'][0]['image']);
    }
}
