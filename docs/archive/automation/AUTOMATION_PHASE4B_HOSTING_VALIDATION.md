> Status: Historical
> Not canonical
> Archived on: 2026-08-01
> Superseded by: ../../modules/AUTOMATION.md
> Purpose: implementation history only
# Automation Phase 4B â€” Hosting Validation Runbook

**Status:** runbook only â€” chÆ°a deploy, chÆ°a Ä‘á»•i env production  
**Updated:** 2026-07-18  
**Constraint:** Local DB khÃ´ng dÃ¹ng. Má»i kiá»ƒm tra DB/integration trÃªn hosting sau deploy.

Code Ä‘Ã£ **wired** (default `legacy`). Tráº¡ng thÃ¡i váº­n hÃ nh tÃ¡ch:

| Tráº¡ng thÃ¡i | Ã nghÄ©a |
|---|---|
| **wired** | Caller production Ä‘i qua bridge (code) |
| **deployed** | Code Ä‘Ã£ lÃªn hosting, flags váº«n `legacy` |
| **shadow validated** | Flag `shadow` trÃªn hosting + parity review pass |
| **promoted to action** | Flag `action` + promotion gate pass |

**KhÃ´ng** ghi â€œmigratedâ€ chá»‰ vÃ¬ Ä‘Ã£ wire.

---

## Step 1 â€” Deploy á»Ÿ legacy

Táº¥t cáº£ Group 2 flags:

```env
AUTOMATION_MIGRATION_PROJECT_ARTICLE_CREATE=legacy
AUTOMATION_MIGRATION_PROJECT_ARTICLE_CONTENT_UPDATE=legacy
AUTOMATION_MIGRATION_PROJECT_ARTICLE_SEO_META_UPDATE=legacy
```

Sau deploy / Ä‘á»•i env trÃªn hosting:

```text
php artisan optimize:clear
php artisan config:clear
php artisan cache:clear
php artisan queue:restart
```

Kiá»ƒm tra runtime khÃ´ng Ä‘á»•i:

- Project táº¡o bÃ i tá»« task
- Project publish ná»™i dung (PromptTestPublish / workflow)
- Meta description tá»« AI import
- KhÃ´ng outbound WordPress tá»« cÃ¡c path nÃ y
- KhÃ´ng automation execution phá»¥ khÃ´ng cáº§n thiáº¿t á»Ÿ mode legacy

---

## Step 2 â€” Shadow tá»«ng caller

Báº­t **riÃªng** tá»«ng flag. KhÃ´ng báº­t Ä‘á»“ng thá»i láº§n Ä‘áº§u.

### 2a â€” create

```env
AUTOMATION_MIGRATION_PROJECT_ARTICLE_CREATE=shadow
```

Giá»¯ content + seo_meta = `legacy`. Clear config/cache nhÆ° Step 1.

### 2b â€” content

```env
AUTOMATION_MIGRATION_PROJECT_ARTICLE_CONTENT_UPDATE=shadow
```

Giá»¯ create + seo_meta = `legacy` (hoáº·c create Ä‘Ã£ shadow-validated).

### 2c â€” seo_meta

```env
AUTOMATION_MIGRATION_PROJECT_ARTICLE_SEO_META_UPDATE=shadow
```

---

## Step 3 â€” Review

Vá»›i tá»«ng caller á»Ÿ shadow, kiá»ƒm tra:

| Check | Ká»³ vá»ng |
|---|---|
| `parity_match` | Log match khi expected â‰ˆ legacy |
| `parity_mismatch` | Äiá»u tra trÆ°á»›c promote |
| `duplicate` / dedup | Task Ä‘Ã£ cÃ³ `article_id` â†’ khÃ´ng táº¡o bÃ i má»›i |
| `conflict` | Content hash/`expected_*` fail rÃµ, khÃ´ng silent overwrite |
| missing link | Task â†” article linkage giá»¯ |
| queue count | Scoring queue **má»™t láº§n** (khÃ´ng double á»Ÿ shadow) |
| sync flag | `markLocalEditPending` Ä‘Ãºng má»™t láº§n theo path legacy |
| WP outbound | KhÃ´ng HTTP WP tá»« Group 2 project path |
| exceptions | Legacy exception = SoT; parity fail chá»‰ log (trá»« security/config critical) |

---

## Step 4 â€” Promote

Chá»‰ chuyá»ƒn **má»™t** caller sang `action` khi `AutomationActionPromotionGate` pass cho caller Ä‘Ã³.

VÃ­ dá»¥:

```env
AUTOMATION_MIGRATION_PROJECT_ARTICLE_CREATE=action
```

Action mode:

- Chá»‰ `ActionRunner` ghi
- KhÃ´ng cháº¡y legacy write sau Ä‘Ã³
- Fail rÃµ â€” khÃ´ng fallback legacy ngáº§m

Clear config/cache láº¡i sau Ä‘á»•i env.

---

## Step 5 â€” Rollback

Set láº¡i:

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

## KhÃ´ng lÃ m tá»« mÃ¡y local / agent

- KhÃ´ng Ä‘á»•i `.env` production tá»« agent
- KhÃ´ng tá»± báº­t shadow/action
- KhÃ´ng cháº¡y DB integration local
- KhÃ´ng deploy tá»± Ä‘á»™ng

## Unit (cÃ³ thá»ƒ cháº¡y local, khÃ´ng DB)

```text
php artisan test app/Addons/SeoContentAi/tests --filter=AutomationPhase4B
php artisan test app/Addons/SeoContentAi/tests --filter=AutomationActionBoundary
```
