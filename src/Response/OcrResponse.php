<?php

namespace OvhOcr\Response;

class OcrResponse
{
    private array $data;
    private string $extractedText = '';
    private string $usedModel;

    public function __construct(array $data, string $usedModel = 'unknown')
    {
        $this->data = $data;
        $this->usedModel = $usedModel;
        $this->parseResponse();
    }

    private function parseResponse(): void
    {
        // OVH format - Mistral
        if (isset($this->data['choices'][0]['message']['content'])) {
            $this->extractedText = $this->data['choices'][0]['message']['content'];
        }
        // Google Vision format
        elseif (isset($this->data['responses'][0]['textAnnotations'][0]['description'])) {
            $this->extractedText = $this->data['responses'][0]['textAnnotations'][0]['description'];
        }
        // Fallback
        elseif (isset($this->data['text'])) {
            $this->extractedText = $this->data['text'];
        }
    }

    /**
     * Zwraca wydobyty tekst
     */
    public function getText(): string
    {
        return trim($this->extractedText);
    }

    /**
     * Model który wydobył tekst
     */
    public function getUsedModel(): string
    {
        return $this->usedModel;
    }

    /**
     * Pełne dane odpowiedzi API
     */
    public function getRawData(): array
    {
        return $this->data;
    }

    /**
     * Zapisuje tekst do pliku
     */
    public function saveToFile(string $outputPath): bool
    {
        $dir = dirname($outputPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        return file_put_contents($outputPath, $this->getText()) !== false;
    }

    /**
     * Zwraca tekst z podziałem na linie
     */
    public function getLines(): array
    {
        // Filter only on an exactly empty string - array_filter() without a callback
        // would also drop lines like "0", which can be real document content, not a blank line.
        return array_filter(
            explode("\n", $this->extractedText),
            static fn (string $line): bool => $line !== ''
        );
    }

    /**
     * Zwraca tekst z podziałem na paragrafy
     */
    public function getParagraphs(): array
    {
        $text = $this->getText();
        $paragraphs = preg_split('/\n\s*\n/', $text);
        return array_filter(array_map('trim', $paragraphs));
    }

    /**
     * JSON representation
     */
    public function toJson(): string
    {
        return json_encode([
            'success' => true,
            'data' => [
                'text' => $this->getText(),
                'model' => $this->usedModel,
                'lineCount' => count($this->getLines()),
                'characterCount' => strlen($this->getText()),
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
}
