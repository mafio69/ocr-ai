# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

**OVH OCR** is a PHP library for extracting text from images using Visual LLM models from OVH AI Endpoints (Qwen, Mistral). It features:
- Multi-model fallback strategy with automatic retries (lite → medium → premium)
- Optional fallback to Google Vision API
- Internationalization support (Polish/English)
- Comprehensive logging and structured error handling
- Batch processing support

The library is **not** traditional OCR like Tesseract—it uses multimodal LLMs that can understand context and handle distorted text but may hallucinate content.

## Architecture

### Core Components

- **OcrClient** (`src/OcrClient.php`): Main entry point. Manages HTTP requests to OVH endpoints and Google Vision, handles retry strategy, orchestrates model fallback logic. Uses Guzzle for HTTP.
- **OcrResponse** (`src/Response/OcrResponse.php`): Encapsulates extracted text and metadata (which model succeeded, lines, paragraphs, JSON/file export).
- **OcrException** (`src/Exceptions/OcrException.php`): Custom exception with dual messaging (technical for logs, user-friendly translated messages for frontend).
- **Logger** (`src/Logging/Logger.php`): Structured logging to file with context arrays.
- **Translator** (`src/i18n/Translator.php`) + **LocaleLoader** (`src/i18n/LocaleLoader.php`): i18n system supporting PL/EN with JSON-based locale files in `resources/locales/`.
- **ErrorHandler** + **ErrorResponse** (`src/Error/`): Parse error responses from OVH and Google Vision APIs into structured exceptions.

### Model Strategy

The client accepts `modelMap` (tier → model name) and `modelPriority` (order to try). Example:
```php
modelMap: ['lite' => 'Qwen3.5-9B', 'medium' => 'Mistral-Small-3.2-24B', 'premium' => 'Qwen3.6-27B']
modelPriority: ['medium', 'premium', 'lite']
```

If a model fails (rate limit, timeout, etc.), the client retries the next one. If all fail, it throws `OcrException` with key `errors.all_models_failed`.

### Request/Response Format

OVH endpoint is OpenAI-compatible. Requests use `chat/completions` with multimodal content:
```json
{
  "model": "Qwen3.5-9B",
  "messages": [{
    "role": "user",
    "content": [
      {"type": "text", "text": "Extract text from image"},
      {"type": "image_url", "image_url": {"url": "data:image/png;base64,..."}}
    ]
  }]
}
```

See `docs/OVH_ENDPOINTS.md` for full technical details.

## Common Commands

```bash
# Install dependencies (includes dev)
composer install

# Run all tests
composer test

# Run a single test file
./vendor/bin/phpunit tests/LoggerTest.php

# Run a single test method
./vendor/bin/phpunit tests/TranslatorTest.php --filter=testTranslate

# Run tests with coverage report
composer test-coverage

# Note: No linting script in composer.json yet (CONTRIBUTING.md references "composer lint" but it's not defined)
```

## Development Workflow

### Adding a New Language

1. Create `resources/locales/XX.json` (where XX is language code, e.g., `de.json` for German)
2. Copy structure from `resources/locales/pl.json` and translate all values
3. Test by instantiating `Translator('xx', 'en')` and calling `loader->loadAll()`
4. Add unit test in `tests/`

### Adding a New Model

1. Update `modelMap` in client instantiation with `'tier' => 'ModelName'`
2. Reorder `modelPriority` as needed
3. Verify against `docs/OVH_ENDPOINTS.md` that the model exists in OVH catalog

### Commit Convention

Follow the style from `CONTRIBUTING.md`:
```
Add:      new feature
Fix:      bug fix
Docs:     documentation
Style:    formatting
Test:     test additions/changes
Refactor: structural changes
```

Example: `git commit -m "Add: batch processing retry with backoff"`

## Key Files & Responsibilities

- `src/OcrClient.php`: Core orchestration, request building, model fallback
- `src/Response/OcrResponse.php`: Response parsing, text extraction, formatting
- `src/Exceptions/OcrException.php`: Error normalization with i18n
- `src/Error/ErrorHandler.php`: Parses HTTP error responses into exception context
- `src/Logging/Logger.php`: File-based structured logging
- `src/i18n/Translator.php`, `LocaleLoader.php`: Translation system
- `resources/locales/`: JSON locale files for error messages and text
- `tests/`: Unit tests (Logger, Translator, Exception, Response)
- `examples/`: Integration examples (not auto-tested—requires real OVH token)
- `phpunit.xml`: Test configuration (bootstrap via composer autoload)

## Testing Notes

- **Covered**: Translator fallback, Logger formatting, OcrResponse parsing, OcrException messages, i18n edge cases
- **NOT covered**: Real HTTP calls to OVH or Google Vision (requires authentication)
- Tests use mocked JSON responses from `tests/` to simulate API behavior
- Bootstrap: `vendor/autoload.php` automatically loads PSR-4 classes
- To run specific test: `./vendor/bin/phpunit tests/YourTest.php --filter=methodName`

## Environment & Dependencies

- **PHP**: 8.1+ (typed properties, match expressions, nullsafe operator)
- **Core deps**: `guzzlehttp/guzzle` ^7.5 for HTTP
- **Dev deps**: `phpunit` ^10.0, `phpdotenv` ^5.5
- **.env setup**: Copy `.env.example`, fill `OVH_AI_ENDPOINTS_ACCESS_TOKEN` (and optionally `GOOGLE_API_KEY` if using Google fallback)

## Rate Limits & Constraints

- OVH: 400 req/min per project token; 2 req/min without token
- Max file size: 20 MB (library enforces this; OVH limit may be smaller)
- Supported formats: JPG, PNG, WebP, GIF
- Visual LLMs can hallucinate—not recommended for legal/medical docs without verification

## Known Limitations

- Batch processing has no built-in retry with backoff—add this if processing thousands of files
- No async support yet (all requests are blocking)
- Google Vision fallback is opt-in (disabled by default)
- Real HTTP testing requires OVH credentials

## Useful Resources

- [OVH AI Endpoints Documentation](docs/OVH_ENDPOINTS.md)
- [Contribution Guidelines](CONTRIBUTING.md)
- [Setup & Publishing to GitHub/Packagist](SETUP_GITHUB.md)
- [Main README](README.md) for user-facing documentation
