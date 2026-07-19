<?php

declare(strict_types=1);

/**
 * OVH OCR - mini interfejs przeglądarkowy do ręcznego testowania.
 *
 * Wybierz obraz, wybierz silnik (który model OVH albo Google Vision), zobacz wynik OCR
 * i pobierz obraz jako "searchable PDF" (obraz + niewidzialna warstwa tekstu, patrz
 * OvhOcr\Pdf\SearchablePdfWriter). Nie jest to część biblioteki - to ręczne narzędzie
 * deweloperskie, nie ma go w src/, nie jest ładowane przez autoload.
 *
 * Uruchomienie:
 *   composer install
 *   cp .env.example .env    # i uzupełnij OVH_AI_ENDPOINTS_ACCESS_TOKEN (+ GOOGLE_API_KEY,
 *                            # jeśli chcesz też testować silnik Google Vision)
 *   php -S localhost:8000 -t examples
 *   -> http://localhost:8000/web-test.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;
use OvhOcr\Exceptions\OcrException;
use OvhOcr\i18n\LocaleLoader;
use OvhOcr\i18n\Translator;
use OvhOcr\Logging\Logger;
use OvhOcr\OcrClient;
use OvhOcr\Pdf\SearchablePdfWriter;

$rootDir = __DIR__ . '/..';
if (file_exists($rootDir . '/.env')) {
    Dotenv::createImmutable($rootDir)->safeLoad();
}

$outputDir = $rootDir . '/storage/web-test-output';
if (!is_dir($outputDir)) {
    mkdir($outputDir, 0755, true);
}

// ============================================================
// Download endpoint - only ever serves *.pdf from $outputDir, by basename only
// (no path traversal: basename() strips any directory component from the input).
// ============================================================
if (isset($_GET['download'])) {
    $safeName = basename((string) $_GET['download']);
    $path     = $outputDir . '/' . $safeName;

    if (!is_file($path) || pathinfo($path, PATHINFO_EXTENSION) !== 'pdf') {
        http_response_code(404);
        exit('Nie znaleziono pliku.');
    }

    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $safeName . '"');
    header('Content-Length: ' . (string) filesize($path));
    readfile($path);
    exit;
}

$engines = [
    'auto'    => 'Auto (medium -> premium -> lite)',
    'lite'    => 'Lite - Qwen3.5-9B (najtańszy)',
    'medium'  => 'Medium - Mistral-Small (domyślny)',
    'premium' => 'Premium - Qwen3.6-27B (najlepsza jakość)',
    'google'  => 'Google Vision (fallback, wymaga GOOGLE_API_KEY)',
];

$resultText     = null;
$usedModel      = null;
$pdfFile        = null;
$error          = null;
$selectedEngine = $_POST['engine'] ?? 'auto';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['image'])) {
    $uploadPath = null;

    try {
        if ($_FILES['image']['error'] !== UPLOAD_ERR_OK || $_FILES['image']['size'] === 0) {
            throw new \RuntimeException('Nie wybrano pliku albo upload się nie powiódł.');
        }

        $ext = strtolower(pathinfo((string) $_FILES['image']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) {
            throw new \RuntimeException("Niedozwolone rozszerzenie pliku: .{$ext} (dozwolone: jpg, jpeg, png, webp, gif)");
        }

        if (!isset($engines[$selectedEngine])) {
            throw new \RuntimeException("Nieznany silnik: {$selectedEngine}");
        }

        $uploadPath = $outputDir . '/upload_' . bin2hex(random_bytes(6)) . '.' . $ext;
        if (!move_uploaded_file($_FILES['image']['tmp_name'], $uploadPath)) {
            throw new \RuntimeException('Nie udało się zapisać przesłanego pliku na dysku.');
        }

        $apiKey = $_ENV['OVH_AI_ENDPOINTS_ACCESS_TOKEN'] ?? '';
        $locale = $_ENV['APP_LOCALE'] ?? 'pl';

        $translator = new Translator($locale, $_ENV['FALLBACK_LOCALE'] ?? 'en');
        (new LocaleLoader($rootDir . '/resources/locales'))->loadAll($translator);
        $logger = new Logger($rootDir . '/' . ($_ENV['LOG_FILE'] ?? 'storage/logs/ocr.log'), true);

        // modelMap/modelPriority intentionally omitted when possible: OcrClient::DEFAULT_MODEL_MAP
        // + the constructor's own default priority already cover "auto" with zero extra config.
        $clientArgs = [
            'apiKey'     => $apiKey,
            'logger'     => $logger,
            'translator' => $translator,
        ];

        if ($selectedEngine === 'google') {
            $googleCredentials = $_ENV['GOOGLE_APPLICATION_CREDENTIALS']
                    ?? $_ENV['VISION_LOGIN']
                    ?? (getenv('GOOGLE_APPLICATION_CREDENTIALS') ?: null)
                    ?? (getenv('VISION_LOGIN') ?: null)
                    ?? '';

            if (trim($googleCredentials) === '') {
                throw new \RuntimeException(
                    'Brak GOOGLE_APPLICATION_CREDENTIALS lub VISION_LOGIN - ustaw ścieżkę do pliku JSON konta usługi Google Vision.',
                );
            }

            if (!is_file($googleCredentials) || !is_readable($googleCredentials)) {
                throw new \RuntimeException(
                    'Plik credentials Google nie istnieje albo nie ma praw odczytu: ' . $googleCredentials,
                );
            }

            putenv('GOOGLE_APPLICATION_CREDENTIALS=' . $googleCredentials);

            $clientArgs['apiKey']        = $apiKey !== '' ? $apiKey : 'unused-google-only-request';
            $clientArgs['modelPriority'] = ['google_vision'];
            $clientArgs['googleEnabled'] = true;
        } else {
            if (trim($apiKey) === '') {
                throw new \RuntimeException('Brak OVH_AI_ENDPOINTS_ACCESS_TOKEN w .env.');
            }

            if ($selectedEngine !== 'auto') {
                $clientArgs['modelPriority'] = [$selectedEngine];
            }
        }


        $client   = new OcrClient(...$clientArgs);
        $response = $client->extractText($uploadPath, $locale);

        $resultText = $response->getText();
        $usedModel  = $response->getUsedModel();

        $pdfName = 'ocr_' . bin2hex(random_bytes(6)) . '.pdf';
        (new SearchablePdfWriter())->write($uploadPath, $resultText, $outputDir . '/' . $pdfName);
        $pdfFile = $pdfName;
    } catch (OcrException $e) {
        // $translator may not exist yet if the exception was thrown during upload/engine
        // validation, before it gets constructed below - falls back to the untranslated
        // message built into OcrException in that case (see getUserMessage()'s own default).
        $error = $e->getUserMessage($translator);
    } catch (\Throwable $e) {
        $error = $e->getMessage();
    } finally {
        if ($uploadPath !== null && is_file($uploadPath)) {
            @unlink($uploadPath); // raw upload isn't needed once OCR + PDF are done
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="UTF-8">
<title>OVH OCR - test w przeglądarce</title>
<style>
    body { font-family: system-ui, sans-serif; max-width: 720px; margin: 2rem auto; padding: 0 1rem; color: #1a1a1a; }
    h1 { font-size: 1.4rem; }
    form { border: 1px solid #ddd; border-radius: 8px; padding: 1.25rem; margin-bottom: 1.5rem; }
    label { display: block; margin-bottom: 0.4rem; font-weight: 600; }
    select, input[type=file] { width: 100%; padding: 0.4rem; margin-bottom: 1rem; box-sizing: border-box; }
    button { background: #2563eb; color: #fff; border: none; padding: 0.6rem 1.2rem; border-radius: 6px; cursor: pointer; font-size: 1rem; }
    button:hover { background: #1d4ed8; }
    .error { background: #fee2e2; border: 1px solid #fca5a5; color: #991b1b; padding: 0.75rem 1rem; border-radius: 6px; margin-bottom: 1.5rem; }
    .result { border: 1px solid #ddd; border-radius: 8px; padding: 1.25rem; }
    .result pre { white-space: pre-wrap; word-break: break-word; background: #f7f7f7; padding: 1rem; border-radius: 6px; }
    .meta { color: #666; font-size: 0.9rem; margin-bottom: 0.75rem; }
    .pdf-link { display: inline-block; margin-top: 0.75rem; background: #16a34a; color: #fff; padding: 0.5rem 1rem; border-radius: 6px; text-decoration: none; }
    .pdf-link:hover { background: #15803d; }
</style>
</head>
<body>

<h1>OVH OCR - test w przeglądarce</h1>

<?php if ($error !== null): ?>
    <div class="error"><strong>Błąd:</strong> <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

<form method="post" enctype="multipart/form-data">
    <label for="image">Obraz (jpg, jpeg, png, webp, gif)</label>
    <input type="file" id="image" name="image" accept=".jpg,.jpeg,.png,.webp,.gif" required>

    <label for="engine">Silnik</label>
    <select id="engine" name="engine">
        <?php foreach ($engines as $key => $label): ?>
            <option value="<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>" <?= $key === $selectedEngine ? 'selected' : '' ?>>
                <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
            </option>
        <?php endforeach; ?>
    </select>

    <button type="submit">Wyślij do OCR</button>
</form>

<?php if ($resultText !== null): ?>
    <div class="result">
        <div class="meta">Zadziałał model: <strong><?= htmlspecialchars($usedModel ?? '?', ENT_QUOTES, 'UTF-8') ?></strong></div>
        <pre><?= htmlspecialchars($resultText, ENT_QUOTES, 'UTF-8') ?></pre>
        <?php if ($pdfFile !== null): ?>
            <a class="pdf-link" href="?download=<?= urlencode($pdfFile) ?>">Pobierz jako searchable PDF</a>
        <?php endif; ?>
    </div>
<?php endif; ?>

</body>
</html>
