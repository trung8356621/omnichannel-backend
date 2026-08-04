# Queue, Scheduler, and Idempotency

> Status: Canonical  
> Owner: SeoContentAi (Content Project + Publishing + shared uniqueness patterns)  
> Last verified: 2026-08-01  
> Supersedes: lock/idempotency sections of `docs/archive/content-projects/CONTENT_PROJECT_COMMAND_BUS_CUTOVER.md`, scheduler path in `CONTENT_PROJECT_PUBLISHING_DELIVERY.md`, queue strategy ownership from `CONTENT_PROJECT_RUN_ENGINE_REFACTOR.md`, CP scheduler notes in `docs/MAP_SEO_PROJECTS.md`

## 1. Purpose

Single contract for:

1. **CommandBus idempotency** (replay-safe mutations).
2. **Business locks** (owner-token TTLs).
3. **Job uniqueness** (queue workers).
4. **Rerun / generate eligibility** before enqueue.
5. **Scheduler ownership** for Content Project generation recovery and publishing.

Does not define Site Sync step semantics in depth (see SITE_SYNC) — only shared uniqueness pattern where jobs implement `ShouldBeUnique`.

## 2. Canonical routes (entry → async)

| Entry | Sync gate | Async owner |
|-------|-----------|-------------|
| Filament/API/Agent generate/rerun | CommandBus + lock + eligibility | `ContentProjectRunEngine` → `RunContentProjectArticleJob` |
| Filament/API/Agent schedule/publish_* | CommandBus + transition guard | Due items via publish cron → Process command |
| Cron publish | `seo:publish-scheduled-articles` | `ContentProjectPublishingQueueRunner` → CommandBus |
| Cron stale gen | `seo:content-project:recover-stale-generation --apply` | Recovery service (apply mode) |
| Site Sync / Automation | Own jobs | `ProcessSiteSync*Job`, `RunAgentAutomationJob` (`ShouldBeUnique`) |

## 3. Main components

| Component | Path |
|-----------|------|
| CommandBus | `ContentProjectCommandBus` |
| Idempotency store | `ContentProjectIdempotencyStore` → table `seo_content_project_idempotency_keys` |
| Business lock | `ContentProjectBusinessLock` (Cache owner token) |
| Run engine | `ContentProjectRunEngine` |
| Article job | `RunContentProjectArticleJob` |
| Publish runner | `ContentProjectPublishingQueueRunner` |
| Publish process | `ProcessScheduledProjectItemPublishHandler` |
| Rerun eligibility | `ContentProjectRerunEligibilityGuard` |
| Stale gen recover | `RecoverContentProjectStaleGenerationCommand` |
| Schedule registration | `SeoContentAiServiceProvider` (named events + `withoutOverlapping`) |

## 4. Data ownership

| Concern | Owner |
|---------|--------|
| Idempotent result cache | `(tenant_key, action, idempotency_key)` in idempotency store |
| Run dispatch token / active article claim | `seo_project_runs.settings.php_engine` (engine-owned) |
| Publish due selection | Task `scheduled_publish_at` + `publish_queue_status` |
| Job uniqueness key | Job `uniqueId()` implementation |
| Confirmation / actor keys | `ActorContext` on each dispatch |

## 5. Read path

- Operation poll: `operation_id` / `operation_ref` on every `ContentProjectActionResult.metadata` (including idempotent replays).
- Queue health / timeline services are **read-only** — they do not dispatch publish or generate.
- Engine `healthCheck` / `recoveryPlan` diagnose stuck runs without starting new work unless ops CLI applies recovery.

## 6. Write path — CommandBus idempotency

```text
dispatch(command, actor):
  if actor.idempotencyKey set:
    begin(tenant, action, key)
      → if completed replay: return cached result (code idempotent.replay / metadata.idempotent_replay)
  invoke handler
  complete(store, result) when key set
  always: business audit + operation log
```

Key formats:

| Actor | Pattern |
|-------|---------|
| Filament UI | `ui:{actor}:{action}:{project}:{token}` |
| Queue job | `queue:{job-uuid}:{action}:{item}` |
| Scheduler publish | `scheduler:{item}:{scheduled_publish_at}` |
| Agent plan step | `plan:{plan_ref}:step:{step_ref}` |
| MCP/HTTP | `Idempotency-Key` header or body `idempotency_key` |

Empty key → no store wrap (handler still audited).

Process publish handler may also begin/complete its own key when invoked with queue actor — still CommandBus-shaped.

## 7. Public capabilities

Public CP mutations that **must** carry idempotency when retried by clients: generate, rerun, archive*, publish_now, schedule*, approve, etc. (registry `idempotencySupport` where declared).

Clients: prefer stable keys for double-submit / Agent plan retries. Do not invent a second store outside `ContentProjectIdempotencyStore` for CP commands.

## 8. Internal-only capabilities

| Mechanism | Notes |
|-----------|-------|
| `ProcessScheduledProjectItemPublish` | Internal command; scheduler key mandatory for due sweeps |
| `SyncContentProjectItems` | Internal; not Agent-exposed |
| Engine `dispatchNext` / claim | Internal to Run Engine — not CommandBus public API |
| Recovery CLI / scheduled `--apply` | Ops; not MCP |

## 9. Authorization and confirmation

Idempotency ≠ authorization. Order:

1. Tenant / capability / confirmation (when required).
2. Business lock (if handler uses one).
3. Eligibility guards (ActionGuard / RerunEligibility).
4. Idempotency begin (may short-circuit to replay **after** auth — callers must not assume replay skips auth; bus still constructs actor context first).

Confirmation stale/expired codes remain authoritative even if an old idempotency key is reused with changed fingerprint — treat as new logical operation (new key).

## 10. Queue and scheduler ownership

### Content Project run queue

- Queue name: `ContentProjectRunEngineFeature::queueName()` (historically `seo-content-run` — verify config).
- Job: `RunContentProjectArticleJob`
  - `ShouldBeUnique`
  - `uniqueId = content-project-run-article:{runId}:{runItemId}:{attempt}`
  - `uniqueFor = 900`, `timeout = 900`, `tries = 1`
- Only `ContentProjectRunEngine` dispatches article jobs and finalizes run progression (`dispatchNext`).
- Generate/rerun handlers: seed under lock → `RunEngine::start` outside lock.

### Publishing scheduler

**One** named schedule: `seo-content-ai:publish-scheduled-articles` → `PublishScheduledArticlesCommand` → `ScheduledArticlePublishRunner`.

- CP branch: `ContentProjectPublishingQueueRunner::dispatchDue()` → `ProcessScheduledProjectItemPublishCommand` (`ActorContext::queue`).
- Claim order: due Scheduled → lock → publisher dispatch → **then** `processing` (Publishing). Dispatch fail → Failed, không giữ Publishing.
- Legacy branch: non-project scheduled articles → business hook emit.
- `withoutOverlapping()` on the schedule event.
- Do **not** add a parallel schedule for the runner or Process command.
- Stuck Publishing recovery: `content_project.recover_stuck_publishing` (không Cancel thường; `processing → cancelled` invalid).
- Auto/Quick không cần selection; exclude Publishing/Published.

### Stale generation scheduler

- Name: `seo-content-ai:content-project-recover-stale-generation`
- Command: `seo:content-project:recover-stale-generation --apply` (string flag — not `--apply=1`)
- Cadence: every 10 minutes, `withoutOverlapping()`

### Related unique jobs (cross-module)

| Job | `uniqueId` pattern |
|-----|-------------------|
| `ProcessSiteSyncStepJob` | `site-sync-step:{id}` |
| `ProcessSiteSyncInboundEventJob` | `site-sync-inbound-event:{id}` |
| `RunAgentAutomationJob` | `agent-automation-run:{id}` |

Contract locked by `ArchitectureHardeningLockContractTest`.

### Scheduler registration rules

- Register with **stable `->name(...)`** and guard against double-register when provider boots twice.
- Prefer CLI string options `--apply` / `--sync` over Symfony boolean `=1` coercion.
- Partial deploy: optional console commands behind `class_exists`.

## 11. Transactions and side effects

### Business lock TTLs

| Key pattern | TTL |
|-------------|-----|
| `project:{id}:generate` | 600s |
| `project:{id}:archive` | 300s |
| `project:{id}:restore` | 180s |
| `project:{id}:schedule` | 180s |
| `item:{id}:publish` | 300s |

Rules: owner token on acquire; only owner may release/refresh; **no** `forceRelease` of foreign locks. Busy → `concurrency.lock_busy` / `operation.locked`.

### Publish delivery

At-least-once process + reconcile on `wp_post_id` / `external_reference` — see [PUBLISHING.md](../modules/PUBLISHING.md).

### Generate/rerun side effects

Under generate lock: create run + queue items. Outside: engine start. Failure after seed leaves rows for safe restart (`engine_started: false`).

## 12. Retry and recovery

### Rerun eligibility (pre-mutation)

`ContentProjectRerunEligibilityGuard::validateFull|validateStep`:

- Fail closed → **no** run row, **no** jobs, **no** status stamp.
- Stale recovery may heal task first, then re-check.
- Conflicting active execution re-checked under generate lock.
- Rejected items listed in result metadata; eligible subset only proceeds when policy allows (handlers treat validation failure as whole-request fail when `ok=false`).

### Generate pending safety

`ContentProjectItemGenerationClassifier` fail-closed if “pending” would silently select entire historically-executed project without technical confirm.

### Engine recovery

Stuck `Generating` / stale `active_dispatch`: engine `recoveryPlan` / health + scheduled stale-generation apply. Do not invent a second dispatcher from Filament.

### Publish retry

`retry_publish` + runner due selection; transition guard blocks illegal edges (e.g. published→retry).

### Job retries

Article job `tries = 1` — workflow-level retry/rerun is CommandBus, not Laravel multi-try of the same unique attempt key.

## 13. Compatibility paths

- Legacy JS run orchestration disabled when PHP engine owns the run — do not re-enable queue loops in browser.
- Legacy non-CP article schedule still shares the **same** artisan publish command.
- Deleted classes (`RerunArticlePipelineJob`, bulk/step rerun services): after deploy `queue:restart` so workers do not unserialize missing jobs.

## 14. Forbidden paths

1. Second cron for CP publish or duplicate generate dispatchers.
2. Bypass CommandBus idempotency/audit by calling handler `handle()` directly from HTTP.
3. Dispatch `RunContentProjectArticleJob` from Filament/API/Agent.
4. Force-release business locks owned by another token.
5. Start generate/rerun without eligibility / ActionGuard.
6. Stamp publish schedule from Sync WP / Site Sync.
7. Rely on Laravel `tries > 1` instead of CommandBus rerun for CP article workflows.
8. Use `--apply => true` array syntax for scheduled SEO commands (breaks as `--apply=1`).

## 15. Tests and invariants

| Test | Covers |
|------|--------|
| `ArchitectureHardeningLockContractTest` | Site Sync / Automation `ShouldBeUnique` + `uniqueId` |
| `PublishScheduledArticlesCanonicalRunnerContractTest` | Single publish scheduler shell |
| `ContentProjectCommandBusCutoverTest` | Bus + cutover |
| `ContentProjectRerunUnifyTest` | Eligibility before mutate; no legacy rerun services |
| `ContentProjectGeneratePendingSafetyTest` | Fail-closed pending set |
| `ContentProjectRunEnginePhase1Test` | Engine dispatch ownership |
| `ContentProjectStaleGenerationRecoveryTest` | Recovery apply semantics |
| `ContentProjectPublishingLifecyclePolishTest` | Queue status helpers |

## 16. Related documents

- [CONTENT_PROJECTS.md](../modules/CONTENT_PROJECTS.md)
- [PUBLISHING.md](../modules/PUBLISHING.md)
- [SITE_SYNC.md](../modules/SITE_SYNC.md)
- [AUTOMATION.md](../modules/AUTOMATION.md)
- Operations runbooks (when present): `docs/operations/SCHEDULER_AND_WORKERS.md`

---

## Quick reference — ownership matrix

| Work | Who may start | Who may enqueue jobs | Idempotency |
|------|---------------|----------------------|-------------|
| Generate / full rerun / step rerun | CommandBus handlers only | Run Engine only | UI/Agent key + generate lock |
| Stop / resume run | CommandBus (Agent for stop/resume; not MCP) | Engine respects stop | Confirm on stop |
| Schedule / publish controls | CommandBus handlers | N/A (status stamp) | UI key + schedule lock |
| Due publish deliver | Cron → Process command | Runner dispatches commands (not raw WP) | `scheduler:{item}:{time}` + item lock |
| Stale gen heal | Cron `--apply` / recovery services | May clear stuck state; generate still via bus | Ops |
| Site Sync step | Site Sync services | Unique step/inbound jobs | Job uniqueId + event keys |
)
