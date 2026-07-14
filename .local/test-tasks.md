# list: ocr-ai-add-testy
description: Testy dla projektu ocr-ai po code review - priorytety P1-P3

- [ ] ErrorHandlerTest - testy ErrorHandler (P1)
  Testy dla ErrorHandler: 1) handleOcrException() poprawnie tłumaczy komunikaty, 2) handleGenericException() używa translatora dla fallback message, 3) isDevelopment flag działa poprawnie
- [ ] Wiele instancji OcrClient z różnymi translatorami (P1)
  Utworzyć 2 klienty z różnymi translatorami (pl/en), sprawdzić czy każdy zwraca komunikaty w swoim języku - weryfikacja P0 #4 (brak static state)
- [ ] OcrClient.extractTextBatch() testy (P2)
  Testy batch processing: 1) poprawnie obsługuje błędy, 2) translator jest przekazywany do komunikatów błędów, 3) zwraca tablicę wyników
- [ ] OcrException.getUserMessage() bez translatora (P2)
  Testy: 1) getUserMessage() bez parametru zwraca klucz, 2) getUserMessage() z null zwraca klucz, 3) getUserMessage() bez klucza zwraca fallback message
- [ ] Google Vision header test (P3)
  Mock HTTP client i sprawdzenie czy header x-goog-api-key jest ustawiony, sprawdzenie czy query string NIE zawiera klucza - weryfikacja P0 #2
- [ ] MIME detection fallback test (P3)
  Test fallback do extension-based detection gdy finfo zawiedzie - trudne do zmockowania, można testować z nietypowym plikiem
