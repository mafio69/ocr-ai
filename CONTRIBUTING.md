# 🤝 Współpraca nad Projektem

Dziękuję za zainteresowanie współpracą! Poniżej znajduje się poradnik.

---

## 📌 Jak Rozpocząć?

1. **Fork** repozytorium
2. **Clone** swój fork
3. Stwórz nowy **branch**
4. Rób commity
5. **Push** do swojego forka
6. Wyślij **Pull Request**

```bash
# Fork na GitHub

# Clone
git clone https://github.com/TWOJA_NAZWA/ovh-ocr.git
cd ovh-ocr

# Branch
git checkout -b feature/moja-funkcja

# Edytuj pliki
# ...

# Commit
git add .
git commit -m "Add: nowa funkcja do OCR"

# Push
git push origin feature/moja-funkcja
```

---

## 📋 Wytyczne Commit

Używaj klarownych commitów:

```
Add:    dodana nowa funkcja
Fix:    napraw błędu
Docs:   dokumentacja
Style:  formatowanie kodu
Test:   testy
Refactor: zmiana struktury
```

**Przykład:**

```bash
git commit -m "Add: obsługa nowych formatów obrazów"
git commit -m "Fix: problem z fallbackiem Google"
git commit -m "Docs: zaktualizuj README"
```

---

## 🎯 Co Możesz Zrobić?

### Nowe Funkcje

- [ ] Nowe modele AI
- [ ] Nowe języki (i18n)
- [ ] Asynchroniczne przetwarzanie
- [ ] Webhook do notyfikacji
- [ ] Dashboard do monitorowania

### Bugfixy

- [ ] Zgłoszone problemy
- [ ] Edge cases
- [ ] Performance

### Dokumentacja

- [ ] Więcej przykładów
- [ ] Tutorial dla początkujących
- [ ] Wideo tutorial
- [ ] Tłumaczenie do innych języków

### Testy

- [ ] Unit testy
- [ ] Integration testy
- [ ] Performance testy

---

## 🧪 Testowanie Kodu

Zanim wyślesz PR, przetestuj:

```bash
# Instalacja dev dependencies
composer install

# Linting
composer lint

# Testy
composer test

# Code coverage
composer coverage
```

---

## 📝 Pull Request Checklist

Przed wysłaniem PR upewnij się:

- [ ] Kod jest czytelny i sformatowany
- [ ] Dodałeś/nowalas nowe testy
- [ ] Zaktualizowałeś dokumentację
- [ ] Commity mają klarowne wiadomości
- [ ] Nie ma konfliiktów merge
- [ ] Wszystkie testy przechodzą

---

## 📚 Struktura Projektu

```
ovh-ocr/
├── src/                    # Główny kod
│   ├── OcrClient.php
│   ├── Exceptions/
│   ├── Logging/
│   ├── Error/
│   ├── Response/
│   └── i18n/
├── resources/              # Zasoby (tłumaczenia)
│   └── locales/
├── examples/               # Przykłady użycia
├── tests/                  # Testy
├── composer.json
├── README.md
└── LICENSE
```

---

## 💡 Wytyczne Kodowania

### PHP Coding Standards

```php
<?php

namespace OvhOcr\Feature;

/**
 * Dokumentuj klasy
 */
class MyClass
{
    /**
     * Dokumentuj metody
     */
    public function myMethod(string $param): string
    {
        return $param;
    }
}
```

### PSR-12 Standard

- 4 spaces do indentacji
- Max 120 znaków w linii
- Docblocks do wszystkiego

---

## 🌍 Dodawanie Nowego Języka

1. Stwórz `resources/locales/XX.json`
2. Sklonuj `pl.json`
3. Przetłumacz wszystkie klucze
4. Dodaj test

```json
{
  "errors": {
    "file_not_found": "Your translation here"
  }
}
```

---

## 🐛 Raportowanie Bugów

**Stwórz Issue z:**

```
Tytuł:       [BUG] Krótki opis problemu

Opis:        Szczegółowy opis co się stało

Kroki:
1. Zrób X
2. Zrób Y
3. Bug się pojawia

Oczekiwany efekt: Co powinno się stać
Faktyczny efekt:  Co się stało

Environment:
- PHP: X.X.X
- OS: Windows/Linux/Mac
- Wersja biblioteki: 1.0.0
```

---

## 💬 Komunikacja

- **Issues** dla bugów i feature requests
- **Discussions** dla pytań i pomysłów
- **Pull Requests** dla zmian

Bądź miły i konstruktywny! 🙂

---

## 📜 Licencja

Wysyłając Pull Request, zgadzasz się że kod będzie pod MIT License.

---

## 🎓 Zasoby

- [Git Tutorial](https://git-scm.com/docs)
- [PHP Best Practices](https://www.php-fig.org/)
- [Our Code Style](CODING_STYLE.md)

---

**Dziękuję za współpracę! 💖**
