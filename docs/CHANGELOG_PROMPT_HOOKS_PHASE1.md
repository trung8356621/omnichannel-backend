# CHANGELOG — Prompt Hooks Phase 1

**Date:** 2026-07-18  
**Scope:** `app/Addons/SeoContentAi/` + `docs/prompt-hooks/` + MAP docs ngắn

---

## Mục tiêu Phase 1

Nền tảng **Prompt Hook** (contract gắn Prompt): registry/manifest, entity input resolve, form chọn Hook, settings slot, API execute generic, 2 nút AI trên Article Editor.

**Không** refactor Prompt/Workflow cũ. Prompt `hook_key = null` giữ behavior cũ.

---

## Migration

| File | Thay đổi |
|---|---|
| `database/migrations/2026_07_18_100000_add_hook_columns_to_prompts_table.php` | `omi_seo_ai.prompts`: `hook_key` (nullable string, index), `hook_version` (nullable unsigned int), `hook_settings` (nullable JSON) |

Model `Prompt` / `SeoPrompt`: cast `hook_settings` → array, `hook_version` → integer.

---

## Registry & manifest format

- Loader: `PromptHooks/PromptHookManifestLoader.php` — fail-fast `local`/`testing`; production log + skip file lỗi
- Registry: `PromptHooks/PromptHookRegistry.php` — singleton + cache marker; `clearCache()`
- Definition: `PromptHooks/Data/PromptHookDefinition.php` — optional `documentation.path` (không tham gia execution)

Manifest schema lõi (Phase 1):

```text
schema_version, key, version,
label_key, description_key,
documentation.path (optional),
model.capability / structured_output,
input.fields + sources + prompt_payload,
settings{},
template { template_key, position, nullable },
output { format, normalize[], validation }
```

Đường dẫn JSON: `app/Addons/SeoContentAi/resources/prompt-hooks/*.json`  
Locale: `lang/vi|en/prompt_hooks.php` (`seo-content-ai::prompt_hooks.*`)

### Final prompt assemble (một chỗ)

`PromptHookPromptAssembler`:

1. Base = `PromptRunnerService::compilePrompt` (markdown user + `{{vars}}`)
2. Hook template locale theo `template.position` (`after_prompt` mặc định)
3. Variables: field expose + settings + `[HOOK_INPUT]…[/HOOK_INPUT]` → `{{input}}` / `{{hook_input}}`
4. Chạy `runWithCompiledPrompt` — **không** đổi model routing

---

## Hai hook test

| Hook key | Manifest | Docs |
|---|---|---|
| `article.title_suggestion` | `article-title-suggestion.json` | `docs/prompt-hooks/article-title-suggestion.md` |
| `article.meta_description_suggestion` | `article-meta-description-suggestion.json` | `docs/prompt-hooks/article-meta-description-suggestion.md` |

Index: `docs/prompt-hooks/README.md`

### Entity resolve

`ArticlePromptHookEntityResolver` + `SeoAnalyzerService`:

- `article.title` ← `articles.title`
- `article.focus_keyword` / `keyword` ← `seo_focus_keyword` meta → Keyword `MainArticleId`
- `article.description` ← meta (`meta_description` / `seo_meta_description` / yoast / rank_math) ∪ `articles.excerpt`  
  (**không** có cột `articles.description`)

Runtime override ưu tiên hơn entity. Validate **sau** resolve. `article_id` không vào prompt payload.

Settings out-of-range: **clamp** min/max (không throw) — tránh lỗi khi đổi Hook chung key `max_length`.

---

## API execution

`POST /api/seo/prompt-hooks/{hookKey}/execute`  
Controller: `Http/Controllers/PromptHookExecuteController.php`  
Name: `seo.prompt-hooks.execute`  
Auth: session + `CheckMainRole` + `SetDynamicSeoDatabase` + CSRF; `canMutateInSeoPanel()`

Body: `{ article_id, input? }` — **không** nhận `prompt_id` client.

Success: `{ success, data: { hook, output: { format, raw, value } } }`  
Error: `{ success: false, error, message }` + HTTP map `PromptHookHttpStatus`

**Không** save article / SEO meta / WordPress sync.

Prompt resolve từ:

- `article_title_suggestion_prompt_id`
- `article_meta_description_suggestion_prompt_id`  
  (`SeoCreateArticleSettingsService` / WpOption `seo_create_article_task`)

---

## UI title / meta description

| UI | File | Hành vi |
|---|---|---|
| Nút AI title | `resources/js/utils/articleTitlePromptHook.js` | Cạnh `.wp-title-input`; set Livewire `articleTitle`; stale guard |
| Nút AI meta | `ArticleGoogleSerpPreview.jsx` | Cạnh «Thẻ mô tả»; chỉ `draftDescription`; không Lưu SEO |
| Client API | `executePromptHookViaApi` trong `articleEditorApi.js` | |
| Bootstrap | `EditArticle::getEditorSettingsPayload()` → `prompt_hooks.*.configured` | |

---

## Form Prompt + Settings

- `PromptHookFormSchema` trên create/edit Prompt — Select Hook từ registry, contract read-only, settings động
- List cột `hook_key`
- `SeoSettingsWorkflows`: section Prompt Hooks — options chỉ Prompt đúng `hook_key`

---

## Backward compatibility

| Trường hợp | Behavior |
|---|---|
| Prompt không Hook | UI/execution cũ không đổi |
| Bỏ Hook khi save | `hook_key` / `hook_version` / `hook_settings` = null |
| Đổi Hook A→B | Normalize settings theo manifest B (clamp + drop key rác) |
| Workflow / Content Project / WP sync | Không đụng Phase 1 |

---

## Files mới (tóm tắt)

```text
PromptHooks/          # Registry, loader, input/settings/template/output, execution, form schema, entity
Http/Controllers/PromptHookExecuteController.php
Http/Requests/PromptHookExecuteRequest.php
resources/prompt-hooks/*.json
lang/vi|en/prompt_hooks.php
resources/js/utils/articleTitlePromptHook.js
docs/prompt-hooks/*
tests/Unit/PromptHook*.php
migration 2026_07_18_100000_add_hook_columns_to_prompts_table.php
```

## Files sửa (tóm tắt)

```text
Models/Prompt.php
SeoContentAiServiceProvider.php (singleton register)
SeoCreateArticleSettingsService.php (2 KEY + getters)
SeoPromptSettingsOptionsService.php (activePromptOptionsForHook)
SeoSettingsWorkflows.php
PromptResource.php + CreatePrompt + EditPrompt
SeoPanelProvider.php (route)
EditArticle.php (prompt_hooks payload)
article-editor.jsx, ArticleGoogleSerpPreview.jsx, SeoArticleEditor.jsx
articleEditorApi.js, article-editor.css, i18n.js
lang/vi|en/filament.php
docs/MAP_SEO_SETTINGS.md, MAP_SEO_EDITOR.md, MAP_SEO_FRONTEND.md, SUPER_MAP_INDEX.md
```

---

## Tests đã viết (chạy trên môi trường remote/CI)

| Test | Nội dung |
|---|---|
| `PromptHookFoundationTest` | Manifest load, input sources, settings clamp, output normalize |
| `PromptHookAssemblerVariablesTest` | Không leak `article_id` |
| `PromptHookFormSchemaTest` | Normalize save / reject image tool |
| `PromptHookDocumentationTest` | `documentation.path` + front matter + locale keys |
| `PromptHookHttpStatusTest` | Map error → HTTP |
| `PromptHookExecuteControllerTest` | Response shape + error codes |

Local agent **không** chạy `php artisan test` / `npm run build` (remote-first rule).

---

## Cố ý chưa làm (Phase 1+)

- Refactor Workflow Prompt block sang Hook
- Hard-code / đổi AI model routing
- Auto-save / publish / WP sync sau hook
- Content Project
- Feature test HTTP full ACL trên DB thật (unit controller mock đủ Phase 1)
- Entity+analyzer integration test DB

---

## Manual verification

```text
php artisan migrate   # connection omi_seo_ai đã bootstrap

php artisan test --filter=PromptHook

# Frontend bundle article-editor
npm run build

# Smoke UI
# 1. Tạo 2 Prompt gắn đúng Hook + cấu hình Settings → Workflows
# 2. Edit article: nút AI title + modal SEO → Thẻ mô tả
# 3. Confirm không auto-save / không sync WP
```
