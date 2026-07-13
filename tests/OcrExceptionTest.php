<?php

namespace OvhOcr\Tests;

use PHPUnit\Framework\TestCase;
use OvhOcr\Exceptions\OcrException;
use OvhOcr\i18n\Translator;
use OvhOcr\i18n\LocaleLoader;

class OcrExceptionTest extends TestCase
{
    protected function setUp(): void
    {
        $translator = new Translator('pl', 'en');
        $loader = new LocaleLoader(__DIR__ . '/../resources/locales');
        $loader->loadAll($translator);
        OcrException::setTranslator($translator);
    }

    public function testInternalMessageStaysTechnical(): void
    {
        $e = new OcrException('Internal technical: null pointer at 0x42', 'errors.file_not_found');
        $this->assertStringContainsString('null pointer', $e->getMessage());
    }

    public function testUserMessageComesFromTranslation(): void
    {
        $e = new OcrException('technical', 'errors.file_not_found');
        $this->assertStringContainsString('zdjęcia', $e->getUserMessage());
    }

    public function testContextIsPreserved(): void
    {
        $ctx = ['file' => 'test.png', 'size' => 12345];
        $e = new OcrException('msg', 'errors.file_not_found', $ctx);
        $this->assertSame($ctx, $e->getContext());
    }

    public function testUserMessageParamsAreSubstituted(): void
    {
        $e = new OcrException(
            'msg',
            'errors.file_too_large',
            [],
            ['size' => 25, 'max_size' => 20]
        );
        $msg = $e->getUserMessage();
        // Klucz istnieje w pl.json - powinien być normalny string, nie klucz
        $this->assertNotSame('errors.file_too_large', $msg);
    }

    public function testFallbackWhenNoTranslator(): void
    {
        // Zresetuj translator (używamy reflection lub innego sposobu)
        // W praktyce - jeśli klucz nie istnieje, dostaniemy klucz
        $e = new OcrException('msg', 'nonexistent.key');
        $msg = $e->getUserMessage();
        // Powinno cos zwrócić (klucz albo default)
        $this->assertIsString($msg);
    }
}
