# Backend Runtime Performance Audit

> Status: Evidence audit (read-only) + production evidence update  
> Task ID: `backend-runtime-performance-audit-no-editor`  
> Date: 2026-08-03  
> Production evidence update: 2026-08-03  
> Remediation docs: `docs/operations/AAPANEL_QUEUE_RUNTIME.md` (dedicated worker applied; smoke pending)  
> Scope: Laravel backend runtime — queue, scheduler, Site Sync, retention, DB, logs, observability  
> **Excluded:** Article Editor frontend/runtime (TipTap/React/editor portals/browser heap/autosave/UI)

Code/config = source of truth. Production values below from host checks 2026-08-03.

---

## 1. Executive conclusion

| Question | Answer |
|----------|--------|
| Confirmed persistent memory leak (multi-job same process)? | **No.** Worker cron short-lived (`--stop-when-empty` + `--max-time=55`). No `memory_get_*` instrumentation in app PHP. Cannot prove cross-job heap growth from source alone. |
| Confirmed per-job memory spike? | **Yes (pattern).** Site Sync force_full/staging JSON, publish `with(['article'])` (full `articles` row incl. `content`/`blocks`/`editor_document`), CP workspace `get()` all tasks + articles, reconcile full manifest — unbounded/large payloads per job/request. |
| Queue cron consume đủ queue? | Shared: yes. `seo-content-run`: dedicated worker **CONFIGURED**; runtime gate **PASS**; **smoke PENDING** — Q01 not closed. |
| Scheduler duplicate? | **No** (prod `schedule:list` aligns). Sole registration: `SeoContentAiServiceProvider` booted (~L763–925). |
| Retention/log growth thiếu? | **Yes** (P1). Cron shell logs unbounded — logrotate separate; **does not block** CP smoke at current sizes. |
| Rủi ro lớn nhất hiện tại? | Generation smoke chưa chạy — xác nhận dedicated worker consume `seo-content-run`. |

### Production evidence / remediation (2026-08-03)

| Fact | Value |
|------|-------|
| Previous `retry_after` | 90 |
| Effective production `retry_after` | **1200** (host `seo:queue-runtime-check` PASS) |
| Dedicated worker | **CONFIGURED** |
| Shared worker includes `seo-content-run`? | **No** (confirmed) |
| Runtime safety gate | **PASS** |
| Smoke | **Pending** |
| Audit Q01 | **NOT CLOSED** |
| Audit Q03 | **VERIFIED REMEDIATED** |

---

## 2. Production runtime baseline

### aaPanel crons (verbatim)

**Shared Queue Worker:**

```bash
/usr/bin/flock -n /tmp/seo-teamviahe-queue.lock \
/usr/local/lsws/lsphp83/bin/php artisan queue:work \
--stop-when-empty \
--max-time=55 \
--timeout=300 \
--tries=3 \
--sleep=1 \
--queue=automation-critical,automation,automation-external,seo,media_generation,default \
>> storage/logs/queue-cron.log 2>&1
```

**Laravel Scheduler:**

```bash
cd /www/wwwroot/seo.teamviahe.com && \
/usr/local/lsws/lsphp83/bin/php artisan schedule:run \
>> storage/logs/cron-schedule.log 2>&1
```

**Dedicated Content Project Queue Worker (production applied):**

```bash
cd /www/wwwroot/seo.teamviahe.com && \
/usr/bin/flock -n /tmp/seo-teamviahe-content-run.lock \
/usr/local/lsws/lsphp83/bin/php artisan queue:work \
--queue=seo-content-run \
--stop-when-empty \
--max-jobs=1 \
--timeout=900 \
--tries=1 \
--sleep=1 \
>> storage/logs/content-run-queue-cron.log 2>&1
```

Full runbook: [AAPANEL_QUEUE_RUNTIME.md](../operations/AAPANEL_QUEUE_RUNTIME.md).

### Process lifecycle

| Fact | Evidence / implication |
|------|------------------------|
| No Supervisor/Horizon/systemd worker in repo deploy SoT | `docs/operations/DEPLOYMENT.md` mentions supervisor as verify hint only; no template forces daemon. Treat aaPanel cron as sole consumer. |
| `flock -n` | Second cron minute exits immediately if lock held → **one** queue worker at a time. |
| `--stop-when-empty` | Process exits when queues empty → short process; limits cross-job persistent leak window. |
| `--max-time=55` | Worker stops accepting **new** jobs after ~55s runtime; **current job finishes** before exit (Laravel Worker). Long job (e.g. 900s) keeps process + flock held far beyond 55s. |
| `--timeout=300` | Worker default timeout 300s. Job `$timeout` property **overrides** worker timeout when set (Laravel). Jobs with `$timeout` 600–3600 can run longer than 300. Jobs **without** `$timeout` capped at 300. |
| `--tries=3` | CLI default; job `$tries` overrides (e.g. `RunContentProjectArticleJob::$tries = 1`). |
| Queue priority | Left-to-right: drain `automation-critical` before `automation`, … before `default`. Single worker → head-of-line blocking across all named queues. |
| Scheduler ownership | Independent cron; does not share flock with queue worker. Overlap mutex via `withoutOverlapping()` (cache locks; default driver `database` per `config/cache.php`). |

### `queue:restart` in this model

Writes restart cache signal. Short-lived cron workers check it between jobs. Useful after deploy to stop mid-minute worker; **not** a long-lived daemon restart. Stale signal in DB/file cache generally cleared after worker sees it — low risk if cache driver stable. If cache table/driver changes mid-flight: **NOT ENOUGH EVIDENCE** without production check.

### pcntl / CLI

- Repo: **no** production `pcntl_signal` / `pcntl_async_signals` usage found in app business paths.
- Laravel queue timeout/graceful stop prefers `pcntl` when loaded; without it, timeout behavior degrades (SIGALRM unavailable).
- Binary claimed: `/usr/local/lsws/lsphp83/bin/php` — FPM vs CLI ini may differ; **verify on host** (see §10).

---

## 3. Queue coverage matrix

| Queue | Producer / jobs | Config source | aaPanel listens? | Priority (order) | Status | Evidence |
|-------|-----------------|---------------|------------------|------------------|--------|----------|
| `automation-critical` | `ExecuteAutomationRuleJob`; stale recovery re-dispatch | `AutomationQueueName::Critical` | Yes | 1 (highest) | Covered | `AutomationQueueName.php`; `ExecuteAutomationRuleJob`; `AutomationStaleRecoveryService` |
| `automation` | `ExecuteAutomationNodeJob` default; Core module nodes | `AutomationQueueName::Automation` | Yes | 2 | Covered — **real jobs**, not legacy-only | `ExecuteAutomationNodeJob`; `CoreAutomationModuleProvider` |
| `automation-external` | WP/external nodes; `DispatchScheduledProductReviewPublishJob`; product review reconcile | `AutomationQueueName::External` / hardcoded | Yes | 3 | Covered | Module providers; `DispatchScheduledProductReviewPublishJob` |
| `seo` | Site Sync step/inbound; Manual WP sync; meta/incr domain sync; SyncArticle* | `ArticleWpSyncQueueService::QUEUE_NAME = 'seo'` | Yes | 4 | Covered | `ArticleWpSyncQueueService.php:24` |
| `media_generation` | `GenerateMediaJob` via `ArticleEditorMediaAiService` | Hardcoded `->onQueue('media_generation')` | Yes | 5 | Covered | `ArticleEditorMediaAiService.php` ~296, 417, 1195 |
| `default` | Jobs **without** `onQueue()`: `AnalyzeArticleSeoJob`, `RunAgentAutomationJob`, `ExecuteContentProjectAgentPlanStepJob`, `DispatchContentProjectAutomationPoliciesJob`, keyword/GSC/import/audit jobs, agent metrics/retention when queued | `config/queue.php` → `DB_QUEUE` / connection default `default` | Yes | 6 (lowest) | Covered | Job ctors lack `onQueue`; dispatch sites |
| **`seo-content-run`** | `RunContentProjectArticleJob`; engine `->onQueue(ContentProjectRunEngineFeature::queueName())` | Prod = **`seo-content-run`** | Dedicated worker (not shared) | n/a | **Consumer created · smoke pending · not resolved** | `AAPANEL_QUEUE_RUNTIME.md` |
| (WpHeadless) config queue | `SyncSiteDataStepJob` → `config('queue.connections.'.default.'.queue')` | Usually `default` | Yes if default | — | Covered if addon active | `WpHeadless/Jobs/SyncSiteDataStepJob.php` |

### Queue name resolution notes

1. **`seo-content-run` is confirmed active prod queue name** — config-driven; production effective value matches default (no remap).
2. **Dedicated consumer prepared** in runbook; **not** deployed yet — still a coverage gap.
3. **`automation` has live producers** — node execution default queue; not dead name.
4. Jobs omitting `onQueue()` fall into **`default`** — currently listened; risk is **starvation** (priority 6), not orphan.
5. Keyword rank jobs: callers `->onQueue('seo')` — covered.
6. `AnalyzeArticleSeoJob`: **no** `onQueue` → `default` (Site Sync scoring fan-out competes at lowest priority).

---

## 4. Scheduled task inventory

**Registration SoT:** only `SeoContentAiServiceProvider` `$this->app->booted` ~L763–925.  
Empty: `bootstrap/app.php` schedule, `app/Console` Kernel schedule, `routes/console.php` (commands only), WpHeadless provider, packages/.

All use `->name(...)` + guard `events()->description === $name`. **No** `onOneServer()`. `withoutOverlapping()` default mutex TTL **1440 min** except reconcile **50**.

| Schedule name | Cadence | Registration | Overlap guard | Duplicate? | Runtime dependency |
|---------------|---------|--------------|---------------|------------|-------------------|
| `seo-content-ai:cleanup-old-notifications` | monthlyOn(1, 00:10) | SeoContentAiServiceProvider | withoutOverlapping() | No | DB `notifications` |
| `seo-content-ai:publish-scheduled-articles` | everyMinute | same | withoutOverlapping() | No (sole publish) | SEO DB + CommandBus + (indirect) queue `seo`/`automation-external` |
| `seo-content-ai:site-sync-reconcile-quick` | hourly | same | withoutOverlapping(**50**) | No | `seo:site-sync-reconcile --mode=quick --limit=30` (max **sites**) |
| `seo-content-ai:automation-dispatch-scheduled` | everyMinute | same | withoutOverlapping() | No (owner A) | `automation_rules` → critical queue |
| `seo-content-ai:agent-automations-dispatch-due` | everyMinute | same + class_exists | withoutOverlapping() | No (owner B) | Agent automations → `RunAgentAutomationJob` (`default`) |
| `seo-content-ai:agent-metrics-aggregate` | hourly | same + class_exists | withoutOverlapping() | No | `--sync` inline aggregate |
| `seo-content-ai:agent-observability-prune` | dailyAt 03:40 | same + class_exists | withoutOverlapping() | No | Agent retention |
| `seo-content-ai:automation-recover-stale` | everyFiveMinutes | same | withoutOverlapping() | No | Stale automation recover |
| `seo-content-ai:content-project-recover-stale-generation` | everyTenMinutes | same + class_exists | withoutOverlapping() | No | `--apply` CP stale gen |
| `seo-content-ai:automation-cleanup-execution-logs` | dailyAt 02:20 | same | withoutOverlapping() | No | Automation log retention |
| `seo-content-ai:wordpress-sync-lease-watchdog` | everyMinute | same | withoutOverlapping() | No | WP sync leases |
| `seo-content-ai:cleanup-agent-plans` | dailyAt 03:10 | same | withoutOverlapping() | No | CP agent plans/approvals |
| `seo-content-ai:dispatch-automation-policies` | hourly | same → `DispatchContentProjectAutomationPoliciesJob` | withoutOverlapping() | No (owner C) | Job on **`default`** queue |

Intentional multi-owner automation (three tables) — not duplicate side effects on same row.

---

## 5. Findings

### [P0] FINDING-Q01 — Content Project run queue not on aaPanel worker

**Classification:** CONFIRMED ISSUE

**Current status:**
- Production code uploaded: **VERIFIED**
- Command registration: **VERIFIED**
- Effective Content Project queue: **`seo-content-run`**
- Dedicated aaPanel worker: **CONFIGURED**
- Shared worker excludes `seo-content-run`: **CONFIRMED**
- Effective `retry_after`: **1200**
- Job timeout: **900**
- Runtime safety gate: **PASS**
- Generation smoke test: **PENDING**
- Resolution: **NOT CLOSED**

Queue coverage **configuration** has been remediated. Actual worker **consumption** remains pending one production generation smoke test.

Do **not** mark Resolved until smoke PASS (see [AAPANEL_QUEUE_RUNTIME.md](../operations/AAPANEL_QUEUE_RUNTIME.md) §5).

**How to verify:** Final smoke procedure in AAPANEL runbook §5 only.

**Post-smoke closeout (template — apply only after operator evidence):**

Status: Resolved in production  

Evidence required: runtime safety PASS · dedicated worker consumed `seo-content-run` · exactly one `RunContentProjectArticleJob` completed · no new failed job · no remaining `seo-content-run` backlog · item exited Generating · worker exited after `--max-jobs=1`.

---

### [P0] FINDING-Q02 — Long jobs hold flock and starve all queues

**Classification:** CONFIRMED ISSUE (mechanism) / POTENTIAL RISK (frequency) — **shared worker only**

**Note:** CP generation now uses a **separate** flock (`content-run.lock`). FINDING-Q02 still applies to long jobs on the **shared** worker (`seo`, `media_generation`, domain sync, etc.).

**Evidence:**
- Jobs with `$timeout` ≫ 55s / 300s on shared queues: `ManualWordPressSyncJob` 600; domain sync / import 3600; keyword batch 900; `GenerateMediaJob` 360; …
- Single shared flock for multi-queue worker

**Minimal remediation direction:** Measure shared queue depth/latency; split workers only after evidence.

---

### [INFO] FINDING-Q03 — `retry_after` was shorter than long-running job window

**Classification:** VERIFIED REMEDIATED / ALREADY SAFE

**History:**
- Previous production value: **90**
- Current effective production value: **1200**
- `RunContentProjectArticleJob` timeout: **900**
- Runtime safety command: **PASS** (`1200 > 900`)

No longer an active P0. Generation delivery still tracked under Q01 smoke.

**Evidence:** host `seo:queue-runtime-check` output 2026-08-03; repo fallback/`DB_QUEUE_RETRY_AFTER` = 1200.

---

### [P1] FINDING-S01 — Site Sync inbound / runs retention missing (Laravel)

**Classification:** CONFIRMED ISSUE

**Evidence:**
- Ingest stores full payload JSON (`SiteSyncDeltaEventIngestor`); max **1.5MB** per delta event
- No Laravel prune/schedule for inbound events, runs, steps, batches
- WP outbox retention 14d exists **plugin-side only**

**Why it matters:** DB growth + large JSON rows; Ops queries slow over time.

**Runtime impact:** Disk/DB size; slower Site Sync ops UI.

**How to verify:** `SELECT COUNT(*), AVG(LENGTH(payload))…` on inbound/batches tables (exact columns per migration).

**Minimal remediation direction:** Scheduled age-based prune for terminal runs + old inbound; keep indexes on `created_at`/`status`.

---

### [P1] FINDING-S02 — Reconcile N+1 + full manifest in memory

**Classification:** CONFIRMED ISSUE

**Evidence:**
- `ReconcileSiteSyncCommand --limit=30` = max **sites**, not records
- `SiteSyncReconciliationService::detectDrift` — per-entry `first()` queries; pluck all local `wp_post_id`
- Manifest loaded without summary pagination on reconcile path

**Why it matters:** Hourly schedule can burn CPU/DB on large sites; memory spike per site.

**Runtime impact:** Scheduler overrun; lock `withoutOverlapping(50)` may skip next hour if still running.

**How to verify:** Time `seo:site-sync-reconcile --mode=quick --limit=1` on largest site; slow query log.

**Minimal remediation direction:** Batch local lookups; summary manifest mode; bound entry walk.

---

### [P1] FINDING-S03 — force_full / staging holds large batch JSON

**Classification:** POTENTIAL RISK

**Evidence:**
- Inline pull max 40 batches/step + catalog 20; WP batch size 25 → ~1000 articles/step order-of-magnitude
- `SiteSyncStagingWriter` stores full `payload_json`; `decodedPayload` loads whole JSON
- Laravel estimate `BATCH_SIZE=50` ≠ WP actual 25

**Why it matters:** Per-job RAM + DB blob growth; not a persistent leak across jobs.

**How to verify:** Peak RSS during force_full; batch row sizes.

**Minimal remediation direction:** Stream/chunk apply; drop staged payload after apply; align estimate constant.

---

### [P1] FINDING-R01 — CP operations / ops_metrics unbounded

**Classification:** CONFIRMED ISSUE

**Evidence:**
- Writer: `ContentProjectOperationLogger::persistOperation` → `seo_content_project_operations`
- Metrics: `ContentProjectOpsMetrics` daily UPSERT — no prune
- Schedule: **none** for these tables
- `commandPayloadForLog` reflects **all public command properties** into metadata JSON (IDs/options — not full article body by design, but arrays of item IDs can be large)

**Why it matters:** Ops Center / CommandBus monitor / audit search degrade as table grows.

**Minimal remediation direction:** TTL prune by `finished_at`; optional strip bulky metadata keys.

---

### [P1] FINDING-R02 — `prompt_results` no global retention

**Classification:** CONFIRMED ISSUE

**Evidence:** Writers via `PromptRunnerService`; cleanup only workspace/article scoped cleaners; no scheduled global TTL.

**Minimal remediation direction:** Age prune / archive; index on created_at for delete.

---

### [P1] FINDING-L01 — Cron log append without in-repo rotation

**Classification:** CONFIRMED ISSUE (disk growth risk — **not** memory leak)

**Evidence:**
- aaPanel redirects to `storage/logs/queue-cron.log`, `cron-schedule.log`, and `content-run-queue-cron.log`
- `config/logging.php`: `web_app` daily 14d; default stack → `single` `laravel.log` (unbounded unless `LOG_STACK=daily`)
- **Zero** logrotate config in repository for these cron redirect files
- Cron runs as **root**; web as **www** — permission split documented in architecture docs

**Note:** Logrotate/retention remains a separate P1. It does **not** block the Content Project smoke test at current observed sizes.

**How to verify:** `du -sh storage/logs/*`; host logrotate.d

**Minimal remediation direction:** Host logrotate size/time; or redirect to dated files.

---

### [P1] FINDING-W01 — Single worker priority starvation

**Classification:** POTENTIAL RISK

**Evidence:**
- Order: critical → automation → external → seo → media → default
- Heavy `seo` (Site Sync steps, manual sync) and `automation` can delay `media_generation` and `default` (scoring, agent plans, agent automations)
- CP generation on orphan queue is separate issue; if remapped to `seo`, generation competes with Site Sync on same lane

**Why it matters:** Publish path uses schedule→CommandBus (sync in scheduler process) then WP side effects on `seo`/`automation-external` — still blocked when flock held by long `seo` job.

**How to verify:** Queue depth by name over time; age of oldest `jobs.available_at`.

**Minimal remediation direction:** Measure; then split worker processes by queue group (critical/publish vs sync vs media).

---

### [P1] FINDING-D01 — CP items read-model loads all tasks + full articles

**Classification:** CONFIRMED ISSUE (unbounded query for large projects)

**Evidence:**
- `ContentProjectItemOperationsReadModel` ~L79–87: `SeoProjectTask::…->with(['article…'])->orderBy('id')->get()` — **no limit**
- `SeoArticle` `$guarded = []`, casts `blocks`/`editor_document` arrays — eager load pulls heavy columns
- Publish due path safer: `ContentProjectPublishingQueueRunner::dueTasks` **limit(50)** but still `with(['article','project'])` full rows

**Why it matters:** Ops/workspace backend RAM/time scales with project size (editor UI excluded; this is backend read-model).

**Minimal remediation direction:** Column `select` without content/blocks; paginate.

---

### [P2] FINDING-O01 — Observability gaps for queue/worker memory

**Classification:** CONFIRMED ISSUE (gap)

**Evidence:**
- No app usage of `memory_get_usage` / `memory_get_peak_usage`
- Site Sync: `SiteSyncHeartbeatService` scheduler/queue heartbeats (presence, not duration/depth)
- CP: operation `duration_ms`, run heartbeat fields — not queue latency/depth
- Agent: allowlisted metrics + prune — domain-specific
- No queue depth gauge writer found

**Minimal remediation direction:** Log job class + duration + peak memory at job end; periodic `jobs` group-by count.

---

### [P2] FINDING-C01 — Cache driver default database; lock/idempotency mostly TTL-safe

**Classification:** ALREADY SAFE (TTL patterns) / POTENTIAL RISK (DB cache row churn)

**Evidence:**
- `CACHE_STORE` default `database` (`.env.example`)
- Idempotency: lazy purge 7d batch 200 on begin (`ContentProjectIdempotencyStore`)
- Site Sync lock TTL 1800s
- Unique jobs `uniqueFor` 900–7200
- `withoutOverlapping` uses cache locks
- No `cache:prune-stale-tags` / expired cache row prune scheduled

**Why it matters:** Expired cache rows may linger physically; not unbounded key cardinality by design for allowlisted agent metrics.

---

### [INFO] FINDING-A01 — Scheduler anti-duplicate registration

**Classification:** ALREADY SAFE

**Evidence:** Named events + `description` containment check before register; freeze tests for publish sole ownership.

---

### [INFO] FINDING-A02 — Publish due bounded

**Classification:** ALREADY SAFE (bound) / POTENTIAL RISK (article payload)

**Evidence:** `dueTasks` / legacy path `limit(50)` per minute tick.

---

### [P1] FINDING-Q04 — Job `$tries` / CLI `--tries=3` interaction

**Classification:** ALREADY SAFE (job wins) with ops confusion risk

**Evidence:** `RunContentProjectArticleJob::$tries = 1` overrides CLI 3 — no surprise triple AI spend from CLI tries. Other jobs set own tries (1–5).

---

### [P2] FINDING-T01 — Typography/media temp cleanup unscheduled

**Classification:** CONFIRMED ISSUE (orphan files)

**Evidence:** `TypographyTemporaryStorageService::cleanupOrphansOlderThanHours` exists but not scheduled; resize temp unlink ad-hoc.

---

### [P2] FINDING-R03 — failed_jobs / job_batches unpruned

**Classification:** CONFIRMED ISSUE

**Evidence:** No `queue:prune-failed` / `queue:prune-batches` in schedule.

---

## 6. Memory risk inventory

| Component/job | Persistent leak | Per-job spike | Unbounded data | Temp/resource leak | Evidence |
|---------------|-----------------|---------------|----------------|--------------------|----------|
| Cron queue worker process | Unlikely (short process) | Depends on job | — | — | `--stop-when-empty`, `--max-time=55` |
| `RunContentProjectArticleJob` | No (if consumed) | AI + article body in runner | Prompt/output in services | HTTP client | `$timeout=900`; engine progression |
| `ProcessSiteSyncStepJob` | No | force_full batches + JSON decode | staged `payload_json` | HTTP 60–90s | StepRunner loops; StagingWriter |
| `ProcessSiteSyncInboundEventJob` | No | ≤1.5MB payload | table growth | — | Ingestor MAX_PAYLOAD_BYTES |
| `ManualWordPressSyncJob` | No | article+media sync | — | — | `$timeout=600` |
| Domain sync jobs | No | full domain crawl | — | — | `$timeout=3600` |
| Publish `dispatchDue` | No (scheduler PHP) | ≤50 tasks × full article | content columns | — | PublishingQueueRunner L173–198 |
| CP items read-model | N/A (HTTP) | all tasks × articles | project-sized | — | ItemOperationsReadModel L79–87 |
| Reconcile quick | N/A (schedule) | manifest entries | per-site unlimited entries | — | ReconciliationService |
| Singleton/static services | Not proven leak | — | — | — | No cross-job memory metric |
| Cron log files | N/A | N/A | **disk** growth | append forever | aaPanel redirects |
| Typography temp | N/A | — | files | cleanup API unused | TypographyTemporaryStorageService |

**Verdict:** No source proof of **persistent** multi-job heap leak under current cron worker model. Strong evidence of **per-job / per-request spikes** and **disk/DB growth**.

---

## 7. Database / index risks

| Query path | Table | Filter/order | Existing index evidence | Risk |
|------------|-------|--------------|-------------------------|------|
| CP operations list/monitor | `seo_content_project_operations` | command, result_code, finished_at, project_ref | migration indexes + composites `cp_ops_cmd_finished_idx`, `cp_ops_result_finished_idx` | Growth without prune; metadata JSON large |
| Ops metrics daily | `seo_content_project_ops_metrics` | metric_key, bucket_date, site, project | unique `cp_ops_metrics_unique` | Unbounded days |
| Publish due | `seo_project_tasks` | scheduled_publish_at ≤ now, publish_queue_status, article_id, active | need host `SHOW INDEX` — migration evidence incomplete in this audit | Filter+order; limit 50 helps |
| CP items workspace | `seo_project_tasks` + `articles` | project_id, planned, working set | project_id indexes typical | **select *** via eloquent + content/blocks |
| Site Sync reconcile | articles by wp_post_id; snapshots | per entry | wp_post_id index? verify host | N+1 CONFIRMED |
| Site Sync inbound list | inbound events | site_id, status, created | indexes on site_id/status/event_id | Unbounded rows |
| Idempotency purge | `seo_content_project_idempotency_keys` | expires_at | expires_at index (application tables migration) | Lazy only, limit 200 |
| Automation executions cleanup | automation_* | age/status | cleanup service batch 500 | Scheduled — safer |
| Audit search | operations | filters + limit | see ops indexes | Bounded by limit |
| Daily report | ops_metrics + tasks | date bucket | metrics unique key | OK if metrics pruned eventually |
| `jobs` / `failed_jobs` | core mysql | queue, reserved_at | Laravel default migrations | failed_jobs growth |
| JSON / LIKE scans | various prompt/automation | whereJson / %like% | case-by-case | POTENTIAL — not fully enumerated |

**Index migrations not invented here.** Host `SHOW INDEX` required for `seo_project_tasks(scheduled_publish_at)`, `articles(wp_post_id)`.

---

## 8. Retention matrix

| Store/table/log | Writer | Cleanup | Scheduled? | Retention | Status |
|-----------------|--------|---------|------------|-----------|--------|
| `seo_content_project_operations` | OperationLogger | none | no | none | **missing** |
| `seo_content_project_ops_metrics` | OpsMetrics | none | no | none | **missing** |
| `seo_content_project_idempotency_keys` | IdempotencyStore | purgeExpired on begin | write-path | 7d | **confirmed safe** (lazy) |
| `prompt_results` | PromptRunnerService | workspace/article scoped only | no global | none global | **missing** |
| Agent traces/events/metrics | Agent observability | `agent:observability:prune` | daily 03:40 | events 14d / traces 30d / … | **confirmed safe** (raw); aggregates kept |
| Site Sync runs/steps/batches | Orchestrator / StagingWriter | none Laravel | reconcile ≠ prune | none | **missing** |
| Site Sync inbound events | DeltaEventIngestor | none | no | none (1.5MB cap ingest only) | **missing** |
| WP sync outbox | WP plugin | WP retention cleanup | WP daily | 14d | **confirmed safe** (WP) |
| Site Sync locks | LockService | TTL replace/delete | no | 1800s | **confirmed safe** |
| Automation executions/logs | Automation services | `automation:cleanup-execution-logs` | daily 02:20 | settings default ~30d | **confirmed safe** |
| `failed_jobs` | queue fail driver | none scheduled | no | none | **missing** |
| `job_batches` | Bus | none scheduled | no | none | **missing** |
| cache / cache_locks | Cache | TTL; no physical prune job | no | per-key | **unknown** / weak |
| notifications | various | monthly delete `< startOfMonth` | monthly | ~1 month boundary | **confirmed safe** |
| CP agent plans | planner | `cleanup-agent-plans` | daily 03:10 | plans 60d / approvals 30d | **confirmed safe** |
| CP agent sessions | session service | command exists | **not scheduled** | soft expire TTL | **missing** schedule |
| Typography temp files | typography pipeline | cleanupOrphans API | **not called** | intended 24h | **missing** |
| `web-app-*.log` | RuntimeLogger | daily driver | built-in | 14d | **confirmed safe** |
| `laravel.log` | CLI default single | none in-app | no | unbounded | **missing** |
| `queue-cron.log` | aaPanel redirect | none in repo | no | unbounded | **missing** |
| `cron-schedule.log` | aaPanel redirect | none in repo | no | unbounded | **missing** |
| `content-run-queue-cron.log` | Dedicated CP worker (when deployed) | none in repo | no | unbounded | **missing** (inventory only; see AAPANEL runbook §6) |
| `watchdog.log` | docs/ops | none in repo | no | unknown | **unknown** |

---

## 9. Observability gaps

| Metric | Collected? | Stored where | Visible where | Gap |
|--------|------------|--------------|---------------|-----|
| Job duration | Partial (CP ops `duration_ms`; not all jobs) | ops table / RuntimeLogger | Ops Center | Most jobs unmeasured |
| Job attempts | Laravel job payload / failed_jobs | DB | `queue:failed` | No dashboard depth |
| Queue latency | No dedicated writer | — | — | **gap** |
| Queue depth | No gauge | `jobs` table query only | manual SQL | **gap** |
| Worker heartbeat | Site Sync queue/scheduler HB; CP run heartbeat; WP lease heartbeat | respective tables | Site Sync scorecard / CP health | Not global worker HB |
| Scheduler heartbeat | Site Sync only on reconcile | `seo_site_sync_heartbeats` | scorecard | Other schedules unmarked |
| `memory_get_usage` | **No** in app | — | — | **gap** |
| Process RSS | **No** | — | — | **gap** |
| Jobs/worker | **No** | — | — | **gap** |
| Slow query | MySQL host only | — | — | **NOT ENOUGH EVIDENCE** |
| Query count | No request profiler found for these surfaces | — | — | **gap** |
| External API duration | Partial (some services log) | logs | logs | inconsistent |
| External payload bytes | Inbound 1.5MB check only | reject path | — | pull path uncapped |
| Site Sync batch duration | Step timing? partial via steps table | steps | Ops | verify UI fields |
| Publish latency | operation/publish attempt logs | ops / publish_attempts | Ops | OK-ish |
| Operation log growth | rows exist | ops table | Ops | no retention metric |

Docs (`OPERATIONS_AND_OBSERVABILITY.md`) describe CP/Agent observation — **not** full queue worker telemetry. Heartbeats ≠ latency SLOs.

---

## 10. Server commands still needed

Read-only / inspect only (adjust paths if docroot differs):

```bash
# PHP CLI binary & extensions
/usr/local/lsws/lsphp83/bin/php -v
/usr/local/lsws/lsphp83/bin/php --ini
/usr/local/lsws/lsphp83/bin/php -m
/usr/local/lsws/lsphp83/bin/php -r "var_dump(extension_loaded('pcntl'), extension_loaded('posix'));"

# Effective config (do not print secrets)
cd /www/wwwroot/seo.teamviahe.com
grep -E '^(CONTENT_PROJECT_RUN_QUEUE|QUEUE_CONNECTION|DB_QUEUE|DB_QUEUE_RETRY_AFTER|CACHE_STORE|LOG_CHANNEL|LOG_STACK|WEB_APP_LOG)' .env
/usr/local/lsws/lsphp83/bin/php artisan about
/usr/local/lsws/lsphp83/bin/php artisan schedule:list
/usr/local/lsws/lsphp83/bin/php artisan queue:failed
/usr/local/lsws/lsphp83/bin/php artisan tinker --execute="echo config('seo-content-ai.content_project.run_queue').PHP_EOL; echo config('queue.connections.database.retry_after').PHP_EOL; echo config('cache.default').PHP_EOL;"

# Queue depth by name (database driver)
# (run via mysql client against core DB that holds `jobs`)
# SELECT queue, COUNT(*) c FROM jobs GROUP BY queue ORDER BY c DESC;
# SELECT queue, COUNT(*) c FROM failed_jobs GROUP BY queue ORDER BY c DESC;

# Process / flock
ps aux | grep -E 'artisan (queue:work|schedule:run)' | grep -v grep
ls -l /tmp/seo-teamviahe-queue.lock 2>/dev/null || true

# Logs / disk
du -sh storage/logs/*
find storage/logs -type f -printf '%s %p\n' 2>/dev/null | sort -nr | head
ls -la /etc/logrotate.d/ 2>/dev/null | head
grep -R 'queue-cron\|cron-schedule\|laravel.log' /etc/logrotate.d/ 2>/dev/null || true

# Site Sync / retention rough counts (SEO DB — use correct connection)
# SELECT COUNT(*) FROM seo_site_sync_inbound_events;
# SELECT COUNT(*) FROM seo_content_project_operations;
# SELECT COUNT(*) FROM prompt_results;

# Index presence samples
# SHOW INDEX FROM seo_project_tasks;
# SHOW INDEX FROM articles;
```

Do **not** run: migrate, queue:work long soak, full Site Sync, truncate, cache:clear (unless ops window), WP API load tests.

---

## 11. Recommended patch sequence

Evidence-only grouping — **no** full refactor:

### P0 — queue coverage / runtime
1. **Done:** `run_queue=seo-content-run`; dedicated worker CONFIGURED; shared worker excludes it.
2. **Done:** effective `retry_after=1200` (was 90) — Q03 VERIFIED REMEDIATED via `seo:queue-runtime-check` PASS.
3. **Done:** repo guard + command registration on production.
4. **Pending:** one generation smoke — do **not** mark Q01 Resolved before smoke.

### P1 — retention / log rotation
1. Prune jobs for CP operations/ops_metrics, Site Sync Laravel artifacts/inbound, `prompt_results` global TTL.
2. Schedule or host-logrotate `queue-cron.log` / `cron-schedule.log` / `laravel.log`.
3. Wire `queue:prune-failed` (and batches if used).

### P1 — Site Sync batching / reconcile
1. Bound reconcile memory (batched ID lookup; summary manifest).
2. Retention for inbound/batches; drop payload after apply where safe.
3. Align bootstrap batch estimate with WP 25.

### P1 — database indexes / query bounds
1. CP items read-model: `select` lean columns; paginate.
2. Publish due: avoid loading full `content`/`blocks`/`editor_document` if unused in loop.
3. Confirm indexes on `scheduled_publish_at`, `wp_post_id` via `SHOW INDEX`.

### P2 — observability
1. End-of-job log: class, queue, duration_ms, memory_peak, attempts.
2. Periodic queue depth by name (ops metric or log).
3. Expose Site Sync step durations already stored if UI missing.

---

## Appendix A — Job timeout / tries snapshot (evidence)

| Job | Queue | timeout | tries | uniqueFor |
|-----|-------|---------|-------|-----------|
| RunContentProjectArticleJob | run_queue cfg | 900 | 1 | 900 |
| ManualWordPressSyncJob | seo | 600 | 2 | 900 |
| ProcessSiteSyncStepJob | seo | (worker 300) | 3 | 900 |
| ProcessSiteSyncInboundEventJob | seo | (worker 300) | 5 | 900 |
| ExecuteAutomationRuleJob | automation-critical | 180 | 3 | — |
| ExecuteAutomationNodeJob | automation | 300 | 3 | — |
| GenerateMediaJob | media_generation | 360 | 1 | — |
| RunMetadataDomainSyncJob | seo | 3600 | 1 | 7200 |
| RunIncrementalDomainSyncJob | seo | 3600 | 1 | 7200 |
| ImportSeoDatabaseJob | default | 3600 | 1 | — |
| AnalyzeArticleSeoJob | default | 120 | 3 | 300 |
| RunAgentAutomationJob | default | — | 3 | 900 |

---

## Appendix B — Explicit non-claims

- No claim of “production safe” or “scalable”.
- No claim Article Editor browser heap (out of scope).
- No claim Supervisor absent on host beyond “not evidenced in repo”.
- No claim `CONTENT_PROJECT_RUN_QUEUE` production value without host grep.
- Singleton services ≠ memory leak without multi-job growth proof.

---

*End of audit. No application code, cron, env, or migration changed in this pass.*
