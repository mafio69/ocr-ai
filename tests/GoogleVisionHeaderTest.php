<?php

namespace OvhOcr\Tests;

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

        $this->logger     = new Logger($this->tempDir . '/test.log', true);
        $this->translator = new Translator('pl', 'en');
        $loader           = new LocaleLoader(__DIR__ . '/../resources/locales');
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

    private function createMockCredentialsFile(): string
    {
        $credentialsPath = $this->tempDir . '/google-credentials.json';
        $credentials     = [
            'type'           => 'service_account',
            'project_id'     => 'test-project',
            'private_key_id' => 'key123',
            'private_key'    => "-----BEGIN PRIVATE KEY-----\nMIIEvQIBADANBgkqhkiG9w0BAQEFAASCBKcwggSjAgEAAoIBAQC7VJTUt9Us8cKj\nMzEfYyjiWA4R4/M2bS1GB4t7NXp98C3SC6dVMvDuictGeurT8jNbvJZHtCSuYEvu\nNMoSfm76oqFvAp8Gy0iz5sxjZmSnXyCdPEovGhLa0VzMaQ8s+CLOyS56YyCFGeJZ\nqgtzJ6GR3eqoYSW9b9UMvkBpZODSctWSNGj3P7jRFDO5Vo1C3VRW6h4WxiNzD/Pg\nJl3tVFPe9Ai7MvB7+Q4xWNxYCqHxQ7cMkq9XhXE70L7L+X6YQICJrqnBf5xHkfJS\n2VcLr5kXzJ4j6TLfY+Y3QZnfQ7pBQJ5qJ5Y3QZnfQ7pBQJ5qJ5Y3QZnfQ7pBQJ5q\n-----END PRIVATE KEY-----\n",
            'client_email'   => 'test@test-project.iam.gserviceaccount.com',
            'client_id'      => '123456789',
            'auth_uri'       => 'https://accounts.google.com/o/oauth2/auth',
            'token_uri'      => 'https://oauth2.googleapis.com/token',
        ];
        file_put_contents($credentialsPath, json_encode($credentials));

        return $credentialsPath;
    }

    private function createClientWithMockHandler(array $responses): OcrClient
    {
        $mock         = new MockHandler($responses);
        $handlerStack = HandlerStack::create($mock);

        $history = Middleware::history($this->requestHistory);
        $handlerStack->push($history);

        $httpClient = new Client(['handler' => $handlerStack]);

        return new OcrClient(
            apiKey: 'test-ovh-key',
            logger: $this->logger,
            translator: $this->translator,
            modelMap: [],
            modelPriority: ['google_vision'],
            googleEnabled: true,
            googleCredentialsPath: $this->createMockCredentialsFile(),
            httpClient: $httpClient,
            googleTokenProvider: fn () => 'test-token',
        );
    }

    private function createTestImage(): string
    {
        $imagePath = $this->tempDir . '/test.jpg';
        $image     = imagecreatetruecolor(10, 10);
        imagejpeg($image, $imagePath);

        return $imagePath;
    }

    public function testGoogleVisionAuthorizationHeader(): void
    {
        $mockResponse = new Response(200, [], json_encode([
            'responses' => [
                ['textAnnotations' => [['description' => 'Test text']]],
            ],
        ]));

        $client    = $this->createClientWithMockHandler([$mockResponse]);
        $imagePath = $this->createTestImage();

        try {
            $client->extractText($imagePath);
        } catch (\Exception $e) {
            // OAuth2 flow may fail in test environment, but we can still check the request
        }

        $this->assertCount(1, $this->requestHistory);

        $request = $this->requestHistory[0]['request'];

        // Should have Authorization header (OAuth2 Bearer token) instead of x-goog-api-key
        $this->assertTrue($request->hasHeader('Authorization'));
        $this->assertStringStartsWith('Bearer ', $request->getHeaderLine('Authorization'));
    }

    public function testGoogleVisionNoApiKeyInQueryString(): void
    {
        $mockResponse = new Response(200, [], json_encode([
            'responses' => [
                ['textAnnotations' => [['description' => 'Test text']]],
            ],
        ]));

        $client    = $this->createClientWithMockHandler([$mockResponse]);
        $imagePath = $this->createTestImage();

        try {
            $client->extractText($imagePath);
        } catch (\Exception $e) {
            // OAuth2 flow may fail in test environment
        }

        $this->assertCount(1, $this->requestHistory);

        $request = $this->requestHistory[0]['request'];
        $uri     = $request->getUri();
        $query   = $uri->getQuery();

        // No API key in query string (using OAuth2 Bearer token instead)
        $this->assertStringNotContainsString('key=', $query);
    }

    public function testGoogleVisionRequestUsesCorrectEndpoint(): void
    {
        $mockResponse = new Response(200, [], json_encode([
            'responses' => [
                ['textAnnotations' => [['description' => 'Test text']]],
            ],
        ]));

        $client    = $this->createClientWithMockHandler([$mockResponse]);
        $imagePath = $this->createTestImage();

        try {
            $client->extractText($imagePath);
        } catch (\Exception $e) {
            // OAuth2 flow may fail in test environment
        }

        $this->assertCount(1, $this->requestHistory);

        $request = $this->requestHistory[0]['request'];
        $uri     = (string) $request->getUri();

        $this->assertStringContainsString('vision.googleapis.com', $uri);
        $this->assertStringContainsString('images:annotate', $uri);
    }

    public function testGoogleVisionRequestContainsImageContent(): void
    {
        $mockResponse = new Response(200, [], json_encode([
            'responses' => [
                ['textAnnotations' => [['description' => 'Test text']]],
            ],
        ]));

        $client    = $this->createClientWithMockHandler([$mockResponse]);
        $imagePath = $this->createTestImage();

        try {
            $client->extractText($imagePath);
        } catch (\Exception $e) {
            // OAuth2 flow may fail in test environment
        }

        $this->assertCount(1, $this->requestHistory);

        $request = $this->requestHistory[0]['request'];
        $body    = json_decode($request->getBody()->getContents(), true);

        $this->assertArrayHasKey('requests', $body);
        $this->assertArrayHasKey('image', $body['requests'][0]);
        $this->assertArrayHasKey('content', $body['requests'][0]['image']);
    }
}
