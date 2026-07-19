<?php

namespace OvhOcr\Tests;

use OvhOcr\Response\OcrResponse;
use PHPUnit\Framework\TestCase;

/**
 * Testy OcrResponse - parsowanie odpowiedzi z OVH i Google.
 */
class OcrResponseTest extends TestCase
{
    public function testParsesOvhFormat(): void
    {
        $data = [
            'choices' => [
                ['message' => ['content' => 'Tekst z OVH']],
            ],
        ];
        $response = new OcrResponse($data, 'premium');
        $this->assertSame('Tekst z OVH', $response->getText());
        $this->assertSame('premium', $response->getUsedModel());
    }

    public function testParsesGoogleVisionFormat(): void
    {
        $data = [
            'responses' => [
                ['textAnnotations' => [
                    ['description' => 'Tekst z Google'],
                ]],
            ],
        ];
        $response = new OcrResponse($data, 'google_vision');
        $this->assertSame('Tekst z Google', $response->getText());
    }

    public function testGetLines(): void
    {
        $data     = ['choices' => [['message' => ['content' => "Linia 1\nLinia 2\nLinia 3"]]]];
        $response = new OcrResponse($data);
        $this->assertCount(3, $response->getLines());
    }

    public function testGetLinesKeepsLineContainingOnlyZero(): void
    {
        // array_filter() without an explicit callback treats the string "0" as falsy and
        // drops it - but it can be real document content (e.g. a sequence number), not a blank line.
        $data     = ['choices' => [['message' => ['content' => "Linia 1\n0\nLinia 3"]]]];
        $response = new OcrResponse($data);
        $this->assertSame(['Linia 1', '0', 'Linia 3'], array_values($response->getLines()));
    }

    public function testGetLinesRemovesOnlyExactlyEmptyLines(): void
    {
        $data     = ['choices' => [['message' => ['content' => "Linia 1\n\nLinia 3"]]]];
        $response = new OcrResponse($data);
        $this->assertSame(['Linia 1', 'Linia 3'], array_values($response->getLines()));
    }

    public function testGetParagraphs(): void
    {
        $data       = ['choices' => [['message' => ['content' => "Akapit 1\ntekst\n\nAkapit 2"]]]];
        $response   = new OcrResponse($data);
        $paragraphs = $response->getParagraphs();
        $this->assertCount(2, $paragraphs);
    }

    public function testSaveToFile(): void
    {
        $data     = ['choices' => [['message' => ['content' => 'Testowy tekst']]]];
        $response = new OcrResponse($data);

        $tmpFile = tempnam(sys_get_temp_dir(), 'ocr_test_');
        $this->assertTrue($response->saveToFile($tmpFile));
        $this->assertSame('Testowy tekst', file_get_contents($tmpFile));
        unlink($tmpFile);
    }

    public function testToJson(): void
    {
        $data     = ['choices' => [['message' => ['content' => 'Test']]]];
        $response = new OcrResponse($data, 'medium');
        $json     = json_decode($response->toJson(), true);

        $this->assertTrue($json['success']);
        $this->assertSame('Test', $json['data']['text']);
        $this->assertSame('medium', $json['data']['model']);
    }

    public function testEmptyResponseDoesntCrash(): void
    {
        $response = new OcrResponse([], 'unknown');
        $this->assertSame('', $response->getText());
    }

    // --- Audit #20: saveToFile() must not fail silently ---

    public function testSaveToFileThrowsWhenDirectoryIsNotWritable(): void
    {
        if (function_exists('posix_getuid') && posix_getuid() === 0) {
            // trivial-check-allow: running as root bypasses filesystem permission checks entirely
            $this->markTestSkipped('Cannot force a permission-denied write while running as root');
        }

        $dir = sys_get_temp_dir() . '/ocr_savetofile_test_' . uniqid();
        mkdir($dir, 0755);
        chmod($dir, 0555); // read + execute only, no write

        $data     = ['choices' => [['message' => ['content' => 'tekst']]]];
        $response = new OcrResponse($data);

        try {
            $this->expectException(\RuntimeException::class);
            $response->saveToFile($dir . '/output.txt');
        } finally {
            chmod($dir, 0755);
            rmdir($dir);
        }
    }
}
