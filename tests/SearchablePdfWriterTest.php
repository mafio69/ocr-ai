<?php

namespace OvhOcr\Tests;

use PHPUnit\Framework\TestCase;
use OvhOcr\Pdf\SearchablePdfWriter;
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
        $image = imagecreatetruecolor(400, 300);
        $white = imagecolorallocate($image, 255, 255, 255);
        imagefill($image, 0, 0, $white);
        imagejpeg($image, $imagePath);
        return $imagePath;
    }

    public function testWritesAValidPdfFile(): void
    {
        $imagePath = $this->createTestImage();
        $outputPath = $this->tempDir . '/output.pdf';

        (new SearchablePdfWriter())->write($imagePath, 'Hello world', $outputPath);

        $this->assertFileExists($outputPath);
        $this->assertStringStartsWith('%PDF-', (string) file_get_contents($outputPath));
    }

    public function testExtractedTextIsPresentAndSearchableInTheResultingPdf(): void
    {
        $imagePath = $this->createTestImage();
        $outputPath = $this->tempDir . '/output.pdf';
        $sourceText = 'Unikalny token do wyszukania: ' . bin2hex(random_bytes(6));

        (new SearchablePdfWriter())->write($imagePath, $sourceText, $outputPath);

        $parser = new PdfParser();
        $pdf = $parser->parseFile($outputPath);

        $this->assertStringContainsString($sourceText, $pdf->getText());
    }

    public function testHandlesPolishDiacriticsInExtractedText(): void
    {
        $imagePath = $this->createTestImage();
        $outputPath = $this->tempDir . '/output.pdf';
        $polishText = 'Zażółć gęślą jaźń - ąćęłńóśźż';

        (new SearchablePdfWriter())->write($imagePath, $polishText, $outputPath);

        $parser = new PdfParser();
        $pdf = $parser->parseFile($outputPath);

        $this->assertStringContainsString('Zażółć gęślą jaźń', $pdf->getText());
    }

    public function testPageDimensionsMatchImageAspectRatio(): void
    {
        $imagePath = $this->createTestImage(); // 400x300 -> aspect ratio 4:3
        $outputPath = $this->tempDir . '/output.pdf';

        (new SearchablePdfWriter())->write($imagePath, 'text', $outputPath);

        $parser = new PdfParser();
        $pdf = $parser->parseFile($outputPath);
        $details = $pdf->getPages()[0]->getDetails();

        $mediaBox = $details['MediaBox'];
        $pageWidth = $mediaBox[2] - $mediaBox[0];
        $pageHeight = $mediaBox[3] - $mediaBox[1];

        $this->assertEqualsWithDelta(400 / 300, $pageWidth / $pageHeight, 0.01);
    }
}
