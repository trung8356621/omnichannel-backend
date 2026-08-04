# Troubleshooting

> Status: Canonical  
> Owner: SeoContentAi (+ core ops)  
> Last verified: 2026-08-01  
> Supersedes: `docs/archive/legacy-readmes/DATABASE_CLEANUP_MISPLACED_TABLES.md`, logging notes from `docs/archive/maps/MAP_SEO_EDITOR.md` (§ logging), common failure notes from Site Sync / Agent archive ops

## 1. Logging — Permission denied on `laravel.log`

**Symptom:** HTTP/Livewire/Filament Internal Server Error; log shows cannot write `storage/logs/laravel.log` (often root-owned from cron).

| Runtime | Use | File |
|---------|-----|------|
| HTTP / PHP-FPM | `RuntimeLogger` / `web_app` | `storage/logs/web-app-YYYY-MM-DD.log` |
| CLI / cron / queue | default `Log::` | `laravel.log`, `queue-cron.log`, `watchdog.log` |

**Do not:** set `LOG_CHANNEL=web_app` in `.env`; fallback web_app → laravel.log; `chown`/delete root `laravel.log` as quick fix.

**Do:** fix code paths to `RuntimeLogger`; tail `web-app-*.log`; keep cron on default channel.

## 2. Database cleanup — misplaced tables

Multi-DB: tables sometimes created on wrong connection (often **0 rows**).

| Logical owner | Connection | Source |
|---------------|------------|--------|
| core | `config('database.core_connection')` (usually `mysql`) | `database/migrations` + GSC/SERP credentials + **`automation_*` / `business_events`** |
| SEO | `omi_seo_ai` | SeoContentAi migrations/models |
| WP Headless | `wp_headless` | WpHeadless addon |

Ownership registry: `config/database_table_ownership.php` + addon `DeclaresDatabaseTableOwnership` → `DatabaseTableOwnershipRegistry`.

```bash
php artisan database:cleanup-misplaced-tables
php artisan database:cleanup-misplaced-tables --dry-run
php artisan database:cleanup-misplaced-tables --execute
php artisan database:cleanup-misplaced-tables --execute --force   # CI / non-interactive
```

`--force` alone is not enough to drop.

**DROP only when:** single owner; table on wrong connection; connections are **different physical DBs**; row count = 0.  
**Never DROP:** `UNKNOWN_OWNER`, `CONFLICT`, `NON_EMPTY`, `WARNING`, same physical DB, unreachable connection.

Audit JSON: `storage/app/database-cleanup/cleanup-YYYY-mm-dd-His.json` (no passwords).

**Root cause summary:** addon `loadMigrationsFrom` + Laravel `$connection` not redirecting `Schema::create` without `Schema::connection(...)`; early runs before SEO bootstrap; GSC/SERP migrations under SEO folder with `$connection = 'mysql'`.

`automation_*` / `business_events` owner = **core** (after `automation:migrate-to-core`). Empty SEO copies may clean if policy allows; runtime core tables stay.

## 3. Site Sync — common failures

| Symptom | Check |
|---------|--------|
| Steps not progressing | Worker includes queue `seo`; unique job not stuck; `SiteSyncLockService` |
| Reconcile skips site | Has `seo_read_token`? V2 writer? Lock held? |
| Inbound dead_letter | WP outbox max attempts; Laravel reachable on `delta-event`; `site.requeue_inbound_event` |
| Dual-apply / wrong KW | V2 writer must not enrich via legacy `push-content` |
| Domain Save “does sync” | Forbidden — Save ≠ Sync |
| Bridge too old | Min plugin for `site_sync.v1`: `1.0.64` |

CLI: `seo:site-sync`, `seo:site-sync-reconcile`, Ops Center Site Sync tab.  
Module: `docs/modules/SITE_SYNC.md`.

## 4. Agent — common failures

| Symptom | Check |
|---------|--------|
| Write rejected | Confirmation token / Gateway policy / capability exposure |
| Cross-site data leak attempt | Timeline/metrics fail-closed by design |
| Automation not due | `agent:automations:dispatch-due` on schedule; worker runs `RunAgentAutomationJob` |
| Job → domain mutate | Forbidden — Job → Runner → Agent paths only |
| Skills Eloquent write | Forbidden — Gateway / CommandBus only |
| Empty metrics | Allowlist reject / fail-open write — check Aggregator job |

Slash: `/agent-health`, `/agent-metrics`, `/agent-trace`.  
Module: `docs/modules/AGENT_WORKSPACE.md`.

## 5. Content Project / queue stuck

| Symptom | Check |
|---------|--------|
| Run not advancing | Worker on `seo-content-run`; `seo:content-project-run:status`; stale dispatch TTL + dead heartbeat |
| Double article processing | Engine invariant — should be 1 running; investigate duplicate workers |
| Publish due not firing | `seo:publish-scheduled-articles` schedule; `withoutOverlapping`; cron `schedule:run` mỗi phút; automation queue cho `article.publish_requested` → `wordpress.article.sync` |
| UI “Publishing” nhưng chưa claim | Due schedule phải là **Scheduled**; Publishing chỉ `processing`. Dùng Recover stuck nếu TTL quá hạn |
| `lifecycle.invalid_transition: processing → cancelled` | Auto/Quick không cancel Publishing; dùng Recover stuck; Cancel thường không cho processing |
| Stale generation | `seo:content-project:recover-stale-generation --apply` |

See `DEPLOYMENT.md` + `QUEUE_SCHEDULER_AND_IDEMPOTENCY.md`.

## 6. Automation / WordPress naming traps

- `PromptTestPublishService::publishArticle` = **local** save, not WP.  
- Content Project completion must **not** call `WordPressArticleSyncService` directly.  
- Automatic WP side effects need enabled published Automation Rule; manual sync is explicit (`ManualWordPressSyncJob` on `seo`).  
- Queues: `automation-critical` (rules), `automation-external` (WP nodes).

## 7. Related documents

- `docs/operations/DEPLOYMENT.md`
- `docs/operations/SCHEDULER_AND_WORKERS.md`
- `docs/modules/OPERATIONS_AND_OBSERVABILITY.md`
- `docs/modules/SITE_SYNC.md`
- `docs/modules/AGENT_WORKSPACE.md`
- `docs/modules/AUTOMATION.md`
)
