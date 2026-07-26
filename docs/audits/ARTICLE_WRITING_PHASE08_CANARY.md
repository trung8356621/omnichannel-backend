# Article Writing — Phase 0.8 Canary Evidence

**Status:** tooling ready — operator paste evidence. Cursor không SSH; không tự tuyên bố production pass.

**Verdict rule:** chỉ nâng **Stable candidate** khi canary A–F pass trên host + `seo:workflow:doctor` sạch.

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
# nếu dry-run ổn:
php artisan seo:workflow:assign-execution-roles --apply
php artisan seo:workflow:doctor
php artisan queue:restart
```

Vite: chỉ khi đổi JS FlowBuilder — dùng command build thật của project (không mặc định `npm run build` nếu khác).

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

## Canary A — Tạo lại dàn ý (bulk outline)

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

Kỳ vọng: chỉ outline node; article body không đổi; outline artifact mới; history có role/node/hash.

---

## Canary B — Tạo lại bài từ dàn ý

```text
Canary B run:
```

Kỳ vọng: không chạy outline; dùng outline hiện có; source badge Outline; body update; không lấy body cũ làm source.

---

## Canary C — Tạo lại dàn ý và bài viết

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

Kỳ vọng: hook `article.content.improve`; không outline/generate; không `article_length`; stale guard OK.

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
  (after — must match snapshot):
Rerun:
  (after — must use current config):
```

UI labels: **Thử lại lần chạy cũ** vs **Chạy lại bằng cấu hình hiện tại**.

---

## Image-role audit (read-only)

| Workflow | Node role | Prompt hook | Risk |
|----------|-----------|-------------|------|
| | `article.image.generate` | | Hook mismatch? |
| | | `product.gallery.generate` | Không gom chung image role nếu contract khác |
| | | typography / video | Đề xuất role riêng chỉ khi runtime có capability |

Phase 0.8: **không** thêm role mới nếu chưa có capability runtime.

---

## Legacy observability

Log event: `article_writing.legacy_adapter_used`  
Context: `caller`, `article_id`, `run_id`, `old_hook`, `mapped_source_type`, `destination_capability`  
Không log full article/prompt.

---

## Dead code (Phase 0.8)

| Item | Action |
|------|--------|
| `rewrite_article_task_id` DB field | **Giữ** |
| `ArticleWritingLegacyRewriteAdapter` | **Giữ** (+ log) |
| Heuristic title trong catalog/execution | Đã bỏ (0.7) — verify doctor/tests |
| Duplicate resolveEditorFullRewrite | Audit sau canary; chỉ xóa khi grep 0 caller |

---

## Rollback

```text
Rollback:
```

---

## Verdict

```text
Verdict: Canary ready | Stable candidate (chỉ khi evidence đủ) | Blocked
Errors:
```
