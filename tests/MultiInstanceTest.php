<?php

namespace OvhOcr\Tests;

use PHPUnit\Framework\TestCase;
use OvhOcr\OcrClient;
use OvhOcr\Logging\Logger;
use OvhOcr\i18n\Translator;
use OvhOcr\i18n\LocaleLoader;
use OvhOcr\Exceptions\OcrException;

class MultiInstanceTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/ocr_multi_instance_test_' . uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }

    private function createTranslator(string $locale): Translator
    {
        $translator = new Translator($locale, 'en');
        $loader = new LocaleLoader(__DIR__ . '/../resources/locales');
        $loader->loadAll($translator);
        return $translator;
    }

    private function createClient(Translator $translator): OcrClient
    {
        $logger = new Logger($this->tempDir . '/test_' . $translator->getLocale() . '.log', true);
        
        return new OcrClient(
            apiKey: 'test-key',
            logger: $logger,
            translator: $translator,
            modelMap: ['lite' => 'Qwen3.5-9B'],
            modelPriority: ['lite']
        );
    }

    public function testMultipleClientsWithDifferentTranslators(): void
    {
        $translatorPl = $this->createTranslator('pl');
        $translatorEn = $this->createTranslator('en');
        
        $clientPl = $this->createClient($translatorPl);
        $clientEn = $this->createClient($translatorEn);
        
        $this->assertInstanceOf(OcrClient::class, $clientPl);
        $this->assertInstanceOf(OcrClient::class, $clientEn);
        
        $this->assertSame('pl', $translatorPl->getLocale());
        $this->assertSame('en', $translatorEn->getLocale());
    }

    public function testExceptionUsesCorrectTranslator(): void
    {
        $translatorPl = $this->createTranslator('pl');
        $translatorEn = $this->createTranslator('en');
        
        $exceptionPl = new OcrException(
            message: 'Technical error',
            userMessageKey: 'errors.file_not_found'
        );
        
        $exceptionEn = new OcrException(
            message: 'Technical error',
            userMessageKey: 'errors.file_not_found'
        );
        
        $messagePl = $exceptionPl->getUserMessage($translatorPl);
        $messageEn = $exceptionEn->getUserMessage($translatorEn);
        
        $this->assertStringContainsString('zdjęcia', $messagePl);
        $this->assertStringContainsString('image', strtolower($messageEn));
        $this->assertStringNotContainsString('zdjęcia', $messageEn);
    }

    public function testExceptionMessagesAreIndependent(): void
    {
        $translatorPl = $this->createTranslator('pl');
        $translatorEn = $this->createTranslator('en');
        
        $exception = new OcrException(
            message: 'Technical error',
            userMessageKey: 'errors.file_not_found'
        );
        
        $messagePl = $exception->getUserMessage($translatorPl);
        $messageEn = $exception->getUserMessage($translatorEn);
        
        $this->assertNotSame($messagePl, $messageEn);
        $this->assertStringContainsString('zdjęcia', $messagePl);
        $this->assertStringContainsString('image', strtolower($messageEn));
    }

    public function testNoStaticStateBetweenExceptions(): void
    {
        $translatorPl = $this->createTranslator('pl');
        $translatorEn = $this->createTranslator('en');
        
        $exception1 = new OcrException('msg1', 'errors.file_not_found');
        $exception2 = new OcrException('msg2', 'errors.file_not_found');
        
        $message1Pl = $exception1->getUserMessage($translatorPl);
        $message2En = $exception2->getUserMessage($translatorEn);
        $message1PlAgain = $exception1->getUserMessage($translatorPl);
        
        $this->assertSame($message1Pl, $message1PlAgain);
        $this->assertStringContainsString('zdjęcia', $message1Pl);
        $this->assertStringContainsString('image', strtolower($message2En));
    }

    public function testClientStrategyIsIndependent(): void
    {
        $translatorPl = $this->createTranslator('pl');
        $translatorEn = $this->createTranslator('en');
        
        $clientPl = new OcrClient(
            apiKey: 'test-key',
            logger: new Logger($this->tempDir . '/pl.log', true),
            translator: $translatorPl,
            modelMap: ['lite' => 'ModelA', 'medium' => 'ModelB'],
            modelPriority: ['lite', 'medium']
        );
        
        $clientEn = new OcrClient(
            apiKey: 'test-key',
            logger: new Logger($this->tempDir . '/en.log', true),
            translator: $translatorEn,
            modelMap: ['premium' => 'ModelC'],
            modelPriority: ['premium']
        );
        
        $this->assertSame(['lite', 'medium'], $clientPl->getStrategy());
        $this->assertSame(['premium'], $clientEn->getStrategy());
    }

    public function testMultipleClientsDoNotInterfere(): void
    {
        $clients = [];
        $translators = [];
        
        for ($i = 0; $i < 5; $i++) {
            $locale = $i % 2 === 0 ? 'pl' : 'en';
            $translators[$i] = $this->createTranslator($locale);
            $clients[$i] = $this->createClient($translators[$i]);
        }
        
        foreach ($clients as $i => $client) {
            $this->assertInstanceOf(OcrClient::class, $client);
            $locale = $i % 2 === 0 ? 'pl' : 'en';
            $this->assertSame($locale, $translators[$i]->getLocale());
        }
        
        $exception = new OcrException('msg', 'errors.file_not_found');
        
        $messagePl = $exception->getUserMessage($translators[0]);
        $messageEn = $exception->getUserMessage($translators[1]);
        
        $this->assertStringContainsString('zdjęcia', $messagePl);
        $this->assertStringContainsString('image', strtolower($messageEn));
    }
}
