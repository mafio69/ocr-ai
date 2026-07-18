<?php

namespace OvhOcr\Tests;

use PHPUnit\Framework\TestCase;
use OvhOcr\OcrClient;
use OvhOcr\Logging\Logger;
use OvhOcr\i18n\Translator;
use OvhOcr\i18n\LocaleLoader;

class ModelValidationTest extends TestCase
{
    private string $tempDir;
    private Logger $logger;
    private Translator $translator;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/ocr_model_validation_test_' . uniqid();
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

    public function testConstructorThrowsWhenModelMapMissingTier(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Model map missing tier: premium');
        
        new OcrClient(
            apiKey: 'test-key',
            logger: $this->logger,
            translator: $this->translator,
            modelMap: ['lite' => 'Qwen3.5-9B'],
            modelPriority: ['premium', 'lite']
        );
    }

    public function testConstructorThrowsWhenAllTiersMissing(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Model map missing tier: medium');
        
        new OcrClient(
            apiKey: 'test-key',
            logger: $this->logger,
            translator: $this->translator,
            modelMap: [],
            modelPriority: ['medium', 'premium']
        );
    }

    public function testConstructorSucceedsWithValidModelMap(): void
    {
        $client = new OcrClient(
            apiKey: 'test-key',
            logger: $this->logger,
            translator: $this->translator,
            modelMap: ['lite' => 'Qwen3.5-9B', 'medium' => 'Mistral-Small'],
            modelPriority: ['medium', 'lite']
        );
        
        $this->assertInstanceOf(OcrClient::class, $client);
    }

    public function testConstructorAllowsGoogleVisionWithoutModelMapEntry(): void
    {
        $client = new OcrClient(
            apiKey: 'test-key',
            logger: $this->logger,
            translator: $this->translator,
            modelMap: ['lite' => 'Qwen3.5-9B'],
            modelPriority: ['google_vision', 'lite'],
            googleEnabled: true,
            googleApiKey: 'test-google-key'
        );
        
        $this->assertInstanceOf(OcrClient::class, $client);
    }

    public function testConstructorThrowsForMissingNonGoogleTier(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Model map missing tier: premium');
        
        new OcrClient(
            apiKey: 'test-key',
            logger: $this->logger,
            translator: $this->translator,
            modelMap: ['lite' => 'Qwen3.5-9B'],
            modelPriority: ['google_vision', 'premium'],
            googleEnabled: true,
            googleApiKey: 'test-google-key'
        );
    }

    public function testConstructorSucceedsWithEmptyPriority(): void
    {
        $client = new OcrClient(
            apiKey: 'test-key',
            logger: $this->logger,
            translator: $this->translator,
            modelMap: [],
            modelPriority: []
        );

        $this->assertInstanceOf(OcrClient::class, $client);
    }

    /**
     * Zero-config happy path: no $modelMap at all should fall back to
     * OcrClient::DEFAULT_MODEL_MAP instead of throwing "Model map missing tier".
     */
    public function testConstructorUsesDefaultModelMapWhenNoneGiven(): void
    {
        $client = new OcrClient(
            apiKey: 'test-key',
            logger: $this->logger,
            translator: $this->translator,
        );

        $this->assertInstanceOf(OcrClient::class, $client);
        $this->assertSame(['medium', 'premium', 'lite'], $client->getStrategy());
    }

    /**
     * Enabling Google Vision via $googleEnabled alone (without also editing
     * $modelPriority by hand) should be enough - it gets appended as the last fallback.
     */
    public function testGoogleVisionAutoAppendedToPriorityWhenEnabled(): void
    {
        $client = new OcrClient(
            apiKey: 'test-key',
            logger: $this->logger,
            translator: $this->translator,
            modelMap: ['lite' => 'Qwen3.5-9B'],
            modelPriority: ['lite'],
            googleEnabled: true,
            googleApiKey: 'test-google-key'
        );

        $this->assertSame(['lite', 'google_vision'], $client->getStrategy());
    }

    /**
     * If the caller already listed 'google_vision' explicitly, it shouldn't be duplicated.
     */
    public function testGoogleVisionNotDuplicatedWhenAlreadyInPriority(): void
    {
        $client = new OcrClient(
            apiKey: 'test-key',
            logger: $this->logger,
            translator: $this->translator,
            modelMap: ['lite' => 'Qwen3.5-9B'],
            modelPriority: ['google_vision', 'lite'],
            googleEnabled: true,
            googleApiKey: 'test-google-key'
        );

        $this->assertSame(['google_vision', 'lite'], $client->getStrategy());
    }
}
