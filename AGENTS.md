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

This file is advice to the model, not a technical guarantee. The actual barrier is
`opencode.json` in this repo: `permission.task = deny` (subagents disabled — a known bug
lets them bypass `deny` on `read`/`grep`), `permission.external_directory = deny` (this is
exactly what would have blocked the incident where `GOOGLE_APPLICATION_CREDENTIALS` in
`.env` resolved to `/opt/vision/vision-login.json`, outside this repo), and
`read`/`edit`/`glob`/`grep` deny anything dot-prefixed except `.local/`, `.github/`,
`.opencode/`. `.local/` (already gitignored here) is the only place for AI scratch/task
files.

Note: `bash` (e.g. `cat .env`, or any script reading the file behind `$VISION_LOGIN`) is a
separate permission channel not fully covered above — the real Google Vision key must not
sit on any machine running a coding agent. Use a low-privilege, quota-capped, budget-capped
dev-only service account for local testing instead (see conversation history for the full
GCP hardening checklist).
