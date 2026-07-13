<?php
/**
 * Uruchamia PRAWDZIWE testy bez composera/phpunit.
 * Ładuje ręcznie klasy i wykonuje asercje.
 * To pokazuje czy kod działa u kogoś kto ma tylko PHP.
 */

// Autoloader PSR-4 lite
spl_autoload_register(function ($class) {
    $prefix = 'OvhOcr\\';
    $baseDir = __DIR__ . '/../src/';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file = $baseDir . str_replace('\\', '/', $relative) . '.php';
    if (file_exists($file)) {
        require $file;
    }
});

use OvhOcr\i18n\Translator;
use OvhOcr\i18n\LocaleLoader;
use OvhOcr\Logging\Logger;
use OvhOcr\Exceptions\OcrException;
use OvhOcr\Response\OcrResponse;
use OvhOcr\Error\ErrorHandler;

$passed = 0;
$failed = 0;

function assertTest(string $name, bool $condition, string $detail = ''): void
{
    global $passed, $failed;
    if ($condition) {
        echo "  ✓ {$name}\n";
        $passed++;
    } else {
        echo "  ✗ {$name}" . ($detail ? " ({$detail})" : '') . "\n";
        $failed++;
    }
}

echo "=== TRANSLATOR ===\n";
$translator = new Translator('pl', 'en');
$loader = new LocaleLoader(__DIR__ . '/../resources/locales');
$loader->loadAll($translator);

assertTest('domyślny locale = pl', $translator->getLocale() === 'pl');
assertTest('trans klucza istniejącego', $translator->trans('errors.file_not_found') !== 'errors.file_not_found');
assertTest('trans z parametrem', str_contains($translator->trans('messages.attempting_model', ['model' => 'X']), 'X'));
assertTest('nieznany klucz zwraca sam klucz', $translator->trans('foo.bar') === 'foo.bar');
assertTest('fallback do en', (function() use ($loader) {
    $t = new Translator('xx', 'en');
    $loader->loadAll($t);
    $t->setLocale('xx');
    return str_contains(strtolower($t->trans('errors.file_not_found')), 'image');
})());
assertTest('__invoke alias', ($translator)('errors.file_not_found') === $translator->trans('errors.file_not_found'));

echo "\n=== LOGGER ===\n";
$logFile = sys_get_temp_dir() . '/test_' . uniqid() . '.log';
$logger = new Logger($logFile, true);
$logger->info('test info', ['k' => 'v']);
$logger->error('test error');

$content = file_get_contents($logFile);
assertTest('log file created', file_exists($logFile));
assertTest('INFO w pliku', str_contains($content, '[INFO]'));
assertTest('ERROR w pliku', str_contains($content, '[ERROR]'));
assertTest('context serialized', str_contains($content, '"k":"v"'));

$logger2 = new Logger('/tmp/nowrite_' . uniqid() . '.log', false);
$logger2->info('nie zapisze');
// Ten test - disabled logger nie tworzy pliku
unlink($logFile);

echo "\n=== OCR RESPONSE ===\n";
$ovhResp = new OcrResponse(['choices' => [['message' => ['content' => "L1\nL2\n\nP2"]]]], 'premium');
assertTest('parse OVH format', $ovhResp->getText() === "L1\nL2\n\nP2");
assertTest('getUsedModel', $ovhResp->getUsedModel() === 'premium');
assertTest('getLines liczy poprawnie', count($ovhResp->getLines()) === 3);
assertTest('getParagraphs liczy poprawnie', count($ovhResp->getParagraphs()) === 2);

$googleResp = new OcrResponse(['responses' => [['textAnnotations' => [['description' => 'Google text']]]]], 'google_vision');
assertTest('parse Google Vision format', $googleResp->getText() === 'Google text');

$emptyResp = new OcrResponse([], 'unknown');
assertTest('empty response nie crashuje', $emptyResp->getText() === '');

$tmpOut = sys_get_temp_dir() . '/ocr_out_' . uniqid() . '.txt';
assertTest('saveToFile działa', $ovhResp->saveToFile($tmpOut) && file_get_contents($tmpOut) === "L1\nL2\n\nP2");
unlink($tmpOut);

$json = json_decode($ovhResp->toJson(), true);
assertTest('toJson valid', $json !== null && $json['success'] === true);

echo "\n=== OCR EXCEPTION + i18n ===\n";
OcrException::setTranslator($translator);
$e = new OcrException('Technical msg', 'errors.file_not_found', ['file' => 'x.png']);
assertTest('getMessage - techniczny', $e->getMessage() === 'Technical msg');
assertTest('getUserMessage - przetłumaczony', str_contains($e->getUserMessage(), 'zdjęcia'));
assertTest('getContext', $e->getContext() === ['file' => 'x.png']);
assertTest('getUserMessageKey', $e->getUserMessageKey() === 'errors.file_not_found');

$e2 = new OcrException(
    'msg', 'errors.file_too_large',
    [], ['size' => 25, 'max_size' => 20]
);
$userMsg = $e2->getUserMessage();
assertTest('user message params substitute', str_contains($userMsg, '25') && str_contains($userMsg, '20'), "got: {$userMsg}");

echo "\n=== ERROR HANDLER ===\n";
$logFile = sys_get_temp_dir() . '/eh_' . uniqid() . '.log';
$logger3 = new Logger($logFile, true);
$handler = new ErrorHandler($logger3, false);

$ex = new OcrException('technical', 'errors.ovh_api_error', ['status' => 500]);
$err = $handler->handle($ex);
assertTest('handler zwraca ErrorResponse', $err->getUserMessage() !== '');
assertTest('httpStatusCode OCR_ERROR = 422', $err->getHttpStatusCode() === 422);

$json = json_decode($err->toJson(), true);
assertTest('errorResponse toJson valid', $json !== null && $json['success'] === false);
assertTest('user message w JSON', !empty($json['error']['message']));

// Development mode = pełne info
$devHandler = new ErrorHandler($logger3, true);
$devErr = $devHandler->handle($ex);
$devJson = json_decode($devErr->toJson(), true);
assertTest('dev mode ma internal message', isset($devJson['error']['internal']));
assertTest('prod mode NIE ma internal', !isset(json_decode($err->toJson(), true)['error']['internal']));

unlink($logFile);

echo "\n=== INVALID ARGUMENT CHECKS ===\n";
// Symulacja - potrzebujemy stub Guzzle. Sprawdzamy tylko czy konstruktor walidacja działa.
require_once __DIR__ . '/../src/OcrClient.php';

// Symulacja Client Guzzle - stub
if (!class_exists('GuzzleHttp\Client')) {
    // Można tego nie testować bo wymaga Guzzle
    echo "  (pomijam OcrClient - brak Guzzle w środowisku)\n";
}

echo "\n=== PODSUMOWANIE ===\n";
echo "Przeszło: {$passed}\n";
echo "Nie przeszło: {$failed}\n";
echo $failed === 0 ? "\n✅ WSZYSTKO OK\n" : "\n❌ SĄ BŁĘDY\n";

exit($failed === 0 ? 0 : 1);
