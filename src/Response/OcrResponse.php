<?php

namespace OvhOcr\Response;

class OcrResponse
{
    // Audit #19: all three are assigned exactly once (constructor / parseResponse(), called
    // only from the constructor) and never mutated afterwards.
    private readonly array $data;
    private readonly string $extractedText;
    private readonly string $usedModel;

    public function __construct(array $data, string $usedModel = 'unknown')
    {
        $this->data          = $data;
        $this->usedModel     = $usedModel;
        $this->extractedText = $this->parseExtractedText();
    }

    private function parseExtractedText(): string
    {
        // OVH format - Mistral
        if (isset($this->data['choices'][0]['message']['content'])) {
            return $this->data['choices'][0]['message']['content'];
        }
        // Google Vision format
        if (isset($this->data['responses'][0]['textAnnotations'][0]['description'])) {
            return $this->data['responses'][0]['textAnnotations'][0]['description'];
        }
        // Fallback
        if (isset($this->data['text'])) {
            return $this->data['text'];
        }

        // No recognized shape - stays empty, same as before this refactor.
        return '';
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
     * Zapisuje tekst do pliku.
     *
     * Audit #20: this used to swallow failures - a false return that's easy for calling
     * code to ignore, with no indication of what actually went wrong. It now throws a
     * RuntimeException with the failing path, so a failed write can't pass unnoticed.
     * Still returns bool (always true) on success, so existing `assertTrue(...)` callers
     * keep working unchanged.
     *
     * @throws \RuntimeException If the output directory can't be created or the write fails.
     */
    public function saveToFile(string $outputPath): bool
    {
        $dir = dirname($outputPath);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException("Failed to create output directory: {$dir}");
        }

        if (file_put_contents($outputPath, $this->getText()) === false) {
            throw new \RuntimeException("Failed to write OCR text to file: {$outputPath}");
        }

        return true;
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
            static fn (string $line): bool => $line !== '',
        );
    }

    /**
     * Zwraca tekst z podziałem na paragrafy
     */
    public function getParagraphs(): array
    {
        $text       = $this->getText();
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
            'data'    => [
                'text'           => $this->getText(),
                'model'          => $this->usedModel,
                'lineCount'      => count($this->getLines()),
                'characterCount' => strlen($this->getText()),
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
}
