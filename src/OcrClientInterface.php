<?php

namespace OvhOcr;

use OvhOcr\Response\OcrResponse;

/**
 * Audit #24: consumers previously had no way to mock/fake OCR extraction in their own
 * tests without depending on the concrete OcrClient (and, transitively, on Guzzle). This
 * interface covers the actual extraction API - consumers can type-hint against it and
 * substitute a test double.
 */
interface OcrClientInterface
{
    /**
     * Extracts text from a single image, trying models in the configured priority order.
     *
     * @throws \OvhOcr\Exceptions\OcrException If every configured model fails, or the file
     *                                          is missing/invalid.
     */
    public function extractText(string $imagePath, string $language = 'pl'): OcrResponse;

    /**
     * Processes multiple images strictly one at a time.
     *
     * @param string[] $imagePaths
     * @return array<string, OcrResponse|array{error: string}>
     */
    public function extractTextBatch(array $imagePaths, string $language = 'pl'): array;

    /**
     * Processes multiple images, sending the first model-tier attempt concurrently and
     * falling back to the full sequential strategy only for images that need it.
     *
     * @param string[] $imagePaths
     * @return array<string, OcrResponse|array{error: string}>
     */
    public function extractTextBatchConcurrent(array $imagePaths, string $language = 'pl', int $concurrency = 5): array;

    /**
     * Returns the current model strategy (tier order actually in effect), for
     * debugging/logging.
     *
     * @return string[]
     */
    public function getStrategy(): array;
}
