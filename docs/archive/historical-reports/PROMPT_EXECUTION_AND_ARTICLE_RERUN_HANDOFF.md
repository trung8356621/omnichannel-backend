> Status: Historical
> Not canonical
> Archived on: 2026-08-01
> Superseded by: ../../modules/ARTICLE_EDITOR.md
> Purpose: implementation history only
# PROMPT EXECUTION + ARTICLE RERUN HANDOFF

Date: 2026-07-25

Related:

- `PROJECT_RUN_RETRY_OUTLINE_DEPENDENCY_HANDOFF.md` (CASE A outline persist) â€” giá»¯ nguyÃªn.
- `PROJECT_RUN_NGAT_STUCK_HANDOFF.md` â€” file chÆ°a cÃ³ trong repo; ná»™i dung Ngáº¯t á»•n Ä‘á»‹nh trÆ°á»›c Ä‘Ã³ váº«n giá»¯, patch nÃ y **khÃ´ng** revert cancel matcher / autorun.

---

## BUG 1 â€” Step lá»—i/cancel nhÆ°ng execution tiáº¿p tá»¥c

### Root cause

1. `cancelActiveStep` chá»‰ flip DB (`Failed` + `Cancelled by user.`).
2. HTTP `executePreparedStep` váº«n block trong `runSingleStep` / provider.
3. Cancel check **thiáº¿u** giá»¯a provider return â†’ persist.
4. `failPrepared` khi Ä‘Ã£ cÃ³ cancel marker **return sá»›m** mÃ  khÃ´ng `ensureCancelledFailureState` â†’ status cÃ³ thá»ƒ cÃ²n `processing` â†’ F5 váº«n busy (`activeStepStatuses`).
5. Success/fail race: provider cÅ© tráº£ vá» sau cancel váº«n cÃ³ thá»ƒ persist náº¿u khÃ´ng re-check.

KhÃ´ng kill Ä‘Æ°á»£c HTTP AI Ä‘ang blocking â€” cooperative discard sau provider.

### Execution path má»›i

```
claim (conditional pendingâ†’processing)
â†’ commit
â†’ provider / runSingleStep
â†’ refresh + isExecutionTerminal?
    yes â†’ output_discarded log, ensureCancelledFailureState, return (no persist)
â†’ step failed? â†’ failPrepared (conditional activeâ†’failed)
â†’ assertExecutionStillActive?
    no â†’ stale_execution_ignored, discard
â†’ persist outline/meta
â†’ re-check terminal / still active
â†’ conditional success (processing + no cancel marker)
```

Bulk: fail má»™t node â†’ `stoppedTaskIds` cháº·n node cÃ²n láº¡i **cÃ¹ng task** (scope article sequence, khÃ´ng stop toÃ n project run).

### Conditional transitions (`step:{nodeId}`)

| Transition | Precondition |
|---|---|
| pendingâ†’processing | status=pending, error khÃ´ng chá»©a cancel marker |
| processingâ†’success | status=processing, error khÃ´ng cancel |
| activeâ†’failed | status in pending\|processing, error khÃ´ng cancel |
| cancel settle | ensureCancelledFailureState conditional |

Stale provider response: update affect 0 â†’ `seo.workflow_step.stale_execution_ignored`.

### Logs

- `seo.workflow_step.terminal_failure`
- `seo.workflow_step.output_discarded`
- `seo.workflow_step.stale_execution_ignored`

---

## BUG 2 â€” Rerun article pipeline khÃ´ng tÃ¬m tháº¥y node

### Root cause

```text
UI sends: from = outline | article  (semantic)
Backend queue: firstPromptNodeIdForKind via resolveSeoTaskForStepRetry (publish graph náº¿u richer)
  â†’ settings.start_node_id = node_1780563019334  (publish canvas ID)
Job execute: resolveSeoTask (rewrite/primary) + tin start_node_id
  â†’ runFromNodeId(rewrite graph, node_1780563019334)
  â†’ throw "KhÃ´ng tÃ¬m tháº¥y bÆ°á»›c báº¯t Ä‘áº§u: node_â€¦"
```

`node_1780563019334` = `node_${Date.now()}` tá»« Task Builder trÃªn **publish** workflow â€” khÃ´ng pháº£i prompt history. Semantic key tÆ°Æ¡ng á»©ng: `outline` hoáº·c `content` (tá»« `from`).

### Fix

`ArticlePipelineRerunStartStepResolver`:

1. LuÃ´n resolve trÃªn **cÃ¹ng** graph `resolveSeoTaskForStepRetry`.
2. Strategy:
   - `direct_node` náº¿u source node cÃ²n + kind khá»›p;
   - `semantic_kind` map `outline|content` â†’ `firstPromptNodeIdForKind`;
   - `unresolved` â†’ message user-facing, **khÃ´ng** dump raw node ID, **khÃ´ng** fallback node Ä‘áº§u pipeline.
3. Queue + Job Ä‘á»u dÃ¹ng resolver; `start_node_id` chá»‰ audit; execution dÃ¹ng `resolved_node_id`.

Run settings má»›i:

- `run_type`, `rerun_from_step`, `semantic_key`, `source_run_id`, `source_article_id`
- `start_node_id` / `resolved_node_id`, `source_node_id`, `resolution_strategy`

Logs: `seo.article_rerun.requested` / `start_step_resolved` / `start_step_unresolved`.

---

## Source of truth

| Layer | Role |
|---|---|
| `seo_project_run_items` | step execution status/history |
| `output_snapshot` | intermediate step payload |
| `article` / `article_meta` | canonical bÃ i (outline = `seo_article_outline`) |
| catalog semantic kind | rerun identity bá»n |
| raw `node_*` | technical ID má»™t workflow version |

---

## Files

- `Exceptions/WorkflowStepCancelledException.php` (new)
- `Exceptions/WorkflowStepDependencyException.php` (new)
- `Exceptions/WorkflowStepExecutionException.php` (new)
- `Services/ArticlePipelineRerunStartStepResolver.php` (new)
- `Services/ArticlePipelineRerunService.php`
- `Jobs/RerunArticlePipelineJob.php`
- `Services/SeoProjectWorkflowStepRetryService.php`
- `tests/Unit/PromptExecutionOrchestrationTest.php` (new)
- `tests/Unit/ArticlePipelineRerunServiceTest.php`
- `docs/audits/PROMPT_EXECUTION_AND_ARTICLE_RERUN_HANDOFF.md` (this)

---

## Manual verify

```text
Manual verification:

php artisan test:doctor

php artisan test app/Addons/SeoContentAi/tests/Unit/PromptExecutionOrchestrationTest.php

php artisan test app/Addons/SeoContentAi/tests/Unit/ArticlePipelineRerunServiceTest.php

php artisan test app/Addons/SeoContentAi/tests/Unit/ArticleOutlineRetryDependencyTest.php

php artisan queue:restart
```

Filter theo class cÅ©ng Ä‘Æ°á»£c sau khi `phpunit.xml` cÃ³ suite SeoContentAi:

```text
php artisan test --filter=PromptExecutionOrchestrationTest
```

Xem `docs/TESTING.md` â€” khÃ´ng dÃ¹ng `optimize:clear` Ä‘á»ƒ sá»­a â€œNo tests foundâ€.

FLOW 1: cháº¡y step â†’ Ngáº¯t â†’ provider xong cÅ©ng discard; F5 khÃ´ng busy; khÃ´ng cáº§n Ngáº¯t láº§n 2.

FLOW 2: Article â†’ Rerun from outline/article â†’ khÃ´ng cÃ²n `KhÃ´ng tÃ¬m tháº¥y bÆ°á»›c báº¯t Ä‘áº§u: node_â€¦` khi semantic cÃ²n trÃªn publish/step-retry graph; run má»›i cÃ³ metadata; persist canonical.

Deploy: upload code + `optimize:clear` + `queue:restart` (job `RerunArticlePipelineJob`). KhÃ´ng migrate.
