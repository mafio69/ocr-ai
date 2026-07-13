# 🚀 Instrukcja: GitHub + Packagist

Ta instrukcja pokaże Ci jak **opublikować bibliotekę na GitHub i Packagist**, żeby było dostępne przez `composer require`.

---

## 📋 Wymagania

- Konto GitHub (darmowe)
- Konto Packagist (darmowe)
- Git zainstalowany na komputerze
- Terminal/CMD

---

## Część 1️⃣: GitHub - Tworzenie Repozytorium

### Krok 1: Zaloguj się na GitHub

Przejdź do https://github.com i zaloguj się (lub zarejestruj się)

### Krok 2: Utwórz nowe repozytorium

Kliknij **+** (górny prawy róg) → **New repository**

**Wypełnij formularz:**

```
Repository name: ovh-ocr
Description:    Biblioteka do ekstrakcji tekstu z obrazów - OVH AI + Google Vision fallback
Public:         ✓ (wybierz public)
Initialize:     ☐ Bez README (już mamy)
```

Kliknij **Create repository**

---

### Krok 3: Konfiguracja Git lokalnie

Otwórz terminal w folderze projektu i wykonaj:

```bash
# Inicjalizuj git (jeśli jeszcze nie zrobiony)
git init

# Dodaj wszystkie pliki
git add .

# Stwórz commit
git commit -m "Initial commit - OVH OCR library"

# Dodaj remote (zamień USERNAME na swoją nazwę GitHub)
git remote add origin https://github.com/USERNAME/ovh-ocr.git

# Zmień branch na main (jeśli trzeba)
git branch -M main

# Push do GitHub
git push -u origin main
```

**Wymaga hasła/tokenu GitHub:**
- Jeśli pyta o hasło → przejdź do: GitHub Settings → Developer settings → Personal access tokens → Generate new token
- Kopiuj token i wklej jako hasło

---

### Krok 4: Zweryfikuj na GitHub

Otwórz https://github.com/USERNAME/ovh-ocr - powinieneś zobaczyć wszystkie pliki

---

## Część 2️⃣: Packagist - Rejestracja Pakietu

### Krok 1: Zaloguj się na Packagist

Przejdź do https://packagist.org

Kliknij **Sign Up** (lub zaloguj się jeśli masz konto)

---

### Krok 2: Submit pakietu

Po zalogowaniu:
- Kliknij **Submit** (górne menu)
- W polu "Repository URL" wklej:
  ```
  https://github.com/USERNAME/ovh-ocr.git
  ```
- Kliknij **Check**
- Jeśli się załadował → **Submit**

---

### Krok 3: Zweryfikuj na Packagist

Jeśli widzisz pakiet na https://packagist.org/packages/USERNAME/ovh-ocr - super! ✓

---

## Część 3️⃣: Automatyczne Aktualizacje (GitHub Hook)

Aby Packagist automatycznie aktualizował się po pushu do GitHub:

### Krok 1: GitHub Hook

Na stronie pakietu na Packagist:
- **Account Settings** → **API Token** (kopiuj token)

### Krok 2: Webhook w GitHub

W repozytorium GitHub:
1. **Settings** → **Webhooks** → **Add webhook**
2. Payload URL:
   ```
   https://packagist.org/api/github?username=USERNAME
   ```
   (zamień USERNAME na swoją nazwę)

3. Content type: **application/json**
4. Która zdarzenia: **Just the push event**
5. **Add webhook**

---

## Używanie Biblioteki

Teraz każdy może zainstalować:

```bash
composer require username/ovh-ocr
```

Lub:

```bash
composer require mafio69/ovh-ocr
```

(jeśli zmienisz `name` w `composer.json` na `mafio69/ovh-ocr`)

---

## 🔄 Wersjonowanie (Semantic Versioning)

### Dodawanie wersji / Releases

Kiedy robisz update:

```bash
# Zmień wersję w composer.json (np. 1.0.0 → 1.0.1)
# Stwórz tag:

git tag v1.0.1
git push origin v1.0.1
```

Na GitHub:
- **Releases** → **Create a new release**
- Tag: `v1.0.1`
- Title: `Version 1.0.1`
- Description: Co się zmieniło
- **Publish release**

Packagist automatycznie uchwyci nową wersję.

---

## 📝 Versioning System (SemVer)

Używaj: `MAJOR.MINOR.PATCH`

```
v1.0.0 - Pierwsze release
v1.0.1 - Bugfix
v1.1.0 - Nowa funkcja (backward compatible)
v2.0.0 - Zmiana breaking API
```

---

## 🛠️ Ciągłe Aktualizowanie

Po każdym update:

```bash
# Zmień pliki
# ...

# Zaktualizuj wersję w composer.json
# "version": "1.0.1"

# Commit
git add .
git commit -m "Fix: typo in translator"

# Tag
git tag v1.0.1

# Push
git push origin main
git push origin v1.0.1
```

Packagist sprawdzi GitHub co godzinę.

---

## ⚠️ Problemy?

### "composer require nie widzi pakietu"
- Czekaj 5-10 minut na Packagist
- Odśwież stronę pakietu
- Sprawdź czy `composer.json` ma pole `"name"`

### "Webhook nie działa"
- Sprawdź GitHub → Settings → Webhooks
- Kliknij webhook → Recent Deliveries
- Czy status = 200?

### "Wersja nie mam na Packagist"
- Czekaj na update
- Na stronie pakietu → **Force Update**

---

## 🎉 Gotowe!

Twoja biblioteka jest teraz dostępna dla całego świata! 🚀

```bash
composer require mafio69/ovh-ocr
```

---

## 📚 Przydatne Linki

- GitHub Docs: https://docs.github.com/
- Packagist Docs: https://packagist.org/about
- Semantic Versioning: https://semver.org/
- Composer: https://getcomposer.org/

---

## 🤝 Polityka Open Source

**Best Practices:**

1. **Dokumentacja** - README zawsze aktualny
2. **Testy** - dodaj `phpunit` do testów
3. **Issues** - odpowiadaj na zgłoszenia
4. **PRs** - przyjmij PRs od innych developerów
5. **Licencja** - jasna licencja MIT

---

**Powodzenia! 🎊**
