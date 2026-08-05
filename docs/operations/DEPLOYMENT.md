# Deployment

> Status: Canonical  
> Owner: SeoContentAi (+ core ops)  
> Last verified: 2026-08-03  
> Supersedes: `docs/archive/operations/CONTENT_PROJECT_ENGINE_PRODUCTION.md` (durable deploy checklist only — not phase rollout narrative)

Short production checklist. Deep semantics: [SCHEDULER_AND_WORKERS.md](SCHEDULER_AND_WORKERS.md).  
**aaPanel workers + Final smoke test:** [AAPANEL_QUEUE_RUNTIME.md](AAPANEL_QUEUE_RUNTIME.md).

## 1. Code + cache

Use the **same PHP binary** as queue/cron (do not guess `/usr/bin/php`).

```text
{PHP_BIN} artisan config:clear
{PHP_BIN} artisan config:cache
{PHP_BIN} artisan optimize:clear
{PHP_BIN} artisan queue:restart
```

- Frontend: `npm run build` when Vite entries changed (CP run UI, Agent, editor).  
- OPcache: reload PHP-FPM when `validate_timestamps=0`.  
- SEO DB: bootstrap `omi_seo_ai` from Admin → SEO Database Connections before addon migrate.

`composer dump-autoload -o` is **not** required on every deploy. Use it only when troubleshooting a new class that runtime does not see (stale Composer classmap).

## 2. Content Project queue post-deploy gate

```bash
cd /www/wwwroot/seo.teamviahe.com

/usr/local/lsws/lsphp83/bin/php artisan optimize:clear
/usr/local/lsws/lsphp83/bin/php artisan config:cache
/usr/local/lsws/lsphp83/bin/php artisan seo:queue-runtime-check
```

`seo:queue-runtime-check` must exit **0 (PASS)** before relying on Content Project generation.

### Checklist

- [ ] `seo:queue-runtime-check` exists in Artisan
- [ ] Runtime safety command **PASS**
- [ ] Effective queue connection = `database`
- [ ] Effective `retry_after` = **1200**
- [ ] Effective Content Project queue = `seo-content-run`
- [ ] Dedicated aaPanel worker enabled
- [ ] Shared worker excludes `seo-content-run`
- [ ] Generate đúng một smoke-test item
- [ ] Kiểm tra `failed_jobs`
- [ ] Chỉ đóng audit sau smoke PASS

**Rule:** Code dispatch thành công **không** đồng nghĩa job đã được worker consume.

Smoke procedure (SoT): [AAPANEL_QUEUE_RUNTIME.md](AAPANEL_QUEUE_RUNTIME.md) §5 Operator smoke checklist.  
Worker ownership: [SCHEDULER_AND_WORKERS.md](SCHEDULER_AND_WORKERS.md).

## 3. Workers (minimum queues)

| Queue | Worker | Why |
|-------|--------|-----|
| `automation-critical` | automation-critical | `ExecuteAutomationRuleJob` |
| `automation-external` | automation-external | WP / external action nodes only |
| `automation-policy` | automation-policy | `DispatchContentProjectAutomationPoliciesJob` |
| `seo-audit` | SEO maintenance (low concurrency) | `AuditLinkStatusJob` — not on WP publish worker |
| `automation` | general | Automation node default queue |
| `seo` | general | Site Sync steps/inbound, manual WP sync, many SEO jobs |
| `media_generation` | general | Image pipeline when used |
| `default` | general | Filament/Laravel queued notifications without `onQueue()` |
| `seo-content-run` | **Dedicated** CP worker | `RunContentProjectArticleJob` (`$timeout=900`, `$tries=1`). **Do not** add to general worker. |

**Invariant:** Do not put `seo-audit` or `automation-policy` on the same worker that listens to `automation-external`.

Scheduler cron: every minute `schedule:run` (see [AAPANEL_QUEUE_RUNTIME.md](AAPANEL_QUEUE_RUNTIME.md)).

Production history: `retry_after` was **90** → effective **1200** (host-verified PASS).

## 4. Content Project engine

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

## 5. Post-deploy smoke (general)

1. `schedule:list` matches canonical registrations (no duplicate publish/Site Sync schedules).  
2. Shared worker listens shared queues only; dedicated CP worker listens `seo-content-run` only.  
3. Operation Center system health green enough to work.  
4. HTTP errors land in `web-app-*.log`, not Permission denied on `laravel.log`.  
5. Site Sync / WP bridge: ping + one reconcile or status command as needed.  
6. CP generation smoke: follow [AAPANEL_QUEUE_RUNTIME.md](AAPANEL_QUEUE_RUNTIME.md) §5 only (one item).

## 6. Related documents

- [AAPANEL_QUEUE_RUNTIME.md](AAPANEL_QUEUE_RUNTIME.md)
- [SCHEDULER_AND_WORKERS.md](SCHEDULER_AND_WORKERS.md)
- [TROUBLESHOOTING.md](TROUBLESHOOTING.md)
- [TESTING.md](TESTING.md)
- [../modules/OPERATIONS_AND_OBSERVABILITY.md](../modules/OPERATIONS_AND_OBSERVABILITY.md)
- [../contracts/QUEUE_SCHEDULER_AND_IDEMPOTENCY.md](../contracts/QUEUE_SCHEDULER_AND_IDEMPOTENCY.md)
- [../audits/BACKEND_RUNTIME_PERFORMANCE_AUDIT.md](../audits/BACKEND_RUNTIME_PERFORMANCE_AUDIT.md)
