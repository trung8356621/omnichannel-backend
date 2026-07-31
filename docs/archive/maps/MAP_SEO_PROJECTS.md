> Status: Historical
> Not canonical
> Archived on: 2026-08-01
> Superseded by: ../../modules/CONTENT_PROJECTS.md
> Purpose: implementation history only
# SeoContentAi â€” Content Projects & Workflow Execution

[â† Quay láº¡i Báº£n Ä‘á»“ tá»•ng](SUPER_MAP_INDEX.md)

**LiÃªn quan:** [React Editor & EditArticle](MAP_SEO_EDITOR.md) Â· [Media / upload](MAP_SEO_MEDIA.md) Â· [WordPress sync](MAP_SEO_WP.md)

---

## 1. Tá»•ng quan

Content Projects lÃ  module láº­p káº¿ hoáº¡ch ná»™i dung theo thÃ¡ng. Má»—i `SeoProject` Ä‘áº¡i diá»‡n cho má»™t thÃ¡ng sáº£n xuáº¥t content cho má»™t site/domain cá»¥ thá»ƒ, chá»©a danh sÃ¡ch cÃ¡c `SeoProjectTask` (bÃ i viáº¿t cáº§n táº¡o/viáº¿t láº¡i/tá»‘i Æ°u) vÃ  lá»‹ch sá»­ cÃ¡c `SeoProjectRun` (cÃ¡c láº§n cháº¡y workflow tá»± Ä‘á»™ng Ä‘á»ƒ sinh ná»™i dung).

### Vai trÃ² trong há»‡ thá»‘ng

```
SeoProject (káº¿ hoáº¡ch thÃ¡ng)
  â”œâ”€â”€ SeoProjectTask (tá»«ng bÃ i viáº¿t / tá»« khÃ³a)
  â”‚     â””â”€â”€ SeoArticle (bÃ i viáº¿t Ä‘Æ°á»£c táº¡o ra)
  â””â”€â”€ SeoProjectRun (láº§n cháº¡y workflow)
        â””â”€â”€ PromptResultLink (liÃªn káº¿t káº¿t quáº£ AI)
```

---

## 2. Models & Database

### 2.1 SeoProject

| Khoáº£n má»¥c | GiÃ¡ trá»‹ |
|-----------|---------|
| **Table** | `seo_projects` (connection `omi_seo_ai`) |
| **File** | `Models/SeoProject.php` |
| **Trait** | `BelongsToOnDefaultConnection` (cross-DB relationships) |

**Casts:**
- `month` â†’ `date`, `site_id` â†’ `integer`, `total_tasks` â†’ `integer`

**Status constants:**
- `pending` (Chá» duyá»‡t), `manual` (Thá»§ cÃ´ng â€” máº·c Ä‘á»‹nh khi táº¡o), `running` (Äang cháº¡y), `completed` (HoÃ n thÃ nh), `paused` (Táº¡m dá»«ng), `approved` (ÄÃ£ duyá»‡t)

**Relationships:**
- `site()`: BelongsTo â†’ `Site` (mysql, cross-DB qua trait)
- `user()`: BelongsTo â†’ `User` (mysql, cross-DB)
- `tasks()`: HasMany â†’ `SeoProjectTask`
- `runs()`: HasMany â†’ `SeoProjectRun`

**Helper methods:**
- `isArchive()` / `KIND_MONTHLY` / `KIND_ARCHIVE`: project thÃ¡ng vs kho lÆ°u trá»¯ domain
- `monthCarbon()`: parse month thÃ nh Carbon
- `maxTasksAllowed()`: archive = unlimited (`PHP_INT_MAX`); monthly = sá»‘ ngÃ y trong thÃ¡ng
- `isExecutionMonthOpen()`: archive luÃ´n má»Ÿ; monthly kiá»ƒm tra háº¡n thÃ¡ng
- `registeredTaskCount()` / `remainingTaskCapacity()` / `canRegisterMoreTasks()`: capacity (archive khÃ´ng giá»›i háº¡n)
- `syncTotalTasksCounter()`: Ä‘á»“ng bá»™ counter
- `defaultNameFromMonth($month)` / `archiveProjectName()` / `archiveSentinelMonth()`: tÃªn + month sentinel `2000-01-01` cho archive

### 2.2 SeoProjectTask

| Khoáº£n má»¥c | GiÃ¡ trá»‹ |
|-----------|---------|
| **Table** | `seo_project_tasks` (connection `omi_seo_ai`) |
| **File** | `Models/SeoProjectTask.php` |
| **Trait** | `BelongsToOnDefaultConnection` |

**Columns quan trá»ng:**
- `project_id` â†’ FK `seo_projects` (CASCADE)
- `site_id` â†’ nullable, index
- `article_id` â†’ nullable, **UNIQUE** (1 task â†” 1 article)
- `type` â†’ ENUM action: `create` | `rewrite` | `improve` (migration `2026_07_24_160000_normalize_seo_project_task_actions`; legacy `new_keyword`/`new_title` â†’ `create`)
- `post_type` â†’ nullable: `article`, `product`, `category`, `product_category` (chá»‰ Create)
- `keyword` / `title` â†’ nullable; Create/Rewrite â€” Prompt inject náº¿u cÃ³ dá»¯ liá»‡u; validation â‰¥1 field
- `source_content` â†’ Create: derived identity (`keyword` ?: `title`); Rewrite/Improve: tiÃªu Ä‘á» Existing/Target article
- `source_key` â†’ SHA-256 identity (`project_id`+`type`+`post_type`+normalized source); **UNIQUE(`project_id`,`source_key`)**
- `secondary_description` â†’ optional context Create/Rewrite (`{{secondary_description}}` / Description)
- `rewrite_mode` â†’ Rewrite luÃ´n `content` (Ä‘á»c bÃ i gá»‘c); cá»™t cÃ²n cho BC
- `rewrite_notes` â†’ Improve instruction (Improve); optional notes Rewrite
- `description` â†’ Gallery description (Product only) â€” khÃ´ng láº«n `secondary_description`
- `loai_san_pham` â†’ loáº¡i sáº£n pháº©m thá»§ cÃ´ng cho prompt áº£nh
- `target_date` â†’ ngÃ y KPI
- `status` â†’ `pending`, `writing`, `reviewing`, `completed`, `failed`, `cancelled` (+ SoftDeletes `deleted_at`)
- `archived_at` / `status_before_archive` â†’ lifecycle archive trÃªn cÃ¹ng task row (khÃ´ng hard-delete)
- `connected_at` â†’ thá»i Ä‘iá»ƒm gáº¯n bÃ i / vÃ o project (nullable datetime)
- `completed_at` â†’ thá»i Ä‘iá»ƒm hoÃ n thÃ nh xá»­ lÃ½ (nullable datetime)
- `archived_from_project_id` â†’ project thÃ¡ng nguá»“n khi chuyá»ƒn sang archive (nullable)

**Task types (action):**
| Type | MÃ´ táº£ |
|------|-------|
| `create` | Viáº¿t má»›i â€” Keyword vÃ /hoáº·c Title (+ Description optional); publish SeoTask |
| `rewrite` | Viáº¿t láº¡i â€” Existing Article + Keyword/Title/Description; rewrite SeoTask (content) |
| `improve` | Prompt Improve only â€” Target article + Improve instruction; rewrite SeoTask; **khÃ´ng** post-run image pipeline / full publish |

**Post types** (cho Create): `article`, `product`, `category`, `product_category`

**Relationships:**
- `site()`: BelongsTo â†’ `Site` (cross-DB)
- `project()`: BelongsTo â†’ `SeoProject`
- `article()`: BelongsTo â†’ `SeoArticle`

### 2.3 SeoProjectRun

| Khoáº£n má»¥c | GiÃ¡ trá»‹ |
|-----------|---------|
| **Table** | `seo_project_runs` (connection `omi_seo_ai`) |
| **File** | `Models/SeoProjectRun.php` |

**Columns:**
- `project_id` â†’ FK `seo_projects` (CASCADE)
- `user_id` â†’ ngÆ°á»i kick-off run
- `mode` â†’ `full` | `test` (test giá»›i háº¡n 1 task)
- `status` â†’ `running`, `completed`, `failed`
- `total`, `succeeded`, `failed` â†’ counters
- `items` â†’ JSON (danh sÃ¡ch task identities)
- `error_message` â†’ TEXT
- `started_at`, `finished_at` â†’ TIMESTAMP

**Relationships:**
- `project()`: BelongsTo â†’ `SeoProject`
- `user()`: BelongsTo â†’ `User` (cross-DB)

### 2.4 SeoTask (Workflow template)

| Khoáº£n má»¥c | GiÃ¡ trá»‹ |
|-----------|---------|
| **Table** | `seo_tasks` (connection `omi_seo_ai`) |
| **File** | `Models/SeoTask.php` |

**Columns:** `user_id`, `name`, `description`, `flow_data` (JSON â€” Ä‘á»‹nh nghÄ©a workflow steps), `is_active`

**Má»‘i quan há»‡:** SeoProjectTask khÃ´ng dÃ¹ng SeoTask trá»±c tiáº¿p; CreateArticlesFromTaskService uses `SeoTask::find($taskId)` Ä‘á»ƒ láº¥y `flow_data` vÃ  cháº¡y workflow cho tá»«ng task cá»§a project.

### 2.5 SeoPromptResultLink (cross-reference)

| Khoáº£n má»¥c | GiÃ¡ trá»‹ |
|-----------|---------|
| **Table** | `seo_prompt_result_links` (connection `omi_seo_ai`) |
| **File** | `Models/SeoPromptResultLink.php` |

ÄÃ¢y lÃ  báº£ng liÃªn káº¿t giá»¯a `PromptResult` (káº¿t quáº£ tá»« AI) vá»›i article, project run, project task. Cho phÃ©p truy xuáº¥t nguá»“n gá»‘c cá»§a má»—i output AI.

**Columns quan trá»ng:** `prompt_result_id`, `article_id`, `project_run_id`, `project_task_id`, `source`, `workflow_node_id`, `workflow_step_title`, `meta` (JSON)

**UNIQUE constraint:** `(prompt_result_id, source, project_run_id, project_task_id, workflow_node_id)`

### 2.6 task_test_results

| Khoáº£n má»¥c | GiÃ¡ trá»‹ |
|-----------|---------|
| **Table** | `task_test_results` (connection `omi_seo_ai`) |
| **File** | `Models/SeoTaskTestResult.php` |

LÆ°u káº¿t quáº£ test workflow cho má»™t task. Columns: `task_id` (FK â†’ `seo_tasks`), `input_snapshot` (JSON), `resolved_context` (JSON), `step_results` (JSON), `error_message`, `started_at`, `finished_at`.

**UI test:** `TaskResource/Pages/TestTask.php` + `test-task.blade.php` â€” `/tasks/{id}/test`; runner `TaskWorkflowTestRunner`; khÃ´ng chá»n model per-step; preview áº£nh á»Ÿ bÆ°á»›c prompt image (`stepMediaUrls`); Â«Cháº¡y láº¡iÂ» áº©n trÃªn node `end`.

---

## 3. Filament UI

### 3.1 Route structure

**Canonical UX:** Content Project â†’ Operations/Items â†’ Article.

```
/seo/{connection_hash}/content-projects           â†’ ListSeoProjects (index)
/seo/{connection_hash}/content-projects/create     â†’ CreateSeoProject
/seo/{connection_hash}/content-projects/{record}   â†’ ViewSeoProject (operations workspace â€” Run Results UX)
/seo/{connection_hash}/content-projects/{record}/edit â†’ EditSeoProject (settings form)
/seo/{connection_hash}/content-projects/{record}/publishing-queue â†’ ContentProjectPublishingQueue
/seo/{connection_hash}/content-operations          â†’ ContentProjectOperationsCenter (manager+; tabs gá»“m **Site Sync**: runs/events/diagnostics; tab **Runtime** cÅ©ng cÃ³ **MCP Reference** markdown; `SiteSyncOperationsCenter` áº©n sidebar nav)
/admin/content-operations                          â†’ ContentOperationsRedirect â†’ SEO ops
```

`ContentProjectOperationsCenter` tab **Site Sync**: recent runs (nÃºt theo status â€” completed: report/diagnostic/reconcile; failed: resume; running: cancel), inbound events, diagnostics. KhÃ´ng cÃ²n menu sidebar riÃªng **Site Sync Ops**.

**Scheduler flags (VALUE_NONE):** Trong `SeoContentAiServiceProvider`, cron Ä‘Äƒng kÃ½ string `--apply` / `--sync` (vd. `seo:content-project:recover-stale-generation --apply`, `agent:metrics:aggregate --sync`) â€” **khÃ´ng** `['--apply' => true]` (Symfony biáº¿n thÃ nh `--apply=1` â†’ fail). `RecoverContentProjectStaleGenerationCommand` má»—i 10 phÃºt.

**MCP / Agent capability contract:** `ContentProjectCapabilityRegistry` + `CanonicalCapabilityRegistry` â€” má»—i cap cÃ³ `capability_kind` (`system_action` vs site_feature keys trong `SiteSyncSchema`), `required_context`, `side_effect_level`, `action_domain`. Fail-closed: `CapabilityContextGuard` â†’ `missing_required_context` / `context_mismatch`. UI domain: `/domains/{id}/mcp` (`ViewDomainMcp` = Markdownâ†’sanitized HTML docs only); Agent slash palette = curated `AgentCliCommandCatalog` (khÃ´ng dump registry). General chá»‰ nÃºt link, khÃ´ng embed catalog. SEO Audit Agent read: `seo_audit.list` (`SeoAuditAgentReadService`, site-level, khÃ´ng cáº§n `project_ref`).

**Edit/Create sync duplicate input:** `SeoProjectTaskSyncService::assertNoDuplicateInput` / `assertNoDuplicateTasksData` â€” trÃ¹ng identity (`type`+`post_type`+`source_content`) vá»›i `task_id` khÃ¡c â†’ `ValidationException` (i18n `projects.sync_duplicate_input`). `ContentProjectCommandBus` map `ValidationException` â†’ `VALIDATION_FAILED` + `errors` bag (khÃ´ng nuá»‘t thÃ nh `RuntimeException` thÃ´). `EditSeoProject` / `CreateSeoProject` preflight trÆ°á»›c save; sync fail validation â†’ Filament field errors (`data.tasks_data.*`).

`ViewSeoProject` = mÃ n hÃ¬nh Ä‘iá»u hÃ nh chÃ­nh (dashboard váº­n hÃ nh compact):

- **Header:** Filament `getHeading` = project name; `getSubheading` = domain Â· owner Â· month. Actions: Generate pending Â· Edit project Â· Project info Â· More (Test run chá»‰ khi `allowsDevTestGenerateUi()`, áº©n production). KhÃ´ng láº·p tÃªn project trong card riÃªng.
- **KPI grid:** 2â†’4â†’8 cá»™t (`x-seo-content-ai::content-project-summary-card`); click Ã¡p filter; accent qua `ContentProjectStatusBadgePresenter::summaryAccent()`; active ring khi filter khá»›p.
- **Filter toolbar:** search + generation/lifecycle/queue/schedule + failed only + clear; mobile drawer Filters. **BulkSelectionToolbar** (`content-project-bulk-selection-toolbar`) chá»‰ hiá»‡n khi `selectedCount > 0` (nhÃ³m Content / Review / Publishing).
- **Má»™t báº£ng Project Items** canonical (`ContentProjectItemOperationsReadModel`): Item meta 2 dÃ²ng + badges Generation/Lifecycle/Queue + Schedule + Last activity + grouped actions menu (`ContentProjectItemActionsPresenter` â€” chá»‰ action há»£p lá»‡ UI). Sticky header, density vá»«a, mobile card list (`md:hidden` / `md:block`).
- Semantic badges: `ContentProjectStatusBadgePresenter` + `content-project-status-badge` (ná»n nháº¡t + icon + ring; dark-mode).
- Empty/loading: no items / no filter results / pulse skeleton.

**Publishing Queue:** khÃ´ng cÃ²n táº§ng/page báº¯t buá»™c. Route `/{record}/publishing-queue` â†’ redirect compatibility tá»›i `view?lifecycle=waiting_publish,published`. `getPublishingQueueUrl()` trá» cÃ¹ng filter.

**Legacy Run History (compatibility redirects only â€” khÃ´ng render UI):**

```
.../content-projects/{record}/runs              â†’ redirect â†’ ViewSeoProject
.../content-projects/runs/{run}                 â†’ redirect â†’ ViewSeoProject (project cá»§a run)
.../content-projects/runs/{run}/items/{article} â†’ redirect â†’ ViewSeoProject
```

Generate pending: header action trÃªn `ViewSeoProject` / `EditSeoProject` â†’ dry-run preview (`ContentProjectItemGenerationClassifier`) â†’ `GenerateProjectItemsCommand` + PHP `ContentProjectRunEngine`. Chá»‰ item **never-generated** (khÃ´ng cÃ³ execution success / article / lifecycle review|approved|published|scheduled / improve). Fail-closed náº¿u sáº½ chá»n cáº£ project khi Ä‘Ã£ cÃ³ execution lá»‹ch sá»­ (cáº§n technical confirm). Test generate chá»‰ hiá»‡n khi `allowsDevTestGenerateUi()` (local/testing + debug; fail-closed production).

**Project Items table:** render trÃªn `view-seo-project-operations.blade.php` (khÃ´ng RelationManager). Cá»™t: checkbox Â· Item Â· Generation Â· Lifecycle Â· Schedule Â· Queue Â· Last activity Â· Actions. Components flat (namespace `seo-content-ai`): `content-project-summary-card`, `content-project-status-badge`, `content-project-filter-toolbar`, `content-project-bulk-selection-toolbar`, `content-project-item-meta`, `content-project-item-actions-menu`. KhÃ´ng Ä‘iá»u hÆ°á»›ng Run History.

**Counters (list):** `Generated` (content ready) â‰  Run OK. TÃ¡ch `Pending` (chÆ°a generate) / `Failed`. KhÃ´ng dÃ¹ng â€œCompletedâ€ mÆ¡ há»“ so vá»›i Run succeeded.

**Compatibility:** `ContentProjectCounterAuditService` (audit 31 OK vs statusâ‰ completed), `ContentProjectLegacyExecutionHydrateService` (dry-run/idempotent; khÃ´ng AI; khÃ´ng Ä‘Ã¨ reviewing/completed).

`SeoProjectRun` = execution record ná»™i bá»™ (ADR-004). Ops/Timeline Ä‘á»c operation â€” khÃ´ng phá»¥c há»“i Run History hub.

Docs: [CONTENT_PROJECT_OPERATIONS.md](CONTENT_PROJECT_OPERATIONS.md) â€” dashboard, metrics, replay, health, analytics.

### 3.2 SeoProjectResource (`Filament/Resources/SeoProjectResource.php`)

- **Model:** `SeoProject`
- **Slug:** `content-projects`
- **Navigation:** "Content projects" â†’ `SEO Workspace` group, sort 8
- **Permission gates:**
  - `canViewAny()`: `SeoAccessControl::canAccessContentFeatures()`
  - `canCreate()`: `canAccessPlannerFeatures()`
  - `canEdit()`: `SeoAccessControl::canMutateContentProjects()`
  - Content manager: chá»‰ xem project cá»§a mÃ¬nh (`user_id == auth()->id()`)

**Assign keyword tá»« editor / keyword list:** `KeywordResource::assignKeywordContentProjectFormSchema()`, `assignKeywordContentProjectFormSchemaForSite()` (editor), `assignKeywordsToContentProject()` â†’ `SeoProjectTask::TYPE_CREATE` (`keyword` + `source_content`); form field `project_id_{siteId}`.

### 3.3 Form schema (create + edit)

**Section 1: Project Info** (2 columns)
- `user_id`: Select â†’ chá»n writer (Content Manager role)
- `site_id`: Select â†’ chá»n domain
- `month`: DatePicker â†’ format `m/Y`, default today's month
- `status_display`: Placeholder (read-only)
- `description`: Textarea

**Section 2: Article / Keyword List** (full width)
- `import_keywords`: Action â†’ modal nháº­p raw text (bullet/numbered/plain list) â†’ parse báº±ng `SeoProjectKeywordListParser`
- `ai_generate_keywords`: Action â†’ modal nháº­p sá»‘ lÆ°á»£ng + brief â†’ sinh AI báº±ng `SeoProjectKeywordAiGeneratorService`
- `tasks_data`: Repeater â†’ má»—i item lÃ  má»™t task:
  - `type`: Select Create / Rewrite / Improve (`TYPE_CREATE` | `TYPE_REWRITE` | `TYPE_IMPROVE`)
  - `keyword` | `title`: cÃ¹ng hÃ ng â€” Create/Rewrite; â‰¥1 field báº¯t buá»™c
  - `secondary_description`: Description optional (Create/Rewrite)
  - `source_content`: SearchableSelect Existing/Target article (Rewrite/Improve)
  - `rewrite_notes`: Improve instruction (Improve)
  - `post_type`: Select (article/product/category/product_category â€” Create)
  - `loai_san_pham` / `description` (gallery): Product Create only
  - KhÃ´ng cÃ²n Generate by / `new_keyword` / `new_title` / rewrite_mode UI

### 3.4 Table columns (List page)

| Column | Source | Ghi chÃº |
|--------|--------|---------|
| `name` | `->name` | Bold, searchable, sortable, linked |
| `user.name` | Relationship | Sortable, searchable |
| `site.domain` | Relationship (cross-DB) | Placeholder "â€”" |
| `month` | `->month` | Date format `m/Y`, sortable |
| `total_items` / `active_tasks_count` | Active tasks | Numeric |
| `generated` / `active_generated_count` | Content ready (completed\|reviewing\|article linked) | **KhÃ´ng** Ä‘á»“ng nghÄ©a Run OK |
| `pending_never_generated` | status=pending vÃ  chÆ°a article | Generate pending target |
| `failed` | status=failed | |
| `status` | `->status` | Badge (color-coded) |
| `updated_at` | `->updated_at` | Toggleable, hidden by default |

**Filters:** status, user_id, site_id, month

**Row actions:** `ActionGroup (...)` chá»©a `open_project_items` (workspace), `publishing_queue`, `archive_project`, `Delete`; bÃªn ngoÃ i `Edit` / `View`

**Bulk actions:** Delete â€” cÃ¹ng logic rollback thÃ¡ng trÆ°á»›c (`SeoProjectTaskMoveService`)

**Header list:** `open_site_archive` â†’ `findOrCreateArchiveProject` + edit archive; Create

**List query:** áº©n `kind=archive` (chá»‰ hiá»‡n project thÃ¡ng)

### 3.5 Legacy Run History pages (redirect stubs)

| Page | Route | Behavior |
|------|-------|----------|
| `ListSeoProjectRuns` | `/{record}/runs` | Redirect â†’ `ViewSeoProject` |
| `ViewSeoProjectRun` | `/runs/{run}` | Redirect â†’ project workspace |
| `ViewSeoProjectRunStep` | `/runs/{run}/items/{article}` | Redirect â†’ project workspace |

KhÃ´ng cÃ²n header Run / Test run / View run trÃªn Run History. Generate: `SeoProjectResource::makeGeneratePendingItemsAction` trÃªn View/Edit project. Blade `view-project-run.blade.php` + `project-run-queue.js` cÃ²n trong repo (asset/legacy) nhÆ°ng **khÃ´ng mount** qua Filament page.

### 3.6 ViewSeoProject / EditSeoProject â€” Project Items workspace

- Canonical items UI trÃªn form project (tasks repeater)
- Header: Generate pending items (+ optional Test generate khi `allowsDevTestGenerateUi()`), publishing queue, edit
- KhÃ´ng breadcrumb/link â€œRun historyâ€
- Item-level regenerate / step rerun: services (`ContentProjectStepRerunService`, `SeoProjectWorkflowStepRetryService`, article editor) â€” khÃ´ng qua Run Detail page

### 3.7 (removed) ViewSeoProjectRunStep UI

Redirect stub only â€” xem Â§3.5. Prompt timeline per article: article editor / `ArticlePromptRunHistoryService` (khÃ´ng mount qua run step route).

---

## 4. Services Layer

### 4.0 Business Hook (WP khÃ´ng gá»i trá»±c tiáº¿p tá»« workflow)

| Symbol | Vai trÃ² |
|--------|---------|
| `MarkProjectTaskCompletedAction` / bridge | Emit `project.task_completed` â†’ bridge map `content_project.task.completed` + `article.completed` náº¿u cÃ³ `article_id` |
| `BusinessHookEmitter` | `taskFailed`, `runCompleted`, `taskArchived`, `articleArchived` / `articleRestored` |
| Rule `sync-article-to-wordpress` | Seed **enabled+published** (business) â€” `article.completed` â†’ linear `wordpress.article.sync` â†’ `product-review.create` â†’ `product-review.sync-wp` on `automation-external` |
| `WordPressManualSyncService` | Manual only (`ManualSyncContext` + `ManualWordPressSyncJob` on `seo`); emit `wordpress.synced` origin=manual; khÃ´ng giáº£ automation |
| `ContentProjectWorkspaceSaveService` | Editor Sync khi bÃ i thuá»™c Content Project active = **Save Workspace** (`project_local_save`): `article.content.update` + stamp flags/hash; **khÃ´ng** WP API / khÃ´ng enqueue `seo`. TX ngáº¯n chá»‰ quanh stamp (`last_synced_at` + sync flags) â€” khÃ´ng bá»c cáº£ persist (trÃ¡nh Lock wait `articles.body`) |
| `automation:audit-wordpress-coupling` / `automation:audit-coupling` | Audit automatic/manual callers + ownership collisions |

Invariant: `SeoProjectWorkflowRunService` / `CreateArticlesFromTaskService` / `ArticleScheduleReconcileService` **khÃ´ng** import WP outbound hub. Completion â†’ business event only. Chi tiáº¿t: [AUTOMATION_CUTOVER_AUDIT.md](automation/AUTOMATION_CUTOVER_AUDIT.md).

**Release freeze (2026-07-20):** Task = business identity; run item = CP execution; Automation execution = workflow (immutable published version). Draft never executes. External WP side effect chá»‰ khi rule **enabled + published**. `ExecuteAutomationRuleJob` queue = `automation-critical` (khÃ´ng `default`).

### 4.1 Core process (diagram)

```mermaid
flowchart TB
    subgraph Filament["Filament Actions"]
        RUN["View/EditSeoProject.generate_pending_items"]
        SAVE["CreateSeoProject.save / EditSeoProject.save"]
    end

    subgraph Project_Services["Core SeoProject Services"]
        WORKFLOW["SeoProjectWorkflowRunService"]
        PREFLIGHT["SeoProjectRunPreflightService"]
        CONSOL["SeoProjectRunConsolidationService"]
        SYNC["SeoProjectTaskSyncService"]
        APPROVE["SeoProjectApprovalService"]
        ARCHIVE["SeoProjectArchiveService"]
        PARSER["SeoProjectKeywordListParser"]
        AI_GEN["SeoProjectKeywordAiGeneratorService"]
        OWNER["SeoProjectArticleOwnerSyncService"]
    end

    subgraph Workflow_Services["Workflow Execution"]
        CREATE_ART["CreateArticlesFromTaskService"]
        TEST_RUNNER["TaskWorkflowTestRunner"]
        INPUT_RES["TaskTestInputResolver"]
        PROMPT_RUNNER["PromptRunnerService"]
        WP_SYNC["WordPressArticleSyncService"]
        LINK_SYNC["DomainLinkListKeywordSyncService"]
    end

    subgraph Parser_Services["Workflow Parsing"]
        WPARSER["WorkflowParserService"]
        TAG_EXTRACT["WorkflowTagExtractorService"]
        AI_EXIST["WorkflowExistingAiOutputService"]
    end

    subgraph Historical["Historical Tracking"]
        RUN_HIST["ArticlePromptRunHistoryService"]
        LINK_HIST["PromptResultLinkService"]
    end

    RUN --> WORKFLOW
    WORKFLOW --> PREFLIGHT
    WORKFLOW --> CONSOL
    SAVE --> SYNC
    SAVE --> AI_GEN
    SAVE --> PARSER
    SAVE --> OWNER

    WORKFLOW --> CREATE_ART
    CREATE_ART --> TEST_RUNNER
    TEST_RUNNER --> INPUT_RES
    TEST_RUNNER --> PROMPT_RUNNER
    TEST_RUNNER --> WP_SYNC
    TEST_RUNNER --> LINK_SYNC
    TEST_RUNNER --> WPARSER
    WPARSER --> TAG_EXTRACT
    WPARSER --> AI_EXIST

    APP["EditArticle.approveLinkedProject"] --> APPROVE
    APPROVE --> WP_SYNC

    WORKFLOW --> RUN_HIST
    TEST_RUNNER --> LINK_HIST
    RUN_HIST --> LINK_HIST
```

### 4.2 Service descriptions

#### Core Project services (9 files)

| Service | File | MÃ´ táº£ |
|---------|------|-------|
| **SeoProjectWorkflowRunService** | `SeoProjectWorkflowRunService.php` | Äiá»u phá»‘i run: `startRun()` (`items=null`) â†’ `prepareRunQueue()` seed `seo_project_run_items` â†’ autorun `retryTask()` (cÃ¹ng task, khÃ´ng copy). Runtime SoT = run items; JSON `runs.items` chá»‰ legacy/debug. |
| **SeoProjectRunPreflightService** | `SeoProjectRunPreflightService.php` | Kiá»ƒm tra preflight trÆ°á»›c khi cháº¡y: tÃ¬m conflict keyword/title giá»¯a cÃ¡c pending task. formatWarningsForModal() sinh HTML cáº£nh bÃ¡o. |
| **SeoProjectRunConsolidationService** | `SeoProjectRunConsolidationService.php` | Phase 3C3: mark `consolidated_into_run_id`/`consolidated_at`, relink run items sang keeper â€” **khÃ´ng** hard-delete run. UI list `notConsolidated()`. |
| **SeoProjectRunItemService** | `SeoProjectRunItemService.php` | Claim/retry/counters trÃªn `seo_project_run_items`; `mirrorJsonSafely()` no-op (Phase 3C3). |
| **SeoProjectRunItemsReader** | `SeoProjectRunItemsReader.php` | Äá»c run: DB XOR legacy JSON â€” khÃ´ng merge dual-source. |
| **SeoProjectRunItemMergeService** | `SeoProjectRunItemMergeService.php` | Relink/merge khi collapse duplicate task hoáº·c consolidate run (`relinkTask` / `relinkRun`). |
| **SeoProjectRunItemsDisplayPresenter** | `Support/SeoProjectRunItemsDisplayPresenter.php` | Gom hÃ ng báº£ng ViewSeoProjectRun: `consolidate()` â€” 1 task/article = 1 row (view layer); giá»¯ raw history; badge/note `retry_count`. Test: `SeoProjectRunItemsDisplayPresenterTest`. |
| **SeoProjectWorkflowStepCatalogService** | `SeoProjectWorkflowStepCatalogService.php` | Liá»‡t kÃª node `prompt` rerunnable tá»« SeoTask publish/rewrite; kind + label + order outlineâ†’content. |
| **SeoProjectWorkflowStepRetryService** | `SeoProjectWorkflowStepRetryService.php` | Rerun tá»«ng prompt (`action=step:{nodeId}`); `cancelActiveStep` / `resolveActiveStepIdsForCancel`; claim/success khÃ´ng Ä‘Ã¨ cancel marker; log `seo.project_run.cancel_workflow_step`. |
| **ContentProjectRunEngine** | `Services/RunEngine/ContentProjectRunEngine.php` | Phase 1 PHP orchestration (flag `CONTENT_PROJECT_PHP_ENGINE`): start/stop/dispatch/finalize; job `RunContentProjectArticleJob`; runner reuse `retryTask`; doc `architecture/CONTENT_PROJECT_RUN_ENGINE_REFACTOR.md` + handoff `audits/CONTENT_PROJECT_RUN_ENGINE_PHASE1_HANDOFF.md`. |
| **ContentProjectArticleRunner** | `Services/RunEngine/ContentProjectArticleRunner.php` | Cháº¡y 1 article trong run; normalize `ArticleExecutionResult`; khÃ´ng dispatch next. |
| **ArticleLastSavedTimestampService** | `ArticleLastSavedTimestampService.php` | `last_manual_saved_at` / `last_synced_at` trÃªn `articles`; resolve display cho cá»™t Â«Láº§n cuá»‘i lÆ°uÂ». |
| **SeoProjectTaskSyncService** | `SeoProjectTaskSyncService.php` | Diff/upsert theo `task_id` â†’ `source_key`; khÃ´ng delete-all/recreate; create qua `SeoProjectTaskUniqueWriter::createStrict()`. |
| **SeoProjectTaskLifecycleService** | `SeoProjectTaskLifecycleService.php` | Archive/restore/softDelete trÃªn task row; mirror `seo_content_archive_items`. |
| **SeoProjectTaskRepairService** | `SeoProjectTaskRepairService.php` | Phase 3C3 repair: backfill `source_key`, merge duplicate groups, archive mirrors, purge sync orphans. |
| **SeoProjectTaskUniqueWriter** | `SeoProjectTaskUniqueWriter.php` | Create race-safe dÆ°á»›i UNIQUE(`project_id`,`source_key`); map conflict â†’ `CONTENT_PROJECT_TASK_SOURCE_KEY_CONFLICT`. |
| **SeoProjectTaskCanonicalResolver** | `Support/SeoProjectTaskCanonicalResolver.php` | Repair-time pick canonical trong duplicate `source_key` group (class Aâ€“F). |
| **ProjectTaskSourceKeyGenerator** | `Support/ProjectTaskSourceKeyGenerator.php` | Generator chuáº©n `source_key` (NFC + lowercase + collapse whitespace). |
| **SeoProjectApprovalService** | `SeoProjectApprovalService.php` | approveLinkedProject() â†’ Ä‘Ã¡nh dáº¥u project lÃ  "approved", yÃªu cáº§u user lÃ  Content Manager. |
| **SeoProjectArchiveService** | `SeoProjectArchiveService.php` | Archive qua lifecycle; resolve article tá»« run items reader (khÃ´ng Ä‘á»c JSON business). |
| **SeoProjectTaskMoveService** | `SeoProjectTaskMoveService.php` | `deleteProjectRollingBackToPreviousMonth()` â€” xÃ³a project thÃ¡ng, chuyá»ƒn má»i task vá» thÃ¡ng âˆ’1 (táº¡o náº¿u thiáº¿u, cháº·n náº¿u Ä‘áº§y); `moveTasksToProject()` di chuyá»ƒn item sang thÃ¡ng/archive khÃ¡c. |
| **SeoProjectKeywordListParser** | `SeoProjectKeywordListParser.php` | parse() â†’ phÃ¢n tÃ­ch raw text (bullet, numbered, plain lines) thÃ nh máº£ng keyword. appendKeywordsToTasks() â†’ gá»™p vÃ o tasks_data hiá»‡n táº¡i. |
| **SeoProjectKeywordAiGeneratorService** | `SeoProjectKeywordAiGeneratorService.php` | generate() â†’ gá»i AI sinh danh sÃ¡ch keyword cho thÃ¡ng, dá»±a trÃªn brief + description. |
| **SeoProjectArticleOwnerSyncService** | `SeoProjectArticleOwnerSyncService.php` | syncProjectArticles() â†’ Ä‘á»“ng bá»™ user_id tá»« project sang article liÃªn káº¿t. |

#### Workflow Execution services (4 files)

| Service | File | MÃ´ táº£ |
|---------|------|-------|
| **CreateArticlesFromTaskService** | `CreateArticlesFromTaskService.php` | Phase 0.6: CREATE â†’ `ArticleWritingExecutionService` PublishGraph; TYPE_REWRITE Â«Táº¡o láº¡i bÃ i tá»« dÃ n Ã½Â» â†’ ContentNode (khÃ´ng cháº¡y láº¡i outline); TYPE_IMPROVE â†’ `ArticleImproveExecutionService` (khÃ´ng Publish). `runOutlineThenArticleForContext()` = outline má»›i + article. KhÃ´ng Ä‘á»c `rewrite_article_task_id`. |
| **ArticleWritingExecutionService** | `ArticleWritingExecutionService.php` | Entry duy nháº¥t `article.content.generate`: validate source â†’ provider â†’ format â†’ Prompt owner XOR â†’ execute (publish_graph / content_node / direct_generate) â†’ persist + history. |
| **ArticleImproveExecutionService** | `ArticleImproveExecutionService.php` | Improve riÃªng (`article.content.improve` Settings binding). KhÃ´ng generate / khÃ´ng outline / khÃ´ng `article_length` full / khÃ´ng `getPublishArticleTaskId()`. |
| **TaskWorkflowTestRunner** | `TaskWorkflowTestRunner.php` | Engine workflow: AI â†’ `PromptTestPublishService.publishArticle` (chá»‰ Laravel). Content generate stamp `prompt_owner_type=workflow_node`. Gallery product chá»‰ khi `isProductWorkflowContext`. |
| **TaskTestInputResolver** | `TaskTestInputResolver.php` | `resolveForProjectTask()`: Create â†’ `contextForNewArticleOnSite` (khÃ´ng copy keywordâ†”title); inject optional Keyword/Title/`secondary_description`. Rewrite/Improve â†’ `resolveExistingArticleRewrite()` (body Markdown + notes); thiáº¿u bÃ i â†’ **throw**. |
| **SeoProjectTaskSyncDataNormalizer** | `Support/SeoProjectTaskSyncDataNormalizer.php` | Chuáº©n hÃ³a Create/Rewrite/Improve; derive `source_content`; `allowedSiteIds()` = `SeoAccessControl::accessibleSiteIds()`. |
| **PromptTestPublishService** | `PromptTestPublishService.php` | `publishArticle()` lÆ°u title/body/meta Laravel + `markLocalEditPending` â€” **khÃ´ng** `WordPressArticleSyncService`. |
| **PromptRunnerService** | `PromptRunnerService.php` | Engine AI cáº¥p tháº¥p nháº¥t: gá»­i request Ä‘áº¿n AI model, xá»­ lÃ½ streaming, lÆ°u PromptResult. |

#### Workflow Parser services (3 files)

| Service | File | MÃ´ táº£ |
|---------|------|-------|
| **WorkflowParserService** | `WorkflowParserService.php` (2609 dÃ²ng) | PhÃ¢n tÃ­ch output workflow tá»« AI (Markdown outline, structured content) â†’ cáº¥u trÃºc dá»¯ liá»‡u article. FAQ parsing + shortcode. |
| **WorkflowTagExtractorService** | `WorkflowTagExtractorService.php` | TrÃ­ch xuáº¥t tagged sections tá»« raw text output (VD: `<OUTLINE>`, `<CONTENT>`). |
| **WorkflowExistingAiOutputService** | `WorkflowExistingAiOutputService.php` | XÃ¡c Ä‘á»‹nh output AI cÃ³ sáºµn (outline/content) cÃ³ thá»ƒ tÃ¡i sá»­ dá»¥ng. Task REWRITE / cÃ³ `rewriteMode` â†’ khÃ´ng reuse (`TaskWorkflowTestRunner::shouldReuseExistingAiOutput()`). |
| **SimpleMarkdownHtmlConverter** | `Support/SimpleMarkdownHtmlConverter.php` | Markdown â†’ HTML cho editor. `prepareImport()` á»§y quyá»n `ArticleMarkdownImportParser`: tÃ¡ch `h1_title` / `seo_title` / `meta_description`, bá» structural wrappers, giá»¯ body sáº¡ch. |
| **ArticleMarkdownImportParser** | `Support/ArticleMarkdownImportParser.php` | Parser theo dÃ²ng + allowlist exact. Nháº­n `# Title`, `H1:`, `**Meta Description:**`, `### 1. Meta Description`, plain numbered `1. Meta Description:` / `2. SEO Title:` / `3. Introduction` / `4. Main Content:`; khÃ´ng xÃ³a list sá»‘ thÆ°á»ng; HTML document tráº£ nguyÃªn; outer ` ```markdown ` fence gá»¡ khi bá»c cáº£ document. |
| **ArticlePostTypeResolver** | `Support/ArticlePostTypeResolver.php` | Resolve post type hiá»‡u lá»±c cá»§a article: Æ°u tiÃªn `articles.type` local, `wp_post_type` meta chá»‰ fallback khi type trá»‘ng (trÃ¡nh meta WP stale Ã©p nháº§m product). Rewrite khÃ´ng ghi Ä‘Ã¨ type bÃ i (`CreateArticlesFromTaskService::ensureArticlePostType()` skip TYPE_REWRITE). |

#### Supporting services (4 files)

| Service | File | MÃ´ táº£ |
|---------|------|-------|
| **SeoNotificationService** | `SeoNotificationService.php` | Gá»­i Filament notification cho cÃ¡c sá»± kiá»‡n project: gÃ¡n owner, approved, task added. DÃ¹ng khi `KeywordResource::assignKeywordsToContentProject()` thÃªm task. |
| **ArticlePendingInternalLinkService** | `ArticlePendingInternalLinkService.php` | GÃ¡n keyword vÃ o `SeoProjectTask` + táº¡o pending link `#hash` tá»« editor (`assignFromEditor`). |
| **PromptResultLinkService** | `PromptResultLinkService.php` | LiÃªn káº¿t PromptResult vá»›i task/article cá»§a project Ä‘á»ƒ truy xuáº¥t nguá»“n gá»‘c output AI. |
| **ArticlePromptRunHistoryService** | `ArticlePromptRunHistoryService.php` | `build()` timeline `/articles/{id}/prompts`. Phase 2.1: `execution_type` First/Retry/Rerun + `status_label` (stale/blocked). |
| **WorkflowKeywordResearchService** | `WorkflowKeywordResearchService.php` | `syncTopicCluster()` â€” lÆ°u Topic Cluster tá»« action workflow `save_vocabulary_research`; khÃ´ng throw khi focus keyword khá»›p CTA blacklist. |
| **AllDomainsDashboardService** | `AllDomainsDashboardService.php` | Tá»•ng há»£p thá»‘ng kÃª article/project/task trÃªn táº¥t cáº£ sites cho All-Domains Dashboard. |

---

## 5. Luá»“ng dá»¯ liá»‡u chi tiáº¿t

### 5.1 Táº¡o Project (Create/CreateSeoProject)

```mermaid
sequenceDiagram
    actor User as Content Planner
    participant Form as CreateSeoProject Form
    participant RS as SeoProjectResource
    participant Sync as SeoProjectTaskSyncService
    participant DB as omi_seo_ai

    User->>Form: Chá»n user, site, month, nháº­p keywords
    Form->>Form: mutateFormDataBeforeCreate()
    Form->>RS: normalizeProjectSiteId()
    Form->>Sync: sanitizeTasksData(tasksData, siteId)
    Form->>Sync: assertWithinMonthlyLimit(month, sanitized)
    Form->>DB: SeoProject::create({status: 'manual', ...})
    Form->>Sync: sync(project, tasksData)
    Sync->>DB: INSERT seo_project_tasks x N
    Form->>User: Redirect to EditProject
```

### 5.2 Generate pending items (Project workspace)

```mermaid
sequenceDiagram
    actor Planner as Content Planner
    participant UI as View/EditSeoProject
    participant RS as SeoProjectResource
    participant Bus as ContentProjectCommandBus
    participant Run as SeoProjectWorkflowRunService
    participant Engine as ContentProjectRunEngine

    Planner->>UI: Generate pending items
    UI->>RS: startGeneratePendingItems(project, full)
    RS->>Bus: GenerateProjectItemsCommand
    Bus->>Run: startRun + prepareRunQueue
    Run->>DB: INSERT seo_project_run (internal)
    RS->>Engine: start(run)
    UI->>Planner: Stay on project workspace
```

`SeoProjectRun` khÃ´ng má»Ÿ UI. Progress qua item status / Operations / timeline.

### 5.3 Äá»“ng bá»™ task type â†’ bÃ i viáº¿t

```
create   â”€â”€â”€â†’ SeoArticle.create() â€” inject Keyword/Title/secondary_description náº¿u cÃ³ (publish SeoTask)
rewrite  â”€â”€â”€â†’ SeoArticle.update() bÃ i cÅ© â€” Ä‘á»c body + optional Keyword/Title/Description (rewrite SeoTask)
improve  â”€â”€â”€â†’ SeoArticle.update() â€” chá»‰ Prompt Improve (rewrite SeoTask); khÃ´ng Outline/Image/Meta post-run
```

### 5.4 Archive Content Project (Ä‘Æ¡n vá»‹ = project)

**ÄÆ¡n vá»‹ archive chÃ­nh = Content Project (monthly), khÃ´ng pháº£i bÃ i láº».**

| ThÃ nh pháº§n | Chi tiáº¿t |
|---|---|
| Flag active/kho | `seo_projects.archived_at` / `archived_by` â€” active = `whereNull(archived_at)` |
| Header | `seo_project_archives` (+ snapshot/stats): 1 record hiá»‡n hÃ nh / project (`restored_at IS NULL`) |
| Items | `seo_project_archive_items` (+ `task_id`, `position`, `article_snapshot`) |
| Service | `ArchiveContentProjectService` â€” transaction, khÃ´ng Ä‘á»•i task/article status, khÃ´ng detach |
| Export | `ContentProjectArchiveExportService` (OpenSpout XLSX, `ExcelFormulaEscaper`) |
| Migration | `2026_07_24_140000_extend_seo_project_archives_for_project_unit` |

```
ArchiveContentProjectService.archive(project, userId, note?)
  1. lock project; reject náº¿u Ä‘Ã£ archived_at
  2. buildSummary tá»« tasks/articles
  3. upsert seo_project_archives + sync items snapshot
  4. set project.archived_at/by
ArchiveContentProjectService.restore(project, userId)
  â†’ clear project.archived_*; set archive.restored_*; giá»¯ snapshot
```

**KhÃ´ng:** soft-delete article, lifecycle archive tá»«ng task, set `content_archived_at` hÃ ng loáº¡t, táº¡o báº£ng archive song song.

**UI:**
- List active: action **LÆ°u trá»¯ dá»± Ã¡n**; nÃºt **Kho dá»± Ã¡n Ä‘Ã£ lÆ°u trá»¯** â†’ `/content-projects/archive`
- Tab 1: dá»± Ã¡n Ä‘Ã£ lÆ°u trá»¯ (preview / Excel / restore)
- Tab 2: **Legacy bÃ i láº»** (`content_archived_at` / `seo_content_archive_items`) â€” chá»‰ Ä‘á»c
- Preview: `/content-projects/archive/{archive}/preview` â€” `{archive}` = ID `seo_project_archives` (khÃ´ng pháº£i project gá»‘c).
  - Page `ContentProjectArchivePreview`: route param scalar `$archive`, model `$archiveRecord` (trÃ¡nh Livewire bind Eloquent trÃ¹ng tÃªn param â†’ 404 layout rá»—ng).
  - KhÃ´ng phá»¥ thuá»™c global domain. Snapshot lá»—i â†’ banner + `RuntimeLogger` (`archive_id`, `source_project_id`), khÃ´ng giáº£ 404.
  - Header summary: CSS grid 1â†’2â†’4 cá»™t (class `fi-archive-preview-summary-grid`, khÃ´ng phá»¥ thuá»™c Tailwind purge).
  - Table full width; title link `text-primary-600` (tab má»›i); cá»™t **Int/Ext** (`internal_link_count` / `external_link_count` tá»« article hoáº·c snapshot).
  - Hydrate rows: method `rebuildArticleRows()` â€” **khÃ´ng** Ä‘áº·t tÃªn `hydrate*` (Livewire coi lÃ  lifecycle hook â†’ `BadMethodCallException`).
- Details bÃ i: Filament Action `viewArchiveItem` **slideOver** (`MaxWidth::FourExtraLarge`, sticky header) + partial `archive-preview-item-slideover` (section Main / SEO / Status / Links / Timestamps / Excerpt).
  - Presenter `ArchivePreviewArticlePresenter`: batch `whereIn` article IDs, map `edit_url` qua `ArticleResource::getUrl('edit')` (binding khÃ´ng scope global domain). KhÃ´ng lazy-load `task`/`articleMetas` náº¿u chÆ°a eager. Auth/URL factory thiáº¿u (pure PHPUnit) â†’ catch, `can_edit=false`.
  - Article máº¥t â†’ badge `archive_preview_article_missing`, khÃ´ng link há»ng.
- Modal archive confirm: bá» dÃ²ng **ÄÃ£ duyá»‡t**; count chá»‰ `tasks()->active()` cÃ²n gáº¯n project. Field `approved_articles` váº«n lÆ°u snapshot (tÆ°Æ¡ng thÃ­ch cÅ©).
- List widget **Staff chÆ°a cÃ³ dá»± Ã¡n** (`UnassignedContentProjectStaffWidget` + `ContentProjectStaffAvailabilityService`): `role=staff` + `seo_role=content_manager`, chÆ°a lÃ  `user_id` cá»§a project active. Create: nhÃ³m unassigned + Staff khÃ¡c; preselect `?writer_id=`; create + assign trong transaction + race validate.
- Tests: `ContentProjectArchivePreviewAndDomainContextTest`, `ArchivePreviewArticleUiTest` (pure PHPUnit â€” dÃ¹ng `dirname(__DIR__, 2)`, khÃ´ng `base_path()`).

**Global domain (UI context, khÃ´ng pháº£i auth):**
- List Content Projects / Articles: váº«n filter theo `SeoAccessControl::globalSiteId()`.
- Detail/edit/preview: `getRecordRouteBindingEloquentQuery()` **khÃ´ng** Ã¡p global site scope. `ArticleResource` / `SeoProjectResource` **override** `resolveRecordRouteBinding()` (Filament core máº·c Ä‘á»‹nh gá»i `getEloquentQuery()` â€” thiáº¿u override = 404 khi má»Ÿ record khÃ¡c domain). `canView` project dÃ¹ng `canAccessSite`, khÃ´ng dÃ¹ng `getEloquentQuery()` Ä‘Ã£ scope domain.
- Edit article khÃ¡c domain: má»Ÿ Ä‘Æ°á»£c, note badge, **khÃ´ng** auto `setGlobalSiteId`, **khÃ´ng** 404 giáº£.
- Legacy run routes redirect via `getRecordRouteBindingEloquentQuery()` / `SeoProjectRun.project`.
- Guard tests: `ContentProjectArchivePreviewAndDomainContextTest` (`test_article_resolve_record_route_binding_uses_unscoped_query`, project twin).

**â€œHoÃ n táº¥t duyá»‡tâ€** (`ArticleReviewService` action `archive`): chá»‰ `review_status=archived` + audit log. **KhÃ´ng** detach task, **khÃ´ng** `content_archived_at`.

**Deprecated:** `SeoProjectArchiveService` (warehouse/task mirror), run UI `archiveItem`, action `archive_project_articles`.

### 5.4b (legacy) Project kind=archive / batch cÅ©

`seo_projects.kind=archive` vÃ  flow move-task-sang-kho-domain: Ä‘Ã£ migrate sang `seo_content_archive_items`. Giá»¯ Ä‘á»c; khÃ´ng dÃ¹ng cho archive project má»›i.

### 5.5 XÃ³a project thÃ¡ng (rollback)

```
SeoProjectTaskMoveService.deleteProjectRollingBackToPreviousMonth(project)
  â†’ chuyá»ƒn má»i task vá» thÃ¡ng trÆ°á»›c cÃ¹ng domain (táº¡o náº¿u chÆ°a cÃ³)
  â†’ náº¿u thÃ¡ng trÆ°á»›c Ä‘áº§y capacity â†’ cháº·n xÃ³a
```

Edit repeater: `extraItemActions` **Di chuyá»ƒn** item sang project thÃ¡ng/archive khÃ¡c cÃ²n chá»—.

---

## 6. Authorization

| Permission | Method | Ghi chÃº |
|-----------|--------|---------|
| Xem danh sÃ¡ch project | `canAccessContentFeatures()` | User cÃ³ SEO role cÆ¡ báº£n |
| Xem chi tiáº¿t project | `canAccessPlannerFeatures()` | Planner + |
| Táº¡o project | `canAccessPlannerFeatures()` | Planner |
| Sá»­a project | `canMutateContentProjects()` | Manager + (trá»« Content Manager) |
| XÃ³a project | `canAccessPlannerFeatures()` | Planner |
| Duyá»‡t project | `isContentManager()` | Chá»‰ Content Manager |
| Cháº¡y workflow | `canAccessContentProjectRun()` | Kiá»ƒm tra quyá»n truy cáº­p run |
| Content Manager scope | `isContentManager()` | Chá»‰ xem project cá»§a mÃ¬nh (`user_id == auth()->id()`) |
| Archive project / xem lá»‹ch sá»­ | `canArchiveContentProjects()` / `canViewProjectArchives()` | Manager + Admin |

---

## Phase 0.7 â€” Workflow execution roles + bulk 3 action + Improve default

### Storage

`SeoTask.flow_data.nodes[].data.execution_role` â€” khÃ´ng migration DB.

### Registry

`WorkflowExecutionRoleRegistry` + enum `WorkflowExecutionRole`:

| Role | Label VI |
|---|---|
| `article.outline.generate` | Táº¡o dÃ n Ã½ |
| `article.content.generate` | Viáº¿t bÃ i |
| `article.content.improve` | Cáº£i thiá»‡n bÃ i viáº¿t |
| `article.image.generate` | Táº¡o hÃ¬nh áº£nh |

Runtime lookup: `WorkflowExecutionRoleResolver` â€” **khÃ´ng** title/hook heuristic.

### Migration command

```text
php artisan seo:workflow:assign-execution-roles
php artisan seo:workflow:assign-execution-roles --apply
```

Auto-assign chá»‰ khi hook map 1-1 vÃ  khÃ´ng duplicate. Ambiguous â†’ null (operator chá»n trong Builder).

Improve default binding (plain `php`, khÃ´ng dÃ¹ng `$PHP_BIN`):

```text
php artisan seo:prompt:install-default-improve
```
Ba action (`ContentProjectBulkRerunService`):

1. `regenerate_outline` â€” chá»‰ outline role
2. `regenerate_article` â€” chá»‰ content role (dÃ n Ã½ hiá»‡n táº¡i)
3. `regenerate_outline_and_article` â€” outline má»›i â†’ artifact hash â†’ content; outline fail â†’ article blocked

### Improve default

`DefaultImprovePromptInstaller` + migration `2026_07_26_140000_*` â€” Prompt + Settings binding náº¿u thiáº¿u; khÃ´ng overwrite binding Ä‘Ã£ cÃ³.

Scope: `article|section|selection` â€” hiá»‡n chá»‰ `article` persist an toÃ n; selection/section reject rÃµ.

### Heuristic Ä‘Ã£ xÃ³a (runtime)

- `ArticleWritingExecutionService::resolveContentNodeId` title/2nd-prompt fallback
- `SeoProjectWorkflowStepCatalogService::detectKind` title haystack

Giá»¯ suggester title-free; only hook trong `WorkflowRoleMigrationSuggester`.

---

## Phase 0.9 â€” Remove remaining heuristics + lock Article Writing contract

### Runtime contract only

`execution_role` / `source_type` / execution snapshot / explicit `node_id` / prompt owner.

Heuristic title/position **Ä‘Ã£ xÃ³a** khá»i:

- `TaskWorkflowTestRunner` (`captureOutlinePromptOutput`, filter hydrate, merge-outline support)
- `ArticleGenerationInputResolver::isOutlineProducerStep`
- `WorkflowExistingAiOutputService::outputType`
- Builder `isWriteFromOutlinePrompt` (hook / `supports_merge_outline_save`)

Heuristic **chá»‰ cÃ²n** `WorkflowRoleMigrationSuggester` (migration/audit).

### Legacy

- `rewrite_article_task_id` DB **giá»¯**; runtime khÃ´ng Ä‘á»c `getRewriteArticleTaskId()`
- `ArticleWritingLegacyRewriteAdapter` má»ng: remap hook + map existing_article + log + delegate

### Retry

Thiáº¿u snapshot â†’ `KhÃ´ng thá»ƒ thá»­ láº¡i láº§n cháº¡y cÅ©. HÃ£y chá»n Â«Cháº¡y láº¡i báº±ng cáº¥u hÃ¬nh hiá»‡n táº¡iÂ».` â€” khÃ´ng vÃ¡ live.

### Tests

`ArticleWritingPhase09Test` + cáº­p nháº­t ExistingAiOutput / PipelineRerun detectKind.

---

## Phase 1.0 â€” Stable lock + legacy surface cleanup

### Contract cuá»‘i

`article.content.generate` + `source_type` âˆˆ {outline, existing_article, brief}  
`article.content.improve` tÃ¡ch riÃªng  
`article.content.rewrite` = **DEPRECATED COMPATIBILITY ONLY** â†’ remap generate + existing_article

### UI

- Settings: khÃ´ng render rewrite selector; save **preserve** `rewrite_article_task_id`
- Hook selector: khÃ´ng cho táº¡o má»›i rewrite; Prompt cÅ© xem Ä‘Æ°á»£c + warning/badge Legacy
- Duplicate Prompt rewrite â†’ remap generate
- Builder: merge-outline chá»‰ `article.content.generate`

### Stable Gate

`ArticleWritingStableHealthService` + `seo:workflow:doctor` in:

`Article Writing Stable Gate: PASS|WARN|FAIL`

### Adapter callers

- `PromptHookExplicitBindingExecutor` â€” chá»‰ khi hook rewrite
- `TaskWorkflowTestRunner` â€” chá»‰ khi hook rewrite  
Generate binding **khÃ´ng** log `article_writing.legacy_adapter_used`

### DB

`rewrite_article_task_id` â€” deprecated_since Phase 1.0; planned_drop sau khi adapter log=0; **khÃ´ng drop** release nÃ y.

### Tests

`ArticleWritingStablePhase10Test`

---

## Phase 2.0 â€” Step Rerun + Bulk Execution

**Verdict:** Canary ready â†’ khÃ³a tiáº¿p Phase 2.1.

KhÃ´ng má»Ÿ láº¡i Article Writing / khÃ´ng engine má»›i / khÃ´ng parallel article / khÃ´ng Agent-SSE.

### Retry vs Rerun

| | Retry | Rerun |
|---|---|---|
| User | Â«Thá»­ láº¡i láº§n cháº¡y lá»—iÂ» | Â«Cháº¡y láº¡i báº±ng cáº¥u hÃ¬nh hiá»‡n táº¡iÂ» |
| Service | `SeoProjectWorkflowStepRetryService` (mutate `step:{nodeId}` + attempt++) | `ContentProjectStepRerunService` |
| Config | Snapshot láº§n lá»—i (Article Writing path) | Live Publish workflow + Prompt + Settings hiá»‡n táº¡i |
| Record | CÃ¹ng row Model A | **Append-only** `action = step:rr:{ulid}` â€” khÃ´ng ghi Ä‘Ã¨ history cÅ© |

### Step Catalog

`SeoProjectWorkflowStepCatalogService::listStepDescriptors()` â†’ `ContentProjectStepDescriptor`:

`node_id`, `execution_role`, `hook_key`, `post_type`, `label`, `kind`, `sequence`, `rerunnable`, `source_requirements`, `downstream_nodes`, `prompt_id`

Identity: `execution_role` / `hook_key` / image tool â€” **khÃ´ng** title heuristic.  
`listGenericPickerSteps()` loáº¡i outline+content (Ä‘Ã£ cÃ³ 3 nÃºt Article).

### Source contract

`ContentProjectStepSourceValidator` â€” outline cáº§n title; article content cáº§n outline usable; FAQ/meta/image cáº§n article body. Thiáº¿u source â†’ khÃ´ng gá»i AI.

### Typed request / result

- `ContentProjectStepRerunRequest` â€” `mode`: `single_step` (UI máº·c Ä‘á»‹nh) | `step_and_downstream`
- `ContentProjectStepRerunResult`
- Metadata item: `execution_type=rerun`, `source_run_id`, `source_run_item_id`, `target_node_id`, `target_execution_role`, `rerun_mode`, `uses_current_workflow`

### Ba action Article (giá»¯ rÃµ)

`ContentProjectBulkRerunService` â†’ á»§y quyá»n `ContentProjectStepRerunService::executeBulkSerial`:

1. `regenerate_outline` â€” single outline node  
2. `regenerate_article` â€” single content node  
3. `regenerate_outline_and_article` â€” `step_and_downstream` + handoff `CreateArticlesFromTaskService::runOutlineThenArticleForContext`

Bulk: preview valid/invalid â†’ confirm partial â†’ **serial** tá»«ng article. KhÃ´ng Â«Cháº¡y láº¡i toÃ n bá»™Â» (`canRerunAllItems()=false`).

### UI (Phase 2.0 â€” updated)

Step rerun services váº«n tá»“n táº¡i; **khÃ´ng** mount qua `ViewSeoProjectRun` (redirect stub). Entry Ä‘iá»ƒm: article editor / project items. Leftover `view-project-run.blade.php` + `project-run-queue.js` khÃ´ng gáº¯n Filament page.

### Tests

`ContentProjectStepRerunPhase20Test`, `ContentProjectBulkRerunPhase20Test`

---

## Phase 2.1 â€” Final UX + History + Timestamp lock

**Verdict:** Content Project step rerun **Stable** (with minor limitations: featured hard-delete gallery cÅ© chá»§ yáº¿u append; modal Alpine khÃ´ng Filament Action class).

### Generic step modal

Bá» `window.prompt`. Alpine modal `genericStepOpen` trÃªn run page:

- Options tá»« `genericPickerSteps` / catalog rerunnable  
- Preview Livewire `previewBulkGenericStep`  
- Submit `bulkRerunGenericStep` â†’ cÃ¹ng `ContentProjectStepRerunService`  
- Partial: Â«Cháº¡y N bÃ i há»£p lá»‡Â» chá»‰ sau confirm

### Row status

`ContentProjectArticleRowStatusResolver` + `ContentProjectArticleRowStatus`:

Priority: Active â†’ Failed(+step) â†’ `ignored_stale` â†’ Manual edit (`manual_saved_at` > `last_ai_content_at`) â†’ Completed â†’ Pending  

Labels: `Äang cháº¡y: {step}`, `Lá»—i: {step}`, `Bá» qua káº¿t quáº£ AI cÅ©`, `ÄÃ£ sá»­a thá»§ cÃ´ng`, â€¦

### Last saved contract

`ArticleLastContentChange` / `ArticleLastContentChangeResolver`:

- Max(`last_manual_saved_at`, `last_synced_at`, `last_ai_content_at`)  
- Tráº£ `occurred_at` + `source` (`manual`|`sync`|`ai`) â€” khÃ´ng chá»‰ Carbon  
- **KhÃ´ng** `updated_at` / poll / heartbeat  
- UI: relative + tooltip absolute + nguá»“n

### `last_ai_content_at`

Migration `2026_07_26_160000_add_last_ai_content_at_to_articles_table`  
Touch: `ArticleLastSavedTimestampService::touchAiContent` sau `PromptTestPublishService::publishArticle` khi body hash Ä‘á»•i  

**CÃ³ touch:** first-run / article rerun / outline+article / editor full rewrite / Improve scope=article / brief generate (qua publish body)  
**KhÃ´ng touch:** outline-only, FAQ/meta/image-only, ignored_stale, AI fail, manual save, sync

### History

`ArticlePromptRunHistoryService` + `view-article-prompts.blade.php`:

- `execution_type` / `execution_type_label`: Láº§n cháº¡y Ä‘áº§u | Thá»­ láº¡i | Cháº¡y láº¡i  
- Status UI: ThÃ nh cÃ´ng / Lá»—i / Äang cháº¡y / Bá» qua vÃ¬ bÃ i Ä‘Ã£ thay Ä‘á»•i / Bá»‹ cháº·nâ€¦  
- Rerun append-only hiá»‡n riÃªng â€” khÃ´ng merge vÃ o row cÅ© trÃªn UI

### Image cleanup contract

`ContentProjectImageRerunCleanupContract` â€” order: generate â†’ persist â†’ update_reference â†’ commit â†’ cleanup_old  
Audit `ArticleEditorMediaAiService`: cancel processing trÆ°á»›c generate; **khÃ´ng** delete completed asset trÆ°á»›c persist.

### Key paths

| Symbol | Path |
|---|---|
| `ContentProjectStepRerunService` | `Services/ContentProject/` |
| `ContentProjectStepSourceValidator` | `Services/ContentProject/` |
| `ContentProjectStepDescriptor` | `Support/ContentProject/` |
| `ContentProjectBulkRerunService` | `Services/` |
| `SeoProjectWorkflowStepCatalogService` | `Services/` |
| `ContentProjectArticleRowStatusResolver` | `Services/` |
| `ArticleLastContentChangeResolver` | `Services/` |
| `ViewSeoProjectRun` | `Filament/.../ViewSeoProjectRun.php` |

### Tests Phase 2.1

`ContentProjectPhase21FinalUxTest`, `ContentProjectArticleRowStatusPhase21Test`, `ArticleLastContentChangePhase21Test`  
(+ regression Phase 2.0 / ArticleWritingStable / RunEngine / PromptOwnership)

### Host commands

```text
php artisan migrate
php artisan optimize:clear
php vendor/bin/phpunit --filter=ContentProjectStepRerun
php vendor/bin/phpunit --filter=ContentProjectBulkRerun
php vendor/bin/phpunit --filter=ContentProjectArticleRowStatus
php vendor/bin/phpunit --filter=ArticleLastContentChange
php vendor/bin/phpunit --filter=ContentProjectPhase21
php vendor/bin/phpunit --filter=ArticleWritingStable
```

### Overall stack verdict

```text
Article Writing: Stable with legacy compatibility
Content Project step rerun: Stable
```

---

## Phase 0.8 â€” Production canary + workflow configuration enforcement

### Settings bind validation

`WorkflowAssignmentValidator` + enum `WorkflowCapability`:

| Capability | Roles báº¯t buá»™c |
|---|---|
| Publish article | outline + content |
| Content-only | content |
| Improve (náº¿u Workflow) | improve |
| Media/gallery/video | khÃ´ng Ã©p `article.image.generate` cá»©ng |

Save Settings fail rÃµ (tÃªn WF + role thiáº¿u + link Builder) â€” khÃ´ng toast success.

### Builder save

`WorkflowExecutionRoleResolver::validateFlowData` + `validateFlowPreservesSettingsBindings`:

- duplicate unique role, wrong node type, hook mismatch
- broken edges, role thiáº¿u Prompt / Prompt missing
- Settings Ä‘ang bind â†’ khÃ´ng cho xÃ³a role báº¯t buá»™c

### Snapshot

`WorkflowExecutionSnapshot` / `WorkflowExecutionSnapshotBuilder`:

- `workflow_id`, `flow_data_hash`, nodes[`node_id`,`execution_role`,`prompt_id`]
- Gáº¯n vÃ o `SeoProjectRun.settings.workflow_execution_snapshot` lÃºc `startRun`
- Stamp vÃ o CreateArticles / retry snapshot (`content_node_id`)

Retry: dÃ¹ng node/prompt/length tá»« snapshot â€” thiáº¿u node â†’ lá»—i rÃµ (khÃ´ng nháº£y live).  
Rerun: config hiá»‡n táº¡i.

### Doctor

```text
php artisan seo:workflow:doctor
php artisan seo:workflow:doctor {workflowId}
```

Exit 0 = khÃ´ng blocking; â‰ 0 = cÃ³ blocking.

### Settings health UI

Placeholder dÆ°á»›i Publish: `âœ“ Workflow há»£p lá»‡` / `âš  Thiáº¿u vai trÃ²: â€¦` + link Builder.

### Legacy log

`article_writing.legacy_adapter_used` tá»« `ArticleWritingLegacyRewriteAdapter::logLegacyAdapterUsed`.

### Canary evidence

`docs/audits/ARTICLE_WRITING_PHASE08_CANARY.md` â€” operator paste. Verdict **Stable candidate** chá»‰ khi canary Aâ€“F pass trÃªn host.

### Remaining risks

- `TaskWorkflowTestRunner` / `ArticleGenerationInputResolver` cÃ²n title haystack phá»¥ â€” audit Phase 0.9
- Image role hooks (gallery/typography/video) chÆ°a tÃ¡ch role riÃªng
- Legacy DB `rewrite_article_task_id` + adapter giá»¯

---

## Phase 0.6 â€” Article Writing runtime stabilization

### Source contract (`article.content.generate`)

| `source_type` | Provider | Caller chÃ­nh |
|---|---|---|
| `outline` | `OutlineArticleWritingSourceProvider` | First-run CREATE; CP Â«Táº¡o láº¡i bÃ i tá»« dÃ n Ã½Â» |
| `existing_article` | `ExistingArticleWritingSourceProvider` | Editor Â«Viáº¿t láº¡i toÃ n bá»™ bÃ i hiá»‡n cÃ³Â»; legacy rewrite adapter |
| `brief` | `BriefArticleWritingSourceProvider` | Manual Task Test raw input (stamp báº¯t buá»™c) |

Improve **khÃ´ng** dÃ¹ng source contract nÃ y â€” capability `article.content.improve`.

### Workflow mapping

| Flow | Mode | Notes |
|---|---|---|
| First-run / CREATE | `publish_graph` | Outline node rá»“i content node (artifact vá»«a táº¡o) |
| CP regenerate article | `content_node` | KhÃ´ng cháº¡y láº¡i outline |
| CP outline + article | `publish_graph` via `runOutlineThenArticleForContext` | Outline má»›i â†’ article |
| Editor full rewrite | `direct_generate` | Settings-owned; khÃ´ng Publish graph; `EditArticle::queueEditorFullRewrite` â†’ `resolveEditorFullRewrite` |
| Improve | `ArticleImproveExecutionService` | Settings `article.content.improve` |

### Prompt owner

- NgoÃ i workflow: `settings_binding` â†’ `PromptBindingResolver` / `article.content.generate`
- Content node: `workflow_node` + `prompt_id` node â€” **khÃ´ng** resolve Settings song song
- History: `prompt_owner_type`, `prompt_owner_id`, `prompt_id`, `hook_key` (+ source badge / length / artifact ids trÃªn `/articles/{id}/prompts`)

### Retry vs rerun

- **Retry same execution:** `ArticleWritingExecutionContext.useRetrySnapshot=true` â€” giá»¯ source_type, source hash/artifact refs, prompt owner/id, `article_length`
- **Rerun / new execution:** resolve láº¡i Settings, binding, length, outline artifact hiá»‡n táº¡i

### Persistence + stale write

- Canonical body/title/meta: `PromptTestPublishService::publishArticle` (+ conflict guard `expected_updated_at` / content hash)
- Late result: `persist_status=ignored_stale` â€” history cÃ³ thá»ƒ ghi, canonical article khÃ´ng overwrite manual edit
- Editor/CP pass `expectedUpdatedAt` vÃ o execution context

### Legacy

- `ArticleWritingLegacyRewriteAdapter`: map â†’ `existing_article` â†’ `ArticleWritingExecutionService` (má»ng; khÃ´ng tá»± persist/workflow)
- `rewrite_article_task_id`: DB legacy only â€” runtime khÃ´ng Ä‘á»c

### Manual verification

```text
A CP Â«Táº¡o láº¡i bÃ i tá»« dÃ n Ã½Â» â†’ Source Outline; khÃ´ng cháº¡y outline node
B Editor Â«Viáº¿t láº¡i toÃ n bá»™â€¦Â» â†’ Source Existing article; Settings owner
C Brief Task Test â†’ Source Brief; labels Ä‘Ãºng
D Retry after Settings change â†’ snapshot cÅ©
E Rerun â†’ config má»›i
F Stale: user sá»­a khi job pending â†’ late result ignored_stale
```

---

## HÆ°á»›ng dáº«n prompt â€” Content Projects

```
Filament Resource: Filament/Resources/SeoProjectResource.php
Pages: ListSeoProjects, CreateSeoProject, EditSeoProject, ViewSeoProject
       (+ legacy redirect stubs: ListSeoProjectRuns, ViewSeoProjectRun, ViewSeoProjectRunStep)
Models: SeoProject, SeoProjectTask, SeoProjectRun (internal), SeoProjectRunItem, SeoProjectTaskEvent
Core Service: SeoProjectWorkflowRunService + ContentProjectRunEngine
Generate UI: SeoProjectResource::startGeneratePendingItems (CommandBus)
Run items SoT: SeoProjectRunItemService + SeoProjectRunItemsReader (DB XOR JSON)
Task Execution: CreateArticlesFromTaskService â†’ ArticleWritingExecutionService / ArticleImproveExecutionService â†’ TaskWorkflowTestRunner
Preflight: SeoProjectRunPreflightService
Consolidation: SeoProjectRunConsolidationService (mark consolidated, khÃ´ng hard-delete)
Run table display: SeoProjectRunItemsDisplayPresenter (1 task = 1 row)
Task Sync: SeoProjectTaskSyncService + SeoProjectTaskUniqueWriter
Lifecycle: SeoProjectTaskLifecycleService
Repair/Diagnose: content-project:repair, content-project:diagnose, content-project:backfill-run-items
Identity: ProjectTaskSourceKeyGenerator + UNIQUE(project_id, source_key)
Move/Delete rollback: SeoProjectTaskMoveService
Archive: SeoProjectArchiveService (mirror seo_content_archive_items.task_id)
Keyword Parser: SeoProjectKeywordListParser
Keyword AI Gen: SeoProjectKeywordAiGeneratorService
Approval: SeoProjectApprovalService
Article Owner Sync: SeoProjectArticleOwnerSyncService
Link History: PromptResultLinkService, ArticlePromptRunHistoryService
Article Writing: ArticleWritingExecutionService + source providers; Improve: ArticleImproveExecutionService
```
