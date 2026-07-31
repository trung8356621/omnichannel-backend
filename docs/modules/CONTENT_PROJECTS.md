# Content Projects

> Status: Canonical  
> Owner: SeoContentAi  
> Last verified: 2026-08-01  
> Supersedes: `docs/MAP_SEO_PROJECTS.md` (architecture/routes/ownership/state — not historical phase dumps), `docs/archive/content-projects/CONTENT_PROJECT_CANONICAL_ARCHITECTURE.md`, `docs/archive/content-projects/CONTENT_PROJECT_BACKEND_FREEZE_V1.md`, `docs/archive/content-projects/CONTENT_PROJECT_COMMAND_BUS_CUTOVER.md` (command inventory), `docs/archive/content-projects/CONTENT_PROJECT_RUN_ENGINE_REFACTOR.md` (engine ownership invariants only), `docs/archive/content-projects/CONTENT_PROJECT_APPLICATION_API.md`, `docs/archive/content-projects/CONTENT_PROJECT_OPERATIONS.md` (dashboard/ops summary)

## 1. Purpose

Monthly content planning + production for one site/domain on connection `omi_seo_ai`.

- `SeoProject` — month plan (or archive kind).
- `SeoProjectTask` — item (create / rewrite / improve) ↔ optional `SeoArticle` (`article_id` unique).
- `SeoProjectRun` + `seo_project_run_items` — execution records owned by PHP Run Engine.
- Mutations go through `ContentProjectCommandBus::dispatch()` only.
- Item lifecycle reads go through `ContentProjectItemStateResolver` (+ `ContentProjectItemActionGuard`).

## 2. Canonical routes

Panel prefix: `/seo/{connection_hash}/`

| Path | Page / role |
|------|-------------|
| `content-projects` | `ListSeoProjects` |
| `content-projects/create` | `CreateSeoProject` |
| `content-projects/{record}` | `ViewSeoProject` — **operations workspace** (KPI + Project Items table) |
| `content-projects/{record}/edit` | `EditSeoProject` — settings + tasks sync |
| `content-projects/{record}/publishing-queue` | Compat redirect → `view?lifecycle=waiting_publish,published` |
| `content-operations` | `ContentProjectOperationsCenter` (manager+) |
| `/admin/content-operations` | Redirect → SEO ops |

**Legacy run URLs (redirect only → ViewSeoProject):**

- `content-projects/{record}/runs`
- `content-projects/runs/{run}`
- `content-projects/runs/{run}/items/{article}`

Resource: `Filament/Resources/SeoProjectResource.php` — slug `content-projects`, nav “Content projects”, group SEO Workspace.

Gates: `SeoAccessControl::canAccessContentFeatures` / `canAccessPlannerFeatures` / `canMutateContentProjects`.

REST: `/api/v1/content-projects*` → same commands via Application controllers. Agent/MCP: see `docs/contracts/AGENT_AND_MCP_CONTRACTS.md`.

## 3. Main components

| Concern | Class |
|---------|--------|
| Command dispatch | `Services/ContentProject/Application/ContentProjectCommandBus` |
| Command→handler map | `ContentProjectCommandBusRegistrar` |
| Item state | `Support/ContentProject/ContentProjectItemStateResolver` |
| Action eligibility | `Support/ContentProject/ContentProjectItemActionGuard` |
| Task status normalize | `Support/ContentProject/ContentProjectTaskStatusNormalizer` |
| Dashboard buckets | `Support/ContentProject/ContentProjectItemDashboardBucketMapper` |
| Review SoT | `Services/ArticleReviewService` (`articles.review_status`) |
| Run lifecycle | `Services/RunEngine/ContentProjectRunEngine` |
| Run seed | `Services/SeoProjectWorkflowRunService` (`startRun` + `prepareRunQueue`) |
| Article job | `Jobs/RunContentProjectArticleJob` |
| Rerun gate | `Application/Support/ContentProjectRerunEligibilityGuard` |
| Generate pending set | `ContentProjectItemGenerationClassifier` |
| Ops read model | `ContentProjectItemOperationsReadModel` |
| Locks | `Application/Support/ContentProjectBusinessLock` |
| Idempotency | `Application/Support/ContentProjectIdempotencyStore` |
| Audit / op log | `ContentProjectBusinessAuditor`, `ContentProjectOperationLogger` |
| Capabilities | `ContentProjectCapabilityRegistry` + `CanonicalCapabilityRegistry` |
| Agent build | `Agent/ContentProjectAgentCommandFactory` |
| Project archive | `ArchiveContentProjectService` |
| Item archive | `SeoProjectArchiveService` (via `ArchiveProjectItemsHandler`) |
| `seo_projects.status` policy | `Support/ContentProject/ContentProjectStatusDecision` |

## 4. Data ownership

**DB:** `omi_seo_ai`. Cross-DB site/user via `BelongsToOnDefaultConnection` (no FK across DBs).

| State | Source of truth | Not SoT |
|-------|-----------------|---------|
| Item lifecycle / actions | `ContentProjectItemStateResolver` / `ContentProjectItemActionGuard` | Raw column heuristics |
| Task generation status | `seo_project_tasks.status` via `ContentProjectTaskStatusNormalizer` | Literal string compares outside normalizer |
| Review | `articles.review_status` | Dropped `articles.is_reviewed` |
| Content archive (item/project) | `seo_project_tasks.archived_at` / normalized `archived`, `seo_projects.archived_at` | `review_status = archived` |
| Publish queue | `publish_queue_status` + `publish_published_at` + `scheduled_publish_at` | Task `status` alone |
| Run | `seo_project_runs.status` via Run Engine mappers | Client Alpine “isRunning” |
| Project workflow flag | `seo_projects.status` — **non-authoritative for items** (Class A/B/C in `ContentProjectStatusDecision`) | Item phase/counters |

Task types: `create` \| `rewrite` \| `improve`. Post types (create): `article`, `product`, `category`, `product_category`. Identity: `UNIQUE(project_id, source_key)`.

## 5. Read path

1. Resolve task (+ article) from DB.
2. `ContentProjectItemStateResolver::resolve()` → `ContentProjectItemState` dimensions + `availableActions` + `blockingReason`.
3. UI/API/Agent read models (`ContentProjectItemOperationsReadModel`, Agent read service, dashboard stats) **must** use resolver/guard — never re-derive lifecycle.
4. Dashboard KPI filters use `ContentProjectItemDashboardBucket` via `ContentProjectItemDashboardBucketMapper`.
5. MCP/Agent reads: `ContentProjectAgentReadService` / MCP read tools — not CommandBus.

`can_generate` / `can_regen` flags = membership of `Generate` / `Rerun` in `availableActions` only.

## 6. Write path

**Single door:** build `ContentProjectCommand` → `ContentProjectCommandBus::dispatch(ActorContext)`.

```text
Filament / REST  ──► CommandBus ──► Handler ──► domain services / RunEngine
Agent/MCP        ──► Gateway → Registry → CommandFactory ──► CommandBus
Scheduler        ──► ProcessScheduledProjectItemPublish (internal) ──► CommandBus
```

### Generate

`GenerateProjectItemsCommand` → `GenerateProjectItemsHandler`:

1. Tenant + reject archived project.
2. Pipeline validate; resolve item set (explicit refs or pending via classifier; fail-closed full-project unless technical confirm).
3. `ActionGuard::assertCan(Generate)`.
4. Under `BusinessLock::projectGenerate`: `startRun` + `prepareRunQueue` (both required).
5. Outside lock: `ContentProjectRunEngine::start($run)` (idempotent kick). Web returns immediately.

### Rerun

| Command | Handler | Guard |
|---------|---------|-------|
| `RerunProjectItemsCommand` | `RerunProjectItemsHandler` | `validateFull()` |
| `RerunProjectItemStepCommand` | `RerunProjectItemStepHandler` | `validateStep()` |

Require explicit `item_refs`. Stale recovery first. Eligibility **before** any run/queue mutation. Same lock → seed → `RunEngine::start` shape as Generate. Step carries `rerun_from_step` / downstream / optional `source_article_id`.

### Review / approve

- `StartReviewCommand` — task status → reviewing (completed/pending only); does **not** write `review_status`.
- `ApproveProjectItemsCommand` → `ArticleReviewService::ensureApproved()` — SoT `review_status = approved`.
- Article submit/approve/archive/reopen owned only by `ArticleReviewService::performAction()`.

### Archive

| Concept | Owner | Command |
|---------|-------|---------|
| Review archive | `ArticleReviewService` | Filament review action |
| Item content archive | `ArchiveProjectItemsHandler` | `ArchiveProjectItemsCommand` |
| Project Destroy AI Workspace | `ArchiveContentProjectHandler` | `ArchiveContentProjectCommand` |

No item-level restore (`ContentProjectItemAction::Restore` removed). Project restore: `RestoreContentProjectCommand` (`workspace_reused = false`).

### Publish writes

See [PUBLISHING.md](PUBLISHING.md). All schedule/publish/retry/skip/cancel via CommandBus handlers → `ContentProjectPublishingQueueService` + transition guard.

## 7. Public capabilities

Public `content_project.*` commands (Capability Registry + Factory arm + CommandBus):

| Capability | Command | Confirm |
|------------|---------|---------|
| `create` / `update` | Create/UpdateContentProject | No |
| `add_items` / `update_item` | Add/Update item | No |
| `generate` | GenerateProjectItems | No (pending safety confirm when needed) |
| `rerun` | RerunProjectItems | No |
| step rerun (Agent app path; no dedicated MCP tool) | RerunProjectItemStep | No |
| `start_review` / `approve` | StartReview / Approve | No |
| `schedule` / `auto_schedule` / `unschedule` / `move_schedule` | Schedule* | schedule dry-run preview |
| `publish_now` / `retry_publish` / `skip_publish` / `cancel_publish` | Publish* | publish_now / skip / cancel: Yes |
| `archive` / `restore` (project) | Archive/RestoreContentProject | Yes |
| `archive_items` | ArchiveProjectItems | Yes |
| `stop_execution` / `resume_execution` | Stop/ResumeProjectExecution | stop: Yes (**Agent only**, not MCP) |

MCP write surface ⊂ Agent writes. Automation workflow map may **label** generate/rerun nodes — no Automation Action dispatches CP commands today.

Result contract: `ContentProjectActionResult` + `ContentProjectActionCodes` — branch on `code`, not `message`.

## 8. Internal-only capabilities

| Command / path | Notes |
|----------------|-------|
| `SyncContentProjectItemsCommand` | Edit/Create sync; `isAgentWriteExposed=false`; not MCP |
| `ProcessScheduledProjectItemPublishCommand` | Scheduler/queue only; not a capability |
| Demoted KI write caps | On bus but not Agent/MCP advertised |
| Run Engine recovery CLI | `ContentProjectRunRecoverCommand` / status — ops tooling |
| Stale generation recover | `seo:content-project:recover-stale-generation --apply` (schedule) |

## 9. Authorization and confirmation

- Tenant: `ContentProjectTenantGuard` / `SeoAccessControl` on Filament + API.
- Action gate: `ContentProjectItemActionGuard::assertCan()` in handlers (same class as read `availableActions`).
- Confirmation tokens bind tenant, actor, action, project_ref, item_refs, input hash, state fingerprint, `expires_at`. Codes: `confirmation.required|invalid|expired|stale`.
- Quota: `ContentProjectQuotaGuard` → `quota.denied`.
- Lock busy → `concurrency.lock_busy`.

## 10. Queue and scheduler ownership

See [QUEUE_SCHEDULER_AND_IDEMPOTENCY.md](../contracts/QUEUE_SCHEDULER_AND_IDEMPOTENCY.md).

Summary for CP:

- Generate/rerun queue ownership: `ContentProjectRunEngine` + `RunContentProjectArticleJob` (`ShouldBeUnique`, queue from `ContentProjectRunEngineFeature::queueName()`).
- Publish due sweep: single schedule `seo:publish-scheduled-articles` → `ScheduledArticlePublishRunner` → CP runner → CommandBus.
- Stale gen: `seo:content-project:recover-stale-generation --apply` every 10m (`withoutOverlapping`).

## 11. Transactions and side effects

- Generate/rerun seed under project generate lock; engine start **outside** lock (safe retry if `engine_started: false`).
- Domain event `ContentProjectGenerationRequested` after commit of seed.
- Business audit + operation log on every `dispatch()` (no AI prompt/output in business audit).
- Project archive deletes AI workspace / prompt history / execution / local media / SaaS revisions — keeps business article + planning metadata.
- Item archive keeps WP post; cleans workspace artifacts; blocked while generating or publish-queue active.

## 12. Retry and recovery

- Engine start failure after seed: retry `RunEngine::start` / another generate/rerun — do not orphan by rolling back queue rows.
- Article job: `tries = 1`, timeout 900s; uniqueness per run/item/attempt.
- Stale generation: recovery service + scheduled `--apply` command.
- Rerun: validate eligibility first; rejected items in `metadata.rejected` — no partial start.
- Publish retry: `RetryProjectItemPublishingCommand` → queue status `retrying`/`waiting` per transition guard.

## 13. Compatibility paths

- Redirect-only run history Filament pages.
- Publishing-queue URL → filtered ViewSeoProject.
- `SeoProjectWorkflowRunService` seed/consolidate — callers only handlers + engine.
- Workflow executors (`CreateArticlesFromTaskService`, prompt runners, step retry) invoked from article job — not public entry points.
- Class C `seo_projects.status` consumers (Keyword/Article create filters, restore stamp) — project-level only; do not extend for item lifecycle.
- `CONTENT_PROJECT_PHP_ENGINE` flag: engine vs legacy JS orchestration for runs (prefer PHP; do not add new JS orchestration).

## 14. Forbidden paths

1. Mutate `SeoProject` / `SeoProjectTask` / `SeoArticle` / `SeoProjectRun` business columns from controller/Filament/Agent without CommandBus.
2. Call `startRun` / `prepareRunQueue` / `RunEngine::start|resume|requestStop` outside handlers (or approved recovery CLI).
3. Re-derive item lifecycle / `available_actions` from raw columns outside resolver/guard.
4. Compare `seo_project_tasks.status` literals for lifecycle outside `ContentProjectTaskStatusNormalizer`.
5. Use or reintroduce `articles.is_reviewed`.
6. Conflate content archive with `review_status = archived`.
7. Gate item lifecycle on `seo_projects.status`.
8. Call handler `handle()` bypassing `dispatch()` (skips idempotency/audit/op-log).
9. Stamp `scheduled_publish_at` / `publish_queue_status` outside publish handlers / queue service.
10. Expose `sync_items` / `process_scheduled_publish` / stop-resume as MCP tools; expose `sync_items` as Agent write.
11. Item-level restore action.
12. Direct `ContentPublisher` / queue mutate from Filament callbacks.
13. Second cron for CP publish dispatcher.

## 15. Tests and invariants

Primary contracts (remote `$PHP_BIN vendor/bin/phpunit --filter=...`):

| Test | Invariant |
|------|-----------|
| `ContentProjectItemStateContractTest` / `ContentProjectItemStateResolverTest` | Resolver SoT + precedence |
| `ContentProjectTaskStatusNormalizerTest` | Legacy status map |
| `ContentProjectApprovalSotTest` / `ArticleReviewServiceTest` | `review_status` SoT; no `is_reviewed` |
| `ContentProjectIsReviewedCutoverMigrationTest` | Column cutover |
| `ContentProjectRerunUnifyTest` / `ContentProjectBulkRerunPhase20Test` / `ContentProjectStepRerunPhase20Test` | Rerun CommandBus-only; deleted bulk/step services absent |
| `ContentProjectGenerateParityTest` / `ContentProjectGeneratePendingSafetyTest` | Generate path + fail-closed pending |
| `ContentProjectRunEnginePhase1Test` / `ContentProjectActiveExecutionLifecycleTest` | Engine ownership |
| `ContentProjectPublicCapabilityContractTest` | Caps + Factory + archive_items wiring |
| `ContentProjectCommandBusCutoverTest` | Bus entry cutover |
| `ContentProjectStaleGenerationRecoveryTest` | Recovery |
| `ArchitectureHardeningLockContractTest` | Related uniqueness contracts |
| `PublishScheduledArticlesCanonicalRunnerContractTest` | Single publish scheduler shell |

Freeze grep invariants: no production `ContentProjectBulkRerunService`, `ContentProjectStepRerunService`, `RerunArticlePipelineJob`, Filament direct `RunEngine::start`, `ContentProjectItemAction::Restore`.

## 16. Related documents

- [PUBLISHING.md](PUBLISHING.md) — publisher registry, schedule, WP vs Site Sync
- [QUEUE_SCHEDULER_AND_IDEMPOTENCY.md](../contracts/QUEUE_SCHEDULER_AND_IDEMPOTENCY.md)
- [AGENT_AND_MCP_CONTRACTS.md](../contracts/AGENT_AND_MCP_CONTRACTS.md) — Agent/MCP surface (owned elsewhere)
- [SITE_SYNC.md](SITE_SYNC.md) — catalog sync ≠ publish
- [ARTICLE_EDITOR.md](ARTICLE_EDITOR.md) — editor save vs CP publish
- Architecture freeze: `docs/architecture/ARCHITECTURE_FREEZE_V1.md` / `ARCHITECTURE_DECISIONS.md`
- Historical detail: `docs/archive/content-projects/*`

### Item state dimensions (quick ref)

| Dimension | Enum |
|-----------|------|
| `lifecycleState` | `ContentProjectLifecyclePhase`: draft, generating, review, approved, waiting_publish, published, failed, archived |
| `generationState` | `ContentProjectItemGenerationState` |
| `reviewState` | `ContentProjectItemReviewState` |
| `publishState` | `ContentProjectItemPublishState` |
| `executionState` | `ContentProjectItemExecutionState` |
| `archiveState` | `ContentProjectItemArchiveState` |
| `availableActions` | `ContentProjectItemAction` (no Restore) |

**Lifecycle precedence** (highest first): content archive → sticky published → queued/scheduled waiting_publish → publish failed → active generation → gen failed → approved → review → draft.

**Dashboard buckets:** `waiting_ai`, `ai_running`, `waiting_review`, `approved`, `waiting_publish`, `published`, `failed`, `archived`, `other`.

### Public CP CommandBus map (core)

```
CreateContentProject, UpdateContentProject, SyncContentProjectItems,
AddContentProjectItems, UpdateContentProjectItem,
GenerateProjectItems, RerunProjectItems, RerunProjectItemStep,
StartReview, ApproveProjectItems,
ScheduleProjectItems, AutoScheduleProjectItems, UnscheduleProjectItems,
MoveProjectItemSchedule, PublishProjectItemsNow, ProcessScheduledProjectItemPublish,
Retry/Skip/Cancel ProjectItemPublishing,
StopProjectExecution, ResumeProjectExecution,
ArchiveContentProject, ArchiveProjectItems, RestoreContentProject
```
)
