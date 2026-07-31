> Status: Historical
> Not canonical
> Archived on: 2026-08-01
> Superseded by: ../../modules/CONTENT_PROJECTS.md
> Purpose: implementation history only
# HOTFIX â€” Terminal run + pending helper/step rows

**Status:** source patched â€” **khÃ´ng** tuyÃªn bá»‘ production run 42 Ä‘Ã£ repair.
**Operator:** deploy â†’ dry-run â†’ (optional) apply. **KhÃ´ng** re-run engine trÃªn run 42; táº¡o run má»›i Ä‘á»ƒ test.

## Root cause UI

Run `completed` nhÆ°ng `seo_project_run_items` cÃ²n row `status=pending` vá»›i `action LIKE 'step:%'`.

- Counters / dispatch Ä‘Ã£ exclude step (`articleExecution` / trÆ°á»›c Ä‘Ã¢y `not like step:%`) â†’ UI Total/Pending Ä‘Ãºng article.
- `SeoProjectWorkflowStepRetryService::stepsForTask` set `busy=true` tá»« step pending â†’ Blade hiá»‡n **Äang cháº¡y / Ngáº¯t**.
- Health trÆ°á»›c Ä‘Ã¢y khÃ´ng phÃ¢n biá»‡t article vs helper pending â†’ cÃ³ thá»ƒ `ok=true` dÃ¹ cÃ²n step pending.

## Classifier (cá»™t `action`)

| Kind | Predicate |
|------|-----------|
| **article** | `action IN` `SeoProjectRunAction` (`article.create`, `article.update`, `article.rewrite`, `article.archive`, `article.restore`, `task.retry`) |
| **workflow_step** | `action LIKE 'step:%'` |
| **helper** | má»i action khÃ¡c (khÃ´ng article, khÃ´ng step) |

Code: `Support/SeoProjectRunItemClassifier.php` + scopes trÃªn `Models/SeoProjectRunItem`.

**IDs 114 / 119 (run 42):** operator paste `SELECT id, action, status FROM seo_project_run_items WHERE id IN (114,119)`.
Dá»± kiáº¿n: `action LIKE 'step:%'` â†’ **workflow_step** (vÃ¬ counters Pending=0 trong khi row cÃ²n pending).

## Terminal-neutral status

`skipped` (`SeoProjectRunItemStatus::Skipped`) â€” enum sáºµn cÃ³. **KhÃ´ng** map pending â†’ success.

## Files Ä‘á»•i

- `Enums/SeoProjectRunItemKind.php` (new)
- `Support/SeoProjectRunItemClassifier.php` (new)
- `Models/SeoProjectRunItem.php` (scopes)
- `Services/RunEngine/ContentProjectRunEngine.php` (scopes, health, finalize normalize, recovery)
- `Services/SeoProjectRunItemsReader.php`, `SeoProjectRunItemService.php`, `ContentProjectArticleRunner.php`
- `Services/SeoProjectWorkflowStepRetryService.php` (busy=false khi run terminal)
- `Console/ContentProjectRunRecoverCommand.php`
- `ViewSeoProjectRun.php`, `view-project-run.blade.php`, `project-run-queue.js`
- `tests/Unit/ContentProjectRunTerminalHelperHotfixTest.php`

## Recover commands

```bash
# Dry-run (máº·c Ä‘á»‹nh)
{PHP_BIN} artisan seo:content-project-run:recover 42

# Apply normalize helper/step pending â†’ skipped
{PHP_BIN} artisan seo:content-project-run:recover 42 \
  --apply \
  --action=normalize-terminal-helpers

# Láº§n 2 = no-op (noop_already_clean) náº¿u Ä‘Ã£ sáº¡ch
{PHP_BIN} artisan seo:content-project-run:recover 42 \
  --apply \
  --action=normalize-terminal-helpers
```

Dry-run ká»³ vá»ng (khi chá»‰ cÃ²n helper pending):

```json
{
  "pending_article_items": [],
  "pending_helper_items": [114, 119],
  "recommended_action": "normalize_terminal_helper_rows",
  "eligible_for_normalize_terminal_helpers": true
}
```

## SQL fallback (trÆ°á»›c khi deploy command)

**Predicate article actions** (khá»›p code):

```sql
('article.create','article.update','article.rewrite','article.archive','article.restore','task.retry')
```

```sql
START TRANSACTION;

-- Inspect
SELECT id, run_id, task_id, action, status, message, finished_at
FROM seo_project_run_items
WHERE run_id = 42
  AND id IN (114, 119)
  AND status IN ('pending', 'processing')
  AND action NOT IN (
    'article.create','article.update','article.rewrite',
    'article.archive','article.restore','task.retry'
  );

-- Optional: xÃ¡c nháº­n khÃ´ng Ä‘á»¥ng article
SELECT id, action, status
FROM seo_project_run_items
WHERE run_id = 42
  AND status = 'pending'
  AND action IN (
    'article.create','article.update','article.rewrite',
    'article.archive','article.restore','task.retry'
  );

-- Normalize helper/step only (KHÃ”NG pendingâ†’success)
UPDATE seo_project_run_items
SET status = 'skipped',
    message = 'Normalized on terminal run (helper/step unused).',
    error_message = NULL,
    finished_at = NOW(),
    updated_at = NOW()
WHERE run_id = 42
  AND id IN (114, 119)
  AND status IN ('pending', 'processing')
  AND action NOT IN (
    'article.create','article.update','article.rewrite',
    'article.archive','article.restore','task.retry'
  );

-- Verify
SELECT id, action, status, message, finished_at
FROM seo_project_run_items
WHERE id IN (114, 119);

COMMIT;
-- ROLLBACK;  -- dÃ¹ng náº¿u verify fail
```

Äiá»u kiá»‡n ops thÃªm (manual check trÆ°á»›c UPDATE): run status terminal, `active_dispatch` null, khÃ´ng article processing.

## PHPUnit (hosting)

```bash
{PHP_BIN} vendor/bin/phpunit --filter=ContentProjectRunTerminalHelperHotfixTest
{PHP_BIN} vendor/bin/phpunit --filter=ContentProjectRunEnginePhase1Test
{PHP_BIN} vendor/bin/phpunit --filter=SeoProjectWorkflowStepRetryServiceTest
```

## Sau deploy

1. Dry-run recover 42 â€” paste JSON.
2. Apply normalize náº¿u eligible.
3. F5 View run â€” khÃ´ng cÃ²n Äang cháº¡y/Ngáº¯t tá»« step pending.
4. **Táº¡o run má»›i** Ä‘á»ƒ test engine; **khÃ´ng** cháº¡y láº¡i engine trÃªn 42.
