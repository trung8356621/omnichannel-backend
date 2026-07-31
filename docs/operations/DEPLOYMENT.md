# Deployment

> Status: Canonical  
> Owner: SeoContentAi (+ core ops)  
> Last verified: 2026-08-01  
> Supersedes: `docs/archive/operations/CONTENT_PROJECT_ENGINE_PRODUCTION.md` (durable deploy checklist only — not phase rollout narrative)

Short production checklist. Deep semantics: module docs + `SCHEDULER_AND_WORKERS.md`.

## 1. Code + cache

Use the **same PHP binary** as queue/cron (do not guess `/usr/bin/php`).

```text
{PHP_BIN} artisan config:clear
{PHP_BIN} artisan optimize:clear
{PHP_BIN} artisan queue:restart
```

- Frontend: `npm run build` when Vite entries changed (CP run UI, Agent, editor).  
- OPcache: reload PHP-FPM when `validate_timestamps=0`.  
- SEO DB: bootstrap `omi_seo_ai` from Admin → SEO Database Connections before addon migrate.

## 2. Workers (minimum queues)

| Queue | Why |
|-------|-----|
| `seo` | Site Sync steps/inbound, manual WP sync, many SEO jobs |
| `seo-content-run` | Content Project article jobs (`timeout` ≥ 900) |
| `media_generation` | Image pipeline when used |
| `automation-critical` | Business Hook rule execution |
| `automation-external` | WP action nodes from Automation |

Cron: `* * * * * {PHP_BIN} artisan schedule:run` every minute.

## 3. Content Project engine

Prefer per-run checkbox or `CONTENT_PROJECT_PHP_ENGINE_PROJECT_IDS` before global `CONTENT_PROJECT_PHP_ENGINE=true`.

```env
CONTENT_PROJECT_ACTIVE_DISPATCH_TTL_MINUTES=45
CONTENT_PROJECT_HEARTBEAT_STALE_MINUTES=20
```

Health (read-only):

```text
{PHP_BIN} artisan seo:content-project-run:status {runId}
```

Heartbeat stale = warning only (no auto-resume). Release stale dispatch when TTL expired **and** heartbeat dead.

## 4. Post-deploy smoke

1. `schedule:run` list includes Site Sync reconcile, publish-scheduled, automation dispatch, stale-gen recover, agent automations.  
2. Worker process listens required queues (verify `ps` / supervisor).  
3. Operation Center system health green enough to work.  
4. HTTP errors land in `web-app-*.log`, not Permission denied on `laravel.log`.  
5. Site Sync / WP bridge: ping + one reconcile or status command as needed.

## 5. Related documents

- `docs/operations/SCHEDULER_AND_WORKERS.md`
- `docs/operations/TROUBLESHOOTING.md`
- `docs/operations/TESTING.md`
- `docs/modules/OPERATIONS_AND_OBSERVABILITY.md`
- `docs/contracts/QUEUE_SCHEDULER_AND_IDEMPOTENCY.md`

