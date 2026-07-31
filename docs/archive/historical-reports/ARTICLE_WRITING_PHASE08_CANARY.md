> Status: Historical
> Not canonical
> Archived on: 2026-08-01
> Superseded by: ../../modules/OPERATIONS_AND_OBSERVABILITY.md
> Purpose: implementation history only
# Article Writing â€” Phase 0.8 Canary Evidence

**Status:** tooling ready â€” operator paste evidence. Cursor khÃ´ng SSH; khÃ´ng tá»± tuyÃªn bá»‘ production pass.

**Verdict rule:** chá»‰ nÃ¢ng **Stable candidate** khi canary Aâ€“F pass trÃªn host + `seo:workflow:doctor` sáº¡ch.

---

## Environment

```text
Environment:
PHP_BIN:
Worker PID/command:
Git commit:
Workflow doctor output:
```

### Pre-flight (operator)

```bash
php artisan optimize:clear
php artisan migrate --force
php artisan seo:workflow:assign-execution-roles
php artisan seo:workflow:doctor
# náº¿u dry-run á»•n:
php artisan seo:workflow:assign-execution-roles --apply
php artisan seo:workflow:doctor
php artisan queue:restart
```

Vite: chá»‰ khi Ä‘á»•i JS FlowBuilder â€” dÃ¹ng command build tháº­t cá»§a project (khÃ´ng máº·c Ä‘á»‹nh `npm run build` náº¿u khÃ¡c).

### Automated tests

```bash
php vendor/bin/phpunit --filter=WorkflowConfiguration
php vendor/bin/phpunit --filter=WorkflowExecutionRole
php vendor/bin/phpunit --filter=ArticleWriting
php vendor/bin/phpunit --filter=ArticleImprove
php vendor/bin/phpunit --filter=ContentProjectRunEnginePhase1Test
php vendor/bin/phpunit --filter=PromptOwnershipModelTest
```

---

## Canary A â€” Táº¡o láº¡i dÃ n Ã½ (bulk outline)

```text
Canary A run:
run ID:
item IDs:
workflow hash:
node IDs / role:
source artifact hash:
prompt owner:
final status:
Errors:
```

Ká»³ vá»ng: chá»‰ outline node; article body khÃ´ng Ä‘á»•i; outline artifact má»›i; history cÃ³ role/node/hash.

---

## Canary B â€” Táº¡o láº¡i bÃ i tá»« dÃ n Ã½

```text
Canary B run:
```

Ká»³ vá»ng: khÃ´ng cháº¡y outline; dÃ¹ng outline hiá»‡n cÃ³; source badge Outline; body update; khÃ´ng láº¥y body cÅ© lÃ m source.

---

## Canary C â€” Táº¡o láº¡i dÃ n Ã½ vÃ  bÃ i viáº¿t

```text
Canary C run:
outline artifact hash:
article source artifact hash (must match):
article_blocked if outline fail:
```

---

## Improve canary

```text
Improve run (scope=article):
selection|section reject message:
```

Ká»³ vá»ng: hook `article.content.improve`; khÃ´ng outline/generate; khÃ´ng `article_length`; stale guard OK.

---

## Editor full rewrite canary

```text
Editor rewrite run:
source_type:
history badge:
Stale result (ignored_stale):
```

---

## Brief canary

```text
Brief run (title+kw+desc / title only / keyword only):
source_type=brief:
```

---

## Retry / Rerun canary

```text
Retry:
  workflow hash / prompt id / node id / article length / source hash (before):
  (after â€” must match snapshot):
Rerun:
  (after â€” must use current config):
```

UI labels: **Thá»­ láº¡i láº§n cháº¡y cÅ©** vs **Cháº¡y láº¡i báº±ng cáº¥u hÃ¬nh hiá»‡n táº¡i**.

---

## Image-role audit (read-only)

| Workflow | Node role | Prompt hook | Risk |
|----------|-----------|-------------|------|
| | `article.image.generate` | | Hook mismatch? |
| | | `product.gallery.generate` | KhÃ´ng gom chung image role náº¿u contract khÃ¡c |
| | | typography / video | Äá» xuáº¥t role riÃªng chá»‰ khi runtime cÃ³ capability |

Phase 0.8: **khÃ´ng** thÃªm role má»›i náº¿u chÆ°a cÃ³ capability runtime.

---

## Legacy observability

Log event: `article_writing.legacy_adapter_used`  
Context: `caller`, `article_id`, `run_id`, `old_hook`, `mapped_source_type`, `destination_capability`  
KhÃ´ng log full article/prompt.

---

## Dead code (Phase 0.8)

| Item | Action |
|------|--------|
| `rewrite_article_task_id` DB field | **Giá»¯** |
| `ArticleWritingLegacyRewriteAdapter` | **Giá»¯** (+ log) |
| Heuristic title trong catalog/execution | ÄÃ£ bá» (0.7) â€” verify doctor/tests |
| Duplicate resolveEditorFullRewrite | Audit sau canary; chá»‰ xÃ³a khi grep 0 caller |

---

## Rollback

```text
Rollback:
```

---

## Verdict

```text
Verdict: Canary ready | Stable candidate (chá»‰ khi evidence Ä‘á»§) | Blocked
Errors:
```
