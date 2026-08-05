# Scheduler and Workers

> Status: Canonical  
> Owner: SeoContentAi (+ core schedule)  
> Last verified: 2026-08-05  
> Supersedes: scattered schedule notes in archived Site Sync / WordPress MAP docs; automation cron/queue notes from `docs/archive/automation/AUTOMATION_SERVICE_INVENTORY.md` (durable ownership only)

Runtime expectation: host cron runs `php artisan schedule:run` every minute; queue workers process named queues below.

**aaPanel production runbook (shared + dedicated workers, flock, `retry_after` gate):** [AAPANEL_QUEUE_RUNTIME.md](AAPANEL_QUEUE_RUNTIME.md).

## Site Sync

### Queues

| Queue | Jobs | Notes |
|-------|------|-------|
| `seo` (`ArticleWpSyncQueueService::QUEUE_NAME`) | `ProcessSiteSyncStepJob` | Unique `site-sync-step:{runId}`; `$tries = 3`; `$uniqueFor = 900` |
| `seo` | `ProcessSiteSyncInboundEventJob` | Unique `site-sync-inbound-event:{eventId}`; `$tries = 5`; `$uniqueFor = 900` |

Workers must include queue `seo` for Site Sync progress and inbound delta processing.

### Scheduler

| Schedule name | Cadence | Command |
|---------------|---------|---------|
| `seo-content-ai:site-sync-reconcile-quick` | Hourly (`withoutOverlapping(50)`) | `seo:site-sync-reconcile --mode=quick --limit=30` |

Registered in `SeoContentAiServiceProvider` when not already present.

### Ops notes

- Reconcile scans sites with `seo_read_token` meta — **not** `sites.settings`.
- Skip when site sync lock held; skip non-V2 writers via `SiteSyncCutoverStateService::isV2Writer`.
- Heartbeat: `SiteSyncHeartbeatService` (`scheduler` / `queue` touches).
- Manual/CLI: `seo:site-sync`, `seo:site-sync-reconcile`, `seo:site-sync-v2-backfill` — see [../modules/SITE_SYNC.md](../modules/SITE_SYNC.md).

### WordPress outbox (plugin side)

- WP-Cron hook `omi_seo_ai_flush_sync_outbox` (+ retention cleanup).
- Overlap lock transient; dead_letter after max attempts.
- Requires Laravel workers + reachable `delta-event` callback — not a substitute for Laravel `schedule:run`.

## WordPress publish / lease (related)

| Schedule / queue | Role |
|------------------|------|
| Every minute `seo:publish-scheduled-articles` | Due Laravel scheduled → publish runner |
| `seo:wordpress-sync-lease-watchdog` | Stale manual/auto sync lease recovery |
| Queue `seo` | `ManualWordPressSyncJob` |
| Queue `automation-external` | `wordpress.article.sync` / WP action nodes |

Details: [../modules/WORDPRESS_BRIDGE.md](../modules/WORDPRESS_BRIDGE.md).

## Content Project

| Schedule / queue | Runtime worker / role |
|------------------|------------------------|
| Queue `seo-content-run` | **Dedicated Content Project Queue Worker** — `RunContentProjectArticleJob` (`$timeout`/`$uniqueFor` 900, `$tries` 1; no `$backoff`). Confirmed active prod queue. Shared worker must **not** list this queue. |
| `seo-content-ai:publish-scheduled-articles` | Named schedule → `PublishScheduledArticlesCommand` (CP + legacy branches) |
| `seo-content-ai:content-project-recover-stale-generation` | Every 10 min → `seo:content-project:recover-stale-generation --apply` |

### Production status (2026-08-03)

| Gate | Status |
|------|--------|
| Runtime safety gate (`seo:queue-runtime-check`) | **VERIFIED PASS** |
| Dedicated worker | **CONFIGURED** |
| Generation smoke test | **PENDING** |

### Database queue safety (production)

| Setting | Value |
|---------|-------|
| `RunContentProjectArticleJob` timeout | **900** |
| Database queue `retry_after` | **1200** (`DB_QUEUE_RETRY_AFTER`) |
| Contract | `retry_after` must remain **greater than** the longest effective job timeout (1200 > 900) |

Preflight: `php artisan seo:queue-runtime-check` → must PASS before generation smoke.

**Invariant:** Long-running Content Project generation must **not** share the shared aaPanel worker flock.

Contract: [../contracts/QUEUE_SCHEDULER_AND_IDEMPOTENCY.md](../contracts/QUEUE_SCHEDULER_AND_IDEMPOTENCY.md).  
aaPanel tasks + Final smoke test: [AAPANEL_QUEUE_RUNTIME.md](AAPANEL_QUEUE_RUNTIME.md) (§5 Operator smoke checklist).

### Queue → worker ownership

| Queue | Runtime worker |
|-------|----------------|
| `seo-content-run` | Dedicated Content Project Queue Worker |
| `automation-critical` | **automation-critical** worker (dedicated) |
| `automation-external` | **automation-external** worker (dedicated — WP publish only) |
| `automation-policy` | **automation-policy** worker (`DispatchContentProjectAutomationPoliciesJob`) |
| `seo-audit` | **SEO maintenance** worker (low concurrency; `AuditLinkStatusJob`) |
| `automation` | general worker |
| `seo` | general worker |
| `media_generation` | general worker |
| `default` | general worker (Filament/Laravel queued notifications without explicit queue) |

**Invariant:** `AuditLinkStatusJob` (`seo-audit`) and CP policy dispatch (`automation-policy`) must **not** share the worker that consumes `automation-external`.

## Automation (three owners)

Registered in `SeoContentAiServiceProvider` (distinct names, `withoutOverlapping`):

| Schedule | Cadence | Target |
|----------|---------|--------|
| `automation:dispatch-scheduled` | everyMinute | Business Hook rules |
| `agent:automations:dispatch-due` | everyMinute | Agent Automations → `RunAgentAutomationJob` (unique `agent-automation-run:{id}`) |
| CP automation policies | hourly job on `automation-policy` | `DispatchContentProjectAutomationPoliciesJob` |

### Queues

| Queue | Role |
|-------|------|
| `automation-critical` | `ExecuteAutomationRuleJob` |
| `automation-external` | WP / external action nodes (`ExecuteAutomationNodeJob` when action `defaultQueue` is External) |
| `automation-policy` | `DispatchContentProjectAutomationPoliciesJob` |
| `seo-audit` | `AuditLinkStatusJob` (link health; not WP publish) |
| `seo` | Legacy/manual WP sync job (not `default`) |
| `default` | Filament/Laravel queued notifications without explicit `onQueue()` |

### Ops invariants (durable)

- Automatic WordPress side effects require **enabled + published** Automation Rule.
- Manual sync (`ManualWordPressSyncJob`) does **not** require a rule; emits `wordpress.synced` (`origin=manual`) after success.
- Content Project / article completion must not dispatch WP sync jobs directly.
- Graph node delay = queue delay (no worker sleep).
- CLI health/recover: `automation:health`, `automation:recover-stale`, `automation:dispatch-scheduled`.

Module: [../modules/AUTOMATION.md](../modules/AUTOMATION.md).

## Prompt / media (when used)

| Queue | Role |
|-------|------|
| `media_generation` | Image generation jobs |
| `seo` / scoring | Analyze after content actions (may defer) |

After env/mode changes for prompt hooks: `optimize:clear`, `seo:prompt-hooks:clear-cache` (if present), `queue:restart`.

## Related documents

- [AAPANEL_QUEUE_RUNTIME.md](AAPANEL_QUEUE_RUNTIME.md)
- [../modules/SITE_SYNC.md](../modules/SITE_SYNC.md)
- [../modules/WORDPRESS_BRIDGE.md](../modules/WORDPRESS_BRIDGE.md)
- [../modules/AUTOMATION.md](../modules/AUTOMATION.md)
- [../modules/CONTENT_PROJECTS.md](../modules/CONTENT_PROJECTS.md)
- [../modules/OPERATIONS_AND_OBSERVABILITY.md](../modules/OPERATIONS_AND_OBSERVABILITY.md)
- [DEPLOYMENT.md](DEPLOYMENT.md)
- [TESTING.md](TESTING.md)
- [TROUBLESHOOTING.md](TROUBLESHOOTING.md)
- [../audits/BACKEND_RUNTIME_PERFORMANCE_AUDIT.md](../audits/BACKEND_RUNTIME_PERFORMANCE_AUDIT.md)

