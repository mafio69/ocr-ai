<?php

namespace OvhOcr\Tests;

use PHPUnit\Framework\TestCase;
use OvhOcr\i18n\LocaleLoader;
use OvhOcr\i18n\Translator;
use RuntimeException;

class LocaleLoaderTest extends TestCase
{
    public function testGetAvailableLocalesFindsRealLocales(): void
    {
        $loader = new LocaleLoader(__DIR__ . '/../resources/locales');
        $locales = $loader->getAvailableLocales();

        $this->assertContains('pl', $locales);
        $this->assertContains('en', $locales);
    }

    public function testLoadAllPopulatesTranslator(): void
    {
        $loader = new LocaleLoader(__DIR__ . '/../resources/locales');
        $translator = new Translator('pl', 'en');
        $loader->loadAll($translator);

        $this->assertContains('pl', $translator->getAvailableLocales());
        $this->assertContains('en', $translator->getAvailableLocales());
    }

    public function testLoadThrowsForMissingFile(): void
    {
        $loader = new LocaleLoader(__DIR__ . '/../resources/locales');

        $this->expectException(RuntimeException::class);
        $loader->load('does-not-exist');
    }

    // --- Audit #23: glob() returning false must not silently produce zero results ---

    public function testThrowsWhenLocalesDirectoryIsUnreadable(): void
    {
        $dir = sys_get_temp_dir() . '/ocr_locale_test_' . uniqid();
        mkdir($dir, 0755);
        chmod($dir, 0000);

        // Whether chmod(0000) actually turns glob() into a false-returning call depends on
        // the OS/filesystem/user running the tests - e.g. root bypasses permissions
        // entirely, and some filesystems/CI containers don't enforce the permission bit
        // the same way. Verify the failure condition is actually reproduced here before
        // asserting on it, instead of assuming - avoids a flaky "test failure" that's
        // really just an environment artifact rather than a bug in scanLocaleFiles().
        $globActuallyFailsHere = @glob($dir . '/*.json') === false;

        if (!$globActuallyFailsHere) {
            chmod($dir, 0755);
            rmdir($dir);
            // trivial-check-allow: this environment does not turn chmod(0000) into a glob() failure (e.g. root)
            $this->markTestSkipped('This environment does not turn chmod(0000) into a glob() failure');
        }

        $loader = new LocaleLoader($dir);

        try {
            $this->expectException(RuntimeException::class);
            $loader->getAvailableLocales();
        } finally {
            chmod($dir, 0755);
            rmdir($dir);
        }
    }
}
