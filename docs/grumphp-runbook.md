# GrumPHP — runbook Kanban (wzorzec)

Pakiety już zainstalowane (`composer require --dev phpro/grumphp friendsofphp/php-cs-fixer phpstan/phpstan`).
Configi gotowe: `grumphp.yml`, `.php-cs-fixer.dist.php`, `phpstan.dist.neon`.

## Odpal po kolei

```bash
cd ~/PhpstormProjects/Kanban-mf

# 1. Sformatuj kod raz (php-cs-fixer naprawia styl)
vendor/bin/php-cs-fixer fix

# 2. Analiza statyczna — zobacz wynik
vendor/bin/phpstan analyse
#   Jesli sypie bledami w istniejacym kodzie:
#     vendor/bin/phpstan analyse --generate-baseline
#   potem odkomentuj w phpstan.dist.neon sekcje "includes: phpstan-baseline.neon"

# 3. Odpal caly zestaw GrumPHP recznie (przed wpieciem w commit)
vendor/bin/grumphp run

# 4. Wepnij GrumPHP w git hook (przejmuje pre-commit)
git config --unset core.hooksPath 2>/dev/null || true   # zdejmij nasze .githooks, GrumPHP przejmuje
vendor/bin/grumphp git:init

# 5. Test: sprobuj commita — GrumPHP odpali composer + audit + php-cs-fixer + phpstan
```

## Co gdzie pilnuje (stan przejsciowy)

- **GrumPHP (commit)**: format, analiza statyczna, audyt zaleznosci.
- **CI `.github/workflows/checks.yml`**: `secret-guard.sh` + `check-trivial-asserts.sh` (sekrety + trywialne, dokladny regex).
- Docelowo sekrety/trywialne przeniesiemy do `grumphp.yml` (`git_blacklist`), gdy potwierdzimy zachowanie regexpow u Ciebie.

## Commit

```bash
git add grumphp.yml .php-cs-fixer.dist.php phpstan.dist.neon composer.json composer.lock docs/grumphp-runbook.md
# + pliki, ktore php-cs-fixer przeformatowal
git commit -m "jakosc: GrumPHP (php-cs-fixer + phpstan + audit) jako pre-commit"
git push
```

## Uwaga

`git config --unset core.hooksPath` wylacza nasze `.githooks` (secret/trivial/ochrona testow) na rzecz GrumPHP.
Sekrety/trywialne dalej lapie CI. Ochrona `tests/**` (podwojna zgoda) na razie odpada — wrocimy do niej,
jesli bedziesz chcial ja odtworzyc jako task GrumPHP.
