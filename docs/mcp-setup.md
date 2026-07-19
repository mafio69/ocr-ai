# Jak podłączyć się do MCP DevBrain TODO (jedyny wspierany sposób)

Ten plik jest dla **każdego narzędzia/agenta AI** (Claude Code, opencode, Cursor, Antigravity,
Claude.ai...), które ma dostać dostęp do tablicy zadań DevBrain przez MCP. Jeśli konfigurujesz
to pierwszy raz albo coś nie działa — czytaj od góry, nie zgaduj.

## Jedna zasada

**Jest tylko jeden wspierany sposób: zdalny endpoint HTTP.** Lokalny serwer
(`devbrain-todo-mcp-server/`, stdio, `docker exec`) jest **celowo zablokowany**
(`runConsoleCommand()` zawsze zwraca błąd) — powód: bił w kontener na maszynie, na której akurat
działał, co nie musiało być tym samym devbrainem (tą samą bazą danych) co widoczny w
przeglądarce. Jeśli gdzieś zobaczysz config wskazujący na `dist/index.js` przez `docker exec` —
to jest zły config, zamień go na poniższy.

## Czego potrzebujesz

1. **URL:** `https://devbrain.virral.tech/mcp`
2. **Token:** wartość zmiennej `MCP_BEARER_TOKEN` z `.env` na serwerze devbraina — **poproś o nią
   użytkownika, nigdy nie zgaduj i nie wymyślaj.** To jeden, stały, wspólny sekret (nie ma osobnych
   tokenów per klient/agent) — kto go ma, ma pełny dostęp do odczytu i zapisu tablicy.
   - Uważaj przy kopiowaniu z terminala: żaden dodatkowy znak na końcu (np. `>`) nie należy do
     tokena — prawdziwy token to czysty ciąg hex, nic więcej.

## Szybki test (zanim będziesz konfigurować klienta)

```bash
curl -s https://devbrain.virral.tech/mcp \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer <TOKEN>" \
  -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}'
```

Poprawna odpowiedź: JSON z listą 6 narzędzi (patrz niżej). `401` = zły/pusty token. Jeśli dostajesz
`401` mimo poprawnego tokena — zapytaj użytkownika, czy `MCP_BEARER_TOKEN` jest w ogóle ustawiony
na serwerze (puste = endpoint zawsze odrzuca, to celowe, fail-closed).

## Konfiguracja klienta

### Claude Code (CLI)

```bash
claude mcp add --transport http devbrain-todo https://devbrain.virral.tech/mcp \
  --header "Authorization: Bearer <TOKEN>"
```

Albo ręcznie w `.mcp.json` / `~/.claude.json`:

```json
{
  "mcpServers": {
    "devbrain-todo": {
      "type": "http",
      "url": "https://devbrain.virral.tech/mcp",
      "headers": {
        "Authorization": "Bearer <TOKEN>"
      }
    }
  }
}
```

### opencode

```json
"devbrain-todo": {
  "type": "remote",
  "enabled": true,
  "url": "https://devbrain.virral.tech/mcp",
  "headers": {
    "Authorization": "Bearer <TOKEN>"
  }
}
```

**Nie** `"type": "local"` z `command`/`docker exec` — to stary, zablokowany config.

### Dowolny inny klient wspierający MCP-over-HTTP

Transport: JSON-RPC 2.0 przez HTTP POST, `Content-Type: application/json`, bez SSE/sesji
(każde zapytanie jest samodzielne). Nagłówek `Authorization: Bearer <TOKEN>` wymagany na
każdym żądaniu.

## Dostępne narzędzia (6)

| Narzędzie | Co robi | Wymagane argumenty |
|---|---|---|
| `devbrain_todo_list` | Wypisuje zadania z listy (domyślnie `dashboard-tmp`) | — |
| `devbrain_todo_claim` | Rezerwuje zadanie o numerze N (dopisuje `[CLAIMED] agent data`) | `number`, `agentName` |
| `devbrain_todo_note` | Dopisuje notatkę/plan do zadania N | `number`, `note` |
| `devbrain_todo_done` | Oznacza zadanie N jako wykonane | `number` |
| `devbrain_todo_propose` | Dodaje NOWE zadanie z prefiksem `[PROPOZYCJA]` | `title` |
| `devbrain_todo_approve` | Zdejmuje prefiks `[PROPOZYCJA]` z zadania N (zatwierdza) | `number` |

Każde narzędzie przyjmuje opcjonalny `listName` (ID albo dokładna nazwa listy) — bez niego
działa na domyślnej liście `dashboard-tmp`.

**Zanim użyjesz numeru (`number`) w jakimkolwiek narzędziu — zawsze najpierw wywołaj
`devbrain_todo_list` z tym samym `listName`.** Numer to pozycja w AKTUALNYM widoku tej
konkretnej listy (niewykonane najpierw), nie ID w bazie — zmienia się przy każdym `done` i jest
inny na każdej liście.

Pełne zasady zachowania (rezerwacja zadań, kiedy tworzyć `[PROPOZYCJA]`, czego nie robić na
cudzych listach) są w [`docs/ai-todo-protocol.md`](ai-todo-protocol.md) — przeczytaj go, zanim
zaczniesz cokolwiek zmieniać na tablicy, nie tylko podłączać klienta.

## Typowe błędy

- **401 Unauthorized** — zły token, pusty `MCP_BEARER_TOKEN` na serwerze, albo dodatkowy znak
  wklejony razem z tokenem (patrz sekcja "Czego potrzebujesz").
- **Narzędzie widoczne w `tools/list`, ale `tools/call` mówi "Nieznane narzedzie"** — literówka
  w nazwie narzędzia albo używasz starej wersji configu z inną listą narzędzi.
- **`devbrain_todo_list` pokazuje pustą/inną listę niż w przeglądarce** — sprawdź `listName`;
  bez niego trafiasz na `dashboard-tmp`, nie na listę widoczną domyślnie w UI.
- **Config wskazuje na `docker exec`/`dist/index.js`** — to zablokowany lokalny serwer, zamień
  na zdalny endpoint z tego pliku.
