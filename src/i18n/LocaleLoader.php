<?php

namespace OvhOcr\i18n;

use RuntimeException;

class LocaleLoader
{
    // Audit #19: assigned once in the constructor, never mutated afterwards.
    private readonly string $localesPath;

    public function __construct(string $localesPath)
    {
        $this->localesPath = $localesPath;
    }

    /**
     * Ładuje wszystkie dostępne języki
     */
    public function loadAll(Translator $translator): void
    {
        foreach ($this->scanLocaleFiles() as $file) {
            $locale = basename($file, '.json');
            $translations = $this->load($locale);
            $translator->load($locale, $translations);
        }
    }

    /**
     * Ładuje konkretny język
     */
    public function load(string $locale): array
    {
        $file = $this->localesPath . '/' . $locale . '.json';

        if (!file_exists($file)) {
            throw new RuntimeException("Locale file not found: {$file}");
        }

        $content = file_get_contents($file);
        $decoded = json_decode($content, true);

        if (!is_array($decoded)) {
            throw new RuntimeException("Invalid JSON in locale file: {$file}");
        }

        return $decoded;
    }

    /**
     * Lista dostępnych języków
     */
    public function getAvailableLocales(): array
    {
        $locales = [];

        foreach ($this->scanLocaleFiles() as $file) {
            $locales[] = basename($file, '.json');
        }

        return $locales;
    }

    /**
     * Audit #23: glob() returns false on error (e.g. unreadable directory), which would
     * make a bare foreach() emit a warning and behave as if there were zero files instead
     * of failing loudly. Centralized here since both loadAll() and getAvailableLocales()
     * scan the same directory the same way.
     *
     * @return string[]
     */
    private function scanLocaleFiles(): array
    {
        $files = glob($this->localesPath . '/*.json');

        if ($files === false) {
            throw new RuntimeException("Failed to scan locales directory: {$this->localesPath}");
        }

        return $files;
    }
}
