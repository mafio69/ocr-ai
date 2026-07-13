# TASK.md - Code Review Fixes

**Projekt:** mafio69/ovh-ocr
**Bazuje na:** `.local/code-review-audit.md` (opencode, 2026-07-13)
**Utworzono:** 2026-07-13

---

## Legenda

- [ ] TODO
- [x] DONE
- **P0** - Krytyczne, blokuje publikację
- **P1** - Wysoki priorytet, przed v1.0
- **P2** - Nice to have, może być w v1.1
- **P3** - Later, kolejne wersje

---

## FAZA A - Przed publikacją (P0)

### Bezpieczeństwo

- [ ] **[P0] #1 - MIME type z `finfo_file()` zamiast rozszerzenia**
  - Plik: `src/OcrClient.php:258-268`
  - Metoda: `detectMimeType()`
  - Zmiana: użyć `new \finfo(FILEINFO_MIME_TYPE)`
  - Uzasadnienie: rozszerzenie może kłamać

- [ ] **[P0] #2 - Google API key do headera zamiast query string**
  - Plik: `src/OcrClient.php:185`
  - Metoda: `tryGoogleVision()`
  - Zmiana: `headers: ['x-goog-api-key' => $this->googleApiKey]`
  - Uzasadnienie: klucz w URL trafia do access logów

- [ ] **[P0] #3 - Sprawdzić `file_get_contents()` na false**
  - Plik: `src/OcrClient.php:148, 181`
  - Zmiana: `if ($content === false) throw OcrException`
  - Nowy klucz i18n: `errors.file_read_error`
  - Uzasadnienie: race condition między validation a read

### Architektura

- [ ] **[P0] #4 + #18 - Wywalić static state z OcrException**
  - Pliki: `src/Exceptions/OcrException.php`, `src/OcrClient.php:88`
  - Zmiana: `Translator` wstrzykiwany do konstruktora `OcrException`, nie przez static setter
  - Alternatywa: `ErrorHandler` tłumaczy user message zamiast Exception
  - Uzasadnienie: static state = multi-client scenario się wywala

- [ ] **[P0] #5 - HTTP client injectable**
  - Plik: `src/OcrClient.php:90-93`
  - Zmiana: `?Client $httpClient = null` w konstruktorze
  - Uzasadnienie: bez tego nie da się testować integracyjnie

- [ ] **[P0] #9 - Walidacja modelMap w konstruktorze**
  - Plik: `src/OcrClient.php:68-78`
  - Zmiana: sprawdzać czy każdy tier z `modelPriority` ma wpis w `modelMap`
  - Rzuca: `\InvalidArgumentException`
  - Uzasadnienie: **fail fast zamiast ukrywania** - obecnie tylko warning w logu

### i18n

- [ ] **[P0] #6 - Fallback message w OcrException po angielsku**
  - Plik: `src/Exceptions/OcrException.php:40`
  - Zmiana: hardcoded PL → EN uniwersalne
  - Uzasadnienie: defeats i18n

- [ ] **[P0] #7 - ErrorHandler też po angielsku fallback**
  - Plik: `src/Error/ErrorHandler.php:37`
  - Zmiana: użyć translator zamiast hardcoded PL
  - Uzasadnienie: to samo co #6

- [ ] **[P0] #17 - Prompt OCR po angielsku**
  - Plik: `src/OcrClient.php:206-216`
  - Metoda: `buildOcrPrompt()`
  - Zmiana: prompt po angielsku (uniwersalny), zmienna tylko `$langLabel`
  - Uzasadnienie: PL prompt dla EN dokumentu = gorsza jakość

### PHP conventions

- [ ] **[P0] #8 - `?Throwable $previous` zamiast `Exception`**
  - Plik: `src/Exceptions/OcrException.php:21`
  - Zmiana: signature konstruktora
  - Uzasadnienie: PHP 8+ convention

### HTTP codes

- [ ] **[P0] #10 - GOOGLE_API_ERROR → 502 zamiast 402**
  - Plik: `src/Error/ErrorResponse.php:62`
  - Zmiana: `match` - `'GOOGLE_API_ERROR' => 502`
  - Uzasadnienie: 402 = "Payment Required" nie ma sensu, 502 = "Bad Gateway"

### Robustness

- [ ] **[P0] #21 - `json_decode()` error check**
  - Plik: `src/OcrClient.php:155, 189`
  - Zmiana: sprawdzać `json_last_error()`
  - Nowy klucz i18n: `errors.invalid_response`
  - Uzasadnienie: lepsze diagnostyki

- [ ] **[P0] #23 - `glob()` false check**
  - Plik: `src/i18n/LocaleLoader.php`
  - Zmiana: sprawdzać czy `glob()` nie zwrócił false
  - Uzasadnienie: edge case ale realny

---

## FAZA B - Przed v1.0 (P1)

- [ ] **[P1] #11 - `array_filter` z explicit callback**
  - Plik: `src/Response/OcrResponse.php:69`
  - Zmiana: `fn($line) => $line !== ''`
  - Uzasadnienie: "0" jako linia nie ma być usuwana

- [ ] **[P1] #19 - `readonly` properties**
  - Pliki: wszystkie klasy
  - Zmiana: dodać `readonly` gdzie właściwości są tylko w konstruktorze
  - Uzasadnienie: PHP 8.1+, immutability

- [ ] **[P1] #20 - `saveToFile` rzuca exception przy failure**
  - Plik: `src/Response/OcrResponse.php`
  - Zmiana: zamiast return false → throw
  - Uzasadnienie: cichy failure to złe UX

- [ ] **[P1] #24 - `OcrClientInterface`**
  - Nowy plik: `src/OcrClientInterface.php`
  - Zmiana: `OcrClient implements OcrClientInterface`
  - Uzasadnienie: testowalność w projektach usera

---

## FAZA C - Później (P2/P3)

- [ ] **[P2] #12 - `temperature`/`max_tokens` konfigurowalne**
  - Możliwość: parametr konstruktora `OcrClientConfig` lub metody
  - Uzasadnienie: elastyczność

- [ ] **[P2] #13 - PSR-3 Logger**
  - Plik: `src/Logging/Logger.php`
  - Zmiana: `implements Psr\Log\LoggerInterface`
  - Nowa zależność: `psr/log`
  - Uzasadnienie: interoperacja z Monolog itd.

- [ ] **[P2] #15 - Efficient `Logger::getLogs()`**
  - Zmiana: seek-based zamiast wczytywania całego pliku
  - Uzasadnienie: performance dla dużych logów

- [ ] **[P3] #14 - Log rotation**
  - Możliwość: udokumentować że biblioteka nie robi rotacji
  - Alternatywa: prosta implementacja size-based
  - Uzasadnienie: log rośnie w nieskończoność

- [ ] **[P3] #16 - Batch parallel processing**
  - Możliwość: `GuzzleHttp\Pool` albo PHP fibers
  - Uzasadnienie: dla dużych batchy

- [ ] **[P3] #22 - Dokumentacja `Translator::load()` overwrite**
  - Zmiana: docblock że load() nadpisuje
  - Uzasadnienie: znany behavior, tylko udokumentować

---

## Ignorowane (świadomie odrzucone)

- **#25** - `composer.lock` w gitignore - dla biblioteki OK, zostaje

---

## Kolejność wykonania (rekomendowana)

1. **Bezpieczeństwo najpierw:** #1, #2, #3
2. **Architektura:** #4/#18, #5, #9
3. **i18n:** #6, #7, #17
4. **Reszta P0:** #8, #10, #21, #23
5. **Test wszystkiego:** uruchomić `composer test` + `run_without_composer.php`
6. **P1 pakietem**
7. **Release v0.2.0** na GitHub + Packagist
8. **P2/P3 w kolejnych wersjach**

---

## Testy do dodania (przy okazji fixów)

- [ ] Test na injectable HTTP client (mock Guzzle) - dotyczy #5
- [ ] Test na walidację modelMap - dotyczy #9
- [ ] Test na fallback message bez translatora - dotyczy #6
- [ ] Test na MIME detection dla pliku z fałszywym rozszerzeniem - dotyczy #1
- [ ] Test na `file_get_contents` false - dotyczy #3

---

## Metryki progresu

| Faza | Zadań | Zrobione |
|------|-------|----------|
| A (P0) | 13 | 0 |
| B (P1) | 4 | 0 |
| C (P2/P3) | 6 | 0 |
| **Razem** | **23** | **0** |

---

## Notatki

- Review był **uczciwy i solidny** - nie ma tu sabotażu ani zmyślonych problemów
- Wszystkie punkty P0 są realnymi problemami
- Kilka Medium i Low to kwestia gustu (PSR-3, interfaces) - decyzja projektowa
- Po Fazie A biblioteka jest **produkcyjnie ready** - można publikować v0.2.0
- Faza B to polerowanie do v1.0
- Faza C to backlog rozwoju

---

**Aktualizuj ten plik oznaczając `[x]` przy zrobionych zadaniach.**
