# HOTFIX — Terminal run + pending helper/step rows

**Status:** source patched — **không** tuyên bố production run 42 đã repair.
**Operator:** deploy → dry-run → (optional) apply. **Không** re-run engine trên run 42; tạo run mới để test.

## Root cause UI

Run `completed` nhưng `seo_project_run_items` còn row `status=pending` với `action LIKE 'step:%'`.

- Counters / dispatch đã exclude step (`articleExecution` / trước đây `not like step:%`) → UI Total/Pending đúng article.
- `SeoProjectWorkflowStepRetryService::stepsForTask` set `busy=true` từ step pending → Blade hiện **Đang chạy / Ngắt**.
- Health trước đây không phân biệt article vs helper pending → có thể `ok=true` dù còn step pending.

## Classifier (cột `action`)

| Kind | Predicate |
|------|-----------|
| **article** | `action IN` `SeoProjectRunAction` (`article.create`, `article.update`, `article.rewrite`, `article.archive`, `article.restore`, `task.retry`) |
| **workflow_step** | `action LIKE 'step:%'` |
| **helper** | mọi action khác (không article, không step) |

Code: `Support/SeoProjectRunItemClassifier.php` + scopes trên `Models/SeoProjectRunItem`.

**IDs 114 / 119 (run 42):** operator paste `SELECT id, action, status FROM seo_project_run_items WHERE id IN (114,119)`.
Dự kiến: `action LIKE 'step:%'` → **workflow_step** (vì counters Pending=0 trong khi row còn pending).

## Terminal-neutral status

`skipped` (`SeoProjectRunItemStatus::Skipped`) — enum sẵn có. **Không** map pending → success.

## Files đổi

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
# Dry-run (mặc định)
{PHP_BIN} artisan seo:content-project-run:recover 42

# Apply normalize helper/step pending → skipped
{PHP_BIN} artisan seo:content-project-run:recover 42 \
  --apply \
  --action=normalize-terminal-helpers

# Lần 2 = no-op (noop_already_clean) nếu đã sạch
{PHP_BIN} artisan seo:content-project-run:recover 42 \
  --apply \
  --action=normalize-terminal-helpers
```

Dry-run kỳ vọng (khi chỉ còn helper pending):

```json
{
  "pending_article_items": [],
  "pending_helper_items": [114, 119],
  "recommended_action": "normalize_terminal_helper_rows",
  "eligible_for_normalize_terminal_helpers": true
}
```

## SQL fallback (trước khi deploy command)

**Predicate article actions** (khớp code):

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

-- Optional: xác nhận không đụng article
SELECT id, action, status
FROM seo_project_run_items
WHERE run_id = 42
  AND status = 'pending'
  AND action IN (
    'article.create','article.update','article.rewrite',
    'article.archive','article.restore','task.retry'
  );

-- Normalize helper/step only (KHÔNG pending→success)
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
-- ROLLBACK;  -- dùng nếu verify fail
```

Điều kiện ops thêm (manual check trước UPDATE): run status terminal, `active_dispatch` null, không article processing.

## PHPUnit (hosting)

```bash
{PHP_BIN} vendor/bin/phpunit --filter=ContentProjectRunTerminalHelperHotfixTest
{PHP_BIN} vendor/bin/phpunit --filter=ContentProjectRunEnginePhase1Test
{PHP_BIN} vendor/bin/phpunit --filter=SeoProjectWorkflowStepRetryServiceTest
```

## Sau deploy

1. Dry-run recover 42 — paste JSON.
2. Apply normalize nếu eligible.
3. F5 View run — không còn Đang chạy/Ngắt từ step pending.
4. **Tạo run mới** để test engine; **không** chạy lại engine trên 42.
