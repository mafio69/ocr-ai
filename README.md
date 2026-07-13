# OVH OCR

Biblioteka PHP do wydobywania tekstu z obrazów przy pomocy **Visual LLM w OVH AI Endpoints** (Qwen, Mistral). Obsługuje strategię trzech modeli (tani → drogi) z automatycznym fallbackiem, opcjonalny fallback do Google Vision, i18n (PL/EN), pełne logowanie oraz obsługę błędów.

![PHP](https://img.shields.io/badge/PHP-8.1+-blue.svg)
![License](https://img.shields.io/badge/License-MIT-yellow.svg)

## Uczciwe zastrzeżenie

To nie jest klasyczny OCR jak Tesseract. To **Visual LLM** - modele multimodalne wywnioskują tekst z obrazu. Zalety: rozumieją kontekst, radzą sobie ze zniekształceniami, format wyjściowy jest zachowany. Wady: mogą **halucynować** (wymyślić tekst którego nie ma), są wolniejsze od Tesseracta, kosztują pieniądze za każde wywołanie.

Dla zrzutów ekranu, zdjęć dokumentów, memów - działa świetnie. Dla masowego skanowania faktur w produkcji - rozważ Tesseract albo Google Vision (kupujesz przewidywalność).

## Wymagania

- PHP 8.1+
- Rozszerzenia: `ext-json`, `ext-mbstring`
- Konto OVH Cloud z aktywnymi **AI Endpoints**
- Token OVH (`OVH_AI_ENDPOINTS_ACCESS_TOKEN`)

## Instalacja

```bash
composer require mafio69/ovh-ocr
```

## Szybki start

```bash
cp vendor/mafio69/ovh-ocr/.env.example .env
# wypełnij OVH_AI_ENDPOINTS_ACCESS_TOKEN swoim tokenem
```

```php
<?php
require 'vendor/autoload.php';

use Dotenv\Dotenv;
use OvhOcr\OcrClient;
use OvhOcr\Logging\Logger;
use OvhOcr\i18n\Translator;
use OvhOcr\i18n\LocaleLoader;
use OvhOcr\Exceptions\OcrException;

Dotenv::createImmutable(__DIR__)->safeLoad();

$translator = new Translator('pl', 'en');
$loader = new LocaleLoader(__DIR__ . '/vendor/mafio69/ovh-ocr/resources/locales');
$loader->loadAll($translator);

$logger = new Logger(__DIR__ . '/storage/logs/ocr.log', true);

$client = new OcrClient(
    apiKey:     $_ENV['OVH_AI_ENDPOINTS_ACCESS_TOKEN'],
    logger:     $logger,
    translator: $translator,
    modelMap:   [
        'lite'    => 'Qwen3.5-9B',
        'medium'  => 'Mistral-Small-3.2-24B-Instruct-2506',
        'premium' => 'Qwen3.6-27B',
    ],
    modelPriority: ['medium', 'premium', 'lite'],
);

try {
    $response = $client->extractText('screenshot.png', 'pl');
    echo $response->getText();
    $response->saveToFile('output.txt');
} catch (OcrException $e) {
    // Wiadomość przyjazna dla użytkownika (przetłumaczona)
    echo "Błąd: " . $e->getUserMessage();
    // Techniczne szczegóły są w logu
}
```

## Modele OVH

Wszystkie dostępne w OVH AI Endpoints. Ceny orientacyjne (sprawdź aktualne):

| Tier | Model | Input €/Mtok | Output €/Mtok |
|------|-------|--------------|---------------|
| lite | `Qwen3.5-9B` | 0.10 | 0.15 |
| medium | `Mistral-Small-3.2-24B-Instruct-2506` | 0.09 | 0.28 |
| premium | `Qwen3.6-27B` | 0.40 | 2.70 |

Sprawdź aktualny katalog: https://www.ovhcloud.com/en-gb/public-cloud/ai-endpoints/catalog/

**Dokładna dokumentacja techniczna endpointów, format request/response, autoryzacja, rate limits i testowanie manualne curl:** [docs/OVH_ENDPOINTS.md](docs/OVH_ENDPOINTS.md)

## Strategia modeli

Biblioteka próbuje modele po kolei według `modelPriority`. Jeśli pierwszy zwróci błąd (rate limit, timeout, coś padło) - próbuje następny. Jeśli wszystkie padną - rzuca `OcrException` z kluczem `errors.all_models_failed`.

Rekomendowana kolejność dla różnych scenariuszy:

- **Balans (default):** `medium, premium, lite` - zwykle Mistral wystarczy, premium tylko gdy trzeba
- **Oszczędny:** `lite, medium` - najpierw najtańszy
- **Maksymalna jakość:** `premium` - tylko Qwen3.6-27B (tryb Reasoning), bez fallbacku

## Google Vision fallback (opcjonalny)

Domyślnie **wyłączony**. Google Vision daje 1000 wywołań miesięcznie za darmo, powyżej ~1.50$ za 1000 wywołań.

```env
GOOGLE_VISION_ENABLED=true
GOOGLE_API_KEY=twoj-klucz-google
```

I dodaj `google_vision` do `OCR_MODEL_PRIORITY`. Jeśli `GOOGLE_VISION_ENABLED=true` bez klucza - konstruktor rzuci `InvalidArgumentException` od razu (nie ukrywa błędu).

## API

### `OcrClient::extractText(string $imagePath, string $language = 'pl'): OcrResponse`

Główna metoda. Ścieżka do pliku + język (`pl`, `en`).

### `OcrClient::extractTextBatch(array $imagePaths, string $language = 'pl'): array`

Batch - zwraca `[ścieżka => OcrResponse | ['error' => msg]]`.

### `OcrResponse`

- `getText(): string` - wydobyty tekst
- `getLines(): array` - podzielony na linie (bez pustych)
- `getParagraphs(): array` - podzielony na akapity
- `getUsedModel(): string` - który tier zadziałał (`lite`/`medium`/`premium`/`google_vision`)
- `saveToFile(string $path): bool` - zapis do pliku
- `toJson(): string` - JSON

### `OcrException`

- `getMessage(): string` - techniczne info (dla logów)
- `getUserMessage(): string` - przyjazny komunikat po polsku/angielsku (dla frontu)
- `getContext(): array` - dodatkowe dane (kod HTTP, model, plik)

## Testy

```bash
composer install
composer test
```

Testy pokrywają: Translator, Logger, OcrResponse, OcrException (parsowanie odpowiedzi OVH, parsowanie Google Vision, i18n, fallback języków, walidację). **Nie pokrywają realnych wywołań HTTP** - do tego potrzebny prawdziwy token OVH.

## Ograniczenia

- **Maks. rozmiar pliku:** 20 MB (limit OVH może być mniejszy - to nasz bezpieczny sufit)
- **Formaty:** JPG, PNG, WebP, GIF
- **Rate limit OVH:** 400 req/min per projekt (na tokenie); 2 req/min bez tokena
- **Nie testowałem produkcyjnie na wielotysięcznych partiach** - jeśli używasz w takim scenariuszu, dodaj retry z backoffem
- **Halucynacje LLM:** Visual LLM może dodać tekst którego nie ma na obrazie. Do skanowania dokumentów prawnych/medycznych **NIE polecam** - użyj klasycznego OCR z weryfikacją

## Licencja

MIT

## Dokumentacja dodatkowa

- **[docs/OVH_ENDPOINTS.md](docs/OVH_ENDPOINTS.md)** - Endpointy OVH, format request/response, autoryzacja, modele, rate limits, curl-e
- **[CONTRIBUTING.md](CONTRIBUTING.md)** - Jak wnieść wkład do projektu
- **[SETUP_GITHUB.md](SETUP_GITHUB.md)** - Publikacja na GitHub i Packagist

## Wkład

PR-y mile widziane. Zwłaszcza:
- Nowe języki (`resources/locales/*.json`)
- Nowe modele w OVH gdy się pojawią
- Testy z prawdziwymi obrazami (mock response)
# ocr-ai
