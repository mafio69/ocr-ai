<?php

namespace OvhOcr\Tests;

use OvhOcr\Image\ImagePreprocessor;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class ImagePreprocessorTest extends TestCase
{
    private function pngBytes(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, 10, 20, 30));
        ob_start();
        imagepng($image);
        $bytes = ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }

    public function testSmallImagePassesThroughUnchanged(): void
    {
        $preprocessor = new ImagePreprocessor(1600);
        $original = $this->pngBytes(200, 100);

        $result = $preprocessor->normalize($original);

        $this->assertSame('image/png', $result->mimeType);
        $size = getimagesizefromstring($result->data);
        $this->assertSame(200, $size[0]);
        $this->assertSame(100, $size[1]);
    }

    public function testOversizedImageIsResizedPreservingAspectRatio(): void
    {
        $preprocessor = new ImagePreprocessor(100);
        // 400x200 (2:1) - dluzszy bok (400) przekracza limit 100, wiec powinno zejsc do 100x50.
        $original = $this->pngBytes(400, 200);

        $result = $preprocessor->normalize($original);

        $size = getimagesizefromstring($result->data);
        $this->assertSame(100, $size[0]);
        $this->assertSame(50, $size[1]);
    }

    public function testExactlyAtLimitIsNotResized(): void
    {
        $preprocessor = new ImagePreprocessor(200);
        $original = $this->pngBytes(200, 150);

        $result = $preprocessor->normalize($original);

        $size = getimagesizefromstring($result->data);
        $this->assertSame(200, $size[0]);
        $this->assertSame(150, $size[1]);
    }

    /**
     * Nie da sie latwo spreparowac prawdziwych, poprawnych bajtow HEIC w tescie
     * jednostkowym bez prawdziwego pliku - ale w OBU mozliwych sciezkach (Imagick
     * niedostepny w ogole, albo Imagick dostepny ale dostaje smieci zamiast realnego HEIC)
     * normalize() musi rzucic RuntimeException, nie cichaczem zwrocic zla/pusta wartosc.
     */
    public function testHeicLikeHeaderWithoutRealDataThrowsRuntimeException(): void
    {
        $this->expectException(RuntimeException::class);

        $fakeHeic = "\x00\x00\x00\x18ftypheic" . str_repeat("\x00", 100);
        (new ImagePreprocessor())->normalize($fakeHeic);
    }

    public function testCompletelyUnrecognizedDataThrowsRuntimeException(): void
    {
        $this->expectException(RuntimeException::class);

        (new ImagePreprocessor())->normalize('to na pewno nie jest obrazek');
    }

    public function testRejectsNonPositiveMaxDimension(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ImagePreprocessor(0);
    }
}
