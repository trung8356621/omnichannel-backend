> Status: Historical
> Not canonical
> Archived on: 2026-08-01
> Superseded by: ../../modules/AUTOMATION.md
> Purpose: implementation history only
# Article Generate + Rewrite Hook Vertical Slice â€” Hosting Checklist

**Hooks:**
- `article.content.generate@0.1.0` â€” **Viáº¿t bÃ i viáº¿t** (sources: outline | existing_article | brief via `ArticleWritingExecutionService`)
- `article.content.improve@0.1.0` â€” **Improve** (Settings-visible; `ArticleImproveExecutionService` â€” khÃ´ng full generation)
- `article.content.rewrite@0.1.0` â€” **Legacy** â†’ adapter `existing_article` + generate (khÃ´ng bind Prompt má»›i)

**Template:** `legacy_prompt_content` (Prompt DB markdown = AI template)  
**Output:** `markdown`  
**Global migration:** `legacy`  
**Stable:** no Â· **Hosting tested:** no

Explicit Prompt binding runs Hook Runtime for that block only (one provider call). Domain save stays in Workflow / Business Action.

---

## Setup

```text
php artisan optimize:clear
php artisan config:clear
php artisan cache:clear
php artisan seo:prompt-hooks:clear-cache
php artisan seo:prompt-hooks:status
```

---

## Editor

1. Prompt create/edit â†’ dropdown shows:
   - **Viáº¿t bÃ i viáº¿t (Thá»­ nghiá»‡m)**
   - **Viáº¿t láº¡i bÃ i viáº¿t (Thá»­ nghiá»‡m)**
2. Select Generate â†’ save `hook_key=article.content.generate`, `hook_version=0.1.0`.
3. Markdown editor stays editable; note: Hook manages contract/runtime; Prompt content still sent to AI.
4. Select Rewrite â†’ save exact key/version.
5. Clear Hook â†’ null binding â†’ legacy PromptRunner.

---

## Generate cases

| Case | Expect |
|---|---|
| Vietnamese article | Markdown article, locale VI |
| English article | Markdown EN |
| outline + vocabulary as `{{input}}` | Combined planning â†’ full article |
| long input | Within max or typed InputTooLarge |
| missing site description | Still runs (optional) |
| custom tone / article_length / CTA | Variables resolve in compiled Prompt |
| provider failover | Typed failure; **no** second legacy AI call |
| regenerate / Test Prompt | ExplicitBinding once |
| connected Create/Update Article Action | Action receives Markdown from `out_main` / Total (AI) |

Verify:

- [ ] One provider call
- [ ] Markdown output (no HTML convert in Hook)
- [ ] Correct locale
- [ ] No Article save inside Hook Runtime
- [ ] No WP outbound from Hook
- [ ] No duplicate PromptResult attach from Engine

---

## Rewrite cases

| Case | Expect |
|---|---|
| rewrite whole article | `input` = existing markdown |
| with instruction | `rewrite_instruction` separate from body |
| preserve headings / change tone / shorten / expand | Instruction honored; facts preserved |
| missing article input | InvalidInput before provider |
| long article | Within max or InputTooLarge |
| provider failure | Typed error; no legacy fallback after cost |
| content conflict before save | `expected_updated_at` / hash still on **article.content.update** Action |

Verify:

- [ ] One provider call
- [ ] Original Article unchanged during Hook execution
- [ ] Save only in downstream Action
- [ ] Conflict protection still active

---

## Status

| Hook | defined | registered | editor_selectable | explicit_binding_executable | hosting_tested | stable |
|---|---|---|---|---|---|---|
| `article.content.generate@0.1.0` | yes | yes | yes | yes | **no** | **no** |
| `article.content.rewrite@0.1.0` | yes | yes | yes | yes | **no** | **no** |
