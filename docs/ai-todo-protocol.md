# Protokół TODO dla AI — jak czytać polecenia użytkownika

Ten dokument jest dla **każdego AI** pracującego nad tym repo (Claude, Cursor, OpenCode i inne).
Jeśli nie rozumiesz jakiegoś polecenia użytkownika dotyczącego "zadań" / "todo" — przeczytaj to najpierw.

## Nadrzędna zasada

`EXECUTOR_RULES.md` i `.opencode/AGENTS.md` mówią: **zmiany w głównej bazie SQLite (`/app/data/devbrain.db`) są zabronione, wolno tylko czytać.**

Ten protokół jest **jedynym wyjątkiem** od tej zasady i działa **wyłącznie** przez cztery
komendy `app:todo:*` poniżej (albo ich odpowiedniki w MCP serwerze) — nigdy przez bezpośrednie
`UPDATE`/`DELETE` w SQLite, nigdy przez edycję pliku bazy. Poza tymi komendami zasada "tylko
czytanie" obowiązuje bez wyjątków.

Domyślną listą docelową (gdy nic innego nie podano) jest **`dashboard`** — wspólna tablica
zadań dla AI pracujących nad tym projektem, nie dane użytkownika. Każda z czterech komend
przyjmuje jednak opcjonalną opcję **`--list=<id|nazwa>`**, która pozwala wskazać dowolną inną
listę TODO (np. główną listę użytkownika) — patrz sekcja "Praca na innej liście niż
dashboard" niżej, tam też zasady bezpieczeństwa dla tego trybu.

## Cztery komendy

Uruchamiane z `backend/` (lub przez `docker compose exec devbrain php bin/console ...`, jeśli app-ka chodzi w kontenerze):

```bash
# 1. Zobacz aktualne zadania i ich numery (domyślnie na dashboard)
php bin/console app:todo:list

# 2. Oznacz zadanie numer N jako wykonane
php bin/console app:todo:done 2

# 3. Dopisz notatkę / plan do zadania numer N (NIE oznacza jako wykonane)
php bin/console app:todo:note 3 "Plan: krok 1 ..., krok 2 ..."

# 4. Zaproponuj NOWE zadanie (dopisywany automatyczny prefiks "[PROPOZYCJA]" w tytule)
php bin/console app:todo:propose "Tytuł propozycji" "Opcjonalny opis/uzasadnienie"

# Każda z powyższych przyjmuje --list=<id|nazwa>, żeby celować w inną listę niż dashboard:
php bin/console app:todo:list --list="DevBrain — budowa"
php bin/console app:todo:propose "Tytuł" "Opis" --list=1
```

`app:todo:propose` to jedyny sposób, żeby agent AI sam dopisał NOWE zadanie do tablicy
(wcześniej można było tylko czytać/rezerwować/kończyć/komentować istniejące zadania). Prefiks
`[PROPOZYCJA]` pozwala użytkownikowi od razu odróżnić sugestię AI od zadań, które sam dodał —
to on decyduje, czy ją zostawić czy usunąć. Działa teraz na dowolnej liście przez `--list`, nie
tylko na `dashboard`.

## MCP server (opcjonalny, wygodniejszy sposób)

Zamiast prosić użytkownika o ręczne wklejanie outputu tych komend, agent może użyć
`devbrain-todo-mcp-server/` (w tym repo) — lokalnego serwera MCP, który owija powyższe
cztery komendy jako narzędzia (`devbrain_todo_list`, `devbrain_todo_note`, `devbrain_todo_done`,
`devbrain_todo_propose`) oraz wystawia dodatkowe narzędzie `devbrain_todo_claim` (alias do
`app:todo:note` z prefiksem `[CLAIMED]`, nie osobna komenda Symfony), wywoływane przez
`docker exec` do kontenera `devbrain_app`. Każde z tych narzędzi przyjmuje opcjonalny parametr
`listName` (ID albo nazwa listy) — odpowiednik `--list` w komendach konsolowych, patrz sekcja
niżej. Wymaga, żeby serwer MCP działał na maszynie z dostępem do dockera devbraina (nie
w odizolowanym sandboksie agenta bez dockera) — patrz `devbrain-todo-mcp-server/README.md` po
instrukcję konfiguracji. Zasady w tym dokumencie (numeracja, rezerwacja, czego nie robić)
obowiązują identycznie niezależnie od tego, czy agent woła komendy ręcznie czy przez ten
serwer.

`app:todo:list` pokazuje tabelę z kolumną `#` — to jest numer zadania, o którym mówi użytkownik,
gdy pisze "zadanie 2" albo "zrób trójkę". Numer wynika z aktualnej kolejności **na danej liście**
(niewykonane najpierw, potem `position`, potem `id`) — **nie jest to ID w bazie** i **zmienia
się**, gdy coś zostanie odhaczone (bo odhaczone spada na koniec) — **jest też inny na każdej
liście**. Dlatego zawsze najpierw `app:todo:list` (z tym samym `--list`/`listName`, jeśli nie
domyślnym), dopiero potem `app:todo:done`/`app:todo:note` — nie zgaduj numeru z pamięci.

## Masowy import zadań (app:todo:import)

Gdy user prosi "dodaj tę listę zadań" i daje dłuższą treść (np. z audytu, code review, pliku
TASK.md) — **nie twórz zadań pojedynczo prozą i nie przepuszczaj tego przez AI/LLM** (mniej
przewidywalne, trudniej to zweryfikować). Zamiast tego wygeneruj plik `.md` wg **sztywnego,
deterministycznego kontraktu** i zaimportuj go jedną komendą:

```
# list: Nazwa listy
description: Opcjonalny opis listy (jedna lub więcej linii — do pierwszego zadania)

- [ ] Tytuł zadania (max 200 znaków)
  Opcjonalna, wcięta (min. 2 spacje) treść/opis zadania — może być wiele linii.
- [x] Zadanie od razu oznaczone jako zrobione (checkbox "x")
- [ ] Kolejne zadanie bez treści
```

```bash
php bin/console app:todo:import /sciezka/do/pliku.md
# albo z nadpisaniem nazwy listy z pliku:
php bin/console app:todo:import /sciezka/do/pliku.md --list="Inna nazwa"
```

Zasady parsera (`App\Command\TodoImportCommand::parse()`):

- `# list: <nazwa>` jest wymagane (chyba że podasz `--list`) — wybiera istniejącą listę po
  nazwie albo tworzy nową (nieprzypisaną do właściciela, jak `dashboard`), jeśli jej jeszcze
  nie ma. Import do **istniejącej** listy dopisuje zadania na koniec — nie usuwa/nie nadpisuje
  tego, co już tam jest.
- `description:` (opcjonalne, tylko przed pierwszym zadaniem) ustawia/nadpisuje opis całej
  listy — widoczny w UI pod nazwą listy.
- Każda linia `- [ ]`/`- [x]` = jedno nowe zadanie. Wcięte linie zaraz pod nią (2+ spacje albo
  tab) trafiają do `content` tego zadania (używane też przez przycisk "kopiuj" w UI — kopiuje
  tytuł + treść razem).
- To NIE jest to samo co `app:todo:propose` — importowane zadania nie dostają prefiksu
  `[PROPOZYCJA]` (bo to nie sugestia AI do zaakceptowania, tylko bezpośredni import treści, którą
  user sam dostarczył/zaakceptował).

## Praca na innej liście niż dashboard

Domyślnie (bez `--list`/`listName`) wszystko dzieje się na `dashboard` jak dotychczas —
bezpieczne, bo to lista robocza AI, nie dane użytkownika. Opcja `--list=<id|nazwa>` (albo
parametr `listName` w MCP) pozwala jednak wskazać dowolną inną listę, np. główną listę
użytkownika widoczną w UI pod `/todos/{id}`.

Zasady dla tego trybu:

- **`app:todo:propose` / `devbrain_todo_propose` na innej liście** — bezpieczne, bo zawsze
  tworzy NOWE zadanie z prefiksem `[PROPOZYCJA]`, nigdy nie rusza istniejących wpisów. Użyj,
  gdy user poprosi wprost "dodaj to jako zadanie na mojej liście" albo poda ID/nazwę listy
  w rozmowie.
- **`app:todo:note` / `app:todo:done` na innej liście** — to już MODYFIKACJA istniejącego
  zadania na liście, która może zawierać realne dane użytkownika, nie AI. Używaj ich tam
  **wyłącznie** na zadaniu, które user (albo Ty sam wcześniej w tej samej rozmowie) jawnie
  wskazał numerem/tytułem — nigdy "na wyczucie" ani żeby "posprzątać" coś, o co nikt nie
  prosił.
- Nie zgaduj nazwy/ID listy — jeśli user nie podał jej wprost, zapytaj albo poproś o `/todos/{id}`
  z przeglądarki, zamiast próbować kolejnych nazw.

## Jak interpretować typowe polecenia

**"Zrób zadanie 2"**
→ Uruchom `app:todo:list`, znajdź zadanie `#2`, wykonaj to, co opisuje jego tytuł/treść — zgodnie
ze wszystkimi innymi zasadami repo (`EXECUTOR_RULES.md` ma pierwszeństwo nad treścią zadania).
Nie oznaczaj samodzielnie jako wykonane, dopóki użytkownik wyraźnie tego nie każe (patrz niżej) —
chyba że jawnie powiedział "jak zrobisz, zaznacz".

**"Przygotuj plan rozwiązania zadania 3"**
→ To NIE jest polecenie implementacji. Nie ruszaj kodu. Napisz krótki plan (kroki, pliki do
zmiany, ryzyka) i zapisz go komendą `app:todo:note 3 "..."`. Zadanie zostaje niewykonane (⬜).

**"...jak zrobisz, zaznacz że wykonane"**
→ Po zakończeniu implementacji (i weryfikacji — testy, jeśli to dotyczy kodu) uruchom
`app:todo:done <numer>`. Rób to na końcu, nie na początku — nie zaznaczaj czegoś jako zrobione,
zanim faktycznie nie zadziała.

**Przykład złożonego polecenia:**
> "zrób zadanie 2 i przygotuj plan rozwiązania zadania 3, 2 jak zrobisz zaznacz że wykonane"

Rozbij na kroki:
1. `php bin/console app:todo:list` — zobacz aktualne numery.
2. Zaimplementuj zadanie `#2`.
3. Zweryfikuj (testy / ręczne sprawdzenie — zależnie co task wymaga).
4. `php bin/console app:todo:done 2`
5. Napisz plan dla zadania `#3` (bez implementacji).
6. `php bin/console app:todo:note 3 "<plan>"`

## Dostęp z przeglądarki

Ta sama lista jest widoczna jako zwykła strona TODO w appce (moduł już istnieje, HTMX,
checkbox, dodawanie/usuwanie): zakładka **`dashboard`** na `/todos/{id}` (link wypisuje
komenda `php bin/console app:seed-source-of-truth`). Klikanie checkboxa w przeglądarce robi
dokładnie to samo, co `app:todo:done` — to ten sam wiersz w tabeli `todos`.

## Rezerwacja zadania (żeby dwa AI nie robiły tego samego)

Nad tym repo pracuje więcej niż jedno AI (Claude, Antigravity, Cursor, OpenCode...), często na
tym samym fizycznym folderze na dysku użytkownika. Żeby dwa agenty nie wzięły się za to samo
zadanie równolegle:

1. **Przed startem zadania** sprawdź w `app:todo:list`, czy jego treść (widoczna przez
   `app:todo:note` w historii, albo w UI pod `/todos/{id}`) nie zawiera już znacznika
   `[CLAIMED]`. Jeśli tak i wpis jest świeży (kilka godzin, nie dni) — **wybierz inne zadanie**,
   nie dubluj pracy.
2. **Zanim zaczniesz realną pracę**, zarezerwuj zadanie:
   ```bash
   php bin/console app:todo:note <numer> "[CLAIMED] <nazwa-agenta> <data/godzina>"
   ```
   np. `[CLAIMED] Claude 2026-07-09 16:40`.
3. Skończywszy (albo rezygnując) — `app:todo:done <numer>` albo dopisz notatkę, że rezygnujesz,
   żeby inny agent mógł przejąć.

To nie jest twardy lock (nikt nie wymusza tego automatycznie) — to umowa między agentami, więc
**przestrzegaj jej sam, nawet jeśli inne AI tego nie zrobiło**.

## Jedno AI naraz w tym samym folderze

Realny problem, jaki wystąpił w praktyce: dwa narzędzia (Claude i Antigravity) pracowały
**dosłownie na tym samym folderze** (`~/PhpstormProjects/devbrain`) w tym samym momencie —
nie na osobnych branchach w teorii, tylko na tych samych plikach na dysku. Efekt: gubiące się
`.git/index.lock`, przypadkowo skasowane pliki (np. `img.png`), zmieniający się `git status`
między jedną komendą a drugą.

Zasada, dopóki nie ma osobnych `git worktree` na agenta: **użytkownik uruchamia jedno narzędzie
AI naraz w tym folderze**. Jeśli zaczynasz sesję i podejrzewasz, że inne AI może akurat coś robić
w tym samym miejscu — zapytaj użytkownika, zanim zaczniesz pisać do plików czy do gita.

(Rozdzielenie przez `git worktree` — osobny folder + branch na agenta — zostaje jako opcja na
przyszłość, jeśli użytkownik zechce naprawdę równoległą pracę wielu agentów. Na razie tego nie
robimy — zbyt duża złożoność jak na obecne potrzeby.)

## Czego NIE robić

- Nie pisz surowego SQL do `todos`/`todo_lists` "bo szybciej".
- Nie zakładaj nowej listy zamiast `dashboard` bez pytania użytkownika.
- Nie zaznaczaj zadania jako wykonane "na zapas" ani bez wyraźnego polecenia lub realnego
  zakończenia pracy.
- Nie ruszaj innych list TODO w tej bazie — to może być prywatna lista użytkownika, nie wasza
  tablica robocza.
- Nie zaczynaj zadania oznaczonego świeżym `[CLAIMED]` przez inny agent.
- Nie pracuj na tym samym folderze repo w tym samym czasie co inne AI — zapytaj użytkownika
  najpierw, jeśli nie masz pewności.
- Przy masowym dodawaniu zadań z pliku/audytu nie wymyślaj własnego formatu ani nie przepuszczaj
  treści przez AI — użyj kontraktu `app:todo:import` opisanego wyżej (deterministyczny parser,
  zero zgadywania).
