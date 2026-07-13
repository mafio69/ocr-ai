<?php
/**
 * OVH OCR - Przykład użycia
 *
 * Uruchomienie:
 *   composer install
 *   cp .env.example .env    # i wypełnij OVH_AI_ENDPOINTS_ACCESS_TOKEN
 *   php examples/example.php examples/test.png
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;
use OvhOcr\OcrClient;
use OvhOcr\Logging\Logger;
use OvhOcr\i18n\Translator;
use OvhOcr\i18n\LocaleLoader;
use OvhOcr\Exceptions\OcrException;

// ============================================================
// 1. Ładuj .env (używa vlucas/phpdotenv - odporne na edge cases)
// ============================================================
$rootDir = __DIR__ . '/..';
if (!file_exists($rootDir . '/.env')) {
    fwrite(STDERR, "Brak pliku .env. Skopiuj .env.example i uzupełnij token OVH.\n");
    exit(1);
}
$dotenv = Dotenv::createImmutable($rootDir);
$dotenv->safeLoad();

// ============================================================
// 2. Pobierz ścieżkę do obrazu z argumentu CLI
// ============================================================
$imagePath = $argv[1] ?? ($rootDir . '/examples/test.png');
if (!file_exists($imagePath)) {
    fwrite(STDERR, "Nie znaleziono pliku: {$imagePath}\n");
    fwrite(STDERR, "Użycie: php examples/example.php ścieżka/do/obrazu.png\n");
    exit(1);
}

echo "=== OVH OCR ===\n";
echo "Plik: {$imagePath}\n";
echo "Rozmiar: " . round(filesize($imagePath) / 1024, 2) . " KB\n\n";

$translator = null;

try {
    // ============================================================
    // 3. Setup: i18n + logger + klient
    // ============================================================
    $translator = new Translator(
        $_ENV['APP_LOCALE'] ?? 'pl',
        $_ENV['FALLBACK_LOCALE'] ?? 'en'
    );
    $loader = new LocaleLoader($rootDir . '/resources/locales');
    $loader->loadAll($translator);

    $logger = new Logger(
        $rootDir . '/' . ($_ENV['LOG_FILE'] ?? 'storage/logs/ocr.log'),
        true
    );

    $modelMap = [
        'lite'    => $_ENV['OVH_MODEL_LITE']    ?? 'Qwen3.5-9B',
        'medium'  => $_ENV['OVH_MODEL_MEDIUM']  ?? 'Mistral-Small-3.2-24B-Instruct-2506',
        'premium' => $_ENV['OVH_MODEL_PREMIUM'] ?? 'Qwen3.6-27B',
    ];

    $priority = array_map('trim', explode(',', $_ENV['OCR_MODEL_PRIORITY'] ?? 'medium,premium,lite'));

    $client = new OcrClient(
        apiKey:        $_ENV['OVH_AI_ENDPOINTS_ACCESS_TOKEN'] ?? '',
        logger:        $logger,
        translator:    $translator,
        apiEndpoint:   $_ENV['OVH_AI_ENDPOINT'] ?? 'https://oai.endpoints.kepler.ai.cloud.ovh.net/v1/chat/completions',
        modelMap:      $modelMap,
        modelPriority: $priority,
        googleEnabled: ($_ENV['GOOGLE_VISION_ENABLED'] ?? 'false') === 'true',
        googleApiKey:  $_ENV['GOOGLE_API_KEY'] ?? null,
    );

    echo "Strategia: " . implode(' -> ', $client->getStrategy()) . "\n\n";

    // ============================================================
    // 4. Wydobywanie tekstu
    // ============================================================
    $response = $client->extractText($imagePath, $_ENV['APP_LOCALE'] ?? 'pl');

    echo "-----------------------------------\n";
    echo "Model użyty: " . $response->getUsedModel() . "\n";
    echo "Liczba znaków: " . mb_strlen($response->getText()) . "\n";
    echo "Liczba linii: " . count($response->getLines()) . "\n";
    echo "-----------------------------------\n\n";
    echo $response->getText() . "\n\n";

    // ============================================================
    // 5. Zapisz do pliku
    // ============================================================
    $outFile = $rootDir . '/storage/output.txt';
    if ($response->saveToFile($outFile)) {
        echo "Zapisano do: {$outFile}\n";
    }

} catch (OcrException $e) {
    fwrite(STDERR, "\nBLAD: " . $e->getUserMessage($translator) . "\n");
    fwrite(STDERR, "Szczegóły techniczne: " . $e->getMessage() . "\n");
    fwrite(STDERR, "Zobacz log: " . ($_ENV['LOG_FILE'] ?? 'storage/logs/ocr.log') . "\n");
    exit(2);
} catch (\InvalidArgumentException $e) {
    fwrite(STDERR, "\nBLAD KONFIGURACJI: " . $e->getMessage() . "\n");
    exit(3);
} catch (\Throwable $e) {
    fwrite(STDERR, "\nBLAD KRYTYCZNY: " . $e->getMessage() . "\n");
    fwrite(STDERR, $e->getTraceAsString() . "\n");
    exit(4);
}
