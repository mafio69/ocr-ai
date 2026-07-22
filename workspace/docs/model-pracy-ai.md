# Model pracy z AI — podział ról i zasada jakości

Dla każdego AI na tym repo (Claude Sonnet/Opus, opencode, inne). Nadrzędne: `EXECUTOR_RULES.md`
i „Trzy Przykazania" z globalnego CLAUDE.md.

## Podział pracy

- **Ważne zadania → Claude (Sonnet/Opus).** „Ważne" to nie zawsze „najtrudniejsze", ale w
  praktyce najczęściej trudne: projekt modelu danych, granice serwisów, integracje (Google,
  OCR), migracje, testy chroniące etap. Tu decyduje i pisze zaufany model.
- **Łatwe/mechaniczne zadania → opencode.** Powtarzalna implementacja pod gotowy kontrakt i
  gotowe testy, drobne poprawki, uzupełnienia w jednym pliku.
- **Nadzór → Claude.** Robotę opencode przegląda Claude (code review) przed uznaniem etapu za
  zrobiony. Handoff działa tylko, gdy zadanie ma jasny kontrakt i test, który mówi „gotowe".

## Zasada jakości: bez rozwiązań tymczasowych

- Wybieramy rozwiązanie **poprawne**, nawet jeśli trudniejsze — takie, które nie wymaga
  poprawek i jest gotowe na rozbudowę. Żadnych łatek „na teraz", które trzeba będzie cofać.
- **„Gotowe na rozbudowę" ≠ abstrakcja na zapas.** Rozbudowywalność = czyste granice, dobre
  nazwy, jedna odpowiedzialność, testy. NIE dodatkowe warstwy „gdyby kiedyś". Abstrakcję
  tworzymy dopiero przy drugiej realnej potrzebie (Przykazanie I, Zasada 4).
- Test poprawności: rozwiązanie jest dobre, jeśli (1) nie trzeba go poprawiać przy następnej
  zmianie i (2) dołożenie nowego przypadku to dopisanie, nie przeróbka.

## Handoff Claude → opencode (kontrakt)

1. Claude pisze test etapu (test-first) — czerwony, nie do oszukania (`docs/testy-protokol.md`).
2. Claude opisuje kontrakt zadania: sygnatury, wejście/wyjście, pliki do dotknięcia.
3. opencode implementuje do zielonego testu — nic poza kontraktem (Zasada 8: bez dokładania).
4. Claude robi review: styl, cienkie kontrolery, SOLID/DRY, brak rozwiązań tymczasowych.

## Granice bezpieczeństwa (dla każdego AI)

- Testy w `tests/**` zmienia się tylko za podwójną zgodą użytkownika (git hooki).
- Sekrety tylko w `.env` (poza repo). `vendor/` nietykalny. Baza robocza: zapis przez
  repozytoria/komendy, nie surowy SQL po kontrolerach.
