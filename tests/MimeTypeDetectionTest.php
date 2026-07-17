<?php

namespace OvhOcr\Tests;

use PHPUnit\Framework\TestCase;
use OvhOcr\OcrClient;
use OvhOcr\Logging\Logger;
use OvhOcr\i18n\Translator;
use OvhOcr\Exceptions\OcrException;
use ReflectionClass;

class MimeTypeDetectionTest extends TestCase
{
    private string $tempDir;
    private Logger $logger;
    private Translator $translator;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/ocr_mime_test_' . uniqid();
        mkdir($this->tempDir, 0755, true);
        
        $this->logger = new Logger($this->tempDir . '/test.log', true);
        $this->translator = new Translator('pl', 'en');
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

    private function createClient(): OcrClient
    {
        return new OcrClient(
            apiKey: 'test-key',
            logger: $this->logger,
            translator: $this->translator,
            modelMap: ['lite' => 'Qwen3.5-9B'],
            modelPriority: ['lite']
        );
    }

    private function callDetectMimeType(OcrClient $client, string $imagePath): string
    {
        $reflection = new ReflectionClass($client);
        $method = $reflection->getMethod('detectMimeType');
        
        return $method->invoke($client, $imagePath);
    }

    public function testDetectsRealJpegImage(): void
    {
        $imagePath = $this->tempDir . '/test.jpg';
        $image = imagecreatetruecolor(10, 10);
        imagejpeg($image, $imagePath);
        
        $client = $this->createClient();
        $mimeType = $this->callDetectMimeType($client, $imagePath);
        
        $this->assertSame('image/jpeg', $mimeType);
    }

    public function testDetectsRealPngImage(): void
    {
        $imagePath = $this->tempDir . '/test.png';
        $image = imagecreatetruecolor(10, 10);
        imagepng($image, $imagePath);
        
        $client = $this->createClient();
        $mimeType = $this->callDetectMimeType($client, $imagePath);
        
        $this->assertSame('image/png', $mimeType);
    }

    public function testDetectsRealWebpImage(): void
    {
        if (!function_exists('imagewebp')) {
            // trivial-check-allow: legitimate conditional skip - not every PHP environment has WebP in GD
            $this->markTestSkipped('WebP support not available');
        }
        
        $imagePath = $this->tempDir . '/test.webp';
        $image = imagecreatetruecolor(10, 10);
        imagewebp($image, $imagePath);
        
        $client = $this->createClient();
        $mimeType = $this->callDetectMimeType($client, $imagePath);
        
        $this->assertSame('image/webp', $mimeType);
    }

    public function testDetectsRealGifImage(): void
    {
        $imagePath = $this->tempDir . '/test.gif';
        $image = imagecreatetruecolor(10, 10);
        imagegif($image, $imagePath);
        
        $client = $this->createClient();
        $mimeType = $this->callDetectMimeType($client, $imagePath);
        
        $this->assertSame('image/gif', $mimeType);
    }

    public function testRejectsPhpFileWithJpgExtension(): void
    {
        $fakeImagePath = $this->tempDir . '/malicious.jpg';
        file_put_contents($fakeImagePath, '<?php echo "evil code"; ?>');
        
        $client = $this->createClient();
        
        $this->expectException(OcrException::class);
        $this->expectExceptionCode(400);
        
        $this->callDetectMimeType($client, $fakeImagePath);
    }

    public function testRejectsTextFileWithPngExtension(): void
    {
        $fakeImagePath = $this->tempDir . '/fake.png';
        file_put_contents($fakeImagePath, 'This is just text, not an image');
        
        $client = $this->createClient();
        
        $this->expectException(OcrException::class);
        $this->expectExceptionCode(400);
        
        $this->callDetectMimeType($client, $fakeImagePath);
    }

    public function testRejectsHtmlFileWithJpegExtension(): void
    {
        $fakeImagePath = $this->tempDir . '/fake.jpeg';
        file_put_contents($fakeImagePath, '<html><body>Not an image</body></html>');
        
        $client = $this->createClient();
        
        $this->expectException(OcrException::class);
        $this->expectExceptionCode(400);
        
        $this->callDetectMimeType($client, $fakeImagePath);
    }

    public function testRejectsMismatchedExtensionAndMimeType(): void
    {
        $imagePath = $this->tempDir . '/real.png';
        $image = imagecreatetruecolor(10, 10);
        imagejpeg($image, $imagePath);
        
        $client = $this->createClient();
        
        $this->expectException(OcrException::class);
        $this->expectExceptionCode(400);
        
        $this->callDetectMimeType($client, $imagePath);
    }

    public function testRejectsUnsupportedMimeType(): void
    {
        $imagePath = $this->tempDir . '/test.bmp';
        $image = imagecreatetruecolor(10, 10);
        imagebmp($image, $imagePath);
        
        $client = $this->createClient();
        
        $this->expectException(OcrException::class);
        $this->expectExceptionCode(400);
        
        $this->callDetectMimeType($client, $imagePath);
    }

    public function testJpegWithJpgExtensionIsValid(): void
    {
        $imagePath = $this->tempDir . '/test.jpg';
        $image = imagecreatetruecolor(10, 10);
        imagejpeg($image, $imagePath);
        
        $client = $this->createClient();
        $mimeType = $this->callDetectMimeType($client, $imagePath);
        
        $this->assertSame('image/jpeg', $mimeType);
    }

    public function testJpegWithJpegExtensionIsValid(): void
    {
        $imagePath = $this->tempDir . '/test.jpeg';
        $image = imagecreatetruecolor(10, 10);
        imagejpeg($image, $imagePath);
        
        $client = $this->createClient();
        $mimeType = $this->callDetectMimeType($client, $imagePath);
        
        $this->assertSame('image/jpeg', $mimeType);
    }

    private function callDetectMimeTypeByExtension(OcrClient $client, string $imagePath): string
    {
        $reflection = new ReflectionClass($client);
        $method = $reflection->getMethod('detectMimeTypeByExtension');
        
        return $method->invoke($client, $imagePath);
    }

    public function testFallbackDetectsJpegByExtension(): void
    {
        $imagePath = $this->tempDir . '/test.jpg';
        file_put_contents($imagePath, 'fake content');
        
        $client = $this->createClient();
        $mimeType = $this->callDetectMimeTypeByExtension($client, $imagePath);
        
        $this->assertSame('image/jpeg', $mimeType);
    }

    public function testFallbackDetectsPngByExtension(): void
    {
        $imagePath = $this->tempDir . '/test.png';
        file_put_contents($imagePath, 'fake content');
        
        $client = $this->createClient();
        $mimeType = $this->callDetectMimeTypeByExtension($client, $imagePath);
        
        $this->assertSame('image/png', $mimeType);
    }

    public function testFallbackDetectsWebpByExtension(): void
    {
        $imagePath = $this->tempDir . '/test.webp';
        file_put_contents($imagePath, 'fake content');
        
        $client = $this->createClient();
        $mimeType = $this->callDetectMimeTypeByExtension($client, $imagePath);
        
        $this->assertSame('image/webp', $mimeType);
    }

    public function testFallbackDetectsGifByExtension(): void
    {
        $imagePath = $this->tempDir . '/test.gif';
        file_put_contents($imagePath, 'fake content');
        
        $client = $this->createClient();
        $mimeType = $this->callDetectMimeTypeByExtension($client, $imagePath);
        
        $this->assertSame('image/gif', $mimeType);
    }

    public function testFallbackRejectsUnsupportedExtension(): void
    {
        $imagePath = $this->tempDir . '/test.bmp';
        file_put_contents($imagePath, 'fake content');
        
        $client = $this->createClient();
        
        $this->expectException(OcrException::class);
        $this->expectExceptionCode(400);
        
        $this->callDetectMimeTypeByExtension($client, $imagePath);
    }

    public function testFallbackRejectsUnknownExtension(): void
    {
        $imagePath = $this->tempDir . '/test.xyz';
        file_put_contents($imagePath, 'fake content');
        
        $client = $this->createClient();
        
        $this->expectException(OcrException::class);
        $this->expectExceptionCode(400);
        
        $this->callDetectMimeTypeByExtension($client, $imagePath);
    }

    public function testFallbackHandlesJpegExtension(): void
    {
        $imagePath = $this->tempDir . '/test.jpeg';
        file_put_contents($imagePath, 'fake content');
        
        $client = $this->createClient();
        $mimeType = $this->callDetectMimeTypeByExtension($client, $imagePath);
        
        $this->assertSame('image/jpeg', $mimeType);
    }
}
