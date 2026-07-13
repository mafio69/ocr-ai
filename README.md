# OVH OCR

PHP library for extracting text from images using Visual LLM in OVH AI Endpoints** (Qwen, Mistral). It supports a three-model strategy (cheap → road) with automatic fallback, optional fallback to Google Vision, i18n (PL/EN), full login and error handling.

! [PHP](https://img.shields.io/badge/PHP-8.1+-blue.svg)
! [License](https://img.shields.io/badge/License-MIT-yellow.svg)

## Fair Disclaimer

It's not a classic OCR like the Tesseract. This is a Visual LLM - multimodal models will infer text from an image. Advantages: they understand the context, they can deal with distortions, the output format is preserved. Cons: they can **hallucinate** (invent text that doesn't exist), they are slower than Tesseract, they cost money for each call.

For screenshots, photos of documents, memes - it works great. For mass scanning of invoices in production - consider Tesseract or Google Vision (you buy predictability).

## Requirements

- PHP 8.1+
- Extensions: 'ext-json', 'ext-mbstring'
- An OVH Cloud account with active AI Endpoints
- OVH token ('OVH_AI_ENDPOINTS_ACCESS_TOKEN')

## Installation

'''bash
Composer require mafio69/ovh-ocr
```

## Quick Start

'''bash
cp vendor/mafio69/ovh-ocr/.env.example .env
# fill OVH_AI_ENDPOINTS_ACCESS_TOKEN with your token
```

```php
<?php require 'vendor/autoload.php'; use Dotenv\Dotenv; use OvhOcr\OcrClient; use OvhOcr\Logging\Logger; use OvhOcr\i18n\Translator; use OvhOcr\i18n\LocaleLoader; use OvhOcr\Exceptions\OcrException; Dotenv::createImmutable(__DIR__)->safeLoad();

$translator = new Translator('en', 'en');
$loader = new LocaleLoader(__DIR__. '/vendor/mafio69/ovh-ocr/resources/locales');
$loader->loadAll($translator);

$logger = new Logger(__DIR__ . '/storage/logs/ocr.log', true);

$client = new OcrClient(
    apiKey: $_ENV['OVH_AI_ENDPOINTS_ACCESS_TOKEN'],
    Logger: $logger,
    translator: $translator,
    modelMap: [
        'lite' => 'Qwen3.5-9B',
        'medium' => 'Mistral-Small-3.2-24B-Instruct-2506',
        'premium' => 'Qwen3.6-27B',
    ],
    modelPriority: ['medium', 'premium', 'lite'],
);

try {
    $response = $client->extractText('screenshot.png', 'pl');
    echo $response->getText();
    $response->saveToFile('output.txt');
} catch (OcrException $e) {
    User-friendly message (translated)
    echo "Error: " . $e->getUserMessage();
    Technical details are in the log
}
```

## OVH models

All available in OVH AI Endpoints. Approximate prices (check the current ones):

| Tier | Model | Input €/Mtok | Output €/Mtok |
|------|-------|--------------|---------------|
| lite | 'Qwen3.5-9B' | 0.10 | 0.15 |
| medium | 'Mistral-Small-3.2-24B-Instruct-2506' | 0.09 | 0.28 |
| Premium | 'Qwen3.6-27B' | 0.40 | 2.70 |

Check the current catalog: https://www.ovhcloud.com/en-gb/public-cloud/ai-endpoints/catalog/

**Detailed technical documentation of endpoints, request/response format, authorization, rate limits, and manual curl testing:** [docs/OVH_ENDPOINTS.md](docs/OVH_ENDPOINTS.md)

## Model Strategy

The library tries models one by one according to 'modelPriority'. If the first one returns an error (rate limit, timeout, something failed) - the next one tries. If all of them fail - it throws an 'OcrException' with the key 'errors.all_models_failed'.

Recommended order for different scenarios:

- **Balance (default):** 'medium, premium, lite' - usually Mistral is enough, premium only when needed
- **Economical:** 'lite, medium' - cheapest first
- **Maximum Quality:** 'premium' - Qwen3.6-27B only (Reasoning mode), no fallback

## Google Vision fallback (optional)

By default, **off**. Google Vision gives you 1000 calls per month for free, over ~$1.50 per 1000 calls.

'''env
GOOGLE_VISION_ENABLED=true
GOOGLE_API_KEY=your-google-key
```

And add 'google_vision' to 'OCR_MODEL_PRIORITY'. If 'GOOGLE_VISION_ENABLED=true' without a key - the constructor will throw an 'InvalidArgumentException' immediately (it does not hide the error).

## API

### 'OcrClient::extractText(string $imagePath, string $language = 'pl'): OcrResponse'

The main method. File path + language ('en', 'en').

### 'OcrClient::extractTextBatch(array $imagePaths, string $language = 'pl'): array'

Batch - returns '[path => OcrResponse | ['error' => msg]]`.

### 'OcrResponse'

- 'getText(): string' - extracted text
- 'getLines(): array' - split into lines (no empty ones)
- 'getParagraphs(): array' - divided into paragraphs
- 'getUsedModel(): string' - which tier worked ('lite'/'medium'/'premium'/'google_vision')
- 'saveToFile(string $path): bool' - write to file
- 'toJson(): string' - JSON

### 'OcrException'

- 'getMessage(): string' - technical info (for logs)
- 'getUserMessage(): string' - friendly message in Polish/English (for front)
- 'getContext(): array' - additional data (HTTP code, model, file)

## Tests

'''bash
composer install
Composer Test
```

The tests cover: Translator, Logger, OcrResponse, OcrException (parsing OVH responses, parsing Google Vision, i18n, language fallback, validation). **They don't cover real HTTP calls** - you need a real OVH token for that.

## Limitations

- **Max. file size:** 20 MB (OVH limit may be smaller - this is our safe ceiling)
- **Formats:** JPG, PNG, WebP, GIF
- **Rate limit OVH:** 400 req/min per project (per token); 2 req/min without tokens
- **I haven't tested production on thousands of batches** - if you're using in such a scenario, add retros with backoff
- LLM Hallucinations:** Visual LLM can add text that is not in the image. For scanning legal/medical documents **NOT recommended** - use classic OCR with verification

## License

MYTH

## Additional documentation

- **[docs/OVH_ENDPOINTS.md](docs/OVH_ENDPOINTS.md)** - OVH endpoints, request/response format, authorization, models, rate limits, curl-e
- **[CONTRIBUTING.md](CONTRIBUTING.md)** - How to contribute to the project
- **[SETUP_GITHUB.md](SETUP_GITHUB.md)** - Publication on GitHub and Packagist

## Contribution

PR is welcome. In particular:
- New languages ('resources/locales/*.json')
- New models at OVH when they arrive
- Tests with real images (mock response)
# OCR-AI