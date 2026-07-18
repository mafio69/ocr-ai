<?php

namespace OvhOcr\i18n;

class Translator
{
    private array $translations = [];
    // Mutable by design - setLocale() changes it after construction, so it stays non-readonly.
    private string $locale = 'en';
    // Audit #19: never reassigned after the constructor, unlike $locale above.
    private readonly string $fallbackLocale;

    public function __construct(string $locale = 'en', string $fallbackLocale = 'en')
    {
        $this->locale = $locale;
        $this->fallbackLocale = $fallbackLocale;
    }

    /**
     * Ładuje tłumaczenia.
     *
     * Audit #22: a second load() call for the same locale used to completely replace the
     * first set instead of merging - so calling load('pl', [...]) twice would silently
     * drop any keys only present in the first call. array_replace_recursive() merges
     * nested keys (new values win on conflicts), matching what callers actually expect
     * when loading translations incrementally (e.g. multiple files for one locale).
     */
    public function load(string $locale, array $translations): self
    {
        $this->translations[$locale] = array_replace_recursive(
            $this->translations[$locale] ?? [],
            $translations
        );
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

        // Try current locale
        $value = $this->getFromLocale($locale, $keys);
        if ($value !== null) {
            return $value;
        }

        // Fallback to default locale
        if ($locale !== $this->fallbackLocale) {
            $value = $this->getFromLocale($this->fallbackLocale, $keys);
            if ($value !== null) {
                return $value;
            }
        }

        // Return the key itself if no translation found
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
