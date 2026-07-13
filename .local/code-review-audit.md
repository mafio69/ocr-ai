# Code Review Audit: OVH OCR

**Date:** 2026-07-13  
**Project:** OVH OCR (PHP library for Visual LLM OCR)  
**Reviewer:** opencode  
**Tests Status:** 41 passed, 0 deprecations (after fixes)

---

## Fixed Issues

### ✅ #1 Security: MIME type detection based on file extension - FIXED
**Location:** `src/OcrClient.php:357-413`

**Changes made:**
- Replaced extension-based detection with `finfo_file()` for actual MIME type detection
- Added validation that detected MIME type is in allowed list (image/jpeg, image/png, image/webp, image/gif)
- Added cross-validation: file extension must match detected MIME type
- Added fallback to extension-based detection if `finfo` fails (with warning log)
- Added `ext-fileinfo` requirement to `composer.json`
- Added comprehensive test suite (11 new tests) covering:
  - Real image detection (JPEG, PNG, WebP, GIF)
  - Rejection of PHP files with image extensions
  - Rejection of text/HTML files with image extensions
  - Rejection of mismatched extension/MIME type
  - Rejection of unsupported MIME types (e.g., BMP)
  - Valid JPEG with both .jpg and .jpeg extensions

**Bonus fixes:**
- Fixed `OcrException::$previous` type from `Exception` to `?Throwable` (PHP 8 convention)
- Removed deprecated `imagedestroy()` calls from tests (no-op since PHP 8.0)
- Removed deprecated `setAccessible()` call from tests (no-op since PHP 8.1)

---

### ✅ #2 Security: Google API key in URL query string - FIXED
**Location:** `src/OcrClient.php:261`

**Changes made:**
- Moved Google API key from query string parameter to `x-goog-api-key` header
- Prevents API key from appearing in access logs, proxy logs, and Guzzle history
- Changed from `'query' => ['key' => $this->googleApiKey]` to `'headers' => ['x-goog-api-key' => $this->googleApiKey]`

---

### ✅ #3 `file_get_contents()` can return `false` - FIXED
**Location:** `src/OcrClient.php:193, 258`

**Changes made:**
- Added error checking for `file_get_contents()` in both `tryOvhModel()` and `tryGoogleVision()`
- Throws `OcrException` with `errors.file_read_error` key when file cannot be read
- Added new i18n keys to both `pl.json` and `en.json`:
  - PL: "Nie udało się odczytać pliku - sprawdź uprawnienia 📂"
  - EN: "Failed to read file - check permissions 📂"
- Added comprehensive tests in `FileReadErrorTest.php` (3 new tests)
- Prevents race condition between file validation and file reading

---

### ✅ #4 Static mutable state in `OcrException::setTranslator()` - FIXED
**Location:** `src/Exceptions/OcrException.php:13,30`

**Changes made:**
- Removed `static ?Translator $translator` property from OcrException
- Removed `static setTranslator()` method
- Changed `getUserMessage()` to accept optional `?Translator $translator` parameter
- Updated `OcrClient::extractTextBatch()` to pass translator to `getUserMessage()`
- Updated `ErrorHandler` constructor to accept `Translator` parameter
- Updated `ErrorHandler::handleOcrException()` to pass translator to `getUserMessage()`
- Updated `ErrorHandler::handleGenericException()` to use translator for fallback message
- Updated `examples/example.php` to pass translator to `getUserMessage()`
- Updated all tests to pass translator explicitly

**Benefits:**
- No more global state - multiple OcrClient instances can use different translators
- No constructor side effects
- Clear dependency injection - translator is passed where needed
- Better testability - each test controls its own translator

---

### ✅ #18 Constructor side-effect: `OcrException::setTranslator()` - FIXED
**Location:** `src/OcrClient.php:91`

**Status:** ✅ FIXED - Removed `OcrException::setTranslator($translator)` call from OcrClient constructor. Translator is now passed explicitly where needed.

---

## Critical Issues (4)

### 1. ~~Security: MIME type detection based on file extension~~ - FIXED
**Location:** `src/OcrClient.php:357-413`

**Status:** ✅ FIXED - Now uses `finfo_file()` with MIME type validation and extension cross-check.

---

### 2. ~~Security: Google API key in URL query string~~ - FIXED
**Location:** `src/OcrClient.php:261`

**Status:** ✅ FIXED - API key now passed via `x-goog-api-key` header instead of query string.

---

### 3. ~~`file_get_contents()` can return `false`~~ - FIXED
**Location:** `src/OcrClient.php:193, 258`

**Status:** ✅ FIXED - Added error checking with proper exception handling and i18n support.

---

### 4. ~~Static mutable state in `OcrException::setTranslator()`~~ - FIXED
**Location:** `src/Exceptions/OcrException.php`

**Status:** ✅ FIXED - Removed static state, translator now passed explicitly to `getUserMessage()`.

---

### 2. Security: Google API key in URL query string
**Location:** `src/OcrClient.php:185`

`'query' => ['key' => $this->googleApiKey]` puts the key in the URL, which gets logged by proxies, servers, and Guzzle history.

**Recommendation:** Pass it as a header instead:
```php
'headers' => ['x-goog-api-key' => $this->googleApiKey],
```

---

### 3. `file_get_contents()` can return `false`
**Location:** `src/OcrClient.php:148, 181`

If the file becomes unreadable between validation and reading, `base64_encode(false)` emits a warning.

**Recommendation:** Add a check or use error handling:
```php
$content = file_get_contents($imagePath);
if ($content === false) {
    throw new OcrException('Failed to read file', 'errors.file_read_error');
}
$base64 = base64_encode($content);
```

---

### 4. Static mutable state in `OcrException::setTranslator()`
**Location:** `src/Exceptions/OcrException.php:14,28`

Global state means creating a second `OcrClient` with a different translator silently breaks the first one.

**Recommendation:** Consider injecting the translator into exceptions or using a service container instead of static state.

---

## High Priority Issues (6)

### 5. HTTP client is not injectable
**Location:** `src/OcrClient.php:90-93`

`new Client(...)` in the constructor makes it impossible to mock HTTP in integration tests.

**Recommendation:** Accept `Client` as an optional constructor parameter:
```php
public function __construct(
    string $apiKey,
    Logger $logger,
    Translator $translator,
    string $apiEndpoint = self::OVH_DEFAULT_ENDPOINT,
    array $modelMap = [],
    array $modelPriority = ['medium', 'premium', 'lite'],
    bool $googleEnabled = false,
    ?string $googleApiKey = null,
    ?Client $httpClient = null
) {
    $this->httpClient = $httpClient ?? new Client([...]);
}
```

---

### 6. `OcrException` fallback message is hardcoded in Polish
**Location:** `src/Exceptions/OcrException.php:40`

```php
return "Coś poszło nie tak. Spróbuj później 🤷";
```

This defeats i18n when no translator is set.

**Recommendation:** Use the fallback locale or a language-agnostic key.

---

### 7. `ErrorHandler` also hardcodes Polish
**Location:** `src/Error/ErrorHandler.php:37`

```php
$userMessage = $this->isDevelopment 
    ? $e->getMessage()
    : "Coś poszło nie tak... spróbuj później 🤷";
```

**Recommendation:** Inject and use the translator for consistent i18n.

---

### 8. `OcrException::$previous` type is `Exception` instead of `?Throwable`
**Location:** `src/Exceptions/OcrException.php:21`

PHP 8 convention is `?Throwable $previous`. This limits exception chaining.

**Recommendation:** Change signature:
```php
public function __construct(
    string $message,
    ?string $userMessageKey = null,
    array $context = [],
    array $userMessageParams = [],
    int $code = 0,
    ?Throwable $previous = null
)
```

---

### 9. No validation that `modelMap` has entries for `modelPriority` tiers
**Location:** `src/OcrClient.php:68-78`

If `modelPriority = ['premium']` but `modelMap` has no `premium` key, all models are skipped silently (only a warning is logged).

**Recommendation:** Throw early in the constructor:
```php
foreach ($this->modelStrategy as $tier) {
    if ($tier !== 'google_vision' && !isset($this->modelMap[$tier])) {
        throw new \InvalidArgumentException("Model map missing tier: {$tier}");
    }
}
```

---

### 10. `ErrorResponse::getHttpStatusCode()` maps `GOOGLE_API_ERROR` to 402
**Location:** `src/Error/ErrorResponse.php:62`

402 = "Payment Required". A Google API error should be 502 (Bad Gateway) or 503.

**Recommendation:** Fix the mapping:
```php
'GOOGLE_API_ERROR' => 502,
```

---

## Medium Priority Issues (8)

### 11. `OcrResponse::getLines()` uses `array_filter()` without callback
**Location:** `src/Response/OcrResponse.php:69`

This removes empty strings, `"0"`, `null`, `false` - not just blank lines.

**Recommendation:** Use explicit callback:
```php
return array_filter(explode("\n", $this->extractedText), fn($line) => $line !== '');
```

---

### 12. `temperature` and `max_tokens` are hardcoded
**Location:** `src/OcrClient.php:160-161`

**Recommendation:** Make configurable via constructor or method parameters.

---

### 13. `Logger` doesn't implement PSR-3
**Location:** `src/Logging/Logger.php`

Using `Psr\Log\LoggerInterface` would allow integration with Monolog and other standard tools.

**Recommendation:** Implement PSR-3 interface or document why custom logger is needed.

---

### 14. No log rotation
**Location:** `src/Logging/Logger.php`

The log file grows indefinitely.

**Recommendation:** Consider size-based rotation or at least documenting this limitation.

---

### 15. `Logger::getLogs()` reads entire file into memory
**Location:** `src/Logging/Logger.php:71-77`

`file()` loads the whole log. For large files, this is inefficient.

**Recommendation:** Use a seek-based approach or limit earlier.

---

### 16. Batch processing is sequential
**Location:** `src/OcrClient.php:124-136`

No parallel processing.

**Recommendation:** For large batches, consider `GuzzleHttp\Pool` or PHP 8.1+ fibers.

---

### 17. `buildOcrPrompt()` is hardcoded in Polish
**Location:** `src/OcrClient.php:206-216`

The OCR prompt itself is always Polish, even for English documents. This could affect extraction quality.

**Recommendation:** Make the prompt language-aware or fully English (more universal).

---

### 18. Constructor side-effect: `OcrException::setTranslator()`
**Location:** `src/OcrClient.php:88`

Constructors shouldn't have global side effects. This couples the client to the exception's static state.

**Recommendation:** Move this to a separate initialization method or use dependency injection.

---

## Low Priority Issues (7)

### 19. Properties could be `readonly`
PHP 8.1+ supports `readonly`, which fits most private properties set only in the constructor.

**Recommendation:** Add `readonly` modifier where appropriate.

---

### 20. `OcrResponse::saveToFile()` doesn't handle `file_put_contents` failure verbosely
Returns `bool` but doesn't throw or log on failure.

**Recommendation:** Throw exception or log error on failure.

---

### 21. `json_decode()` errors are not checked
**Location:** `src/OcrClient.php:155, 189`

If JSON is invalid, `json_decode` returns `null`, which is caught by the `!is_array()` check, but `json_last_error_msg()` would give better diagnostics.

**Recommendation:** Add error checking:
```php
$body = json_decode($response->getBody()->getContents(), true);
if (json_last_error() !== JSON_ERROR_NONE) {
    throw new OcrException('Invalid JSON: ' . json_last_error_msg(), 'errors.invalid_response');
}
```

---

### 22. `Translator::load()` overwrites existing locale data
Calling `load('pl', ...)` twice replaces the first set entirely instead of merging.

**Recommendation:** Document this behavior or implement merge logic if needed.

---

### 23. `LocaleLoader::loadAll()` doesn't handle `glob()` returning `false`
`glob()` returns `false` on error, which would cause `foreach` to fail.

**Recommendation:** Add check:
```php
$files = glob($this->localesPath . '/*.json');
if ($files === false) {
    throw new RuntimeException("Failed to scan locales directory");
}
foreach ($files as $file) { ... }
```

---

### 24. No interface for `OcrClient`
Consumers can't easily mock it in their tests.

**Recommendation:** Create `OcrClientInterface` for better testability.

---

### 25. `composer.lock` in `.gitignore`
For applications, `composer.lock` should be committed for reproducible builds. For libraries it's typically excluded, so this is acceptable but worth noting.

---

## Summary

| Severity | Count | Fixed | Remaining |
|----------|-------|-------|-----------|
| Critical | 4 | 4 | 0 |
| High | 6 | 0 | 6 |
| Medium | 8 | 0 | 8 |
| Low | 7 | 0 | 7 |
| **Total** | **25** | **4** | **21** |

---

## Top 3 Recommendations

1. ~~**Fix MIME type detection** - Use `finfo_file()` instead of extension-based detection~~ ✅ DONE
2. ~~**Move API keys to headers** - Never put secrets in URLs~~ ✅ DONE
3. **Make HTTP client injectable** - Enables proper testing and flexibility

---

## All P0 Issues - COMPLETED ✅

- ✅ #1 MIME type detection (finfo_file)
- ✅ #2 Google API key w headerze
- ✅ #3 file_get_contents() error handling
- ✅ #4 OcrException static translator
- ✅ #18 OcrClient constructor side-effect (powiązany z #4)

---

## Architectural Concerns

- **Static state in `OcrException`** - Will cause bugs in multi-client scenarios
- **Hardcoded messages in Polish** - Defeats the i18n system
- **No interfaces** - Makes testing and mocking difficult
- **Constructor side effects** - Violates single responsibility principle

---

## Positive Aspects

- Good use of modern PHP 8.1+ features (match expressions, named arguments, nullsafe operator)
- Comprehensive i18n system with fallback
- Well-structured error handling with user-facing and technical messages
- Good test coverage for core components (36 tests, 49 assertions)
- Clean separation of concerns (Client, Response, Exception, Logger, Translator)
- Proper use of PSR-4 autoloading
- Proper use of `finfo` for secure MIME type detection
- Comprehensive security tests for MIME type validation

---

**End of Audit**
