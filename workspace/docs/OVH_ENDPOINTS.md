# OVH AI Endpoints - Dokumentacja Techniczna

Wszystko co trzeba wiedzieć o integracji z OVH AI Endpoints przez tę bibliotekę. Dokument uzupełnia oficjalną dokumentację OVH informacjami praktycznymi.

## Źródła oficjalne

- **Katalog modeli:** https://www.ovhcloud.com/en-gb/public-cloud/ai-endpoints/catalog/
- **Dokumentacja OVH AI:** https://help.ovhcloud.com/csm/en-public-cloud-ai-endpoints
- **Cennik:** https://www.ovhcloud.com/en-gb/public-cloud/prices/

---

## 1. Endpoint

### Bazowy URL

```
https://oai.endpoints.kepler.ai.cloud.ovh.net/v1
```

### Endpointy używane przez bibliotekę

| Endpoint | Metoda | Cel |
|----------|--------|-----|
| `/v1/chat/completions` | POST | Chat + vision (multimodal) |

### Pełny URL do vision OCR

```
https://oai.endpoints.kepler.ai.cloud.ovh.net/v1/chat/completions
```

### Uwaga o kompatybilności

**API jest OpenAI-compatible.** Znaczy to, że format request/response jest zgodny z OpenAI Chat Completions API. W praktyce można używać oficjalnego SDK OpenAI zmieniając tylko `base_url`.

---

## 2. Autoryzacja

### Nagłówek

```
Authorization: Bearer <TWÓJ_TOKEN>
```

### Jak wygenerować token

1. Zaloguj się do OVH Cloud: https://www.ovh.com/manager/
2. **Public Cloud** → wybierz projekt (lub utwórz nowy)
3. Menu boczne: **AI & Machine Learning** → **AI Endpoints**
4. **Manage access tokens** (albo "Zarządzaj tokenami")
5. **Create a new token** - nadaj nazwę, wybierz projekt
6. Skopiuj token (pokazywany tylko raz!)

### Nazwa zmiennej środowiskowej

W tej bibliotece używamy:

```env
OVH_AI_ENDPOINTS_ACCESS_TOKEN=<twój-token>
```

Zgodne z oficjalną konwencją OVH.

### Bez tokena (anonimowo)

Możesz wywoływać endpoint bez tokena, ale:
- Rate limit **2 requesty/minutę** (praktycznie nieużyteczne)
- Nie można tego używać komercyjnie
- Zamiar: tylko do testów

---

## 3. Format Zapytania (Vision Multimodal)

### Struktura payload

```json
{
  "model": "<nazwa-modelu>",
  "messages": [
    {
      "role": "user",
      "content": [
        {
          "type": "text",
          "text": "Twoja instrukcja (prompt)"
        },
        {
          "type": "image_url",
          "image_url": {
            "url": "data:image/jpeg;base64,<BASE64_OBRAZU>"
          }
        }
      ]
    }
  ],
  "temperature": 0.1,
  "max_tokens": 8192
}
```

### Kluczowe rzeczy

- `content` **musi być tablicą** obiektów gdy używasz obrazu (nie string!)
- Kolejność w `content`: **najpierw tekst, potem obraz** (wg standardu OpenAI)
- `image_url.url` to **data URL** - format `data:<mime>;base64,<dane>`
- Obsługiwane mime: `image/jpeg`, `image/png`, `image/webp`, `image/gif`

### Parametry rekomendowane dla OCR

| Parametr | Wartość | Dlaczego |
|----------|---------|----------|
| `temperature` | `0.1` | Niska temperatura = mniej "kreatywności", bardziej dosłowne przepisywanie |
| `max_tokens` | `8192` | Wystarczy dla większości obrazów z gęstym tekstem |
| `top_p` | (nie ustawiaj) | Zostaw default |

**Nie ustawiaj `stream: true`** - biblioteka nie obsługuje streamingu.

### Przykład curl (surowy request)

```bash
IMAGE_BASE64=$(base64 -w 0 zrzut.png)

curl https://oai.endpoints.kepler.ai.cloud.ovh.net/v1/chat/completions \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $OVH_AI_ENDPOINTS_ACCESS_TOKEN" \
  -d "{
    \"model\": \"Qwen3.6-27B\",
    \"messages\": [{
      \"role\": \"user\",
      \"content\": [
        {\"type\": \"text\", \"text\": \"Wydobądź cały tekst z obrazu.\"},
        {\"type\": \"image_url\", \"image_url\": {\"url\": \"data:image/png;base64,$IMAGE_BASE64\"}}
      ]
    }],
    \"temperature\": 0.1,
    \"max_tokens\": 8192
  }"
```

---

## 4. Format Odpowiedzi

### Sukces (HTTP 200)

```json
{
  "id": "chatcmpl-xxx",
  "object": "chat.completion",
  "created": 1234567890,
  "model": "Qwen3.6-27B",
  "choices": [
    {
      "index": 0,
      "message": {
        "role": "assistant",
        "content": "Tutaj wydobyty tekst z obrazu..."
      },
      "finish_reason": "stop"
    }
  ],
  "usage": {
    "prompt_tokens": 1234,
    "completion_tokens": 567,
    "total_tokens": 1801
  }
}
```

### Wydobycie tekstu

Biblioteka odczytuje z:

```
choices[0].message.content
```

### Błąd (HTTP 4xx/5xx)

```json
{
  "error": {
    "message": "Opis błędu",
    "type": "invalid_request_error",
    "code": "..."
  }
}
```

### Typowe kody błędów

| HTTP | Znaczenie | Co robić |
|------|-----------|----------|
| 401 | Nieprawidłowy token | Sprawdź `OVH_AI_ENDPOINTS_ACCESS_TOKEN` |
| 403 | Brak dostępu do modelu | Model niedostępny w Twoim projekcie |
| 404 | Model nie istnieje | Sprawdź nazwę modelu w katalogu OVH |
| 413 | Payload za duży | Zmniejsz obraz |
| 429 | Rate limit | Odczekaj, wprowadź retry z backoffem |
| 500 | Błąd po stronie OVH | Fallback do innego modelu |
| 503 | Model niedostępny | Fallback do innego modelu |

---

## 5. Modele Visual LLM

Biblioteka używa modeli **multimodalnych** (przyjmują obrazy). Aktualny stan:

### Rekomendowane modele OCR

| Tier | Model | Params | Context | Input €/Mtok | Output €/Mtok |
|------|-------|--------|---------|--------------|---------------|
| **lite** | `Qwen3.5-9B` | 9.7B | 262K | 0.10 | 0.15 |
| **medium** | `Mistral-Small-3.2-24B-Instruct-2506` | 24B | 128K | 0.09 | 0.28 |
| **premium** | `Qwen3.6-27B` | 27B | 262K | 0.40 | 2.70 |

### Uwaga

Nazwy modeli **mogą się zmieniać**. Sprawdź aktualny katalog: https://www.ovhcloud.com/en-gb/public-cloud/ai-endpoints/catalog/

Nazwy w `.env.example` są aktualne na dzień publikacji tej biblioteki. Jeśli któryś model przestanie istnieć - dostaniesz HTTP 404 i biblioteka spróbuje kolejnego z listy.

### Który wybrać?

- **Szybko i tanio (screeny, memy):** `lite` (Qwen3.5-9B)
- **Balans (dokumenty, zrzuty):** `medium` (Mistral Small)
- **Maksymalna jakość (zniekształcone obrazy, ręczne pismo drukowane):** `premium` (Qwen3.6-27B, tryb Reasoning, 262K kontekstu)

### Modele NIE obsługujące obrazów

Tych **nie używaj** w tej bibliotece - są to LLM tekstowe:

- `Meta-Llama-3_3-70B-Instruct`
- `Meta-Llama-3_1-70B-Instruct`
- `Mixtral-8x22B-Instruct-v0.1`

Wywołanie ich z payloadem multimodal zwróci błąd.

---

## 6. Rate Limits

### Z tokenem

**400 requestów na minutę** per projekt OVH.

To dużo - dla większości zastosowań wystarczy. Ale jeśli masz batch tysięcy obrazów, wprowadź:
- Kolejkę (np. Symfony Messenger, Laravel Queue)
- Retry z exponential backoff przy HTTP 429
- Rozproszenie w czasie

### Bez tokena

2 requesty/minutę. Zapomnij.

### Jak biblioteka reaguje na 429

Obecnie: **nie retry-uje**, rzuca `OcrException` z `errors.ovh_api_error`. Jeśli w konfiguracji jest kolejny model - próbuje go. Jeśli nie - kończy z błędem.

Jeśli potrzebujesz retry, obwoluj wywołanie własną logiką lub zgłoś PR.

---

## 7. Koszty

### Jak liczone

OVH liczy w tokenach (jak OpenAI):
- **Input tokens** - tekst promptu + obraz (obraz = określona liczba tokenów zależna od rozmiaru)
- **Output tokens** - tekst wygenerowany

Obrazy są droższe niż tekst - jeden średni obraz ~500-2000 tokenów input.

### Przykładowy koszt

Zrzut ekranu 800x600, ~200 słów wydobytych, model `Mistral-Small-3.2-24B`:
- Input: ~1500 tokenów × 0.09€/M = **0.000135€**
- Output: ~300 tokenów × 0.28€/M = **0.000084€**
- **Razem: ~0.0002€ za obraz**

Czyli **5000 obrazów za 1€**. Tanio.

Dla modelu premium (Qwen3.6-27B) - input ~4x drożej, output ~10x drożej niż medium (Mistral). Dla tego samego przykładu:
- Input: ~1500 tokenów × 0.4€/M = **0.0006€**
- Output: ~300 tokenów × 2.7€/M = **0.00081€**
- **Razem: ~0.0014€ za obraz** - ok. 7x drożej niż medium, ale nadal ~700 obrazów za 1€.

### Kontrola kosztów

W panelu OVH: **Public Cloud → Billing → AI Endpoints**. Możesz ustawić alerty.

---

## 8. Praktyczne wskazówki

### Optymalizacja jakości OCR

1. **Zmniejsz obraz** przed wysłaniem - obrazy > 2000px szerokości nie dają lepszych wyników, tylko więcej tokenów
2. **Zwiększ kontrast** - dla słabych zdjęć telefonem
3. **Wytnij niepotrzebne** - jeśli tekst jest w rogu, przytnij
4. **Prompt** - biblioteka używa uniwersalnego, ale dla specyficznych przypadków (tabela, faktura) możesz zmodyfikować w `OcrClient::buildOcrPrompt()`

### Kiedy OVH Vision LLM się NIE nadaje

- **Masowy OCR faktur w produkcji** - użyj klasycznego OCR (Tesseract) z weryfikacją
- **Dokumenty prawne/medyczne** - halucynacje LLM są niedopuszczalne
- **Bardzo szybkie odpowiedzi (< 1s)** - LLM ma latency 2-10s
- **Rozpoznawanie pisma odręcznego** - słabe u wszystkich obecnych modeli

### Kiedy się nadaje

- Zrzuty ekranu, memy, screenshoty
- Zdjęcia dokumentów drukowanych (jeśli można zweryfikować)
- Wydobywanie tekstu z grafik marketingowych
- Konwersja slajdów prezentacji do tekstu
- OCR ad-hoc, nie produkcyjny

---

## 9. Testowanie manualne

Zanim zintegrujesz bibliotekę, przetestuj że sam endpoint działa:

```bash
# Prosty test - LLM tekstowy (bez obrazu)
curl https://oai.endpoints.kepler.ai.cloud.ovh.net/v1/chat/completions \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $OVH_AI_ENDPOINTS_ACCESS_TOKEN" \
  -d '{
    "model": "Meta-Llama-3_3-70B-Instruct",
    "messages": [{"role": "user", "content": "Powiedz hello po polsku"}]
  }'
```

Jeśli dostajesz odpowiedź - autoryzacja i endpoint działają. Teraz test vision:

```bash
# Test vision - z obrazem
IMAGE_BASE64=$(base64 -w 0 twoj_test.png)

curl https://oai.endpoints.kepler.ai.cloud.ovh.net/v1/chat/completions \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $OVH_AI_ENDPOINTS_ACCESS_TOKEN" \
  -d "{
    \"model\": \"Mistral-Small-3.2-24B-Instruct-2506\",
    \"messages\": [{
      \"role\": \"user\",
      \"content\": [
        {\"type\": \"text\", \"text\": \"Co jest na obrazie?\"},
        {\"type\": \"image_url\", \"image_url\": {\"url\": \"data:image/png;base64,$IMAGE_BASE64\"}}
      ]
    }]
  }"
```

Jeśli to działa - biblioteka też będzie działać.

---

## 10. FAQ

**Q: Model zwrócił tekst opakowany w markdown ```. Co robić?**  
A: Wystarczy usunąć wrappery po stronie kodu (dodaj do `OcrResponse::parseResponse()`). Możliwy PR.

**Q: Chcę streamować odpowiedź.**  
A: Biblioteka nie wspiera. Możesz dodać obsługę `stream: true` i SSE parser. PR mile widziany.

**Q: Czy można używać z Azure OpenAI / Anthropic zamiast OVH?**  
A: Nie tą biblioteką bezpośrednio - jest zbudowana pod endpoint OVH. Ale ponieważ format jest OpenAI-compatible, wystarczy zmienić `apiEndpoint` w konstruktorze - powinno działać z Azure OpenAI. Anthropic ma inny format i wymaga własnej implementacji.

**Q: Jaki jest max rozmiar obrazu?**  
A: Biblioteka ogranicza do 20 MB. OVH może mieć własny limit (nie znaleziono w dokumentacji na dzień pisania). W praktyce - zdjęcie z telefonu (5-10 MB) przechodzi.

**Q: Czy obraz może być URL zamiast base64?**  
A: OpenAI-compatible API pozwala na `"url": "https://..."` zamiast `"data:..."`. Biblioteka obecnie **nie używa** tego - wysyła zawsze base64. Zaleta base64: nie musisz hostować obrazów publicznie. Wada: 33% większy payload.

---

## 11. Linki

- Katalog modeli OVH: https://www.ovhcloud.com/en-gb/public-cloud/ai-endpoints/catalog/
- Dokumentacja: https://help.ovhcloud.com/csm/en-public-cloud-ai-endpoints
- Cennik: https://www.ovhcloud.com/en-gb/public-cloud/prices/
- Status OVH: https://www.status-ovhcloud.com/
- OpenAI Vision API (referencja formatu): https://platform.openai.com/docs/guides/vision
