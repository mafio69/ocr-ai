<?php

namespace OvhOcr\i18n;

class LocaleLoader
{
    private string $localesPath;

    public function __construct(string $localesPath)
    {
        $this->localesPath = $localesPath;
    }

    /**
     * Ładuje wszystkie dostępne języki
     */
    public function loadAll(Translator $translator): void
    {
        foreach (glob($this->localesPath . '/*.json') as $file) {
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
            throw new \RuntimeException("Locale file not found: {$file}");
        }

        $content = file_get_contents($file);
        $decoded = json_decode($content, true);

        if (!is_array($decoded)) {
            throw new \RuntimeException("Invalid JSON in locale file: {$file}");
        }

        return $decoded;
    }

    /**
     * Lista dostępnych języków
     */
    public function getAvailableLocales(): array
    {
        $locales = [];

        foreach (glob($this->localesPath . '/*.json') as $file) {
            $locales[] = basename($file, '.json');
        }

        return $locales;
    }
}
