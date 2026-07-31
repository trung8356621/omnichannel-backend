> Status: Historical
> Not canonical
> Archived on: 2026-08-01
> Superseded by: ../../modules/CONTENT_PROJECTS.md
> Purpose: implementation history only
# Content Project Run Engine â€” Architecture Refactor

**Status:** PHASE 1 SKELETON LANDED â€” flag `CONTENT_PROJECT_PHP_ENGINE` default **false**  
**Date:** 2026-07-25  
**Scope:** Content Project Run orchestration (UI / API / CLI / Scheduler / MCP / Agent)  
**Constraint:** KhÃ´ng vÃ¡ thÃªm orchestration vÃ o `project-run-queue.js`. Loáº¡i bá» dual orchestration (JS + PHP) khi flag on.

---

## 0. Má»¥c tiÃªu

Content Project Run trá»Ÿ thÃ nh **Workflow Engine cháº¡y hoÃ n toÃ n báº±ng PHP**.

| Frontend Ä‘Æ°á»£c phÃ©p | Frontend bá»‹ cáº¥m |
|---|---|
| Start / Stop / Retry (HTTP) | Cháº¡y prompt / step tiáº¿p theo |
| Subscribe progress (SSE) | Quyáº¿t Ä‘á»‹nh workflow / dependency |
| Render UI + toast | Resume / loop / dispatchNext |
| | Polling Ä‘iá»u khiá»ƒn orchestration |

Má»™t engine dÃ¹ng chung cho: Content Project UI, API, CLI, Scheduler, MCP, Agent.

---

## 1. Audit â€” Kiáº¿n trÃºc hiá»‡n táº¡i (AS-IS)

### 1.1 Nguá»“n Ä‘Ã£ Ä‘á»c

| Nguá»“n | Vai trÃ² audit |
|---|---|
| `docs/audits/PROMPT_EXECUTION_AND_ARTICLE_RERUN_HANDOFF.md` | Cancel race, step terminal, article pipeline rerun node resolve |
| `docs/audits/PROJECT_RUN_RETRY_OUTLINE_DEPENDENCY_HANDOFF.md` | Outline persist gap, step dependency |
| `docs/MAP_SEO_PROJECTS.md` | Service map, sequence (má»™t pháº§n lá»‡ch code thá»±c) |
| `resources/js/project-run-queue.js` | JS queue loop = article orchestrator |
| `ViewSeoProjectRun.php` | Livewire bridge: start/stop/retry/complete |
| `SeoProjectWorkflowRunService.php` | Seed run + `retryTask` / `runOneTask` |
| `SeoProjectWorkflowStepRetryService.php` | Step retry sync + cancel markers |
| `TaskWorkflowTestRunner.php` | Node graph executor (trong 1 article) |
| `PromptRunnerService.php` | AI call tháº¥p nháº¥t |
| `RerunArticlePipelineJob.php` | Queue path riÃªng (editor article rerun) |
| `ArticlePipelineRerunService.php` | Editor rerun orchestration (Ä‘Ã£ queue) |
| Automation `ExecuteAutomationNodeJob` | Pattern queue-per-node (tham chiáº¿u, khÃ¡c domain) |

### 1.2 SÆ¡ Ä‘á»“ AS-IS

```mermaid
flowchart TB
  subgraph UI["Browser"]
    ALP["Alpine store seoRunQueue\nisRunning / stopRequested / currentTaskId"]
    JS["project-run-queue.js\nstartQueue for-loop taskIds"]
    DOM["DOM dataset.runItemStatus\nrow UI"]
  end

  subgraph LW["Livewire ViewSeoProjectRun"]
    BEGIN["beginRunQueue"]
    RUNQ["runItemQueued(taskId)"]
    COMP["completeRunQueue / forceStop"]
    STEP["retryWorkflowStep / cancelWorkflowStep"]
  end

  subgraph PHP["PHP sync (HTTP request lifetime)"]
    WRS["SeoProjectWorkflowRunService\nretryTask â†’ runOneTask"]
    CREATE["CreateArticlesFromTaskService"]
    TWR["TaskWorkflowTestRunner\nfull graph trong 1 article"]
    STEP_SVC["SeoProjectWorkflowStepRetryService\nexecutePreparedStep sync"]
    PR["PromptRunnerService"]
  end

  subgraph DB["omi_seo_ai"]
    RUN["seo_project_runs"]
    ITEM["seo_project_run_items"]
    TASK["seo_project_tasks"]
    ART["articles + article_meta"]
  end

  subgraph Q["Queue (riÃªng, khÃ´ng pháº£i CP full run)"]
    RERUN_JOB["RerunArticlePipelineJob\n(editor only)"]
  end

  ALP --> JS
  JS -->|"sequential await"| RUNQ
  JS --> BEGIN
  JS --> COMP
  JS --> STEP
  RUNQ --> WRS
  WRS --> CREATE --> TWR --> PR
  STEP --> STEP_SVC --> TWR
  WRS --> ITEM
  STEP_SVC --> ITEM
  CREATE --> ART
  WRS --> TASK
  RERUN_JOB --> TWR
```

### 1.3 JS Ä‘ang lÃ m gÃ¬

File: `app/Addons/SeoContentAi/resources/js/project-run-queue.js`

| TrÃ¡ch nhiá»‡m | Chi tiáº¿t |
|---|---|
| **Article-level orchestrator** | `startQueue()` `for (taskId of taskIds)` â€” quyáº¿t Ä‘á»‹nh thá»© tá»±, khi nÃ o gá»i item tiáº¿p |
| Autorun | `?autorun=1` â†’ `processQueue()` khi mount |
| Stop flag | Alpine `stopRequested` â€” dá»«ng **giá»¯a** cÃ¡c item; item Ä‘ang Livewire váº«n cháº¡y tá»›i háº¿t request |
| Force stop | `forceStopRunQueue()` â†’ Livewire cancel steps + completed + reload |
| Single / bulk | `runSingleTask`, `handleStartQueue`, `confirmBulkRetry` |
| Step retry UI | `retryWorkflowStep` / `cancelWorkflowStep` â†’ Livewire sync |
| DOM state | `dataset.runItemStatus`, stats DOM, badge, scroll, bump row |
| Editor ready poll | `pollArticleEditorReady` â€” poll Livewire má»—i 3s (khÃ´ng pháº£i orchestration workflow, nhÆ°ng lÃ  polling) |
| Gate concurrent | `store.isRunning` cháº·n queue thá»© 2 |

**Káº¿t luáº­n:** JS = **dispatcher vÃ²ng láº·p article**. PHP chá»‰ execute **má»™t** article/task má»—i Livewire call.

### 1.4 PHP Ä‘ang lÃ m gÃ¬

| Layer | Class | Viá»‡c |
|---|---|---|
| Entry UI | `ListSeoProjectRuns` | Preflight â†’ `createProjectWorkflowRun` â†’ redirect `view-run?autorun=1` |
| Seed | `SeoProjectWorkflowRunService::startRun` + `prepareRunQueue` | Insert run `running`, seed `seo_project_run_items` pending |
| Bridge | `ViewSeoProjectRun` | `runItemQueued` â†’ `retryTask`; step retry/cancel; begin/complete/forceStop |
| Article exec | `retryTask` â†’ `runOneTask` | Claim item â†’ `CreateArticlesFromTaskService` â†’ full workflow |
| Graph | `TaskWorkflowTestRunner` | Cháº¡y toÃ n bá»™ node graph **trong 1 HTTP** (outlineâ†’contentâ†’â€¦) |
| Step retry | `SeoProjectWorkflowStepRetryService` | Claim `action=step:{nodeId}`, sync execute, cancel marker cooperative |
| AI | `PromptRunnerService` | Gá»i model, lÆ°u PromptResult |
| Editor parallel | `ArticlePipelineRerunService` + `RerunArticlePipelineJob` | Rerun tá»« semantic step â€” **Ä‘Ã£ queue**, khÃ´ng qua JS loop |
| Sync CLI path | `SeoProjectWorkflowRunService::execute()` | Loop PHP thuáº§n â€” **UI khÃ´ng dÃ¹ng** |

### 1.5 Queue Ä‘ang lÃ m gÃ¬

| Job | LiÃªn quan CP Run? |
|---|---|
| `RerunArticlePipelineJob` | CÃ³ â€” path Editor article rerun (má»™t article, tá»« node) |
| `ExecuteAutomationNodeJob` | KhÃ´ng â€” Business Automation graph |
| GenerateMedia / WP sync / GSC / â€¦ | Side-effect khÃ¡c |
| **KhÃ´ng cÃ³** `ExecuteContentProjectRunJob` / `ExecuteContentProjectArticleJob` | Full Content Project Run **khÃ´ng** queue â€” gáº¯n browser Livewire |

### 1.6 State náº±m á»Ÿ Ä‘Ã¢u (duplicated)

| State | NÆ¡i | SoT? |
|---|---|---|
| Run status | `seo_project_runs.status` (`running`/`completed`/`failed`) | DB â€” nhÆ°ng hoÃ n thÃ nh phá»¥ thuá»™c JS gá»i `completeRunQueue` |
| Item status | `seo_project_run_items.status` | DB (SoT runtime Phase 3) |
| Task status | `seo_project_tasks.status` | DB |
| Queue Ä‘ang cháº¡y | Alpine `seoRunQueue.isRunning` | **JS only** |
| Stop request | Alpine `stopRequested` | **JS only** (DB cancel chá»‰ khi forceStop / cancel step) |
| Current task | Alpine `currentTaskId` + DOM | **UI only** |
| Row visual | `dataset.runItemStatus` | **DOM** â€” cÃ³ thá»ƒ lá»‡ch DB náº¿u F5 giá»¯a chá»«ng |
| Livewire page | `$projectRun` props / result items | Snapshot render |
| Cancel marker | `error_message` chá»©a cancel text trÃªn run item | DB (cooperative) |
| Legacy JSON | `runs.items` | Legacy/debug â€” reader XOR |

**KhÃ´ng cÃ³** `STATUS_CANCELLED` trÃªn `SeoProjectRun` (chá»‰ task cÃ³ `cancelled`). Stop hiá»‡n = mark completed quietly + cancel active steps.

### 1.7 Äiá»ƒm duplicated / lá»‡ch

1. **Hai orchestration:** JS article loop **vÃ ** `WorkflowRunService::execute()` PHP loop (dead path UI).
2. **Hai path rerun article:** CP UI (`retryTask` sync) vs Editor (`RerunArticlePipelineJob` queue).
3. **Hai path step:** Full article graph trong `TaskWorkflowTestRunner` vs `SeoProjectWorkflowStepRetryService` single-node.
4. **Complete run:** JS quyáº¿t Ä‘á»‹nh khi gá»i `completeRunQueue` â€” Ä‘Ã³ng tab giá»¯a chá»«ng = run káº¹t `running` (autorun Ä‘Ã£ táº¯t F5 spam, nhÆ°ng orphan running váº«n cÃ³).
5. **Docs MAP_SEO_PROJECTS Â§5.2** váº½ loop trong PHP/View; code thá»±c = JS `startQueue`.
6. **Cancel:** JS flag + DB marker + HTTP váº«n block AI â€” cooperative discard (handoff audit).
7. **Progress:** response Livewire tá»«ng item + DOM patch â€” khÃ´ng event bus chung.

### 1.8 Execution flow cÅ© (tÃ³m táº¯t)

```
User "Run Workflow"
  â†’ startRun + prepareRunQueue (PHP, seed items)
  â†’ redirect view-run?autorun=1
  â†’ Alpine init â†’ processQueue
  â†’ beginRunQueue
  â†’ for each taskId:
        Livewire runItemQueued(taskId)
          â†’ retryTask â†’ runOneTask â†’ CreateArticlesâ€¦ â†’ TaskWorkflowTestRunner (all nodes)
          â†’ return item JSON
        JS applyItemResult (DOM)
  â†’ completeRunQueue / consolidate
```

Step retry:

```
User menu "Cháº¡y láº¡i outline"
  â†’ JS retryWorkflowStep
  â†’ Livewire â†’ SeoProjectWorkflowStepRetryService::retryOne (sync)
  â†’ DOM update
```

---

## 2. Kiáº¿n trÃºc má»›i (TO-BE)

### 2.1 NguyÃªn táº¯c

1. **Má»™t engine** â€” má»i entry point chá»‰ gá»i facade.
2. **DB = SoT** â€” UI khÃ´ng giá»¯ orchestration state.
3. **Queue = worker** â€” article/step cháº¡y ngoÃ i HTTP browser.
4. **SSE = progress** â€” UI subscribe, khÃ´ng poll Ä‘iá»u khiá»ƒn.
5. **Cancel cooperative + gate** â€” trÆ°á»›c má»i dispatch/persist, engine Ä‘á»c DB.
6. **KhÃ´ng vÃ¡** `project-run-queue.js` orchestration â€” thay báº±ng thin client.

### 2.2 TÃªn class Ä‘á» xuáº¥t

Khá»›p naming addon: **`ContentProjectRunEngine`** (facade).

Namespace: `App\Addons\SeoContentAi\Services\RunEngine\`

| Class | Vai trÃ² |
|---|---|
| `ContentProjectRunEngine` | Public API: start/resume/cancel/retry/dispatch |
| `RunLifecycleService` | Transition run status (running/cancelled/completed/failed) |
| `ArticleDispatchService` | Chá»n next article, enqueue job |
| `StepDispatchService` | Chá»n next node trong article (náº¿u tÃ¡ch step-level queue sau) |
| `RunEventPublisher` | Ghi event + push SSE / broadcast channel |
| `RunProgressProjector` | Counters + payload SSE tá»« DB |
| `RunCancellationGuard` | `assertNotCancelled(run/item)` trÆ°á»›c persist/dispatch |
| `ContentProjectRunJob` | Worker: 1 run item (1 article pipeline) |
| `ContentProjectStepJob` | (Phase muá»™n) 1 step â€” optional; Phase 1 cÃ³ thá»ƒ giá»¯ full graph trong 1 article job |
| `ContentProjectRunEventsController` | SSE endpoint |
| `ContentProjectRunApiController` | REST start/stop/retry |

Giá»¯ nguyÃªn (khÃ´ng bá»): `TaskWorkflowTestRunner`, `PromptRunnerService`, `CreateArticlesFromTaskService`, `SeoProjectRunItemService`, catalog, parsers, history.

### 2.3 SÆ¡ Ä‘á»“ TO-BE

```mermaid
flowchart TB
  subgraph Clients["Clients"]
    UI["Filament UI thin JS"]
    API["REST API"]
    CLI["artisan seo:content-project-run"]
    SCH["Scheduler"]
    MCP["MCP / Agent"]
  end

  ENG["ContentProjectRunEngine"]

  subgraph Persist["SoT"]
    RUN["seo_project_runs"]
    ITEM["seo_project_run_items"]
    EVT["seo_project_run_events optional"]
  end

  subgraph Workers["Queue seo-content-run"]
    AJ["ContentProjectRunArticleJob"]
    SJ["ContentProjectRunStepJob optional"]
  end

  subgraph Exec["Existing executors"]
    CREATE["CreateArticlesFromTaskService"]
    TWR["TaskWorkflowTestRunner"]
    STEP["SeoProjectWorkflowStepRetryService logic"]
    PR["PromptRunnerService"]
  end

  SSE["GET .../runs/{id}/events"]

  UI & API & CLI & SCH & MCP --> ENG
  ENG --> RUN
  ENG --> ITEM
  ENG --> EVT
  ENG -->|"dispatchNext"| AJ
  AJ --> CREATE --> TWR --> PR
  AJ -->|"complete â†’ dispatchNext"| ENG
  ENG --> SSE
  UI -->|"EventSource"| SSE
```

### 2.4 Engine API (contract)

```php
interface ContentProjectRunEngineContract
{
    public function startRun(SeoProject $project, string $mode, ?array $settings = null): SeoProjectRun;

    public function resumeRun(SeoProjectRun $run): SeoProjectRun;

    public function cancelRun(SeoProjectRun $run, ?string $reason = null): SeoProjectRun;

    public function cancelStep(SeoProjectRun $run, int $taskId, string $nodeId): array;

    public function retryStep(SeoProjectRun $run, int $taskId, string $nodeId): array;

    public function retryArticle(SeoProjectRun $run, int $taskId, ?array $options = null): array;

    public function dispatchNext(SeoProjectRun $run): void;

    public function completeStep(SeoProjectRunItem $item, array $output): void;

    public function failStep(SeoProjectRunItem $item, \Throwable|string $error): void;

    public function completeArticle(SeoProjectRun $run, int $taskId, array $result): void;

    public function completeRun(SeoProjectRun $run): SeoProjectRun;

    public function restoreRun(SeoProjectRun $run): SeoProjectRun; // recovery / reopen pending
}
```

Má»i Ä‘Æ°á»ng cháº¡y (UI/API/CLI/MCP) **chá»‰** gá»i contract nÃ y.

---

## 3. Sequence Diagram â€” flow má»›i

### 3.1 Start full run

```mermaid
sequenceDiagram
  actor User
  participant UI
  participant Eng as ContentProjectRunEngine
  participant DB
  participant Q as Queue
  participant Job as ArticleJob
  participant SSE

  User->>UI: Start
  UI->>Eng: startRun(project, full, settings)
  Eng->>DB: INSERT run running
  Eng->>DB: seed run_items pending
  Eng->>Q: dispatchNext â†’ ArticleJob(task1)
  Eng-->>UI: { run_id }
  UI->>SSE: EventSource /runs/{id}/events
  Eng-->>SSE: run_started

  Job->>Eng: (guard not cancelled)
  Job->>DB: claim item processing
  Eng-->>SSE: article_started
  Job->>Job: CreateArticles + TaskWorkflowTestRunner
  loop each node
    Eng-->>SSE: step_started / step_finished
  end
  Job->>Eng: completeArticle
  Eng-->>SSE: article_finished
  Note over UI: Hiá»‡n "ÄÃ£ hoÃ n thÃ nh" â€” user má»Ÿ editor
  Job->>Eng: dispatchNext
  Eng->>Q: ArticleJob(task2) â€¦
  Eng->>Eng: completeRun khi háº¿t pending
  Eng-->>SSE: run_finished
```

### 3.2 Cancel

```mermaid
sequenceDiagram
  participant UI
  participant Eng
  participant DB
  participant Job

  UI->>Eng: cancelRun(run)
  Eng->>DB: status=cancelled, cancelled_at
  Eng->>DB: cancelAllActiveSteps (failed + marker)
  Eng-->>UI: ok (SSE run_cancelled)

  Job->>Eng: before persist / dispatchNext
  Eng->>DB: refresh status
  alt cancelled
    Eng-->>Job: abort (no persist, no next)
  else active
    Eng-->>Job: continue
  end
```

---

## 4. State Machine

### 4.1 Run lifecycle

```
                startRun
                   â”‚
                   â–¼
              â”Œâ”€â”€â”€â”€â”€â”€â”€â”€â”€â”
         â”Œâ”€â”€â”€â”€â”‚ running â”‚â”€â”€â”€â”€â”
         â”‚    â””â”€â”€â”€â”€â”¬â”€â”€â”€â”€â”˜    â”‚
 cancelRun         â”‚         â”‚ all articles terminal
         â”‚         â”‚ fail hard (optional)
         â–¼         â–¼         â–¼
   â”Œâ”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â” â”Œâ”€â”€â”€â”€â”€â”€â”€â”€â” â”Œâ”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”
   â”‚ cancelled â”‚ â”‚ failed â”‚ â”‚ completed â”‚
   â””â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”˜ â””â”€â”€â”€â”€â”€â”€â”€â”€â”˜ â””â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”˜
         â”‚
         â”‚ restoreRun (chá»‰ reopen pending items â€” policy riÃªng)
         â–¼
      running
```

**ThÃªm** status `cancelled` trÃªn `seo_project_runs` (migration). KhÃ´ng map stop â†’ `completed` ná»¯a.

### 4.2 Article / run item lifecycle

Operation-level item (`action` = create/rewrite/â€¦):

```
pending â†’ processing â†’ success
                    â†˜ failed
                    â†˜ skipped / manual
processing â†’ failed (cancel)
```

Step-level item (`action=step:{nodeId}`) giá»¯ transition audit hiá»‡n cÃ³ (conditional claim/success/cancel).

### 4.3 Step lifecycle (trong article job)

Giá»¯ semantics `TaskWorkflowTestRunner` + cancel guard tá»« handoff:

```
claim â†’ provider â†’ terminal? discard
      â†’ fail? failPrepared
      â†’ assert still active â†’ persist â†’ success
```

Engine **khÃ´ng** Ä‘á»ƒ JS quyáº¿t Ä‘á»‹nh next step.

---

## 5. Event Flow & SSE

### 5.1 Endpoint

```
GET /seo/content-project-runs/{run}/events
Authorization: session / token (cÃ¹ng SeoAccessControl)
Accept: text/event-stream
Last-Event-ID há»— trá»£ reconnect
```

Tham chiáº¿u pattern sáºµn: `TeamMessageController` SSE.

### 5.2 Event catalog

| Event | Payload tá»‘i thiá»ƒu | UI |
|---|---|---|
| `run_started` | run_id, total | Progress bar init |
| `article_started` | run_id, task_id, article_id? | Row â†’ running |
| `step_started` | task_id, node_id, label | Busy step badge |
| `step_finished` | task_id, node_id, status | Step menu update |
| `article_finished` | task_id, article_id, status | NÃºt **ÄÃ£ hoÃ n thÃ nh** / editor link â€” **khÃ´ng block** article sau |
| `article_failed` | task_id, message, error_code | Row failed + retry |
| `run_progress` | succeeded, failed, total, pending | Stats |
| `run_finished` | counters | Toast complete |
| `run_failed` | message | Toast danger |
| `run_cancelled` | reason | Toast stopped |

### 5.3 Persistence events (khuyáº¿n nghá»‹)

Báº£ng optional `seo_project_run_events` (id, run_id, type, payload JSON, created_at) â†’ SSE Ä‘á»c theo cursor; Agent/API cÅ©ng query Ä‘Æ°á»£c.  
Phase Ä‘áº§u cÃ³ thá»ƒ SSE tá»« cache/redis pubsub + DB counters; phase sau harden event log.

---

## 6. Queue Strategy

| Quyáº¿t Ä‘á»‹nh | GiÃ¡ trá»‹ Ä‘á» xuáº¥t |
|---|---|
| Queue name | `seo-content-run` (tÃ¡ch `default` / `automation-*`) |
| Granularity Phase A | **1 job = 1 article** (full `TaskWorkflowTestRunner` graph) |
| Granularity Phase B (optional) | 1 job = 1 step â€” parity Automation node job |
| Concurrency | Config `CONTENT_PROJECT_RUN_CONCURRENCY` (default 1 per run Ä‘á»ƒ trÃ¡nh race; multi-run OK) |
| Timeout | â‰¥ AI timeout hiá»‡n táº¡i (mirror step retry / runner) |
| Unique | `ShouldBeUnique` theo `run_id:task_id:action:attempt` |
| Retry job | Laravel tries tháº¥p; business retry qua Engine `retryArticle` / `retryStep` |
| After success | `dispatchNext(run)` trong engine |
| After fail | Policy: continue next article (default CP) hoáº·c stop-on-fail (settings) |

**RerunArticlePipelineJob:** dáº§n gá»i Engine (`retryArticle` / start partial run) thay orchestration riÃªng â€” trÃ¡nh 2 engine.

---

## 7. Cancel / Retry / Recovery

### 7.1 Cancel Strategy

1. `cancelRun` â†’ DB `cancelled` ngay (SoT).
2. Cancel má»i item `pending|processing` (reuse `cancelAllActiveSteps` + abandon article items).
3. Job Ä‘ang cháº¡y: sau provider / trÆ°á»›c persist / trÆ°á»›c `dispatchNext` â†’ `RunCancellationGuard` â†’ discard, khÃ´ng next.
4. UI chá»‰ POST cancel â€” **khÃ´ng** cáº§n JS Ã©p dá»«ng loop (khÃ´ng cÃ²n loop).
5. KhÃ´ng fallback / khÃ´ng resume tá»± Ä‘á»™ng sau cancel.

### 7.2 Retry Strategy

| Action | Engine method | HÃ nh vi |
|---|---|---|
| Retry 1 article (full) | `retryArticle` | Reset/claim item, enqueue job |
| Retry 1 step | `retryStep` | Giá»¯ dependency/outline rules tá»« `SeoProjectWorkflowStepRetryService` |
| Bulk step | `retryStep` loop server-side hoáº·c `enqueueBulk` qua Engine | JS khÃ´ng loop execute |
| Rerun from semantic | `retryArticle(..., from: outline\|content)` | Gá»™p `ArticlePipelineRerunStartStepResolver` |

### 7.3 Recovery Strategy

| TÃ¬nh huá»‘ng | Xá»­ lÃ½ |
|---|---|
| Worker cháº¿t giá»¯a processing | Watchdog / `abandonStaleActiveSteps` + item â†’ failed hoáº·c re-queue theo policy |
| Run `running` khÃ´ng job | `resumeRun` â†’ `dispatchNext` |
| Deploy giá»¯a run | Cancel hoáº·c resume explicit â€” khÃ´ng JS autorun |
| Duplicate dispatch | Unique lock + claim conditional |

---

## 8. Frontend (thin)

### 8.1 JS cÃ²n láº¡i

- POST Start / Stop / Retry (fetch hoáº·c Livewire **thin** â€” chá»‰ proxy Engine, khÃ´ng loop)
- `EventSource` subscribe
- Render row/stats/toast
- Confirm modal (Alpine UI only)

### 8.2 XÃ³a / khÃ´ng dÃ¹ng orchestration

Khá»i `project-run-queue.js` (thay file thin hoáº·c xÃ³a entry):

- `processQueue` / `startQueue` for-loop
- `runSingleTask` gá»i sync execute tuáº§n tá»± nhÆ° dispatcher
- `continueQueue` / `resumeQueue` / `dispatchNext` (náº¿u cÃ³ alias)
- Autorun loop phá»¥ thuá»™c browser tab
- Phá»¥ thuá»™c `store.isRunning` Ä‘á»ƒ Ä‘iá»u khiá»ƒn workflow

Giá»¯ (náº¿u cáº§n) UI helpers: select-all, archive row animation, bulk confirm **sau** khi server enqueue.

### 8.3 Livewire thu háº¹p

`ViewSeoProjectRun` chá»‰ cÃ²n:

- Bootstrap read model (items + stats)
- `startRun` / `cancelRun` / `retry*` â†’ Engine
- KhÃ´ng `runItemQueued` execute sync dÃ i
- KhÃ´ng `beginRunQueue` / `completeRunQueue` tá»« JS loop

---

## 9. Agent / API / CLI Integration

```
Agent / MCP  â†’ ContentProjectRunEngine::startRun()
API POST /runs â†’ Engine::startRun()
API POST /runs/{id}/cancel â†’ Engine::cancelRun()
API POST /runs/{id}/retry-article â†’ Engine::retryArticle()
CLI php artisan seo:content-project-run {project} â†’ Engine::startRun()
Scheduler â†’ Engine::startRun() / resumeRun()
UI â†’ Engine::*
```

KhÃ´ng client nÃ o Ä‘Æ°á»£c gá»i trá»±c tiáº¿p `retryTask` / `TaskWorkflowTestRunner` cho orchestration run.

---

## 10. Backward compatibility

| Giá»¯ nguyÃªn | CÃ¡ch |
|---|---|
| Content Project UI routes | CÃ¹ng Filament pages; Ä‘á»•i internals |
| Prompt History / links | `PromptRunner` + result links khÃ´ng Ä‘á»•i contract |
| Retry step dependency / outline | Port logic tá»« `SeoProjectWorkflowStepRetryService` vÃ o Engine |
| Article Editor | Rerun dáº§n qua Engine; meta `article_pipeline_rerun` tÆ°Æ¡ng thÃ­ch |
| Prompt Manager / Workflow Catalog | KhÃ´ng Ä‘á»¥ng canvas |
| Run History / consolidate | `completeRun` váº«n gá»i consolidation |
| Business hooks | `BusinessHookEmitter` runStarted/Completed giá»¯; thÃªm Cancelled náº¿u cáº§n |
| `seo_project_run_items` schema | Extend status/run cancelled; khÃ´ng phÃ¡ reader |

---

## 11. Class inventory

### 11.1 Sáº½ táº¡o

| Class / artifact |
|---|
| `Services/RunEngine/ContentProjectRunEngine.php` |
| `Services/RunEngine/ContentProjectRunEngineContract.php` |
| `Services/RunEngine/RunLifecycleService.php` |
| `Services/RunEngine/ArticleDispatchService.php` |
| `Services/RunEngine/RunCancellationGuard.php` |
| `Services/RunEngine/RunEventPublisher.php` |
| `Services/RunEngine/RunProgressProjector.php` |
| `Jobs/ContentProjectRunArticleJob.php` |
| `Http/Controllers/ContentProjectRunEventsController.php` |
| `Http/Controllers/ContentProjectRunActionController.php` (hoáº·c Filament Actions má»ng) |
| `Console/ContentProjectRunCommand.php` |
| Migration: `cancelled` status + optional `seo_project_run_events` |
| Config: `config/seo-content-ai.php` queue/concurrency |
| Tests: Engine unit + cancel guard + dispatchNext |
| Thin JS: `project-run-events.js` (thay orchestration queue) |

### 11.2 Sáº½ bá» / deprecate

| Item | Ghi chÃº |
|---|---|
| Orchestration trong `project-run-queue.js` | XÃ³a loop; replace thin client |
| `ViewSeoProjectRun::runItemQueued` sync execute | Deprecate â†’ enqueue |
| `beginRunQueue` / `finalizePartialQueue` / JS-driven `completeRunQueue` | Engine sá»Ÿ há»¯u lifecycle |
| Autorun `?autorun=1` browser loop | Start Ä‘Ã£ enqueue server-side |
| Direct UI calls tá»›i `SeoProjectWorkflowRunService::retryTask` | Wrap Engine |
| Dual use `execute()` vs JS loop | Má»™t path: Engine |

### 11.3 Giá»¯, gá»i tá»« Engine

- `SeoProjectWorkflowRunService` (seed/consolidate helpers â€” refactor dáº§n thÃ nh lifecycle internals)
- `SeoProjectRunItemService`, `SeoProjectRunItemsReader`, DisplayPresenter
- `SeoProjectWorkflowStepRetryService` (logic step â€” facade qua Engine)
- `SeoProjectWorkflowStepCatalogService`
- `CreateArticlesFromTaskService`, `TaskWorkflowTestRunner`, `PromptRunnerService`
- `ArticlePipelineRerunStartStepResolver`
- `ContentProjectPostRunPipeline`, BusinessHookEmitter

---

## 12. Migration Plan (phased)

KhÃ´ng code all-at-once. Má»—i phase shippable + rollback.

### Phase 0 â€” Design gate (THIS DOC)

- [x] Audit AS-IS
- [x] Design TO-BE
- [x] **User duyá»‡t doc + open decisions (2026-07-25)**

### Phase 1 â€” Engine skeleton + article queue

- [x] `ContentProjectRunEngine` facade
- [x] Status mapper (stopping/cancelled additive strings â€” no schema migration)
- [x] `RunCancellationGuard` + EventPublisher abstraction
- [x] `ContentProjectArticleRunner` + `RunContentProjectArticleJob`
- [x] Feature flag `CONTENT_PROJECT_PHP_ENGINE`
- [x] List start â†’ engine; JS orchestration disabled when flag on
- [ ] Ops verify remote (flag on + queue worker + manual 5-article scenario)

### Phase 2 â€” Article job + dispatchNext

- [ ] `ContentProjectRunArticleJob`
- [ ] `startRun` enqueue first article; job gá»i existing `runOneTask` path
- [ ] `dispatchNext` / `completeRun`
- [ ] Feature flag `CONTENT_PROJECT_RUN_ENGINE_V2`
- [ ] Parallel: giá»¯ JS loop khi flag off

### Phase 3 â€” Cutover UI start/stop

- [ ] Flag on: List run â†’ Engine start (no `?autorun` loop)
- [ ] Stop â†’ `cancelRun`
- [ ] Thin JS + optional Livewire proxy
- [ ] XÃ³a for-loop orchestration khá»i bundle

### Phase 4 â€” SSE progress

- [ ] Events endpoint + publisher
- [ ] UI EventSource render
- [ ] `article_finished` â†’ nÃºt hoÃ n thÃ nh ngay
- [ ] Bá» poll Ä‘iá»u khiá»ƒn; háº¡n cháº¿ `pollArticleEditorReady` hoáº·c thay event

### Phase 5 â€” Retry/step qua Engine

- [ ] `retryStep` / `retryArticle` / bulk server-side
- [ ] Deprecate Livewire sync execute dÃ i
- [ ] Gá»™p Editor `RerunArticlePipelineJob` vÃ o Engine

### Phase 6 â€” API / CLI / Agent

- [ ] REST endpoints
- [ ] `artisan seo:content-project-run`
- [ ] MCP/Agent docs + examples
- [ ] Scheduler resume orphan runs

### Phase 7 â€” Cleanup

- [ ] Remove flag + dead Livewire methods
- [ ] Update `MAP_SEO_PROJECTS.md` / frontend map
- [ ] Delete obsolete JS orchestration
- [ ] Hardening event log table náº¿u chÆ°a

---

## 13. Risk

| Risk | Má»©c | Mitigation |
|---|---|---|
| PHP-FPM timeout biáº¿n máº¥t nhÆ°ng queue worker timeout | High | Timeout/config riÃªng; monitor `seo-content-run` |
| Cancel khÃ´ng dá»«ng AI HTTP giá»¯a chá»«ng | Med | Giá»¯ cooperative discard (Ä‘Ã£ cÃ³); khÃ´ng promise kill TCP |
| Dual path flag on/off lá»‡ch state | High | Flag theo run.settings[`engine_v2`]; khÃ´ng mix |
| SSE proxy buffering (nginx) | Med | `X-Accel-Buffering: no`; heartbeat comment |
| Máº¥t progress khi Ä‘Ã³ng tab | Low (má»›i) | Cá»‘ Ã½ â€” worker tiáº¿p tá»¥c; SSE reconnect |
| Consolidate / business hook Ä‘á»•i timing | Med | `completeRun` cÃ¹ng chá»— cÅ© |
| Step dependency regress | High | Port tests `PromptExecutionOrchestrationTest`, outline handoff |
| Concurrent articles cÃ¹ng project | Med | Default concurrency 1/run |
| Livewire session dÃ i request cÅ© | Low sau cutover | Bá» sync execute |

---

## 14. Rollback Plan

1. **Feature flag off** â†’ UI láº¡i JS loop + `runItemQueued` (giá»¯ code path Phase 2â€“3 cho tá»›i Phase 7).
2. Runs Ä‘Ã£ `cancelled` status: UI map hiá»ƒn thá»‹; reader khÃ´ng phÃ¡.
3. Jobs: stop queue `seo-content-run`; `cancelRun` orphan.
4. KhÃ´ng reverse migration status enum náº¿u Ä‘Ã£ ghi `cancelled` â€” reader tolerant.
5. SSE fail â†’ UI fallback read-only refresh thá»§ cÃ´ng (khÃ´ng báº­t láº¡i JS orchestrator).

---

## 15. Checklist tá»«ng phase (gate)

### TrÆ°á»›c má»i phase code

- [ ] Doc nÃ y Ä‘Ã£ duyá»‡t
- [ ] KhÃ´ng sá»­a orchestration `project-run-queue.js` ngoÃ i thin replace theo phase
- [ ] Test plan gáº¯n phase (unit tá»‘i thiá»ƒu)

### Definition of Done toÃ n refactor

- [ ] KhÃ´ng cÃ²n JS for-loop gá»i execute
- [ ] Má»i entry â†’ Engine
- [ ] SSE progress; article_finished khÃ´ng block
- [ ] Cancel chá»‰ DB + guard
- [ ] DB SoT duy nháº¥t
- [ ] API/CLI/Agent dÃ¹ng chung
- [ ] Backward: history, editor, catalog, retry dependency OK
- [ ] Docs vá»‡ tinh cáº­p nháº­t (`MAP_SEO_PROJECTS`, `MAP_SEO_FRONTEND`)

---

## 16. Execution flow â€” so sÃ¡nh nhanh

| | CÅ© | Má»›i |
|---|---|---|
| Ai chá»n article tiáº¿p | JS `startQueue` | Engine `dispatchNext` |
| Ai cháº¡y graph | PHP sync trong Livewire | Queue job â†’ cÃ¹ng executors |
| Ai complete run | JS `completeRunQueue` | Engine khi háº¿t item |
| Progress | Livewire return + DOM | SSE events |
| Stop | Alpine flag + optional forceStop | `cancelRun` DB |
| Tab Ä‘Ã³ng giá»¯a run | Orphan / stall | Worker tiáº¿p tá»¥c |
| Agent | KhÃ´ng cÃ³ path sáº¡ch | `Engine::startRun()` |

---

## 17. Quyáº¿t Ä‘á»‹nh Ä‘Ã£ duyá»‡t (2026-07-25)

| # | Chá»§ Ä‘á» | Quyáº¿t Ä‘á»‹nh |
|---|---|---|
| 1 | Granularity | **Article job** only. KhÃ´ng step-job Phase 1. Job cháº¡y trá»n workflow 1 article; reuse runner hiá»‡n cÃ³. |
| 2 | Concurrency | Phase 1 = **1 article/run**. `max_parallel_articles` config sáºµn, engine enforce 1. |
| 3 | Article fail | **Continue run**. Mark failed â†’ dispatch next. Chá»‰ dá»«ng khi stop/cancel. |
| 4 | Stop | DB-first: `running â†’ stopping â†’ cancelled`. KhÃ´ng map completed. Má»™t láº§n báº¥m Ä‘á»§. |
| 5 | Realtime | SSE Phase 2. Phase 1: EventPublisher + DB SoT + optional read-only poll. KhÃ´ng Redis Pub/Sub SoT. KhÃ´ng event table Phase 1. |
| 6 | Facade name | **`ContentProjectRunEngine`** |

---

## 18. Execution Ownership (báº¯t buá»™c)

| Owner | Sá»Ÿ há»¯u | KhÃ´ng Ä‘Æ°á»£c |
|---|---|---|
| **ContentProjectRunEngine** | Run lifecycle; start/resume/stop; chá»n article tiáº¿p; dispatch job; finalize; aggregate counters | Gá»i AI/model trá»±c tiáº¿p; cháº¡y workflow node |
| **ContentProjectArticleRunner** | Lifecycle 1 article; claim qua service cÅ©; gá»i workflow; cancel boundary; tráº£ `ArticleExecutionResult` | Tá»± `dispatchNextArticle` |
| **TaskWorkflowTestRunner** (WorkflowRunner) | Graph/node order; dependency; step lifecycle; prior context | Update run lifecycle |
| **PromptRunnerService** | Compile; provider; timeout/retry provider; raw result | Update run/article run status |
| **RunContentProjectArticleJob** | Load fresh; guard; gá»i runner; gá»i engine sau finish | Ownership chá»n article káº¿ (á»§y quyá»n engine) |

KhÃ´ng Ä‘á»ƒ nhiá»u service cÃ¹ng update má»™t state khÃ´ng cÃ³ owner.

---

## 19. State mapping legacy (Phase 1)

### Run (`seo_project_runs.status` string)

| Semantic | DB value | Ghi chÃº |
|---|---|---|
| pending | `running` (seed) | ChÆ°a tÃ¡ch cá»™t; start â†’ running |
| running | `running` | |
| stopping | `stopping` | Additive string â€” khÃ´ng migration |
| cancelled | `cancelled` | Additive string â€” khÃ´ng map `completed` |
| completed | `completed` | CÃ³ failed articles váº«n `completed` + counters |
| failed | `failed` | Fatal engine only |

Mapper: `Support/RunEngine/ContentProjectRunStatusMapper`.

### Article item (`seo_project_run_items.status`)

| Semantic | DB value |
|---|---|
| pending | `pending` |
| running | `processing` |
| completed | `success` |
| failed | `failed` |
| cancelled | `failed` + error `Cancelled by user.` |
| skipped | `skipped` |

Step statuses giá»¯ schema hiá»‡n táº¡i (`pending/processing/success/failed/...`); semantic `completed` â‰¡ `success`.

---

## 20. Phase 1 sequence (Ä‘Ã£ implement skeleton)

```mermaid
sequenceDiagram
  actor User
  participant List as ListSeoProjectRuns
  participant Eng as ContentProjectRunEngine
  participant Q as Queue seo-content-run
  participant Job as RunContentProjectArticleJob
  participant Runner as ContentProjectArticleRunner
  participant WRS as SeoProjectWorkflowRunService
  participant UI as ViewSeoProjectRun JS

  User->>List: Start (flag on)
  List->>List: createProjectWorkflowRun (seed)
  List->>Eng: start(run)
  Eng->>Q: dispatchNextArticle â†’ Job
  List-->>User: open view-run (no autorun)
  UI->>UI: phpEngine=true â†’ no for-loop
  UI->>UI: pollRunProgress read-only

  Job->>Runner: run(task)
  Runner->>WRS: retryTask(markCompleted=false)
  WRS-->>Runner: item row (DB terminal ngay)
  Runner-->>Job: ArticleExecutionResult
  Job->>Eng: handleArticleFinished
  alt stop requested
    Eng->>Eng: finalizeIfDone â†’ cancelled
  else continue
    Eng->>Q: dispatchNextArticle
  end
```

---

## 21. Locking / claim strategy (Phase 1)

1. `dispatchNextArticle`: transaction + `lockForUpdate` trÃªn `seo_project_runs` + next pending item (`action not like step:%`).
2. Reject náº¿u status khÃ´ng `allowsDispatch` hoáº·c Ä‘Ã£ cÃ³ item `processing` (â‰¥ `effectiveMaxParallelArticles()` = 1).
3. Ghi `settings.php_engine.active_dispatch` (task_id, run_item_id, attempt, token).
4. Dispatch job **ngoÃ i** transaction (khÃ´ng giá»¯ lock khi gá»i model).
5. Job: verify token; `ShouldBeUnique` theo `runId:runItemId:attempt`.
6. Claim tháº­t sá»± váº«n trong `SeoProjectRunItemService::claimForExecution` (qua `retryTask`).
7. Failed article **khÃ´ng** auto-reset vá» pending trong `dispatchNextArticle`.

---

## 22. Feature flag

```
CONTENT_PROJECT_PHP_ENGINE=true|false
config: seo-content-ai.content_project.php_engine (default false)
```

| Flag | HÃ nh vi |
|---|---|
| **off** | Legacy JS `startQueue` + `runItemQueued` |
| **on** | `ContentProjectRunEngine::start` tá»« List; JS orchestration disabled; Livewire `runItemQueued`/`completeRunQueue`/`beginRunQueue` no-op/reject |

Server + frontend cÃ¹ng Ä‘á»c flag (`getQueueBootstrapData.phpEngine`).

Queue: `CONTENT_PROJECT_RUN_QUEUE` default `seo-content-run`.

---

## 23. Phase 1 file inventory

### Táº¡o má»›i

- `Enums/ContentProjectRunSemanticStatus.php`
- `Enums/ContentProjectArticleSemanticStatus.php`
- `Support/RunEngine/ContentProjectRunStatusMapper.php`
- `Support/RunEngine/ArticleExecutionResult.php`
- `Support/RunEngine/ContentProjectRunEngineFeature.php`
- `Services/RunEngine/ContentProjectRunEngine.php`
- `Services/RunEngine/ContentProjectArticleRunner.php`
- `Services/RunEngine/RunCancellationGuard.php`
- `Services/RunEngine/ContentProjectRunEventPublisher.php`
- `Services/RunEngine/LoggingContentProjectRunEventPublisher.php`
- `Jobs/RunContentProjectArticleJob.php`
- `Console/ContentProjectRunStatusCommand.php`
- `tests/Unit/ContentProjectRunEnginePhase1Test.php`

### Sá»­a

- `config/seo-content-ai.php` â€” flag + queue + max_parallel
- `Models/SeoProjectRun.php` â€” `STATUS_STOPPING` / `STATUS_CANCELLED`
- `SeoContentAiServiceProvider.php` â€” bind engine services
- `Filament/.../ListSeoProjectRuns.php` â€” `engine.start` khi flag on
- `Filament/.../ViewSeoProjectRun.php` â€” bootstrap, stopâ†’requestStop, reject Livewire execute, pollRunProgress
- `resources/js/project-run-queue.js` â€” disable orchestration khi `phpEngine`

### Ownership tráº£ lá»i nhanh

| CÃ¢u há»i | Tráº£ lá»i |
|---|---|
| JS orchestration nÃ o táº¯t? | `processQueue` / `startQueue` / `runSingleTask` / `handleStartQueue` / autorun khi `phpEngine` |
| Endpoint start engine? | `ListSeoProjectRuns` actions `run_workflow` / `test_run_workflow` â†’ `ContentProjectRunEngine::start` |
| Job article? | `RunContentProjectArticleJob` |
| Service sá»Ÿ há»¯u article? | `ContentProjectArticleRunner` |
| Ai dispatch next? | `ContentProjectRunEngine::dispatchNextArticle` (sau `handleArticleFinished`) |
| Ai finalize run? | `ContentProjectRunEngine::finalizeIfDone` |

---

## 24. Rollback Phase 1

1. `CONTENT_PROJECT_PHP_ENGINE=false`
2. `php artisan config:clear` (remote)
3. Stop/restart worker queue `seo-content-run`
4. Legacy JS path hoáº¡t Ä‘á»™ng láº¡i
5. History run/item giá»¯ nguyÃªn; status `stopping`/`cancelled` váº«n Ä‘á»c Ä‘Æ°á»£c qua mapper

KhÃ´ng migration phÃ¡ há»§y. KhÃ´ng xÃ³a legacy path trÆ°á»›c khi verify flag on.

---

## 25. Deploy / verify (remote â€” khÃ´ng cháº¡y local agent)

```text
Manual verification:

# Enable (chá»‰ 1 site / run nhá» 3â€“5 article)
CONTENT_PROJECT_PHP_ENGINE=true

# DÃ¹ng Ä‘Ãºng binary PHP mÃ  queue/cron production Ä‘ang dÃ¹ng
# (server cÃ³ nhiá»u PHP 8.3 â€” KHÃ”NG giáº£ Ä‘á»‹nh /usr/bin/php)
# VÃ­ dá»¥ (Ä‘iá»n path thá»±c táº¿ tá»« supervisor/cron):
#   /usr/bin/php8.3 artisan â€¦
#   /opt/alt/php83/usr/bin/php artisan â€¦

php artisan optimize:clear
php artisan config:clear
php artisan queue:restart
# Worker pháº£i listen queue seo-content-run (timeout â‰¥ article job 900s)

# Frontend (náº¿u chÆ°a build báº£n cÃ³ phpEngine guards)
npm run build

# PHPUnit (Ä‘Ãºng binary + vendor runner â€” khÃ´ng dÃ¹ng artisan test)
php vendor/bin/phpunit --filter=ContentProjectRunEnginePhase1Test

# Ops snapshot (read-only)
php artisan seo:content-project-run:status {runId}

# Manual scenario
# 1. Start má»™t láº§n â†’ request tráº£ nhanh â†’ Ä‘Ãºng 1 article running
# 2. F5/Ä‘Ã³ng tab â†’ backend tiáº¿p tá»¥c; Network khÃ´ng JS queue loop
# 3. Article 1 completed â†’ má»Ÿ editor; article 2 váº«n cháº¡y
# 4. Article fail cÃ³ chá»§ Ä‘Ã­ch â†’ article sau váº«n cháº¡y
# 5. Stop khi article Ä‘ang cháº¡y â†’ stopping â†’ khÃ´ng dispatch má»›i
#    â†’ provider muá»™n discard (non-success) â†’ cancelled
# 6. F5 khÃ´ng resume; start láº¡i trÃªn terminal run khÃ´ng sá»‘ng láº¡i
```

---

## 26. Sign-off

| Role | Quyáº¿t Ä‘á»‹nh | NgÃ y |
|---|---|---|
| Product / Owner | â˜‘ Duyá»‡t design + open decisions | 2026-07-25 |
| Implementer | â˜‘ Phase 1 skeleton landed (flag default **off**) | 2026-07-25 |
| Implementer | â˜‘ Phase 1 hardening (idempotent start, claim token, result, stop/finalize, logs, status cmd, tests) | 2026-07-25 |

**Phase 2:** SSE endpoint + bá» poll. KhÃ´ng code SSE giá»¯ worker HTTP má»Ÿ lÃ¢u trÆ°á»›c khi queue á»•n Ä‘á»‹nh.

---

## 27. Phase 1 implementation status (production-ready trial)

| TiÃªu chÃ­ | Status |
|---|---|
| Flag default OFF | â˜‘ |
| Start idempotent, khÃ´ng gá»i provider | â˜‘ `ContentProjectRunEngine::start` |
| 1 active article / run | â˜‘ `effectiveMaxParallelArticles()=1` + `active_dispatch` + processing count |
| Job chain khÃ´ng cáº§n tab | â˜‘ `RunContentProjectArticleJob` â†’ `handleArticleFinished` |
| Failed article â†’ continue | â˜‘ `mayDispatchNext()` true trá»« Cancelled |
| Stop â†’ stopping â†’ cancelled | â˜‘ khÃ´ng map completed |
| Finalize khÃ´ng cáº§n JS | â˜‘ `finalizeIfDone` / `completeRunQueue` no-op khi flag ON |
| Poll/F5 read-only | â˜‘ `pollRunProgress` + JS poll only |
| Legacy reject khi flag ON | â˜‘ `runItemQueued` / `beginRunQueue` / `completeRunQueue` + JS guards |
| Status command | â˜‘ `seo:content-project-run:status` |
| Structured logs `content_project_run.*` | â˜‘ |
| SSE / public API / CLI agent | âœ— ngoÃ i scope Phase 1 |

**KhÃ´ng káº¿t luáº­n â€œdone production foreverâ€** cho Ä‘áº¿n khi checklist Â§25 cháº¡y tháº­t trÃªn remote vá»›i flag ON (run 3â€“5 article).

### Exact claim strategy

1. `dispatchNextArticle`: txn `lockForUpdate` run + next pending item (`action not like step:%`, `orderBy id`).
2. Block náº¿u `!allowsDispatch` hoáº·c `processingâ‰¥1` hoáº·c `hasBlockingActiveDispatch`.
3. Ghi `settings.php_engine.active_dispatch{task_id,run_item_id,attempt,token,dispatched_at}`.
4. Dispatch job **ngoÃ i** txn (`afterResponse` trÃªn web).
5. Job: token match â†’ terminal guards â†’ stop guard â†’ re-check token/stop/runnable â†’ runner.
6. Claim DB `pendingâ†’processing` váº«n trong `claimForExecution` via `retryTask`.
7. `ShouldBeUnique` `runId:runItemId:attempt`; `tries=1`.
8. Stale dispatch sweep: item terminal / missing / age â‰¥ `run_item_stale_minutes`.

### Exception classification

| Class | HÃ nh vi |
|---|---|
| A Domain failure | Article Failed; `mayDispatchNext=true`; chain tiáº¿p |
| B Cancellation | Article Cancelled; no next; finalize â†’ cancelled |
| C Infra (`Job::failed`) | Mark item Failed náº¿u cÃ²n pending/processing; continue chain |
| D Fatal engine | Run Failed semantic (hiáº¿m); khÃ´ng loop vÃ´ háº¡n |

KhÃ´ng `throw` sau domain mark náº¿u lÃ m Ä‘á»©t chain. KhÃ´ng nuá»‘t infra rá»“i Ä‘Ã¡nh Failed sai trÆ°á»›c khi classify.

### Cancellation safe boundaries

1. TrÆ°á»›c job execute (sau token).
2. TrÆ°á»›c runner (re-check).
3. Äáº§u `ContentProjectArticleRunner::run`.
4. Sau `retryTask` return: success Ä‘Ã£ persist giá»¯; non-success + stop â†’ Cancelled / discard.
5. `requestStop`: runningâ†’stopping + cancel active steps; finalize chá»‰ cancel khi khÃ´ng cÃ²n processing **vÃ ** khÃ´ng cÃ²n blocking `active_dispatch`.

### Finalization rules

- **Completed**: khÃ´ng stop; 0 pending/processing article-level; gá»i `workflowRunService->completeRunQueue` (counters; má»™t sá»‘ article failed váº«n completed â€” khÃ´ng invent `completed_with_errors` column).
- **Cancelled**: stop requested; 0 active processing + 0 blocking dispatch; abandon remaining pending; status cancelled.
- **Failed (run)**: chá»‰ corruption/fatal â€” khÃ´ng vÃ¬ vÃ i article failed.

### Polling read-only proof

- `pollRunProgress`: refresh + stats only.
- Mount/hydrate: khÃ´ng `engine.start` / khÃ´ng dispatch.
- JS `phpEngine`: disable `processQueue`/`startQueue`/`runSingleTask`/`handleStartQueue`/autorun; chá»‰ `pollRunProgress`.

### Feature flag behavior

| Flag | Orchestration |
|---|---|
| OFF | Legacy JS + `runItemQueued` |
| ON | Engine only; Livewire execute reject/no-op; JS loop off |

### Late write / editor protection (Phase 1)

- KhÃ´ng lock cáº¥p run trÃªn article editor.
- Article 1 terminal â†’ job cÅ©: terminal guard / token mismatch â†’ khÃ´ng `retryTask` láº¡i.
- Cancel sau provider: discard non-success.
- KhÃ´ng thÃªm article versioning lá»›n; reuse claim `already_processed` khi khÃ´ng forceRetry (engine path váº«n forceRetry nhÆ°ng bá»‹ cháº·n trÆ°á»›c runner náº¿u item terminal).

### Known limitations

- Claim reservation á»Ÿ run settings, chÆ°a conditional UPDATE status lÃºc dispatch (trÃ¡nh phÃ¡ `claimForExecution`).
- Stop + worker cháº¿t: cÃ³ thá»ƒ `stopping` Ä‘áº¿n khi stale sweep hoáº·c job cancel boundary.
- Tests Phase1 chá»§ yáº¿u contract/source; DB/Bus integration cáº§n remote SEO DB.
- ChÆ°a SSE; poll 3s.
- ChÆ°a public API/CLI agent runner.

### Phase 2 prerequisites

- Queue á»•n Ä‘á»‹nh production (1 active/run, stop/finalize OK).
- EventPublisher Ä‘á»§ cho SSE fan-out.
- KhÃ´ng phá»¥ thuá»™c JS complete/resume (Ä‘Ã£ Ä‘áº¡t Phase 1).

Handoff chi tiáº¿t: `docs/audits/CONTENT_PROJECT_RUN_ENGINE_PHASE1_HANDOFF.md`.

---

## 28. Phase 1.5 â€” Production hardening

Checklist: `docs/checklists/CONTENT_PROJECT_ENGINE_PRODUCTION.md`

| Item | Status |
|---|---|
| `active_dispatch` TTL + dead-heartbeat release | â˜‘ |
| Heartbeat (claim / pre-run / post-run) â€” warn only khi stale | â˜‘ |
| `healthCheck()` + status command má»Ÿ rá»™ng | â˜‘ |
| Finalize-once (`finalized_at`) | â˜‘ |
| Per-run / project allowlist flag | â˜‘ |
| Metrics log (`content_project_run.metrics`) | â˜‘ |
| `NoOpContentProjectRunEventPublisher` (SSE placeholder) | â˜‘ |
| KhÃ´ng SSE / khÃ´ng Ä‘á»•i ownership | â˜‘ |

**Verdict:** Ready with limitations â€” chÆ°a chá»©ng minh production trial checklist.

---

## 29. Phase 1.8 â€” Orchestration stamp + legacy deprecate

### Resolution (single helper)

`ContentProjectRunEngineFeature::orchestrationFor($run)`:

1. Stamp `settings.php_engine.orchestration` = `php|legacy` â†’ dÃ¹ng stamp (báº¥t biáº¿n).
2. Else `use_php_engine` bool â†’ map php/legacy.
3. Historical unstamped:
   - terminal â†’ `legacy` (khÃ´ng restamp);
   - active + `active_dispatch` / `started_at` / `enabled` â†’ `php`;
   - active khÃ´ng PHP signal â†’ `legacy` (**khÃ´ng** láº¥y global flag).
4. `ensureStamped()` lazy-write chá»‰ khi chÆ°a stamp vÃ  chÆ°a terminal.

### Blocked khi orchestration=php (active)

| Action | Policy |
|---|---|
| `beginRunQueue` / `completeRunQueue` | luÃ´n block + `legacy_action_blocked` |
| `runItemQueued` / `runItem` / `retryWorkflowStep` | block khi non-terminal; **cho phÃ©p** khi terminal (manual retry) |
| `forceStopRunQueue` | delegate `requestStop` |
| `pollRunProgress` | read-only, khÃ´ng log block |
| JS `processQueue`/`startQueue`/`runSingleTask`/`handleStartQueue` | guard `phpEngine` + `@deprecated` |

### Criteria xÃ³a legacy JS

Canary nhiá»u run pass + failure/stop/edit parallel verified + rollback hiáº¿m + default-on á»•n â†’ má»›i xÃ³a. KhÃ´ng xÃ³a á»Ÿ Phase 1.8.

### Default-on prerequisites

KhÃ´ng nÃ¢ng Default-on candidate chá»‰ báº±ng source tests â€” cáº§n production canary evidence.
