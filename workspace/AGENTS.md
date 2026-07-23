# AGENTS.md

## Purpose

OVH OCR — a PHP library for extracting text from images via visual LLMs (OVH AI Endpoints,
optional Google Vision fallback). See `CLAUDE.md` for architecture.

## Rules

- Prefer simple, practical solutions.
- Keep changes small and reviewable; do exactly what the task says.
- No new dependencies, no unrequested "improvements".
- Follow SOLID, KISS, DRY.

## Quality gates

```bash
composer install
composer test
```

## DO NOT MAKE CHANGES WITHOUT EXPLICIT PERMISSION
## DO NOT READ, ACCESS, OR EXPOSE SECRETS. DO NOT ASK TO EDIT .ENV* FILES. DO NOT OPEN THEM. DO NOT PRINT THEM. ALWAYS REFER TO THE VARIABLE NAME OR TOKEN NAME, NEVER TO THE SECRET VALUE, TOKEN VALUE, OR PASSWORD. USE DUMMY SECRETS FOR TESTS.
## DO NOT MODIFY ANYTHING INSIDE vendor/
## DO NOT READ ANYTHING OUTSIDE THIS REPO (e.g. paths resolved from .env indirection like GOOGLE_APPLICATION_CREDENTIALS) WITHOUT EXPLICIT PERMISSION.

## Technical enforcement (this file alone is not enough)

This file is advice to the model, not a technical guarantee. As of the devcontainer hardening
pass, enforcement is layered (full detail in root `../CLAUDE.md`, "Safety rules" section — read
that, this is just the summary):

1. **No real secret ever lives inside this repo tree.** The real `.env` lives at
   `~/.config/ocr-ai/.env` on the host, outside the bind-mounted directory
   (`docker-compose.yml` mounts the whole repo root into `app`). This is the actual barrier —
   everything below is defense-in-depth on top of it, not a substitute for it.
2. **Network egress allowlist** (`.devcontainer/egress-proxy/squid.conf`, root `docker-compose.yml`
   `frontend-net`/`backend-net: internal: true`) — `app` has no route to the internet except
   through the allowlisted proxy. Even a leaked secret has nowhere to be sent.
3. `opencode.json` in this repo: `permission.task = deny` (subagents disabled — a known bug
   lets them bypass `deny` on `read`/`grep`), `permission.external_directory = deny` (this is
   exactly what would have blocked the incident where `GOOGLE_APPLICATION_CREDENTIALS` in
   `.env` resolved to `/opt/vision/vision-login.json`, outside this repo), and
   `read`/`edit`/`glob`/`grep` deny anything dot-prefixed except `.local/`, `.github/`,
   `.opencode/`. `.local/` (already gitignored here) is the only place for AI scratch/task
   files.
4. Root `.claude/settings.json` has the equivalent `permissions.deny` for Claude Code
   specifically (`Read`/`Edit` on `.env*`/`secrets/**`, plus a few Bash patterns). Same caveat
   as `opencode.json`: covers Claude's own tools and a handful of recognized Bash commands, not
   arbitrary subprocesses (`php -r "readfile(...)"` etc.) — that gap is exactly why layer 1
   (no real secret present) is the one that actually matters.

Note: even with all of the above, `bash` running an unrecognized command (or any script reading
the file behind an env-var indirection like `$VISION_LOGIN`) is a permission channel none of
these configs fully cover — the real Google Vision key must not sit on any machine running a
coding agent regardless. Use a low-privilege, quota-capped, budget-capped dev-only service
account for local testing instead (see conversation history for the full GCP hardening
checklist).
