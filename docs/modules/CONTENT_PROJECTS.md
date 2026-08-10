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
| `content-projects/{record}/publishing-queue` | Compat redirect (`ContentProjectPublishingQueue`) → `publishing-queue?projectId={record}` |
| `publishing-queue` | `PublishingQueueHub` — Publishing Queue hub (route unchanged); optional `?projectId=` scopes to one project, otherwise cross-project (actions disabled per row). **Nav:** nested under Content Projects (`SeoProjectResource::getNavigationItems` + `parentItem`; hub `shouldRegisterNavigation = false`) |
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
| Publishing Queue read model | `ContentProjectPublishingQueueReadModel` (`forProject` scoped, `forHub` cross-project) |
| Generation Needs Review read-state | `ContentProjectGenerationReadStateStore` + `seo_content_project_item_generation_read_states` (per user/item `viewed_generation_completed_at`) |
| Needs Review definition | `Support/ContentProject/ContentProjectRecentlyCompletedDefinition` (presentation filter key `recently_completed`) |
| In Review reporting definition | `Support/ContentProject/ContentProjectInReviewReportingDefinition` (filter key `in_review_reporting`; stamp columns on `seo_project_tasks`) |
| Ops counter transition map | `Support/ContentProject/ContentProjectOpsCounterTransitionMap` (optimistic presentation deltas only) |
| Locks | `Application/Support/ContentProjectBusinessLock` |
| Idempotency | `Application/Support/ContentProjectIdempotencyStore` |
| Audit / op log | `ContentProjectBusinessAuditor`, `ContentProjectOperationLogger` |
| Capabilities | `ContentProjectCapabilityRegistry` + `CanonicalCapabilityRegistry` |
| Agent build | `Agent/ContentProjectAgentCommandFactory` |
| Project archive | `ArchiveContentProjectService` |
| Archive preview UI | `ContentProjectArchivePreview` + `ArchivePreviewArticlePresenter` |
| Manual Index marker (checklist) | `ArticleManualIndexMarkerService` — `articles.indexed_at` / `previous_indexed_at` (+ patch archive `article_snapshot`); not GSC/Indexing API |
| Archive Excel export | `ContentProjectArchiveExportService` (includes Index gần nhất / Index lần trước) |
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
| Manual Index checklist | `articles.indexed_at` + `articles.previous_indexed_at` (2 latest only; preview may patch `article_snapshot`) | Google index status / GSC |
| Publish queue | `publish_queue_status` + `publish_published_at` + `scheduled_publish_at` | Task `status` alone |
| Run | `seo_project_runs.status` via Run Engine mappers | Client Alpine “isRunning” |
| Project workflow flag | `seo_projects.status` — **non-authoritative for items** (Class A/B/C in `ContentProjectStatusDecision`) | Item phase/counters |

Task types: `create` \| `rewrite` \| `improve`. Post types (create): `article`, `product`, `category`, `product_category`. Identity: `UNIQUE(project_id, source_key)`.

**Item identity (create/rewrite):** Project item requires at least one of keyword or post_title. Both may be provided. Canonical validator: `Support/ContentProject/ContentProjectItemIdentity` (`filled(keyword) || filled(post_title)`). Used by Filament form, sync normalizer, Command Bus add/update, MCP/Agent, generation guards. AI outline/article generation may generate or optimize the final title — do not invent a fake keyword/title only to pass validators, and do not persist topic fallback as if the user entered `post_title`.

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

**Operator skip generation (not `skip_publish`):** durable columns on `seo_project_tasks` — `generation_blocked_at` / `generation_blocked_by` / `generation_block_reason`. Canonical scope `SeoProjectTask::eligibleForGeneration()` (+ `isGenerationBlocked()`). Classifier preview uses the scope; `classifySnapshot` skips with reason `generation_blocked`; rerun/resume/Generate fail closed with message `Item đã được đánh dấu bỏ qua tạo bài.` Commands: `BlockProjectItemGenerationCommand` / `UnblockProjectItemGenerationCommand` (planner/manager via `canAccessContentProjectRun`). UI: **Bỏ qua tạo bài** / **Cho phép tạo lại** + badge Skipped. Does not delete article/content.

Archived project note: Article Editor FAQ/CTA body mutations blocked via session `assertArticleEditable` — see [`ARTICLE_EDITOR_WIDGETS_OWNERSHIP.md`](../architecture/ARTICLE_EDITOR_WIDGETS_OWNERSHIP.md).

### Rerun / Resume

| Command | Handler | Guard |
|---------|---------|-------|
| `ResumeProjectItemFromFailedStepCommand` | `ResumeProjectItemFromFailedStepHandler` | fail-closed resolve via `ContentProjectFailedStepResumeResolver` then `validateStep()` |
| `RerunProjectItemStepCommand` | `RerunProjectItemStepHandler` | `validateStep()` |
| `RerunProjectItemsCommand` | `RerunProjectItemsHandler` | `validateFull()` — **explicit full rerun from start** |

**Default Failed-row Retry = resume, not full rerun.** Primary UI action «Tiếp tục từ bước lỗi» and Agent/MCP `content_project.resume_failed_step` resolve the first retryable failed step from the **latest run-item attempt**, set `rerun_from_step`, reuse valid upstream artifacts (`ArtifactReusePolicy`), invalidate failed + downstream. Fail closed when `failed_step_key` cannot be resolved. Empty `item_refs` never expands to project-wide. Menu «Chạy lại từ đầu» is the separate full-rerun action (cost warning).

**Dismiss stale Failed overlay (no AI):** when content/lifecycle already OK (e.g. Published) but Generation still shows Failed from a soft domain-write error, UI «Bỏ qua lỗi (giữ nội dung)» / `content_project.acknowledge_generation_error` marks latest failed run-item `success`, clears `error_message`, and may flip sticky task `failed|writing|processing` → `completed` if `article_id` present. Does **not** regenerate. Prefer this CTA over resume when lifecycle ∈ published/approved/review/waiting_publish and article exists.

Require explicit `item_refs` (fail-closed — empty selection never expands to all pending). Stale recovery first. Eligibility **before** any run/queue mutation. Same lock → seed → `RunEngine::start` shape as Generate. Step carries `rerun_from_step` / downstream / optional `source_article_id`.

**Typed workflow artifacts:** Successful prompt nodes emit typed artifacts (`article_outline`, `article_content`, `product_gallery`, `product_review`) with identity (`project_task_id`, `run_id`/`run_item_id`/`attempt`, `workflow_node_id`, `producer_hook_key`, fingerprint, status). Domain article write consumes **only** `article_content` from the declared dependency node — never `article_outline`, never latest `PromptResult` / `lastPromptOutput` fallback. Content fail → persist action `blocked` (body unchanged, Generation stays Failed).

**Upstream reuse:** `Services/Workflow/ArtifactReusePolicy` — same task, compatible graph version, matching generation input fingerprint, succeeded + not invalidated. Keyword/title/description/post_type changes invalidate outline; publishing schedule alone does not.

**Run settings isolation:** `ContentProjectRunSettings::snapshotForRun()` persists operational keys (`task_ids`, `rerun`, `rerun_*`) on `seo_project_runs.settings`. `prepareRunQueue()` reads those keys — must not fall back to project-wide pending when selection was explicit. On accept, `prepareOperation` marks the task `pending` and clears the latest run-item error so Failed-only filters/cards drop the row immediately.

**Outline → article:** outline parse fail stops writing; full graph marks content steps `skipped` (`Không chạy vì bước Dàn ý thất bại.`). Content fail blocks article persist (`Không ghi nội dung vì bước Viết bài chưa tạo được article_content artifact hợp lệ.`). Parser pre-normalizes BOM / outer fence / short prologue before `TEXT_OUTSIDE_DECLARED_SECTIONS`. Outline markers never persist into `articles.body`.

**Generation vs lifecycle:** Generation status reads the **latest run-item attempt** (independent from article lifecycle / published). Lifecycle may stay `Published` while Generation = `Failed`. Generation = Generated only when mandatory generation graph succeeded **and** `article_content` was domain-persisted. Run/item cannot be Completed when mandatory Write article failed. Optional product-only nodes on Article = `SKIPPED_NOT_APPLICABLE` (do not fail Article generation).

**Ops filters (latest attempt):** Failed-only matches current generation failed/stale only — excludes latest run-item `pending`/`processing` and genuinely running rows. Summary cards use the same display generation status as the table.

**Roles (Content Project only — no new roles):**

| Role | Capability |
|------|------------|
| **planner** | Canonical business actor. Full CP workflow via `SeoAccessControl::canManageContentProjectWorkflow()`. |
| **manager** | Planner-equivalent capabilities **inside Content Project only** (same `canManageContentProjectWorkflow`). Not the canonical actor; does not imply Prompt / user / system settings rights. |
| **content_manager** | Edit assigned AI output in Article Editor; canonical Save stamps reporting In Review only. No generate / rerun / approve / schedule / publish / archive bulk. |

**Workflow:**

1. Planner/Manager: **Draft** (never generated) → Generate → **Pending** (AI queued/running) → **Needs Review** (reporting)
2. Content Manager: Needs Review → Open → Edit → **canonical Save** → **stop** (no Approve / Schedule / Publish / Rerun)
3. Planner/Manager may **Schedule** from Needs Review **or** In Review **or** Approved (Approved optional marker, not hard gate)
4. Optional: Planner Approve → Approved → Schedule — also valid (Approved **not** an active summary card)

**Normal (UI label, ex-"Draft" card):** whole Content Project **working set** — every item with `publishing_queued_at IS NULL`, regardless of generation/review/lifecycle state (never-generated, Pending, Generated, Needs Review, In Review, Failed all count). **Not** WordPress draft, **not** a lifecycle state, **not** limited to never-generated items. Clicking the Normal card clears the workflow filter (shows the full working set). Backed by `stats['normal']` / `stats['working_set']` in `ContentProjectItemOperationsReadModel`; `'draft'` kept only as a legacy filter/query alias with the same match-all semantics (`ContentProjectOpsStateClassifier::matchesSummaryFilter`).

**Draft (internal ops bucket, distinct from the Normal card):** never generated AI, no active execution, no canonical generation result — used by the Generate-eligibility classifier only, not shown as its own KPI card. Definition: `ContentProjectDraftOpsDefinition`.

**Pending (ops):** AI queued or running — between Draft and Needs Review. Definition: `ContentProjectPendingOpsDefinition`.

**Needs Review (reporting / presentation only):** AI finished AND Content Manager has **not** stamped canonical Save (`content_manager_reviewed_at` null). Unread for viewer still applies. Filter key `recently_completed`. **Not** lifecycle. **Not** Schedule gate.

**In Review (reporting / presentation only):** Content Manager stamped `content_manager_reviewed_at` (+ `content_manager_reviewed_by`) once via `ContentProjectContentManagerHandoffService`. Filter key `in_review_reporting`. Legacy residue `pending_review` / task `reviewing` still counts. **Not** lifecycle handoff. **Not** Schedule/Approve gate. Does **not** call `SubmitReview` / change generation status.

**KPI / summary cards (SSOT):** Normal → Pending → Needs Review → In Review → Failed. **No Scheduled/Published/Approved cards** — those belong to **Publishing Queue**. Title badge shows project **total_items** (working set + Publishing Queue); a muted subtitle breaks it down as ":working in workspace · :queue in Publishing Queue". Handoff: Planner/Manager **Send to Publishing Queue** (`publishing_queued_at`) → item leaves CP working set as Unscheduled (no WP, no auto schedule).

**Module boundary:** Content Project = content production only (workspace = the Normal/working-set items). Publishing Queue = schedule + WordPress publication, owned by **Publishing Queue Hub** (`PublishingQueueHub`, slug `publishing-queue`, nested under Content Projects nav; optional `?projectId=`). Legacy nested `content-projects/{id}/publishing-queue` remains a compat redirect. `publishing_queued_at` ownership unchanged.

**Shared ops UI (CP ↔ PQ):** both pages reuse `content-project-ops-styles`, `content-project-summary-cards`, `content-project-filter-toolbar` (`variant`), `content-project-bulk-selection-toolbar` (`variant`), `content-project-items-list` (`variant`), thumbnail/meta/status-badge. CP actions: `content-project-item-actions-menu` + `ContentProjectItemActionsPresenter`. PQ actions: `publishing-queue-item-actions-menu` + `PublishingQueueItemActionsPresenter` (includes **View on WordPress** when publish state = published and stored `wp_permalink` is a valid URL). Edit-article anchors use real `href` + `target="_blank"` / `rel="noopener noreferrer"` (claim Needs Review is side-effect only — no `preventDefault` navigation).

**Counters (reporting):** CM Save `needs_review−1/review+1`; enqueue Draft→Pending `draft−1/pending+1`; approve from Needs Review `needs_review−1/approved+1`; approve In Review `review−1/approved+1`; self-edit after viewed `approved+1`; schedule from Approved `approved−1/scheduled+1`; schedule from Needs Review `needs_review−1/scheduled+1`; schedule from In Review `review−1/scheduled+1`.

**Schedule:** allowed from Review lifecycle (Needs Review or In Review reporting) **or** Approved / WaitingPublish. Does **not** require In Review or Approved. Blocks only: capability, project/item validity, AI/queue busy, invalid datetime, enqueue failure. Never Schedule CTA on Published (republish separate). False-Published recovery bypasses Schedule guard (debug flag + residue).

### Presentation Layer States

| Kind | Examples | Notes |
|------|----------|-------|
| Generation | Pending, Running, Generated, Failed | AI run only — never Needs Review / In Review |
| Workflow / publishing | Scheduled, Published, Failed | Column Workflow; Draft/Pending empty (—); Approved not shown as active workflow badge |
| Reporting | Needs Review, In Review (Reviewed by Content Manager) | Workload only — auto-hide after Approve/Schedule/Publish; not Schedule gate |

Needs Review definition:

AI completed + unread + `content_manager_reviewed_at` IS NULL (and not legacy pending_review/reviewing)

In Review definition:

`content_manager_reviewed_at` IS NOT NULL (or legacy pending_review/reviewing) AND not Approved/Scheduled/Published

Content Manager:
Needs Review → Edit → canonical Save → reporting In Review stamp → stop (`ContentProjectContentManagerHandoffService`).

Ops presentation for Content Manager (`SeoAccessControl::usesContentManagerOpsPresentation`): Total badge beside title; KPI cards Normal / Needs Review / In Review only; workflow filter All+Normal+reporting; no Generate/Queue/Retry/Approve/Schedule/debug.

**Debug lifecycle override** (dev/recovery only):

- Flag: `CONTENT_PROJECT_DEBUG_LIFECYCLE_OVERRIDE` → `config('seo-content-ai.content_project.debug_lifecycle_override')` (default `false`).
- Capability: `SeoAccessControl::canDebugContentProjectLifecycle()` (Planner-equivalent + flag).
- Command: `content_project.debug_override_lifecycle` / `DebugOverrideProjectItemLifecycleCommand`.
- Allowed: Approved ↔ Scheduled ↔ Published. No WordPress API, no publisher dispatch.
- Published → Scheduled clears fake `publish_published_at`, sets Waiting queue + future `scheduled_publish_at` (required if missing). Keeps `articles.wp_post_id`.
- → Published debug stamps `last_publish_error = DEBUG_LIFECYCLE_OVERRIDE:not_wordpress_publish` — not real publisher success.
- Audit via `ContentProjectBusinessAuditor` metadata (`reason=debug_recovery`).

Do not call it AI Inbox / Inbox / Mailbox / Notification Queue. Do not use “staff” for this workflow — use content_manager.

**SSOT ops Summary/List:** `ContentProjectOpsStateClassifier` + per-state Definitions (`Draft` / `Pending` / `Published` / `Scheduled` / `Approved` internal / `NeedsReview`=`RecentlyCompleted` / `InReviewReporting` / `Failed`). Ops card counts = `countSummary(mapped rows)` — same predicates as list filters (`workflowFilter` / failure quick filter). Published ignores `articles.status`. Reporting chips auto-hide after Approve/Schedule/Publish. Table columns: Generation | Workflow (not Lifecycle) | reporting chip under Item. Approved removed from active UI cards/filters.


### Review / approve

- Content Manager canonical Save → `ContentProjectContentManagerHandoffService` stamps `content_manager_reviewed_at` / `content_manager_reviewed_by` once (reporting In Review). **No** `SubmitReview`, **no** task `reviewing`, **no** lifecycle transition.
- `StartReviewCommand` — task status → reviewing (completed/pending only); does **not** write `review_status` (legacy/manual path).
- `ApproveProjectItemsCommand` → `ArticleReviewService::ensureApproved()` — SoT `review_status = approved` (from draft or pending_review). Planner + manager via `canApproveArticleReview`. Optional before Schedule.
- Article submit/approve/archive/reopen owned only by `ArticleReviewService::performAction()`.

### Archive

| Concept | Owner | Command |
|---------|-------|---------|
| Review archive | `ArticleReviewService` | Filament review action |
| Item content archive | `ArchiveProjectItemsHandler` | `ArchiveProjectItemsCommand` |
| Project Destroy AI Workspace | `ArchiveContentProjectHandler` | `ArchiveContentProjectCommand` |

No item-level restore (`ContentProjectItemAction::Restore` removed). Project restore: `RestoreContentProjectCommand` (`workspace_reused = false`). Archive revokes active Article Editor sessions for project articles, snapshots old articles for reports/history, then resets active project tasks to a fresh pending flow (`article_id = null`, publish/review handoff cleared). Restore does not restore old sessions or reuse the old workspace; the next Generate creates new articles/workspace.

**Archive Content Project ≠ archive Article.** Archiving ends project execution/workspace lifecycle only. Articles return to normal standalone Article behavior (editor open/save/Sync WP). Historical associations (`seo_project_archive_items.article_id`, snapshots, preview stats) remain for reports. Active-project ownership gates (`ContentProjectArticleMembership::belongsToContentProject` / `assignedTaskForArticle`, `ArticleResource::articleIsInContentProject`) apply only while the project is not archived (`archived_at` null). Leftover `seo_project_tasks.article_id` on an archived project must not block the editor.

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
| `resume_failed_step` | ResumeProjectItemFromFailedStep | No |
| `acknowledge_generation_error` | AcknowledgeProjectItemGenerationError | No — soft-clear Failed overlay |
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
- Project archive snapshots old business articles for reports/history, deletes AI workspace / prompt history / execution / local media / SaaS revisions, and resets active tasks so the restored project behaves like a fresh flow. Old articles are no longer the active project workspace.
- Articles associated with archived Content Projects return to normal standalone Article behavior while historical project associations remain available for reporting/archive preview. Edit/save/Sync WP must not restore the project, recreate workspace, or resume workflow.
- Archived project preview stays read-only for workflow/content, except manual Index marker (`ContentProjectArchivePreview::markArticleIndexed` → `ArticleManualIndexMarkerService`) and title/copy using stored public WP permalink (`wp_permalink` / snapshot `wordpress_url`). Marker does not restore/unarchive or recreate workspace.
- Item archive keeps WP post; cleans workspace artifacts; blocked while generating or publish-queue active.

## 12. Retry and recovery

- Engine start failure after seed: retry `RunEngine::start` / another generate/rerun — do not orphan by rolling back queue rows.
- Article job: `tries = 1`, timeout 900s; uniqueness per run/item/attempt.
- Stale generation: recovery service + scheduled `--apply` command.
- **Default item Retry:** `ResumeProjectItemFromFailedStep` → first failed retryable step; preserve valid upstream artifacts; do not silently restart from Outline.
- **Full rerun:** `RerunProjectItems` only — explicit confirm/cost warning in UI.
- Step rerun: validate eligibility first; rejected items in `metadata.rejected` — no partial start.
- Agent/MCP use the same resume/step commands — no separate retry logic in Filament/Agent layers.
- Publish retry: `RetryProjectItemPublishingCommand` → queue status `retrying`/`waiting` per transition guard.
- **Manual artifact apply** (Article AI History) is **not** retry/rerun: does not create runs, clear failures, or change Generation status. Future Agent capabilities must call the same `ArticleAiHistoryApplicationService` / command DTOs if exposed — not Hook Engine.

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
| `WorkflowArtifactOwnershipTest` / `ContentProjectFailedStepResumeTest` | Typed artifacts; no outline→body; resume ≠ full rerun |
| `AcknowledgeProjectItemGenerationErrorTest` | Soft-clear Failed overlay without AI; UI prefer-acknowledge CTA |
| `OutlineAsContentDetectorTest` | Outline-as-content diagnostic |
| `ContentProjectGenerateParityTest` / `ContentProjectGeneratePendingSafetyTest` | Generate path + fail-closed pending |
| `ContentProjectRunEnginePhase1Test` / `ContentProjectActiveExecutionLifecycleTest` | Engine ownership |
| `ContentProjectPublicCapabilityContractTest` | Caps + Factory + archive_items wiring |
| `ContentProjectCommandBusCutoverTest` | Bus entry cutover |
| `ContentProjectStaleGenerationRecoveryTest` | Recovery |
| `ArticleEditorArchivedContentProjectStandaloneTest` | Archived CP → standalone Article editor/sync; historical archive items kept |
| `ArchitectureHardeningLockContractTest` | Related uniqueness contracts |
| `PublishScheduledArticlesCanonicalRunnerContractTest` | Single publish scheduler shell |

Freeze grep invariants: no production `ContentProjectBulkRerunService`, `ContentProjectStepRerunService`, `RerunArticlePipelineJob`, Filament direct `RunEngine::start`, `ContentProjectItemAction::Restore`.

## 16. Related documents

- [PUBLISHING.md](PUBLISHING.md) — publisher registry, schedule, WP vs Site Sync
- [QUEUE_SCHEDULER_AND_IDEMPOTENCY.md](../contracts/QUEUE_SCHEDULER_AND_IDEMPOTENCY.md)
- [AGENT_AND_MCP_CONTRACTS.md](../contracts/AGENT_AND_MCP_CONTRACTS.md) — Agent/MCP surface (owned elsewhere)
- [SITE_SYNC.md](SITE_SYNC.md) — catalog sync ≠ publish
- [ARTICLE_EDITOR.md](ARTICLE_EDITOR.md) — editor save vs CP publish
- [ARTICLE_EDITOR_JSON_PERSISTENCE.md](../architecture/ARTICLE_EDITOR_JSON_PERSISTENCE.md) — CP body writers must invalidate/update `editor_document` (not HTML-only silent)
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

**UI badges:** `ContentProjectStatusBadgePresenter` labels via `seo-content-ai::filament.projects.badge_*` (vi/en). Generation Failed overlay SoT = latest run-item attempt (independent of lifecycle).

**Dashboard buckets:** `waiting_ai`, `ai_running`, `waiting_review`, `approved`, `waiting_publish`, `published`, `failed`, `archived`, `other`.

### Public CP CommandBus map (core)

```
CreateContentProject, UpdateContentProject, SyncContentProjectItems,
AddContentProjectItems, UpdateContentProjectItem,
GenerateProjectItems, RerunProjectItems, RerunProjectItemStep, ResumeProjectItemFromFailedStep,
AcknowledgeProjectItemGenerationError, BlockProjectItemGeneration, UnblockProjectItemGeneration,
StartReview, ApproveProjectItems,
ScheduleProjectItems, AutoScheduleProjectItems, UnscheduleProjectItems,
MoveProjectItemSchedule, PublishProjectItemsNow, ProcessScheduledProjectItemPublish,
Retry/Skip/Cancel ProjectItemPublishing,
StopProjectExecution, ResumeProjectExecution,
ArchiveContentProject, ArchiveProjectItems, RestoreContentProject
```
)
