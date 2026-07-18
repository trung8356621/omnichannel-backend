# Automation Phase 4B — Hosting Validation Runbook

**Status:** runbook only — chưa deploy, chưa đổi env production  
**Updated:** 2026-07-18  
**Constraint:** Local DB không dùng. Mọi kiểm tra DB/integration trên hosting sau deploy.

Code đã **wired** (default `legacy`). Trạng thái vận hành tách:

| Trạng thái | Ý nghĩa |
|---|---|
| **wired** | Caller production đi qua bridge (code) |
| **deployed** | Code đã lên hosting, flags vẫn `legacy` |
| **shadow validated** | Flag `shadow` trên hosting + parity review pass |
| **promoted to action** | Flag `action` + promotion gate pass |

**Không** ghi “migrated” chỉ vì đã wire.

---

## Step 1 — Deploy ở legacy

Tất cả Group 2 flags:

```env
AUTOMATION_MIGRATION_PROJECT_ARTICLE_CREATE=legacy
AUTOMATION_MIGRATION_PROJECT_ARTICLE_CONTENT_UPDATE=legacy
AUTOMATION_MIGRATION_PROJECT_ARTICLE_SEO_META_UPDATE=legacy
```

Sau deploy / đổi env trên hosting:

```text
php artisan optimize:clear
php artisan config:clear
php artisan cache:clear
php artisan queue:restart
```

Kiểm tra runtime không đổi:

- Project tạo bài từ task
- Project publish nội dung (PromptTestPublish / workflow)
- Meta description từ AI import
- Không outbound WordPress từ các path này
- Không automation execution phụ không cần thiết ở mode legacy

---

## Step 2 — Shadow từng caller

Bật **riêng** từng flag. Không bật đồng thời lần đầu.

### 2a — create

```env
AUTOMATION_MIGRATION_PROJECT_ARTICLE_CREATE=shadow
```

Giữ content + seo_meta = `legacy`. Clear config/cache như Step 1.

### 2b — content

```env
AUTOMATION_MIGRATION_PROJECT_ARTICLE_CONTENT_UPDATE=shadow
```

Giữ create + seo_meta = `legacy` (hoặc create đã shadow-validated).

### 2c — seo_meta

```env
AUTOMATION_MIGRATION_PROJECT_ARTICLE_SEO_META_UPDATE=shadow
```

---

## Step 3 — Review

Với từng caller ở shadow, kiểm tra:

| Check | Kỳ vọng |
|---|---|
| `parity_match` | Log match khi expected ≈ legacy |
| `parity_mismatch` | Điều tra trước promote |
| `duplicate` / dedup | Task đã có `article_id` → không tạo bài mới |
| `conflict` | Content hash/`expected_*` fail rõ, không silent overwrite |
| missing link | Task ↔ article linkage giữ |
| queue count | Scoring queue **một lần** (không double ở shadow) |
| sync flag | `markLocalEditPending` đúng một lần theo path legacy |
| WP outbound | Không HTTP WP từ Group 2 project path |
| exceptions | Legacy exception = SoT; parity fail chỉ log (trừ security/config critical) |

---

## Step 4 — Promote

Chỉ chuyển **một** caller sang `action` khi `AutomationActionPromotionGate` pass cho caller đó.

Ví dụ:

```env
AUTOMATION_MIGRATION_PROJECT_ARTICLE_CREATE=action
```

Action mode:

- Chỉ `ActionRunner` ghi
- Không chạy legacy write sau đó
- Fail rõ — không fallback legacy ngầm

Clear config/cache lại sau đổi env.

---

## Step 5 — Rollback

Set lại:

```env
AUTOMATION_MIGRATION_PROJECT_ARTICLE_CREATE=legacy
AUTOMATION_MIGRATION_PROJECT_ARTICLE_CONTENT_UPDATE=legacy
AUTOMATION_MIGRATION_PROJECT_ARTICLE_SEO_META_UPDATE=legacy
```

```text
php artisan optimize:clear
php artisan config:clear
php artisan cache:clear
php artisan queue:restart
```

---

## Không làm từ máy local / agent

- Không đổi `.env` production từ agent
- Không tự bật shadow/action
- Không chạy DB integration local
- Không deploy tự động

## Unit (có thể chạy local, không DB)

```text
php artisan test app/Addons/SeoContentAi/tests --filter=AutomationPhase4B
php artisan test app/Addons/SeoContentAi/tests --filter=AutomationActionBoundary
```
