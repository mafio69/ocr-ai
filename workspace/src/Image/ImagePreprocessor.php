<?php

namespace OvhOcr\Image;

use RuntimeException;

/**
 * Normalizes an uploaded image before it goes anywhere else in the pipeline (OCR request,
 * SearchablePdfWriter, or a caller app's own storage): converts HEIC/HEIF (iPhone default
 * format since iOS 11) to JPEG, and downscales anything larger than a configurable maximum
 * dimension, keeping the aspect ratio.
 *
 * Added 2026-07-23 per user request: phones/cameras increasingly send HEIC (which this
 * library's declared supported formats - JPG/PNG/WebP/GIF - never covered) and full-
 * resolution photos (user reported manually resizing to ~1000px in an external editor
 * before upload was the only way to avoid the app just forwarding oversized originals).
 *
 * HEIC/HEIF decoding requires ext-imagick with libheif support compiled in - this is a
 * *suggested*, not a hard, dependency (same pattern as ext-gd/ext-exif in
 * SearchablePdfWriter): if a HEIC file arrives and Imagick isn't available, this throws a
 * clear RuntimeException rather than silently failing or hallucinating a black image.
 * Resizing itself only needs ext-gd (already a suggested dependency), so JPEG/PNG/WebP/GIF
 * resizing works even without Imagick - only HEIC *decoding* needs it.
 */
final class ImagePreprocessor
{
    public const DEFAULT_MAX_DIMENSION = 1600;

    /** First 4 bytes are always "ftyp" preceded by a 4-byte box size; brand sits right after. */
    private const HEIC_BRANDS = ['heic', 'heix', 'hevc', 'hevx', 'heim', 'heis', 'hejm', 'hejs', 'mif1', 'msf1'];

    public function __construct(
        private readonly int $maxDimension = self::DEFAULT_MAX_DIMENSION,
    ) {
        if ($this->maxDimension < 1) {
            throw new \InvalidArgumentException('maxDimension musi byc dodatnie.');
        }
    }

    /**
     * @param string $imageData Raw bytes of the uploaded file.
     * @throws RuntimeException If the file is HEIC/HEIF but Imagick isn't available, or if
     *                           the (converted) image can't be decoded at all.
     */
    public function normalize(string $imageData): PreprocessedImage
    {
        $mimeType = $this->isHeic($imageData) ? null : $this->detectMimeType($imageData);

        if ($mimeType === null) {
            // Zarowno prawdziwy HEIC, jak i cokolwiek innego, czego GD nie rozpoznaje -
            // proba konwersji przez Imagick jest tu ostatnia deska ratunku, nie tylko
            // sciezka dla HEIC. Jesli Imagick tez nie da rady, throw z jasnym komunikatem.
            [$imageData, $mimeType] = $this->convertToJpegViaImagick($imageData);
        }

        $size = @getimagesizefromstring($imageData);
        if ($size === false || $size[0] <= 0 || $size[1] <= 0) {
            throw new RuntimeException('Nie mozna odczytac wymiarow obrazu po normalizacji.');
        }

        [$width, $height] = $size;

        if (max($width, $height) > $this->maxDimension) {
            [$imageData, $mimeType] = $this->resize($imageData, $mimeType, $width, $height);
        }

        return new PreprocessedImage($imageData, $mimeType);
    }

    /**
     * ISO base media file format box structure: [4 bytes size][4 bytes "ftyp"][4 bytes
     * major brand]... - HEIC/HEIF containers always start this way with one of a known
     * set of major brands. Cheap, no external deps needed just to detect.
     */
    private function isHeic(string $imageData): bool
    {
        if (strlen($imageData) < 12 || substr($imageData, 4, 4) !== 'ftyp') {
            return false;
        }

        return in_array(substr($imageData, 8, 4), self::HEIC_BRANDS, true);
    }

    /** @return string|null MIME type recognized by GD (JPEG/PNG/WebP/GIF), null if unrecognized. */
    private function detectMimeType(string $imageData): ?string
    {
        $size = @getimagesizefromstring($imageData);

        return $size !== false ? $size['mime'] : null;
    }

    /** @return array{0: string, 1: string} [jpeg bytes, 'image/jpeg'] */
    private function convertToJpegViaImagick(string $imageData): array
    {
        if (!class_exists(\Imagick::class)) {
            throw new RuntimeException(
                'Ten plik wymaga konwersji (HEIC/HEIF albo nierozpoznany format), a rozszerzenie '
                . 'Imagick (z obsluga libheif) nie jest zainstalowane. Zainstaluj ext-imagick, '
                . 'zbudowany z --with-heic, albo przekonwertuj plik recznie przed uploadem.',
            );
        }

        try {
            $imagick = new \Imagick();
            $imagick->readImageBlob($imageData);
            $imagick->setImageFormat('jpeg');
            $imagick->setImageCompressionQuality(90);
            $jpeg = $imagick->getImageBlob();
            $imagick->clear();
            $imagick->destroy();
        } catch (\ImagickException $e) {
            throw new RuntimeException('Nie udalo sie przekonwertowac obrazu przez Imagick: ' . $e->getMessage(), 0, $e);
        }

        return [$jpeg, 'image/jpeg'];
    }

    /** @return array{0: string, 1: string} [zresizowane bajty, mime] - mime bez zmian. */
    private function resize(string $imageData, string $mimeType, int $width, int $height): array
    {
        if (!function_exists('imagecreatefromstring')) {
            // ext-gd to suggested, nie hard, dependency - bez niego po prostu zwracamy
            // oryginal w pelnym rozmiarze zamiast rzucac wyjatkiem (resize to optymalizacja,
            // nie wymog funkcjonalny - w przeciwienstwie do dekodowania HEIC).
            return [$imageData, $mimeType];
        }

        $source = @imagecreatefromstring($imageData);
        if ($source === false) {
            throw new RuntimeException('Nie mozna zdekodowac obrazu do zmiany rozmiaru.');
        }

        $scale = $this->maxDimension / max($width, $height);
        $newWidth = max(1, (int) round($width * $scale));
        $newHeight = max(1, (int) round($height * $scale));

        $resized = imagecreatetruecolor($newWidth, $newHeight);

        // Zachowaj przezroczystosc dla PNG/GIF (bez tego przezroczyste tlo stawaloby sie czarne).
        if ($mimeType === 'image/png' || $mimeType === 'image/gif') {
            imagealphablending($resized, false);
            imagesavealpha($resized, true);
            $transparent = imagecolorallocatealpha($resized, 0, 0, 0, 127);
            imagefilledrectangle($resized, 0, 0, $newWidth, $newHeight, $transparent);
        }

        imagecopyresampled($resized, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
        imagedestroy($source);

        $encoded = $this->encode($resized, $mimeType);
        imagedestroy($resized);

        return [$encoded, $mimeType];
    }

    private function encode(\GdImage $image, string $mimeType): string
    {
        ob_start();
        $ok = match ($mimeType) {
            'image/png' => imagepng($image, null, 6),
            'image/gif' => imagegif($image),
            'image/webp' => function_exists('imagewebp') ? imagewebp($image, null, 90) : imagejpeg($image, null, 90),
            default => imagejpeg($image, null, 90),
        };
        $data = ob_get_clean();

        if ($ok === false || $data === false) {
            throw new RuntimeException('Nie udalo sie zakodowac zresizowanego obrazu.');
        }

        return $data;
    }
}
