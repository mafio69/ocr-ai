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
use OvhOcr\Response\OcrResponse;
use PHPUnit\Framework\TestCase;

class HttpClientInjectionTest extends TestCase
{
    private string $tempDir;
    private Logger $logger;
    private Translator $translator;
    private array $requestHistory = [];

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/ocr_http_injection_test_' . uniqid();
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

    public function testHttpClientCanBeInjected(): void
    {
        $mock = new MockHandler([
            new Response(200, [], json_encode([
                'choices' => [
                    ['message' => ['content' => 'Test text from mocked client']],
                ],
            ])),
        ]);

        $handlerStack = HandlerStack::create($mock);
        $history      = Middleware::history($this->requestHistory);
        $handlerStack->push($history);

        $customClient = new Client(['handler' => $handlerStack]);

        $ocrClient = new OcrClient(
            apiKey: 'test-key',
            logger: $this->logger,
            translator: $this->translator,
            modelMap: ['lite' => 'TestModel'],
            modelPriority: ['lite'],
            httpClient: $customClient,
        );

        $imagePath = $this->createTestImage();
        $response  = $ocrClient->extractText($imagePath);

        $this->assertInstanceOf(OcrResponse::class, $response);
        $this->assertSame('Test text from mocked client', $response->getText());
        $this->assertCount(1, $this->requestHistory);
    }

    public function testDefaultHttpClientIsCreatedWhenNotInjected(): void
    {
        $ocrClient = new OcrClient(
            apiKey: 'test-key',
            logger: $this->logger,
            translator: $this->translator,
            modelMap: ['lite' => 'TestModel'],
            modelPriority: ['lite'],
        );

        $this->assertInstanceOf(OcrClient::class, $ocrClient);
    }

    public function testInjectedClientIsUsedForOvhRequests(): void
    {
        $mock = new MockHandler([
            new Response(200, [], json_encode([
                'choices' => [
                    ['message' => ['content' => 'OVH response']],
                ],
            ])),
        ]);

        $handlerStack = HandlerStack::create($mock);
        $history      = Middleware::history($this->requestHistory);
        $handlerStack->push($history);

        $customClient = new Client(['handler' => $handlerStack]);

        $ocrClient = new OcrClient(
            apiKey: 'test-key',
            logger: $this->logger,
            translator: $this->translator,
            modelMap: ['lite' => 'TestModel'],
            modelPriority: ['lite'],
            httpClient: $customClient,
        );

        $imagePath = $this->createTestImage();
        $ocrClient->extractText($imagePath);

        $this->assertCount(1, $this->requestHistory);
        $request = $this->requestHistory[0]['request'];

        $this->assertStringContainsString('chat/completions', (string) $request->getUri());
        $this->assertSame('Bearer test-key', $request->getHeaderLine('Authorization'));
    }

    public function testInjectedClientIsUsedForGoogleVisionRequests(): void
    {
        $mock = new MockHandler([
            new Response(200, [], json_encode([
                'responses' => [
                    ['textAnnotations' => [['description' => 'Google Vision response']]],
                ],
            ])),
        ]);

        $handlerStack = HandlerStack::create($mock);
        $history      = Middleware::history($this->requestHistory);
        $handlerStack->push($history);

        $customClient = new Client(['handler' => $handlerStack]);

        $ocrClient = new OcrClient(
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

        $imagePath = $this->createTestImage();
        $ocrClient->extractText($imagePath);

        $this->assertCount(1, $this->requestHistory);
        $request = $this->requestHistory[0]['request'];

        $this->assertStringContainsString('vision.googleapis.com', (string) $request->getUri());
        $this->assertSame('Bearer test-token', $request->getHeaderLine('Authorization'));
    }

    public function testMultipleRequestsWithInjectedClient(): void
    {
        $mock = new MockHandler([
            new Response(200, [], json_encode([
                'choices' => [['message' => ['content' => 'First response']]],
            ])),
            new Response(200, [], json_encode([
                'choices' => [['message' => ['content' => 'Second response']]],
            ])),
        ]);

        $handlerStack = HandlerStack::create($mock);
        $history      = Middleware::history($this->requestHistory);
        $handlerStack->push($history);

        $customClient = new Client(['handler' => $handlerStack]);

        $ocrClient = new OcrClient(
            apiKey: 'test-key',
            logger: $this->logger,
            translator: $this->translator,
            modelMap: ['lite' => 'TestModel'],
            modelPriority: ['lite'],
            httpClient: $customClient,
        );

        $imagePath1 = $this->createTestImage('test1.jpg');
        $imagePath2 = $this->createTestImage('test2.jpg');

        $response1 = $ocrClient->extractText($imagePath1);
        $response2 = $ocrClient->extractText($imagePath2);

        $this->assertSame('First response', $response1->getText());
        $this->assertSame('Second response', $response2->getText());
        $this->assertCount(2, $this->requestHistory);
    }

    private function createTestImage(string $filename = 'test.jpg'): string
    {
        $imagePath = $this->tempDir . '/' . $filename;
        $image     = imagecreatetruecolor(10, 10);
        imagejpeg($image, $imagePath);

        return $imagePath;
    }
}
