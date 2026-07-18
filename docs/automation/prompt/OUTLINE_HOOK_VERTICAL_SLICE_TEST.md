# Outline Hook Vertical Slice — Hosting Test Checklist

**Hook:** `article.outline.generate@0.1.0`  
**Label:** Tạo dàn ý bài viết  
**Output:** `markdown_sections` (outline + vocabulary, one provider call)  
**Template:** `legacy_prompt_content` (Prompt markdown remains AI template)  
**Status:** editor selectable + explicit-binding executable — **hosting tested = no** · **stable = no**

Global migration mode remains `legacy`. Explicit binding on a Prompt record runs Hook Runtime for that block only.

---

## Setup

```text
php artisan optimize:clear
php artisan config:clear
php artisan cache:clear
php artisan seo:prompt-hooks:clear-cache
php artisan seo:prompt-hooks:status
```

Run migration for SemVer `hook_version` (if not yet):

```text
php artisan migrate --path=app/Addons/SeoContentAi/database/migrations/2026_07_18_140000_change_prompt_hook_version_to_string.php
```

(Use project hosting migrate process / SEO connection bootstrap as usual.)

---

## Editor

1. Open Prompt create/edit.
2. Dropdown Prompt Hook shows **Tạo dàn ý bài viết (Thử nghiệm)**.
3. Select it → version `0.1.0`; read-only section contract shows Task 1/2 markers + ports.
4. Markdown editor **stays editable** (legacy template source). Note: Hook manages I/O contract; Prompt content still sent to AI.
5. Save. Confirm DB: `hook_key=article.outline.generate`, `hook_version=0.1.0`.
6. Option **Không sử dụng Hook** clears binding → legacy path (same Prompt content).

---

## Cases

| Case | Expect |
|---|---|
| Valid AI output with both markers | Task 1 = outline only; Task 2 = vocabulary only; Total = full raw (markers OK) |
| Missing / mismatched markers | Typed section failure + correlation_id; no secrets / full dump in UI |
| Provider failure | Typed error + correlation; **no** second provider call / no legacy fallback after cost |
| Regenerate / Test Prompt | ExplicitBinding; one provider call; `compilePrompt` from DB content |
| Workflow next block | `out_task_1*` / `out_task_2*` / `out_main` as today |
| Remove Hook | Legacy PromptRunner once again; Prompt body unchanged |

---

## Verify

- [ ] One provider call when Hook bound
- [ ] Correct provider/model when policy allows
- [ ] Outline / vocabulary / total ports correct
- [ ] Markers excluded from section ports; present in total
- [ ] Next node receives expected port values
- [ ] No WP outbound from Hook Runtime
- [ ] No Article/Task domain write from Hook Engine
- [ ] Legacy works after clearing binding

---

## Status vocabulary

| Flag | Value |
|---|---|
| defined | yes |
| registered | yes |
| generic caller wired | yes (Heading AI bridge still legacy-default) |
| editor selectable | yes |
| explicit-binding executable | yes |
| markdown_sections | yes |
| hosting tested | **no** until checklist filled |
| stable | **no** |
