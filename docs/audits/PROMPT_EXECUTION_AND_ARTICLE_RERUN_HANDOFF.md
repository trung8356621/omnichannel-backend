# PROMPT EXECUTION + ARTICLE RERUN HANDOFF

Date: 2026-07-25

Related:

- `PROJECT_RUN_RETRY_OUTLINE_DEPENDENCY_HANDOFF.md` (CASE A outline persist) — giữ nguyên.
- `PROJECT_RUN_NGAT_STUCK_HANDOFF.md` — file chưa có trong repo; nội dung Ngắt ổn định trước đó vẫn giữ, patch này **không** revert cancel matcher / autorun.

---

## BUG 1 — Step lỗi/cancel nhưng execution tiếp tục

### Root cause

1. `cancelActiveStep` chỉ flip DB (`Failed` + `Cancelled by user.`).
2. HTTP `executePreparedStep` vẫn block trong `runSingleStep` / provider.
3. Cancel check **thiếu** giữa provider return → persist.
4. `failPrepared` khi đã có cancel marker **return sớm** mà không `ensureCancelledFailureState` → status có thể còn `processing` → F5 vẫn busy (`activeStepStatuses`).
5. Success/fail race: provider cũ trả về sau cancel vẫn có thể persist nếu không re-check.

Không kill được HTTP AI đang blocking — cooperative discard sau provider.

### Execution path mới

```
claim (conditional pending→processing)
→ commit
→ provider / runSingleStep
→ refresh + isExecutionTerminal?
    yes → output_discarded log, ensureCancelledFailureState, return (no persist)
→ step failed? → failPrepared (conditional active→failed)
→ assertExecutionStillActive?
    no → stale_execution_ignored, discard
→ persist outline/meta
→ re-check terminal / still active
→ conditional success (processing + no cancel marker)
```

Bulk: fail một node → `stoppedTaskIds` chặn node còn lại **cùng task** (scope article sequence, không stop toàn project run).

### Conditional transitions (`step:{nodeId}`)

| Transition | Precondition |
|---|---|
| pending→processing | status=pending, error không chứa cancel marker |
| processing→success | status=processing, error không cancel |
| active→failed | status in pending\|processing, error không cancel |
| cancel settle | ensureCancelledFailureState conditional |

Stale provider response: update affect 0 → `seo.workflow_step.stale_execution_ignored`.

### Logs

- `seo.workflow_step.terminal_failure`
- `seo.workflow_step.output_discarded`
- `seo.workflow_step.stale_execution_ignored`

---

## BUG 2 — Rerun article pipeline không tìm thấy node

### Root cause

```text
UI sends: from = outline | article  (semantic)
Backend queue: firstPromptNodeIdForKind via resolveSeoTaskForStepRetry (publish graph nếu richer)
  → settings.start_node_id = node_1780563019334  (publish canvas ID)
Job execute: resolveSeoTask (rewrite/primary) + tin start_node_id
  → runFromNodeId(rewrite graph, node_1780563019334)
  → throw "Không tìm thấy bước bắt đầu: node_…"
```

`node_1780563019334` = `node_${Date.now()}` từ Task Builder trên **publish** workflow — không phải prompt history. Semantic key tương ứng: `outline` hoặc `content` (từ `from`).

### Fix

`ArticlePipelineRerunStartStepResolver`:

1. Luôn resolve trên **cùng** graph `resolveSeoTaskForStepRetry`.
2. Strategy:
   - `direct_node` nếu source node còn + kind khớp;
   - `semantic_kind` map `outline|content` → `firstPromptNodeIdForKind`;
   - `unresolved` → message user-facing, **không** dump raw node ID, **không** fallback node đầu pipeline.
3. Queue + Job đều dùng resolver; `start_node_id` chỉ audit; execution dùng `resolved_node_id`.

Run settings mới:

- `run_type`, `rerun_from_step`, `semantic_key`, `source_run_id`, `source_article_id`
- `start_node_id` / `resolved_node_id`, `source_node_id`, `resolution_strategy`

Logs: `seo.article_rerun.requested` / `start_step_resolved` / `start_step_unresolved`.

---

## Source of truth

| Layer | Role |
|---|---|
| `seo_project_run_items` | step execution status/history |
| `output_snapshot` | intermediate step payload |
| `article` / `article_meta` | canonical bài (outline = `seo_article_outline`) |
| catalog semantic kind | rerun identity bền |
| raw `node_*` | technical ID một workflow version |

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

Filter theo class cũng được sau khi `phpunit.xml` có suite SeoContentAi:

```text
php artisan test --filter=PromptExecutionOrchestrationTest
```

Xem `docs/TESTING.md` — không dùng `optimize:clear` để sửa “No tests found”.

FLOW 1: chạy step → Ngắt → provider xong cũng discard; F5 không busy; không cần Ngắt lần 2.

FLOW 2: Article → Rerun from outline/article → không còn `Không tìm thấy bước bắt đầu: node_…` khi semantic còn trên publish/step-retry graph; run mới có metadata; persist canonical.

Deploy: upload code + `optimize:clear` + `queue:restart` (job `RerunArticlePipelineJob`). Không migrate.
