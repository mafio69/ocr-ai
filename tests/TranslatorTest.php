<?php

namespace OvhOcr\Tests;

use PHPUnit\Framework\TestCase;
use OvhOcr\i18n\Translator;
use OvhOcr\i18n\LocaleLoader;

/**
 * Testy Translatora - PRAWDZIWE testy, bez mocków.
 * Ładują realny plik pl.json i sprawdzają realne zachowanie.
 */
class TranslatorTest extends TestCase
{
    private Translator $translator;

    protected function setUp(): void
    {
        $this->translator = new Translator('pl', 'en');
        $loader = new LocaleLoader(__DIR__ . '/../resources/locales');
        $loader->loadAll($this->translator);
    }

    public function testDefaultLocale(): void
    {
        $this->assertSame('pl', $this->translator->getLocale());
    }

    public function testChangeLocale(): void
    {
        $this->translator->setLocale('en');
        $this->assertSame('en', $this->translator->getLocale());
    }

    public function testTranslateExistingKey(): void
    {
        $result = $this->translator->trans('errors.file_not_found');
        $this->assertNotSame('errors.file_not_found', $result);
        $this->assertStringContainsString('zdjęcia', $result);
    }

    public function testTranslateWithParams(): void
    {
        $result = $this->translator->trans('messages.attempting_model', ['model' => 'premium']);
        $this->assertStringContainsString('premium', $result);
        $this->assertStringNotContainsString('{model}', $result);
    }

    public function testNonexistentKeyReturnsKey(): void
    {
        $result = $this->translator->trans('does.not.exist');
        $this->assertSame('does.not.exist', $result);
    }

    public function testFallbackToEnglishWhenPolishMissing(): void
    {
        // Załaduj minimalny locale bez klucza
        $t = new Translator('xx', 'en');
        $loader = new LocaleLoader(__DIR__ . '/../resources/locales');
        $loader->loadAll($t);
        $t->setLocale('xx'); // język który nie istnieje

        $result = $t->trans('errors.file_not_found');
        // Powinno fallbackowac do 'en'
        $this->assertStringContainsString('image', strtolower($result));
    }

    public function testInvokeAlias(): void
    {
        $direct = $this->translator->trans('errors.internal_error');
        $viaInvoke = ($this->translator)('errors.internal_error');
        $this->assertSame($direct, $viaInvoke);
    }

    public function testAvailableLocales(): void
    {
        $locales = $this->translator->getAvailableLocales();
        $this->assertContains('pl', $locales);
        $this->assertContains('en', $locales);
    }

    public function testFileReadErrorKeyExists(): void
    {
        $result = $this->translator->trans('errors.file_read_error');
        $this->assertNotSame('errors.file_read_error', $result);
        $this->assertStringContainsString('pliku', $result);
    }

    // --- Audit #22: load() should merge, not overwrite ---

    public function testSecondLoadCallMergesInsteadOfReplacing(): void
    {
        $t = new Translator('xx', 'en');
        $t->load('xx', ['errors' => ['a' => 'First A']]);
        $t->load('xx', ['errors' => ['b' => 'Second B']]);

        $this->assertSame('First A', $t->trans('errors.a'));
        $this->assertSame('Second B', $t->trans('errors.b'));
    }

    public function testSecondLoadCallOverwritesOnlyConflictingKeys(): void
    {
        $t = new Translator('xx', 'en');
        $t->load('xx', ['errors' => ['a' => 'Original', 'b' => 'Keep me']]);
        $t->load('xx', ['errors' => ['a' => 'Updated']]);

        $this->assertSame('Updated', $t->trans('errors.a'));
        $this->assertSame('Keep me', $t->trans('errors.b'));
    }
}
