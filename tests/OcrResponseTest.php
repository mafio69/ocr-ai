<?php

namespace OvhOcr\Tests;

use PHPUnit\Framework\TestCase;
use OvhOcr\Response\OcrResponse;

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
        $data = ['choices' => [['message' => ['content' => "Linia 1\nLinia 2\nLinia 3"]]]];
        $response = new OcrResponse($data);
        $this->assertCount(3, $response->getLines());
    }

    public function testGetParagraphs(): void
    {
        $data = ['choices' => [['message' => ['content' => "Akapit 1\ntekst\n\nAkapit 2"]]]];
        $response = new OcrResponse($data);
        $paragraphs = $response->getParagraphs();
        $this->assertCount(2, $paragraphs);
    }

    public function testSaveToFile(): void
    {
        $data = ['choices' => [['message' => ['content' => 'Testowy tekst']]]];
        $response = new OcrResponse($data);

        $tmpFile = tempnam(sys_get_temp_dir(), 'ocr_test_');
        $this->assertTrue($response->saveToFile($tmpFile));
        $this->assertSame('Testowy tekst', file_get_contents($tmpFile));
        unlink($tmpFile);
    }

    public function testToJson(): void
    {
        $data = ['choices' => [['message' => ['content' => 'Test']]]];
        $response = new OcrResponse($data, 'medium');
        $json = json_decode($response->toJson(), true);

        $this->assertTrue($json['success']);
        $this->assertSame('Test', $json['data']['text']);
        $this->assertSame('medium', $json['data']['model']);
    }

    public function testEmptyResponseDoesntCrash(): void
    {
        $response = new OcrResponse([], 'unknown');
        $this->assertSame('', $response->getText());
    }
}
