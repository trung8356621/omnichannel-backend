# PROJECT_RUN_RETRY — Outline dependency handoff

Date: 2026-07-25

## Bug

Route `/content-projects/runs/{run}` — Rewrite (Keyword):

1. Retry «Tạo lại outline» → Success + menu «Lần cuối: HH:mm»
2. Retry «Viết lại nội dung» → `Không thể tạo lại bài viết vì bài này chưa có outline.`

## Root cause: **CASE A** (persist gap)

Không phải CASE C (task mismatch) làm dependency fail — `assertDependencies()` đọc **article meta**, không đọc task workflow.

| Stage | Trước fix |
|---|---|
| Outline retry writes | `seo_project_run_items.output_snapshot` (`action=step:{nodeId}`, status success). `TaskWorkflowTestRunner::runSingleStep` set `lastPromptOutput` nhưng **không** set `direct_publish_outline_markdown` cho outline prompt. |
| `applyParsedMetaFromSteps` | `applyCompletedStepToState` chỉ hydrate `nodeOutputs` / `lastPromptOutput` cho `type=prompt`. |
| `persistWorkflowMeta` fallback | Chỉ lưu `seo_article_outline` từ `lastPromptOutput` khi `!shouldPublishMarkdownAsArticle(...)`. Outline AI thường có `#` / dài ≥200–400 → **bị coi là article** → **không persist**. |
| Menu «Lần cuối» | `latestStepFinishes()` từ row `step:%` có `finished_at` (success). |
| `assertDependencies()` | `article_meta.meta_key = seo_article_outline` non-empty. |
| Content prompt | Edge/`priorSteps`/`direct_publish_outline_markdown` / seed article meta. |

```text
Outline retry writes to: run_item output_snapshot (+ lastPromptOutput in-memory)
Dependency check reads from: article_meta.seo_article_outline
Content prompt reads from: workflow edges / priorSteps / direct_publish_outline_markdown / seo_article_outline

Ba nguồn lệch → CASE A.
```

`resolveSeoTaskForStepRetry` (ưu tiên publish nếu nhiều prompt node) vẫn dùng **cùng** catalog cho outline + content menu — không phải root cause dependency fail lần này. Giữ logic; log/seed vẫn theo `task.article_id`.

## Canonical outline

**`article_meta.seo_article_outline`** (markdown) — editor (`EditArticle`) + `WorkflowExistingAiOutputService` + seed `runFromNodeId`.

Parsed tree phụ: `seo_article_outlines` (JSON) khi `WorkflowParserService::parseOutline` ra headings.

Không thêm cột mới.

## Fix

1. `ArticleOutlineResolver` — resolve / validate / persist canonical meta.
2. `TaskWorkflowTestRunner::captureOutlinePromptOutput` — outline prompt ghi `direct_publish_outline_markdown` + `outline_markdown` / `persists_as_outline` trên step result.
3. `applyCompletedStepToState` — hydrate outline meta từ `outline_markdown` / `persists_as_outline` / `out_outline`.
4. `SeoProjectWorkflowStepRetryService`:
   - kind `outline`: **persist canonical trước** khi đánh Success; invalid → Failed, không «Lần cuối» usable.
   - `assertDependencies` dùng resolver.
   - content: `ensureOutlinePriorFromArticle` seed prior từ canonical nếu snapshot thiếu.
5. `latestStepFinishes`: `last_finished_at` chỉ từ status Success.

## Main row Failed UI

`project-run-queue.js` `applyItemFailure` **vẽ** status/message lên hàng chính khi step retry fail — **không** ghi đè DB main item (`action not like step:%`). Counter cũng bỏ qua `step:%`. Giữ behavior; ngoài scope cancel/JS stop.

## Files

- `Services/ArticleOutlineResolver.php` (new)
- `Services/TaskWorkflowTestRunner.php`
- `Services/SeoProjectWorkflowStepRetryService.php`
- `tests/Unit/ArticleOutlineRetryDependencyTest.php` (new)
- `docs/audits/PROJECT_RUN_RETRY_OUTLINE_DEPENDENCY_HANDOFF.md` (this)

## Verify (manual — remote)

```text
Manual verification:

php artisan optimize:clear

php artisan test --filter=ArticleOutlineRetryDependencyTest

# UI: outline retry → check article_meta.seo_article_outline
# → content retry must pass dependency và compile với outline mới
# F5 không bắt buộc sau outline success
```

Deploy: upload code + `optimize:clear` (không migrate). Queue restart nếu worker cache autoload cũ.
