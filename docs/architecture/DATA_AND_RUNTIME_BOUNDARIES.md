# Data and Runtime Boundaries

> Status: Canonical  
> Owner: Core + SeoContentAi  
> Last verified: 2026-08-01  
> Supersedes: scattered MAP notes on multi-DB, logging, and addon isolation (durable rules only)

## 1. Database connections

| Name | Config source | Typical owners |
|------|---------------|----------------|
| **`mysql`** (default) | Core `.env` | `User`, `Site`, `SiteMeta`, `Service*`, wallets/orders, `SeoDatabaseConnection`, `ApiConnection`, GSC **OAuth** `seo_gsc_master_connections` / property mappings, WpOption |
| **`omi_seo_ai`** | Runtime from core table `seo_database_connections` | SeoArticle, SeoProject*, SeoMedia, keyword/SERP/GSC **facts**, runs, prompt results |

Rules:

- SEO Content AI credentials are **not** in addon.json or core `.env` DB_* for tenant data.
- No FK constraints across connections — store scalar IDs; enforce in Eloquent/app (`BelongsToOnDefaultConnection` pattern).
- Addon migrations for SEO target `$connection = 'omi_seo_ai'` (or equivalent) after bootstrap.
- Other addons may use `RegistersAddonDatabase` + addon.json — do not assume they share `omi_seo_ai`.

## 2. SEO connection bootstrap

Class: `App\Addons\SeoContentAi\Services\SeoDatabaseConnectionService`  
Constant runtime name: `SeoDatabaseConnectionService::CONNECTION_NAME` = `omi_seo_ai` (overridable via `config('seo-content-ai.connection')`).

| Method | Use |
|--------|-----|
| `bootstrapByHash(string $hashId)` | Panel/URL connection hash → active `SeoDatabaseConnection` + configure Laravel connection |
| `bootstrapFromConnection(SeoDatabaseConnection)` | Already-loaded credential row |
| `bootstrapByConnectionId(int)` | By PK |
| `bootstrapBySiteId(int)` / `bootstrapSeoDatabaseConnection` | Site-bound resolution |
| `bootstrapLegacySharedConnection()` | Shared/legacy single-tenant bootstrap path |

Request path: middleware (`SetDynamicSeoDatabaseByHash` / SEO panel connection context) must run before SEO models query.

Admin UI: Filament **SEO Database Connections** — CRUD + **Run migrations** against bootstrapped connection.

Fingerprint cache avoids re-decrypt/reconfigure identical hash within process (`$bootstrappedHashes`).

## 3. Addon boundary

| Inside `app/Addons/SeoContentAi/` | Outside (core) |
|----------------------------------|----------------|
| SEO Filament pages/resources, SEO routes, SEO jobs, SEO services/models/migrations | Auth users, sites, billing, addon registry, SEO DB credential **rows**, plugin ZIP release |
| Extension SDK contracts under addon | `bootstrap/app.php` only for true app middleware / narrow CSRF |

Do not implement SEO product features by editing core randomly. Core changes only when shared identity/site/credential infrastructure requires it.

`config/addons.php` `skip_slugs`: discovered folders still skipped (e.g. retired `wp-headless`).

## 4. Logging — web vs cron

Production: cron/root often owns `storage/logs/laravel.log` (+ `queue-cron.log`, `watchdog.log`). PHP-FPM (`www`) writing default channel → `Permission denied` / 500.

| Runtime | API | File |
|---------|-----|------|
| HTTP / Livewire / Filament / browser REST / editor | `App\Support\RuntimeLogger` or `Log::channel('web_app')` | `storage/logs/web-app-YYYY-MM-DD.log` |
| CLI / cron / queue / artisan / watchdog | `Log::` default / job channels | `laravel.log`, `queue-cron.log`, … |

### Forbidden on HTTP paths

- `Log::info/warning/error/debug` on default channel
- `logger()->…`
- Bare `report($e)` in web controllers — use `RuntimeLogger::report`
- Setting `LOG_CHANNEL=web_app` in `.env` (breaks cron)
- `chown` / delete / rename root-owned `laravel.log` as “fix”
- Fallback from `web_app` to `single`/`laravel.log` on write failure

### Wiring

- `config/logging.php` → `channels.web_app` (daily; `WEB_APP_LOG_LEVEL` / `WEB_APP_LOG_DAYS`)
- `AppServiceProvider` HTTP boot may set `logging.default=web_app` when channel exists (stale config cache → skip)
- `bootstrap/app.php` exceptions: HTTP `RuntimeLogger::report` then `return false` (block default log)
- Missing `web_app` channel → RuntimeLogger uses `null` driver (no EMERGENCY spam to laravel.log)

Ops: `tail -f storage/logs/web-app-$(date +%F).log` for editor/panel errors.

## 5. Queue / worker boundary

- Prefer named queues (`seo`, `media_generation`) where jobs declare them — workers must listen those names.
- No Filament Queue Manager pause/resume UI (removed).
- Scheduler/cron ownership for publish: single artisan shell — see Publishing + `QUEUE_SCHEDULER_AND_IDEMPOTENCY` contracts.
- Jobs are process-isolated: they must bootstrap SEO connection themselves when not inherited.

## 6. AuthZ boundary (SEO)

- `SeoAccessControl` is the SEO panel gate SoT (effective role, site scope, mutate, read-only admin).
- Global site cookie scopes **lists/dashboards**, not detail authorization.
- Agent/MCP: opaque public refs + tenant guards — no raw numeric ID leakage on keyword/CP public surfaces.

## 7. Related documents

- [SYSTEM_OVERVIEW.md](SYSTEM_OVERVIEW.md)
- [ARCHITECTURE_FREEZE_V1.md](ARCHITECTURE_FREEZE_V1.md)
- [ARCHITECTURE_DECISIONS.md](ARCHITECTURE_DECISIONS.md)
- Module maps under `docs/modules/`
- `.cursor/rules/web-app-logging.mdc` (editor rule mirror)
