# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Repo layout (read this first)

This repo is **mid-restructure** (see commit `cf69b53`, "przebudowa struktury repo do katalogu
workspace/", task #89 — not yet reviewed/finalized). The actual PHP library — source, tests,
composer.json, docs, CI configs referenced by scripts — now lives under **`workspace/`**, not at
the repo root:

```
ocr-ai/                      # repo root: container/infra layer only
├── docker-compose.yml       # app + nginx + postgres + redis stack
├── .devcontainer/           # Dockerfile, nginx.conf, php.ini, xdebug.ini
├── secrets/                 # docker secrets (db_user_password.txt) — see Safety below
├── postgres/init.sql
├── public/                  # nginx web root (mounted read-only into the nginx service)
└── workspace/                # <-- the actual OvhOcr PHP library; mounted as /workspace in the app container
    ├── CLAUDE.md            # detailed architecture/commands doc for the library itself
    ├── AGENTS.md            # safety rules for AI agents (secrets, permissions)
    ├── composer.json / vendor/ / src/ / tests/ / docs/ / examples/
    └── ...
```

**Always `cd workspace` before running composer/phpunit/php-cs-fixer/phpstan commands** — the
root has a stray `composer.lock` and `.php-cs-fixer.cache` left over from the move but no
`composer.json`, `src/`, or `vendor/` of its own.

The root `.github/workflows/ci.yml` still runs `composer install`, `scripts/secret-guard.sh`,
etc. against the repo root — those paths currently only exist under `workspace/`, so CI as
checked in is stale relative to this restructure. Don't assume root-level CI is green; check
`workspace/`'s own tooling instead (see below) and flag this mismatch if asked to fix CI.

**For the library's architecture, model-fallback strategy, request/response format, and full
command reference, read `workspace/CLAUDE.md` — do not duplicate that here.**

## Root-level (infra) commands

```bash
# Bring up the full dev stack (PHP app + nginx + postgres + redis)
docker compose up -d

# Rebuild the app image after Dockerfile/devcontainer changes
docker compose build app

# Tail logs
docker compose logs -f app
```

- `nginx` serves `./public` on host port 8082, proxying to the `app` container.
- `db` (postgres:17-alpine) and `redis` are healthchecked before `app` starts (`depends_on: condition: service_healthy`).
- Postgres password is a Docker secret at `secrets/db_user_password.txt` (gitignored, dev-only,
  rotate freely), injected via `POSTGRES_PASSWORD_FILE` — never read or print this file (see
  Safety below).
- `app` runs with `cap_drop: ALL` and **no** `cap_add` — it needs zero Linux capabilities under
  this network architecture. Don't add capabilities back without a specific reason.
- **Network isolation (this is the core security property of this repo, not incidental):**
  `frontend-net` and `backend-net` are both `internal: true` — `app` and `nginx` have **no
  direct route to the internet at all**. All outbound HTTP(S) from `app` goes through
  `egress-proxy` (squid, `.devcontainer/egress-proxy/`), which only allows a domain allowlist
  (OVH AI Endpoints, Google Vision, Perplexity, api.anthropic.com, GitHub, Packagist — see
  `squid.conf` to extend it). `nginx` gets its own `publish-net` (not internal) purely so Docker
  can publish port 8082 to the host — `nginx` doesn't hold secrets or run agent code, so this is
  an accepted, scoped exception; `app` is **not** on `publish-net`.
  - Known consequence: `host.docker.internal` is **not reachable from `app`** (no default route
    on an internal network) — this is intentional, not a bug. If something needs it, that's a
    reason to stop and reconsider the network design, not to silently add a route back.
  - Adding a new external host `app` needs to reach: edit the allowlist in
    `.devcontainer/egress-proxy/squid.conf`, then `docker compose build egress-proxy && docker
    compose up -d`.

## Working inside the library (`workspace/`)

```bash
cd workspace
composer install          # includes dev deps (phpunit, php-cs-fixer, phpstan, grumphp)
composer test              # trivial-assert gate + phpunit
composer cs-check          # php-cs-fixer, dry-run
composer cs-fix            # php-cs-fixer, applies fixes
composer audit             # composer security audit
composer all-checks        # cs-check + audit + test — same gate as CI
./vendor/bin/phpunit tests/LoggerTest.php                       # single file
./vendor/bin/phpunit tests/TranslatorTest.php --filter=testName # single method
./vendor/bin/phpunit --testsuite integration                    # integration suite (needs real credentials)
```

GrumPHP (`workspace/grumphp.yml`) runs php-cs-fixer, phpstan (level 5), a composer strict
check, and a blacklist for `var_dump()/dd()/dump()/console.log()` — treat these as pre-commit
gates, same as CI (`.github/workflows/ci.yml` inside `workspace/`, not the stale root one).

## Safety rules (apply repo-wide, not just `workspace/`)

This repo's actual purpose right now is being a template for a portable, hardened PhpStorm
devcontainer — the OCR library is secondary. The threat model: an AI coding agent (Claude Code,
opencode, Copilot, or anything else) running inside the `app` container must never be able to
read or exfiltrate real API credentials, even by accident. This is enforced in layers, in order
of how much you should actually trust each one:

1. **The real boundary: no real secret ever exists inside the bind-mounted repo tree.**
   `docker-compose.yml` mounts the whole repo root into `app` (`.:/workspace`), so anything
   physically present in this directory is readable by every process in the container,
   regardless of `.gitignore` or permission configs. The real, populated `.env` lives at
   `~/.config/ocr-ai/.env` on the host — **outside** this repo, never inside it. Only
   `.env.example` (placeholders) is allowed in-repo. If you ever find a populated `.env` inside
   this repo directory, treat it as an incident: move it back out immediately and flag it, don't
   just gitignore it in place.
   - Real API calls (manual smoke-testing against live OVH/Google Vision) happen **outside this
     devcontainer** — host PHP or a separate, disposable container — never inside the one
     Claude Code/PhpStorm Gateway runs in.
2. **The second boundary: network egress allowlist** (see above) — even if a secret somehow did
   leak into the container, there's nowhere to send it except the allowlisted API hosts.
3. **`.claude/settings.json` (`permissions.deny`) is UX/hygiene, not a real barrier.** It blocks
   Claude's `Read`/`Edit` tools and a few recognized Bash commands (`cat`, `head`, `tail`, `sed`,
   plus explicit `grep`/`less`/`strings`/etc. rules here) from touching `.env*`/`secrets/**`.
   It does **not** stop an arbitrary subprocess that opens the file itself (`php -r
   "readfile(...)"`, a Python one-liner, an unrecognized command). Don't add more Bash deny
   patterns expecting this to become a real sandbox — it can't be, by design of how Bash pattern
   matching works. If you need OS-level enforcement, that's boundary #1, not this.
   `workspace/opencode.json` has an equivalent, older deny-config for opencode specifically (with
   a known bug where subagents can bypass its `read`/`grep` deny) — keep both in sync if you
   change one.
- **Never read, print, or edit secrets or `.env*` files**, even placeholders that look real.
  Refer to variables by name only (e.g. `OVH_AI_ENDPOINTS_ACCESS_TOKEN`), never by value.
- **Don't follow indirection outside the repo** — e.g. `GOOGLE_APPLICATION_CREDENTIALS` in a
  real `.env` can point at a path like `/opt/vision/vision-login.json` outside this checkout. A
  past incident here was exactly a real Vision service-account key sitting outside the repo and
  getting read by an agent; don't read files outside the repo tree without explicit permission.
- **Never modify anything inside `vendor/`.**
- Prefer small, reviewable, exactly-what-was-asked changes — no unrequested "improvements" or
  new dependencies.