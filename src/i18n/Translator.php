<?php

namespace OvhOcr\i18n;

class Translator
{
    private array $translations = [];
    private string $locale = 'en';
    private string $fallbackLocale = 'en';

    public function __construct(string $locale = 'en', string $fallbackLocale = 'en')
    {
        $this->locale = $locale;
        $this->fallbackLocale = $fallbackLocale;
    }

    /**
     * Ładuje tłumaczenia
     */
    public function load(string $locale, array $translations): self
    {
        $this->translations[$locale] = $translations;
        return $this;
    }

    /**
     * Ustawia aktualny język
     */
    public function setLocale(string $locale): self
    {
        $this->locale = $locale;
        return $this;
    }

    /**
     * Pobiera aktualny język
     */
    public function getLocale(): string
    {
        return $this->locale;
    }

    /**
     * Tłumaczy klucz z parameterami
     * 
     * Przykłady:
     * trans('errors.file_not_found')
     * trans('validation.file_size_exceeded', ['size' => 100, 'max_size' => 50])
     */
    public function trans(string $key, array $params = []): string
    {
        $translation = $this->get($key);

        if (empty($params)) {
            return $translation;
        }

        foreach ($params as $paramKey => $paramValue) {
            $translation = str_replace('{' . $paramKey . '}', (string)$paramValue, $translation);
        }

        return $translation;
    }

    /**
     * Alias do trans()
     */
    public function __invoke(string $key, array $params = []): string
    {
        return $this->trans($key, $params);
    }

    private function get(string $key): string
    {
        $keys = explode('.', $key);
        $locale = $this->locale;

        // Spróbuj aktualny język
        $value = $this->getFromLocale($locale, $keys);
        if ($value !== null) {
            return $value;
        }

        // Fallback do domyślnego języka
        if ($locale !== $this->fallbackLocale) {
            $value = $this->getFromLocale($this->fallbackLocale, $keys);
            if ($value !== null) {
                return $value;
            }
        }

        // Zwróć sam klucz jeśli nie ma tłumaczenia
        return $key;
    }

    private function getFromLocale(string $locale, array $keys): ?string
    {
        if (!isset($this->translations[$locale])) {
            return null;
        }

        $current = $this->translations[$locale];

        foreach ($keys as $key) {
            if (!isset($current[$key])) {
                return null;
            }
            $current = $current[$key];
        }

        return is_string($current) ? $current : null;
    }

    /**
     * Zwraca dostępne języki
     */
    public function getAvailableLocales(): array
    {
        return array_keys($this->translations);
    }
}
