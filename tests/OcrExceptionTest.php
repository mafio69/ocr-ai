<?php

namespace OvhOcr\Tests;

use OvhOcr\Exceptions\OcrException;
use OvhOcr\i18n\LocaleLoader;
use OvhOcr\i18n\Translator;
use PHPUnit\Framework\TestCase;

class OcrExceptionTest extends TestCase
{
    private Translator $translator;

    protected function setUp(): void
    {
        $this->translator = new Translator('pl', 'en');
        $loader           = new LocaleLoader(__DIR__ . '/../resources/locales');
        $loader->loadAll($this->translator);
    }

    public function testInternalMessageStaysTechnical(): void
    {
        $e = new OcrException('Internal technical: null pointer at 0x42', 'errors.file_not_found');
        $this->assertStringContainsString('null pointer', $e->getMessage());
    }

    public function testUserMessageComesFromTranslation(): void
    {
        $e = new OcrException('technical', 'errors.file_not_found');
        $this->assertStringContainsString('zdjęcia', $e->getUserMessage($this->translator));
    }

    public function testContextIsPreserved(): void
    {
        $ctx = ['file' => 'test.png', 'size' => 12345];
        $e   = new OcrException('msg', 'errors.file_not_found', $ctx);
        $this->assertSame($ctx, $e->getContext());
    }

    public function testUserMessageParamsAreSubstituted(): void
    {
        $e = new OcrException(
            'msg',
            'errors.file_too_large',
            [],
            ['size' => 25, 'max_size' => 20],
        );
        $msg = $e->getUserMessage($this->translator);
        $this->assertNotSame('errors.file_too_large', $msg);
    }

    public function testFallbackWhenNoTranslator(): void
    {
        $e   = new OcrException('msg', 'nonexistent.key');
        $msg = $e->getUserMessage();
        $this->assertSame('An unexpected error occurred', $msg);
    }

    public function testFallbackWhenNoKey(): void
    {
        $e   = new OcrException('msg');
        $msg = $e->getUserMessage($this->translator);
        $this->assertStringContainsString('Spróbuj później', $msg);
    }

    public function testGetUserMessageWithNullTranslator(): void
    {
        $e   = new OcrException('msg', 'errors.file_not_found');
        $msg = $e->getUserMessage(null);
        $this->assertSame('Image file not found', $msg);
    }

    public function testGetUserMessageWithoutParamReturnsKey(): void
    {
        $e   = new OcrException('msg', 'errors.file_not_found');
        $msg = $e->getUserMessage();
        $this->assertSame('Image file not found', $msg);
    }

    public function testGetUserMessageWithoutKeyReturnsFallback(): void
    {
        $e   = new OcrException('msg');
        $msg = $e->getUserMessage(null);
        $this->assertSame('An unexpected error occurred', $msg);
    }

    public function testGetUserMessageWithoutKeyAndWithoutTranslatorReturnsFallback(): void
    {
        $e   = new OcrException('msg');
        $msg = $e->getUserMessage();
        $this->assertSame('An unexpected error occurred', $msg);
    }

    public function testGetUserMessageKeyReturnsKey(): void
    {
        $e = new OcrException('msg', 'errors.file_not_found');
        $this->assertSame('errors.file_not_found', $e->getUserMessageKey());
    }

    public function testGetUserMessageKeyReturnsNullWhenNoKey(): void
    {
        $e = new OcrException('msg');
        $this->assertNull($e->getUserMessageKey());
    }
}
