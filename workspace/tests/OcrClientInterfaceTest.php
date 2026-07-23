<?php

namespace OvhOcr\Tests;

use OvhOcr\i18n\Translator;
use OvhOcr\Logging\Logger;
use OvhOcr\OcrClient;
use OvhOcr\OcrClientInterface;
use OvhOcr\Response\OcrResponse;
use PHPUnit\Framework\TestCase;

/**
 * Audit #24: OcrClient previously had no interface, so consumers couldn't substitute a
 * test double without depending on the concrete class (and, transitively, Guzzle).
 */
class OcrClientInterfaceTest extends TestCase
{
    public function testOcrClientImplementsTheInterface(): void
    {
        $client = new OcrClient(
            apiKey: 'test-key',
            logger: new Logger(sys_get_temp_dir() . '/ocr_interface_test_' . uniqid() . '.log'),
            translator: new Translator('pl', 'en'),
            modelMap: ['lite' => 'TestModel'],
            modelPriority: ['lite'],
        );

        $this->assertInstanceOf(OcrClientInterface::class, $client);
    }

    public function testInterfaceCanBeImplementedByATestDouble(): void
    {
        // Demonstrates the actual point of the interface: a consumer's test suite can
        // substitute a fake without touching Guzzle/OVH/Google at all.
        $fake = new class () implements OcrClientInterface {
            public function extractText(string $imagePath, string $language = 'pl'): OcrResponse
            {
                return new OcrResponse(['choices' => [['message' => ['content' => 'fake text']]]], 'fake');
            }

            public function extractTextBatch(array $imagePaths, string $language = 'pl'): array
            {
                return array_fill_keys($imagePaths, $this->extractText('', $language));
            }

            public function extractTextBatchConcurrent(array $imagePaths, string $language = 'pl', int $concurrency = 5): array
            {
                return $this->extractTextBatch($imagePaths, $language);
            }

            public function getStrategy(): array
            {
                return ['fake'];
            }
        };

        $this->assertSame('fake text', $fake->extractText('irrelevant.jpg')->getText());
        $this->assertSame(['fake'], $fake->getStrategy());
    }
}
