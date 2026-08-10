# Final Local Release Manifest (one-time ZIP)

> Status: Local release prep — **NO DEPLOY in this task**  
> Date: 2026-08-10  
> Architecture: CLOSED — [ADDON_ARCHITECTURE.md](ADDON_ARCHITECTURE.md) · [NEW_AGENT_HANDOFF.md](NEW_AGENT_HANDOFF.md)

## Purpose

Package localhost-verified application for **one ZIP upload** later. This document is the inventory + post-upload plan. Do **not** treat it as permission to deploy.

## Scope

| Repo | Include in release? |
|------|---------------------|
| `omnichannel-backend` | **YES** — primary ZIP |
| `wp-seo-ai` | Separate plugin package only if plugin files changed (`compress_plugin.ps1`). **This session: no plugin diff.** |

## MUST upload (backend)

| Path | Why |
|------|-----|
| `app/` | Core + SeoContentAi **compat shell** only |
| `addons/` | All peer addon business code + migrations + JS/CSS sources |
| `bootstrap/` | Providers / app bootstrap (exclude `bootstrap/cache/*.php` generated) |
| `config/` | Config including `refactor_migrate.php`, addon discovery |
| `database/migrations/` | Core migrations |
| `routes/` | Core routes |
| `resources/` | Core Blade/JS/CSS (incl. `resources/js/core/saveCoordinator.js`) |
| `public/build/` | **YES** — upload Vite build artifacts (local `npm run build` already PASS) |
| `public/css`, `public/js`, `public/fonts` if present from Filament publish | Assets used at runtime |
| `composer.json`, `composer.lock` | Autoload map / deps lock |
| `artisan`, `phpunit.xml` optional | artisan required; phpunit not required on prod |
| `docs/architecture/*` optional | Helpful on server; not runtime-required |

## MUST NOT upload

- `.env`, `.env.*`
- `vendor/` (unless host has no Composer — this host normally runs Composer on server)
- `node_modules/`
- `.git/`, `.cursor/`, `.agents/`, `.secure/` secrets, agent transcripts
- `storage/logs/*`, `storage/framework/cache/*`, `storage/framework/sessions/*`, `storage/framework/views/*`
- Local DB dumps, `*_test` DB dumps, `.tmp_*`, `dumpcheck*`
- MCP / codebase-memory indexes
- `addons/_legacy-obsolete/` only if intentionally archived (prefer exclude from prod ZIP to shrink)

## Migrations to run (post-upload)

Non-destructive only on protected DBs:

```text
php artisan refactor:migrate --verify --via-mysql
php artisan refactor:migrate --verify --via-mysql
```

Expect: `Nothing to migrate` / zero row deltas when already cut over.

**Never** `migrate:fresh` / `refactor:migrate-fresh` against `omi_channel` / `omi_seo_ai`.

## Post-upload command plan

Exact sequence after ZIP extract on server (use host `$PHP_BIN`):

```text
$PHP_BIN artisan down

composer dump-autoload -o

$PHP_BIN artisan optimize:clear

$PHP_BIN artisan refactor:migrate --verify --via-mysql
$PHP_BIN artisan refactor:migrate --verify --via-mysql

$PHP_BIN artisan optimize

$PHP_BIN artisan queue:restart

$PHP_BIN artisan up
```

Notes:

- `composer install` / `update` — **only if** `composer.lock` changed vs server; this wave is mostly app/addons code. Prefer `dump-autoload -o` first.
- `npm run build` — **not required on server** if `public/build/` is included in ZIP (recommended).
- Scheduler: no new crontab expected for this refactor wave (existing project cron/queue workers remain).
- Queue: **restart workers** via `queue:restart` (and aaPanel cron workers per `docs/operations/AAPANEL_QUEUE_RUNTIME.md` if used).
- Optional gate: `$PHP_BIN artisan seo:queue-runtime-check` when Content Project queue matters.

## WordPress plugin

- This local session: **no `wp-seo-ai` changes**.
- If a future plugin fix is needed: bump version + `.\compress_plugin.ps1` (separate from backend ZIP).
- Real WP E2E remains external debt until a reachable test WP is used.

## Rollback note

1. Keep previous server tree / previous ZIP before overwrite.  
2. Restore previous code tree.  
3. Do **not** reverse-drop extension tables or re-add addon columns to `articles` without an explicit rollback migration plan.  
4. `refactor:migrate` is forward/idempotent — rollback is **code restore**, not migrate-down of cutover.

## Packaging command

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File ".secure\package-local-release.ps1"
```

Output example from this session:

`storage/release/omnichannel-backend-local-20260810-1747.zip` (~7.12 MB, no vendor/node_modules/.env)

Regenerate anytime:

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File ".secure\package-local-release.ps1"
```

Output: `storage/release/omnichannel-backend-local-YYYYMMDD-HHMM.zip` (under `storage/`).

## Local verification snapshot (this task)

| Gate | Result |
|------|--------|
| `composer dump-autoload -o` + `optimize:clear` | PASS |
| HTTP `localhost:8000` | 200 (artisan serve) |
| Peer provider class_exists | PASS |
| `npm run build` | PASS |
| SaveCoordinator node test | PASS |
| `check:editor-cycles` | PASS |
| `refactor:migrate-fresh --confirm-destroy-test-db` | PASS on `*_test` only |
| Protected `omi_seo_ai.articles` | 7851 intact (direct DB count) |
| Broad PHPUnit | Understood residual — see TASK10 report |
| Manual UI checklist | **PENDING** — `.env` points at empty `*_test` after fresh; use imported DBs for UI |
| Real WP E2E | **PENDING** / harness only |

**Deploy: not run.**
