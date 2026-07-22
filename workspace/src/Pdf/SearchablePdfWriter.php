<?php

namespace OvhOcr\Pdf;

use Mpdf\Mpdf;
use Mpdf\Output\Destination;
use RuntimeException;

/**
 * Builds a "searchable PDF" (source image + an invisible, selectable text layer) from
 * already-extracted OCR text and the original image.
 *
 * v1 - deliberately simple, evolutionary first step (user decision, 2026-07): a single
 * invisible text block covering the whole page, not aligned word-by-word with the image.
 * Fully selectable/searchable in any PDF viewer, but a text selection won't highlight the
 * exact position of each word on the page. This works uniformly for both engines this
 * library supports:
 * - OVH (the default Visual LLM engine) returns plain text only, no position data at all,
 *   so word-level alignment isn't possible for it regardless of effort spent here.
 * - Google Vision *does* return per-word bounding boxes (`textAnnotations[1:]`), currently
 *   unused by this class. Precise alignment for Google Vision results specifically is a
 *   natural next evolutionary step if it's ever actually needed - not implemented now,
 *   per "add complexity at the second real need", not speculatively.
 *
 * Requires mpdf/mpdf, which is a *suggested*, not a hard, dependency of this library -
 * install it yourself if you want this feature: `composer require mpdf/mpdf`.
 */
class SearchablePdfWriter
{
    private const DPI = 96;

    /**
     * @throws RuntimeException If mpdf/mpdf isn't installed, the image can't be read, or
     *                           the PDF can't be written.
     */
    public function write(string $imagePath, string $extractedText, string $outputPath): void
    {
        if (!class_exists(Mpdf::class)) {
            throw new RuntimeException(
                'SearchablePdfWriter requires mpdf/mpdf, which is not installed. Run: composer require mpdf/mpdf',
            );
        }

        // Telefony zapisuja pionowe zdjecia z pikselami LEZACYMI POZIOMO + flaga EXIF
        // Orientation mowiaca viewerowi "obroc przed wyswietleniem". getimagesize()/
        // Mpdf::Image() ignoruja te flage, wiec bez tej korekty finalny PDF ma obrazek
        // na boku (zgloszone przez uzytkownika 2026-07-22, patrz przykladowy skan
        // zaswiadczenia lekarskiego). Zwraca sciezke do POPRAWIONEJ kopii (albo
        // oryginalna sciezke, jesli korekta nie byla potrzebna/mozliwa).
        [$normalizedPath, $tempCopy] = $this->normalizeOrientation($imagePath);

        try {
            $imageSize = @getimagesize($normalizedPath);
            if ($imageSize === false || $imageSize[0] <= 0 || $imageSize[1] <= 0) {
                throw new RuntimeException("Could not read image dimensions: {$imagePath}");
            }

            [$widthPx, $heightPx] = $imageSize;
            $this->render($normalizedPath, $widthPx, $heightPx, $extractedText, $outputPath);
        } finally {
            if ($tempCopy !== null && is_file($tempCopy)) {
                @unlink($tempCopy);
            }
        }
    }

    /**
     * Czyta flage EXIF "Orientation" (tylko JPEG realnie ja niesie) i jesli obraz wymaga
     * obrotu, fizycznie go obraca (GD) do tymczasowej kopii PNG. Zwraca [sciezka_do_uzycia,
     * sciezka_tymczasowa_do_posprzatania_albo_null].
     *
     * @return array{0: string, 1: ?string}
     */
    private function normalizeOrientation(string $imagePath): array
    {
        if (!function_exists('exif_read_data') || !function_exists('imagerotate')) {
            return [$imagePath, null];
        }

        $exif = @exif_read_data($imagePath);
        $orientation = is_array($exif) ? ($exif['Orientation'] ?? 1) : 1;

        // 1 = normalna orientacja, nic do zrobienia. 2/4/5/7 (lustrzane odbicia) celowo
        // nieobslugiwane - w praktyce telefony generuja niemal wylacznie 1/3/6/8.
        if (!in_array($orientation, [3, 6, 8], true)) {
            return [$imagePath, null];
        }

        $rawContents = @file_get_contents($imagePath);
        if ($rawContents === false) {
            return [$imagePath, null];
        }

        $image = @imagecreatefromstring($rawContents);
        if ($image === false) {
            return [$imagePath, null];
        }

        $angle = match ($orientation) {
            3 => 180,
            6 => -90,
            8 => 90,
            default => 0,
        };

        $rotated = imagerotate($image, $angle, 0);
        imagedestroy($image);

        if ($rotated === false) {
            return [$imagePath, null];
        }

        $tmpPath = tempnam(sys_get_temp_dir(), 'ocr_exif_fix_') . '.png';
        $written = imagepng($rotated, $tmpPath);
        imagedestroy($rotated);

        if (!$written) {
            @unlink($tmpPath);

            return [$imagePath, null];
        }

        return [$tmpPath, $tmpPath];
    }

    private function render(string $imagePath, int $widthPx, int $heightPx, string $extractedText, string $outputPath): void
    {

        // mPDF works in mm; treat the image as self::DPI dots per inch to size the PDF
        // page to the image's exact aspect ratio (matches how most tools interpret
        // raster images with no embedded DPI metadata).
        $widthMm  = $widthPx / self::DPI * 25.4;
        $heightMm = $heightPx / self::DPI * 25.4;

        $mpdf = new Mpdf([
            'format'        => [$widthMm, $heightMm],
            'margin_left'   => 0,
            'margin_right'  => 0,
            'margin_top'    => 0,
            'margin_bottom' => 0,
            'margin_header' => 0,
            'margin_footer' => 0,
        ]);

        // Root cause of the "content before %PDF- header" corruption below: Mpdf::Image()
        // does not call AddPage() itself - it assumes a page already exists and writes
        // straight to the content stream. AddPage() is what actually calls Open(), which
        // writes the "%PDF-1.x" preamble. Without an explicit AddPage() first, Image()'s
        // drawing operators (and SetAlpha()'s "/GS1 gs") get written to the stream before
        // the header exists at all, so every following byte offset - the whole xref table -
        // ends up shifted by however many bytes got written first. Confirmed against
        // vendor/mpdf/mpdf/src/Mpdf.php: AddPage() -> Open() -> writer->write('%PDF-...').
        $mpdf->AddPage();

        $mpdf->Image($imagePath, 0, 0, $widthMm, $heightMm);

        // Invisible text via 0 opacity: stays fully selectable/searchable in any PDF
        // viewer. Deliberately not using mPDF's SetVisibility('hidden') - that uses
        // Optional Content Groups (a togglable "layer"), which isn't guaranteed to be
        // included in every viewer's text search by default the way a plain 0-alpha
        // fill is.
        $mpdf->SetAlpha(0.0, 'Normal');
        $mpdf->WriteFixedPosHTML(
            '<div>' . nl2br(htmlspecialchars($extractedText, ENT_QUOTES, 'UTF-8')) . '</div>',
            0,
            0,
            $widthMm,
            $heightMm,
        );
        $mpdf->SetAlpha(1.0, 'Normal');

        // Deliberately not Output($outputPath, Destination::FILE): that path was observed
        // to prepend a stray raw content-stream fragment before the "%PDF-" header on this
        // mpdf/PHP combination, corrupting every byte offset in the xref table (readable by
        // some viewers via recovery heuristics, but rejected outright by strict parsers like
        // smalot/pdfparser - "Invalid object reference for $obj"). Getting the PDF back as a
        // string and writing it ourselves with a single, plain file_put_contents guarantees
        // the bytes on disk are exactly what mPDF generated, with nothing else in between.
        $pdfContents = $mpdf->Output('', Destination::STRING_RETURN);

        if (file_put_contents($outputPath, $pdfContents) === false) {
            throw new RuntimeException("Failed to write PDF to: {$outputPath}");
        }
    }
}
