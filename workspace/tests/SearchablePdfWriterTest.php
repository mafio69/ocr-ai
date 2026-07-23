<?php

namespace OvhOcr\Tests;

use OvhOcr\Pdf\SearchablePdfWriter;
use PHPUnit\Framework\TestCase;
use Smalot\PdfParser\Parser as PdfParser;

/**
 * Real round-trip test: generate a searchable PDF, then use an independent PDF text
 * extraction library (smalot/pdfparser) to read it back and confirm the invisible text
 * layer is actually present and extractable - not just "a file got created".
 */
class SearchablePdfWriterTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/ocr_searchable_pdf_test_' . uniqid();
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

    private function createTestImage(): string
    {
        $imagePath = $this->tempDir . '/source.jpg';
        $image     = imagecreatetruecolor(400, 300);
        $white     = imagecolorallocate($image, 255, 255, 255);
        imagefill($image, 0, 0, $white);
        imagejpeg($image, $imagePath);

        return $imagePath;
    }

    /**
     * 400x300 (landscape) JPEG piksele, ale z flaga EXIF Orientation=6 (obroc 90 CW przed
     * wyswietleniem) - dokladnie tak, jak zapisuje pionowe zdjecie aparat w telefonie.
     * Wygenerowane raz (Pillow + piexif) i zaszyte jako staly fixture, zeby test nie
     * zalezal od zewnetrznej biblioteki do PISANIA EXIF-u w PHP (GD tego nie potrafi).
     */
    private const LANDSCAPE_PIXELS_WITH_ORIENTATION_6_JPEG_BASE64 = '/9j/4AAQSkZJRgABAQAAAQABAAD/4QAiRXhpZgAATU0AKgAAAAgAAQESAAMAAAABAAYAAAAAAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/2wBDAQkJCQwLDBgNDRgyIRwhMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjL/wAARCAEsAZADASIAAhEBAxEB/8QAHwAAAQUBAQEBAQEAAAAAAAAAAAECAwQFBgcICQoL/8QAtRAAAgEDAwIEAwUFBAQAAAF9AQIDAAQRBRIhMUEGE1FhByJxFDKBkaEII0KxwRVS0fAkM2JyggkKFhcYGRolJicoKSo0NTY3ODk6Q0RFRkdISUpTVFVWV1hZWmNkZWZnaGlqc3R1dnd4eXqDhIWGh4iJipKTlJWWl5iZmqKjpKWmp6ipqrKztLW2t7i5usLDxMXGx8jJytLT1NXW19jZ2uHi4+Tl5ufo6erx8vP09fb3+Pn6/8QAHwEAAwEBAQEBAQEBAQAAAAAAAAECAwQFBgcICQoL/8QAtREAAgECBAQDBAcFBAQAAQJ3AAECAxEEBSExBhJBUQdhcRMiMoEIFEKRobHBCSMzUvAVYnLRChYkNOEl8RcYGRomJygpKjU2Nzg5OkNERUZHSElKU1RVVldYWVpjZGVmZ2hpanN0dXZ3eHl6goOEhYaHiImKkpOUlZaXmJmaoqOkpaanqKmqsrO0tba3uLm6wsPExcbHyMnK0tPU1dbX2Nna4uPk5ebn6Onq8vP09fb3+Pn6/9oADAMBAAIRAxEAPwD3+iiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigD//2Q==';

    private function createLandscapePixelsPortraitIntentImage(): string
    {
        $imagePath = $this->tempDir . '/exif_source.jpg';
        file_put_contents($imagePath, base64_decode(self::LANDSCAPE_PIXELS_WITH_ORIENTATION_6_JPEG_BASE64));

        return $imagePath;
    }

    public function testWritesAValidPdfFile(): void
    {
        $imagePath  = $this->createTestImage();
        $outputPath = $this->tempDir . '/output.pdf';

        (new SearchablePdfWriter())->write($imagePath, 'Hello world', $outputPath);

        $this->assertFileExists($outputPath);
        $this->assertStringStartsWith('%PDF-', (string) file_get_contents($outputPath));
    }

    public function testExtractedTextIsPresentAndSearchableInTheResultingPdf(): void
    {
        $imagePath  = $this->createTestImage();
        $outputPath = $this->tempDir . '/output.pdf';
        $sourceText = 'Unikalny token do wyszukania: ' . bin2hex(random_bytes(6));

        (new SearchablePdfWriter())->write($imagePath, $sourceText, $outputPath);

        $parser = new PdfParser();
        $pdf    = $parser->parseFile($outputPath);

        $this->assertStringContainsString($sourceText, $pdf->getText());
    }

    public function testHandlesPolishDiacriticsInExtractedText(): void
    {
        $imagePath  = $this->createTestImage();
        $outputPath = $this->tempDir . '/output.pdf';
        $polishText = 'Zażółć gęślą jaźń - ąćęłńóśźż';

        (new SearchablePdfWriter())->write($imagePath, $polishText, $outputPath);

        $parser = new PdfParser();
        $pdf    = $parser->parseFile($outputPath);

        $this->assertStringContainsString('Zażółć gęślą jaźń', $pdf->getText());
    }

    public function testPageDimensionsMatchImageAspectRatio(): void
    {
        $imagePath  = $this->createTestImage(); // 400x300 -> aspect ratio 4:3
        $outputPath = $this->tempDir . '/output.pdf';

        (new SearchablePdfWriter())->write($imagePath, 'text', $outputPath);

        $parser  = new PdfParser();
        $pdf     = $parser->parseFile($outputPath);
        $details = $pdf->getPages()[0]->getDetails();

        $mediaBox   = $details['MediaBox'];
        $pageWidth  = $mediaBox[2] - $mediaBox[0];
        $pageHeight = $mediaBox[3] - $mediaBox[1];

        $this->assertEqualsWithDelta(400 / 300, $pageWidth / $pageHeight, 0.01);
    }

    /**
     * Zdjecia z telefonu pionowe "w zamierzeniu" czesto maja piksele zapisane POZIOMO
     * (400x300) plus flage EXIF Orientation=6 mowiaca viewerowi "obroc o 90 CW przed
     * pokazaniem". Zgloszony bug (2026-07-22, prawdziwy skan zaswiadczenia lekarskiego):
     * bez odczytania tej flagi finalny PDF mial obrazek na boku. Ten test potwierdza, ze
     * strona PDF ma teraz PROPORCJE PIONOWE (300 szerokosc / 400 wysokosc), czyli obraz
     * zostal fizycznie obrocony przed osadzeniem, a nie tylko "jakos" zapisany.
     */
    public function testRotatesImagePerExifOrientationBeforeEmbedding(): void
    {
        if (!function_exists('exif_read_data') || !function_exists('imagerotate')) {
            // trivial-check-allow: warunkowy skip - ext-exif/ext-gd sa suggested, nie hard, dependency (patrz composer.json).
            $this->markTestSkipped('ext-exif / ext-gd (imagerotate) niedostepne w tym srodowisku.');
        }

        $imagePath  = $this->createLandscapePixelsPortraitIntentImage();
        $outputPath = $this->tempDir . '/output_exif.pdf';

        (new SearchablePdfWriter())->write($imagePath, 'text', $outputPath);

        $parser  = new PdfParser();
        $pdf     = $parser->parseFile($outputPath);
        $details = $pdf->getPages()[0]->getDetails();

        $mediaBox   = $details['MediaBox'];
        $pageWidth  = $mediaBox[2] - $mediaBox[0];
        $pageHeight = $mediaBox[3] - $mediaBox[1];

        // Odwrotnie niz w testPageDimensionsMatchImageAspectRatio() - tu surowe piksele
        // sa 400x300 (4:3, poziomo), ale PO korekcie EXIF strona ma byc 300x400 (3:4, pionowo).
        $this->assertEqualsWithDelta(300 / 400, $pageWidth / $pageHeight, 0.01);
    }
}
