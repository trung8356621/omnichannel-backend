> Status: Historical
> Not canonical
> Archived on: 2026-08-01
> Superseded by: ../../modules/CONTENT_PROJECTS.md
> Purpose: implementation history only
# PROJECT_RUN_RETRY â€” Outline dependency handoff

Date: 2026-07-25

## Bug

Route `/content-projects/runs/{run}` â€” Rewrite (Keyword):

1. Retry Â«Táº¡o láº¡i outlineÂ» â†’ Success + menu Â«Láº§n cuá»‘i: HH:mmÂ»
2. Retry Â«Viáº¿t láº¡i ná»™i dungÂ» â†’ `KhÃ´ng thá»ƒ táº¡o láº¡i bÃ i viáº¿t vÃ¬ bÃ i nÃ y chÆ°a cÃ³ outline.`

## Root cause: **CASE A** (persist gap)

KhÃ´ng pháº£i CASE C (task mismatch) lÃ m dependency fail â€” `assertDependencies()` Ä‘á»c **article meta**, khÃ´ng Ä‘á»c task workflow.

| Stage | TrÆ°á»›c fix |
|---|---|
| Outline retry writes | `seo_project_run_items.output_snapshot` (`action=step:{nodeId}`, status success). `TaskWorkflowTestRunner::runSingleStep` set `lastPromptOutput` nhÆ°ng **khÃ´ng** set `direct_publish_outline_markdown` cho outline prompt. |
| `applyParsedMetaFromSteps` | `applyCompletedStepToState` chá»‰ hydrate `nodeOutputs` / `lastPromptOutput` cho `type=prompt`. |
| `persistWorkflowMeta` fallback | Chá»‰ lÆ°u `seo_article_outline` tá»« `lastPromptOutput` khi `!shouldPublishMarkdownAsArticle(...)`. Outline AI thÆ°á»ng cÃ³ `#` / dÃ i â‰¥200â€“400 â†’ **bá»‹ coi lÃ  article** â†’ **khÃ´ng persist**. |
| Menu Â«Láº§n cuá»‘iÂ» | `latestStepFinishes()` tá»« row `step:%` cÃ³ `finished_at` (success). |
| `assertDependencies()` | `article_meta.meta_key = seo_article_outline` non-empty. |
| Content prompt | Edge/`priorSteps`/`direct_publish_outline_markdown` / seed article meta. |

```text
Outline retry writes to: run_item output_snapshot (+ lastPromptOutput in-memory)
Dependency check reads from: article_meta.seo_article_outline
Content prompt reads from: workflow edges / priorSteps / direct_publish_outline_markdown / seo_article_outline

Ba nguá»“n lá»‡ch â†’ CASE A.
```

`resolveSeoTaskForStepRetry` (Æ°u tiÃªn publish náº¿u nhiá»u prompt node) váº«n dÃ¹ng **cÃ¹ng** catalog cho outline + content menu â€” khÃ´ng pháº£i root cause dependency fail láº§n nÃ y. Giá»¯ logic; log/seed váº«n theo `task.article_id`.

## Canonical outline

**`article_meta.seo_article_outline`** (markdown) â€” editor (`EditArticle`) + `WorkflowExistingAiOutputService` + seed `runFromNodeId`.

Parsed tree phá»¥: `seo_article_outlines` (JSON) khi `WorkflowParserService::parseOutline` ra headings.

KhÃ´ng thÃªm cá»™t má»›i.

## Fix

1. `ArticleOutlineResolver` â€” resolve / validate / persist canonical meta.
2. `TaskWorkflowTestRunner::captureOutlinePromptOutput` â€” outline prompt ghi `direct_publish_outline_markdown` + `outline_markdown` / `persists_as_outline` trÃªn step result.
3. `applyCompletedStepToState` â€” hydrate outline meta tá»« `outline_markdown` / `persists_as_outline` / `out_outline`.
4. `SeoProjectWorkflowStepRetryService`:
   - kind `outline`: **persist canonical trÆ°á»›c** khi Ä‘Ã¡nh Success; invalid â†’ Failed, khÃ´ng Â«Láº§n cuá»‘iÂ» usable.
   - `assertDependencies` dÃ¹ng resolver.
   - content: `ensureOutlinePriorFromArticle` seed prior tá»« canonical náº¿u snapshot thiáº¿u.
5. `latestStepFinishes`: `last_finished_at` chá»‰ tá»« status Success.

## Main row Failed UI

`project-run-queue.js` `applyItemFailure` **váº½** status/message lÃªn hÃ ng chÃ­nh khi step retry fail â€” **khÃ´ng** ghi Ä‘Ã¨ DB main item (`action not like step:%`). Counter cÅ©ng bá» qua `step:%`. Giá»¯ behavior; ngoÃ i scope cancel/JS stop.

## Files

- `Services/ArticleOutlineResolver.php` (new)
- `Services/TaskWorkflowTestRunner.php`
- `Services/SeoProjectWorkflowStepRetryService.php`
- `tests/Unit/ArticleOutlineRetryDependencyTest.php` (new)
- `docs/audits/PROJECT_RUN_RETRY_OUTLINE_DEPENDENCY_HANDOFF.md` (this)

## Verify (manual â€” remote)

```text
Manual verification:

php artisan optimize:clear

php artisan test --filter=ArticleOutlineRetryDependencyTest

# UI: outline retry â†’ check article_meta.seo_article_outline
# â†’ content retry must pass dependency vÃ  compile vá»›i outline má»›i
# F5 khÃ´ng báº¯t buá»™c sau outline success
```

Deploy: upload code + `optimize:clear` (khÃ´ng migrate). Queue restart náº¿u worker cache autoload cÅ©.
