> Status: Historical
> Not canonical
> Archived on: 2026-08-01
> Superseded by: ../../modules/AUTOMATION.md
> Purpose: implementation history only
# Prompt Hook Phase 5C / 5D1 â€” Hosting Validation & Rollout Runbook

**Status:** code ready for hosting rollout â€” repo defaults remain **legacy**  
**Updated:** 2026-07-18  
**Live shadow:** OFF. Multi-worker live shadow **blocked** (in-memory budget store).

Operational status vocabulary (do not say â€œdoneâ€):

| Status | Meaning |
|---|---|
| code ready | Code merged; defaults legacy |
| deployed | Code on hosting; modes still legacy |
| shadow enabled | One hook set to `shadow` on hosting |
| sample threshold reached | Parity samples â‰¥ configured threshold |
| gate passed | `PromptHookPromotionGate` allowed (manual review) |
| hook enabled | Mode flipped to `hook` for that hook only |
| stable version | **Not** in 5D1 â€” title/meta stay experimental@0.1.0 |

---

## Shared cache / queue refresh (after every env change)

Project conventions (see also Phase 4B runbook + `.cmd` queue notes):

```text
php artisan optimize:clear
php artisan config:clear
php artisan cache:clear
php artisan seo:prompt-hooks:clear-cache
php artisan queue:restart
```

Queue workers (hosting â€” when AI/jobs use queues):

```text
php artisan queue:work --queue=seo,media_generation,default --timeout=360
```

---

## Step 0 â€” Pre-check (deployed, legacy)

```text
php artisan seo:prompt-hooks:status
php artisan seo:prompt-hooks:parity-report
```

Expect: all modes `legacy`, live_shadow_enabled=false, five hooks registered@0.1.0.

Smoke (legacy path unchanged):

- Generate outline/headings
- Generate FAQ
- Keyword structured discovery
- Title / meta suggestion UI

---

## Rollout order (one hook at a time)

1. `article.outline.generate`
2. `article.faq.generate`
3. `keyword.discovery.structured`
4. `article.title_suggestion`
5. `article.meta_description_suggestion`

### Per-hook template

#### Pre-check

- `seo:prompt-hooks:status` shows target still `legacy` (or previous hook already reviewed)
- No unrelated hooks in `shadow`/`hook` on first pass

#### Env change (shadow)

| Hook | Env |
|---|---|
| outline | `PROMPT_HOOK_MIGRATION_ARTICLE_OUTLINE_GENERATE=shadow` |
| faq | `PROMPT_HOOK_MIGRATION_ARTICLE_FAQ_GENERATE=shadow` |
| keyword | `PROMPT_HOOK_MIGRATION_KEYWORD_DISCOVERY_STRUCTURED=shadow` |
| title | `PROMPT_HOOK_MIGRATION_ARTICLE_TITLE_SUGGESTION=shadow` |
| meta | `PROMPT_HOOK_MIGRATION_ARTICLE_META_DESCRIPTION_SUGGESTION=shadow` |

#### Cache refresh

Run shared clear commands above.

#### Test scenarios

See Â§ Manual scenarios below.

#### Log review

```text
grep prompt_hook.shadow_parity storage/logs/laravel.log
grep prompt_hook.execution_audit storage/logs/laravel.log
php artisan seo:prompt-hooks:parity-report {hook} --version=0.1.0 --evaluate
```

Thresholds (config `promotion_thresholds.hooks`, overridable by env):

| Hook | Default samples |
|---|---|
| outline / faq / keyword | 20 |
| title / meta | 30 |

#### Promotion decision

Flip to `hook` **only** when gate `allowed=yes` and manual checklist pass.  
Fill `PROMPT_HOOK_PHASE5D1_ROLLOUT_REPORT.md`.

#### Rollback

```text
PROMPT_HOOK_MIGRATION_*=legacy
php artisan optimize:clear
php artisan config:clear
php artisan cache:clear
php artisan seo:prompt-hooks:clear-cache
php artisan queue:restart
```

Does **not** delete definitions or execution logs.  
Does **not** silent-fallback to legacy inside a request after hook provider already ran.

---

## Manual scenarios

### Outline

- Normal keyword input
- Long `heading_context`
- Non-default locale
- Missing optional fields
- Provider failover (connection/model)
- Empty / truncated response

### FAQ

- 0 valid FAQ
- Many FAQs
- JSON / markdown fence if structured path used
- Domain persistence once after AI
- Retry same request

### Keyword structured

- Clean JSON
- Fenced JSON
- Missing key / wrong type
- Duplicate keywords
- Other locale
- Provider refusal

### Title / meta (experimental@0.1.0)

- Too long / empty / multiline
- Wrong locale / special characters
- UI regenerate/retry
- Disable / not-configured prompt
- Redaction (no secrets in logs)
- Token/cost within band

Stabilization report fields: input/output contract, locale, length, empty, refusal, redaction, UI, disable, token/cost, sample count.  
**Propose** stable `1.0.0` only after real samples + manual review â€” never auto-bump version.

---

## Hook mode checks

When mode=`hook`:

- Provider called once
- Legacy AI path not executed
- Domain persistence order unchanged (caller after bridge)
- Typed failures map to existing UI contract
- PromptResult attach once via Action/domain service when caller requires â€” not Hook Engine

---

## Blockers (unchanged)

- Live shadow multi-worker: durable `PromptBudgetStore` required
- No real production samples until hosting shadow enabled
- Title/meta not stable until checklist + samples
- Multistep / image / video / WP out of scope
