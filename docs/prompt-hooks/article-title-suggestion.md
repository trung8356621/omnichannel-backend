---
hook_key: article.title_suggestion
version: 1
status: active
manifest: app/Addons/SeoContentAi/resources/prompt-hooks/article-title-suggestion.json
---

# Prompt Hook: Gợi ý tiêu đề bài viết

## Định danh

| Thuộc tính | Giá trị |
|---|---|
| Hook key | `article.title_suggestion` |
| Version | `1` |
| Capability | `text` |
| Output | Plain text |
| Manifest | `app/Addons/SeoContentAi/resources/prompt-hooks/article-title-suggestion.json` |
| Locale | `seo-content-ai::prompt_hooks.article_title_suggestion.*` (`lang/vi|en/prompt_hooks.php`) |

## Mục đích

Tạo hoặc cải thiện tiêu đề bài viết từ từ khóa chính và tiêu đề hiện tại.

Hook chỉ tạo đề xuất. **Không** tự lưu bài, publish, hay sync WordPress (ExecutionService cũng không gọi các flow đó).

## Điểm gọi

| Trạng thái | Điểm |
|---|---|
| **Done** | Form Prompt create/edit — chọn Hook (`PromptHookFormSchema`) |
| **Done** | Settings → Workflows — slot `article_title_suggestion_prompt_id` |
| **Done** | Service: `PromptHookExecutionService::execute()` / `resolveOnly()` |
| **Done** | HTTP `POST /api/seo/prompt-hooks/{hookKey}/execute` → `PromptHookExecuteController` |
| **Done** | Client helper `executePromptHookViaApi` (`articleEditorApi.js`) |
| **Done** | Editor bootstrap `prompt_hooks.title_suggestion.configured` |
| **Done** | Nút AI cạnh title — `articleTitlePromptHook.js` + `.wp-postbox-title-toolbar` |

Title hiện tại nằm **ngoài** React hub (`SeoArticleEditor`): Blade Livewire `EditArticle::$articleTitle`.

## Entity context

Entity key: `article`  
Resolver: `App\Addons\SeoContentAi\PromptHooks\Entities\ArticlePromptHookEntityResolver`

Hành vi:

1. `SeoArticle::query()->find($articleId)` trên connection `omi_seo_ai`
2. `SeoAccessControl::canAccessArticle($article)`
3. Eager-load `articleMetas`, `site`
4. Normalize context (không expose raw meta rows)

Normalized context thực tế:

```php
[
    'article' => [
        'id' => (int) $article->id,
        'title' => trim($article->title) ?: null,
        'focus_keyword' => $resolvedKeyword, // string|null
        'keyword' => $resolvedKeyword,       // alias cùng giá trị
        'description' => $resolvedDescription, // dùng hook meta; title hook không cần
    ],
]
```

### Cách resolve `keyword` / `focus_keyword`

Delegate `SeoAnalyzerService::resolveFocusKeywordForArticle()`:

1. `article_meta.meta_key = seo_focus_keyword` (normalize phrase)
2. Fallback: `Keyword` gắn article qua `KeywordMetaKey::MainArticleId`
3. Rỗng → `null` trong context (InputResolver bỏ qua entity null → fail nếu required)

## Input contract

### `article_id` (request-level, không phải field manifest)

- Integer, bắt buộc khi gọi `execute` / `resolveOnly`
- Không nằm trong `prompt_payload`
- Không tin site/tenant do client giả mạo — auth qua `SeoAccessControl`

### `keyword`

- String, **required sau resolve**
- Sources (manifest):
  1. runtime `input.keyword`
  2. entity `article.focus_keyword`
  3. entity `article.keyword`
- Normalize: `trim`, `empty_to_null`
- Thiếu sau resolve → `HOOK_INPUT_INVALID`

### `old_title`

- `string|null`, không bắt buộc
- Sources:
  1. runtime `input.old_title`
  2. entity `article.title`
  3. constant `null`
- Mapping nghiệp vụ: `old_title` ↔ `articles.title`
- Runtime ưu tiên vì UI title có thể dirty chưa save

## Request mẫu

```json
{
  "article_id": 123,
  "input": {
    "keyword": "cách giữ form balo",
    "old_title": "Mách bạn cách giữ form balo"
  }
}
```

Route: `POST /api/seo/prompt-hooks/article.title_suggestion/execute`  
Name: `seo.prompt-hooks.execute`  
Middleware: session auth + `CheckMainRole` + `SetDynamicSeoDatabase` (+ CSRF).  
Không nhận `prompt_id` từ client.

## Prompt payload

Expose tới PromptRunner (`PromptHookInputResolver::exposeToPrompt` + `PromptHookPromptAssembler`):

- `keyword`, `old_title` (từng biến `{{keyword}}`, `{{old_title}}`)
- Block serialize `[HOOK_INPUT]…[/HOOK_INPUT]` gán vào `{{input}}` và `{{hook_input}}`
- Settings: `{{max_length}}`, `{{preserve_meaning}}`

**Không** gửi: `article_id`, `user_id`, `site_id`, credentials.

Thứ tự assemble (`PromptHookPromptAssembler`):

1. Base = `PromptRunnerService::compilePrompt($prompt, $variables)`
2. Template locale append (`after_prompt`)
3. Chạy `runWithCompiledPrompt`

## Prompt resolution

| Mục | Giá trị thật |
|---|---|
| Settings key | `SeoCreateArticleSettingsService::KEY_ARTICLE_TITLE_SUGGESTION_PROMPT_ID` = `article_title_suggestion_prompt_id` |
| Storage | `WpOption` option `seo_create_article_task` |
| Getter | `getArticleTitleSuggestionPromptId()` |
| Resolve | `PromptHookExecutionService::resolveConfiguredPrompt()` → `SeoPrompt` active |
| Match | `prompt.hook_key === article.title_suggestion` |
| Options UI | `SeoPromptSettingsOptionsService::activePromptOptionsForHook('article.title_suggestion')` |

Không hard-code Prompt ID. Chưa cấu hình → `HOOK_PROMPT_NOT_CONFIGURED`. Sai hook → `HOOK_PROMPT_MISMATCH`.

## Model requirement

- Manifest: `capability: text`, `structured_output: false`
- Prompt `tools` phải **không** image pipeline (`ImageToolType::isImagePipeline()`); form ép/reject
- Model runtime: `PromptRunnerService` + `AiModelRouterService` (không hard-code Gemini)

## Hook settings

| Setting | Kiểu | Default | Min–Max | Locale label |
|---|---|---:|---|---|
| `max_length` | integer | 65 | 30–100 | `prompt_hooks.article_title_suggestion.settings.max_length` |
| `preserve_meaning` | boolean | true | — | `…settings.preserve_meaning` |

Lưu trên Prompt: cột `hook_settings` (JSON). Đổi Hook → normalize bỏ key rác (`PromptHookSettingsResolver`).

## Template

- Locale key: `prompt_hooks.article_title_suggestion.template`
- Position: `after_prompt`
- `nullable: false`
- Ép: một tiêu đề plain text; không giải thích / prefix / markdown / quotes; ưu tiên keyword; tôn trọng `max_length` / `preserve_meaning`

Không copy full template vào đây — sửa tại `lang/vi|en/prompt_hooks.php`.

## Output contract

Plain text một dòng. Normalize (`PromptHookOutputNormalizer`):

1. `trim`
2. `strip_markdown_fence`
3. `strip_wrapping_quotes`
4. `first_non_empty_line`
5. `validation.not_empty` → `HOOK_OUTPUT_INVALID` nếu rỗng

## UI behavior

| Trạng thái | |
|---|---|
| **Done** | Nút Sparkles cạnh `.wp-title-input`; loading trên nút; disable khi đang chạy / thiếu keyword / chưa cấu hình Prompt |
| **Done** | Thành công: set Livewire `articleTitle` + input; **không** auto-save / WP sync |
| **Done** | Stale: nếu user đổi title trong lúc request → không ghi đè, toast warning kèm gợi ý |
| **Done** | Lỗi: giữ title cũ, toast danger |

## Errors (đã implement)

| Code | Khi nào |
|---|---|
| `HOOK_NOT_FOUND` | Hook key không có trong registry |
| `HOOK_PROMPT_NOT_CONFIGURED` | Chưa gán / prompt inactive |
| `HOOK_PROMPT_MISMATCH` | `prompt.hook_key` ≠ hook |
| `HOOK_INPUT_INVALID` | Field lạ, required thiếu sau resolve, settings out of range, `article_id` ≤ 0 |
| `HOOK_ARTICLE_NOT_FOUND` | Article không tồn tại |
| `HOOK_ARTICLE_FORBIDDEN` | Không có quyền article |
| `HOOK_MODEL_UNSUPPORTED` | Prompt image pipeline với capability text |
| `HOOK_EXECUTION_FAILED` | PromptRunner lỗi |
| `HOOK_OUTPUT_INVALID` | Output rỗng sau normalize |
| `HOOK_MANIFEST_INVALID` | Manifest lỗi (loader) |

## Tests (đã có)

| File | Nội dung liên quan |
|---|---|
| `tests/Unit/PromptHookFoundationTest.php` | Load 2 manifests; keyword/old_title resolve + override; empty keyword fail; unknown field; settings; output strip; no `article_id` in exposed payload |
| `tests/Unit/PromptHookAssemblerVariablesTest.php` | `[HOOK_INPUT]` không chứa `article_id` |
| `tests/Unit/PromptHookFormSchemaTest.php` | Clear hook / version+settings / reject image tool |
| `PromptHookExecuteControllerTest` | Success shape; map `HOOK_PROMPT_NOT_CONFIGURED` / forbidden |
| `PromptHookHttpStatusTest` | HTTP status theo error code |

## Files liên quan

- Manifest: `app/Addons/SeoContentAi/resources/prompt-hooks/article-title-suggestion.json`
- Locale: `app/Addons/SeoContentAi/lang/vi|en/prompt_hooks.php`
- Entity: `PromptHooks/Entities/ArticlePromptHookEntityResolver.php`
- Analyzer keyword: `Services/SeoAnalyzerService.php` (`resolveFocusKeywordForArticle`)
- Registry / execution / assembler / form schema: `PromptHooks/*`
- Settings: `Services/SeoCreateArticleSettingsService.php`, `Filament/Pages/SeoSettingsWorkflows.php`
- Prompt form: `Filament/Resources/PromptResource.php`
- Title UI (Planned nút AI): `resources/views/.../edit-article.blade.php`, `EditArticle.php`
- Tests: `tests/Unit/PromptHook*.php`

## Change history

| Version | Ghi chú |
|---|---|
| 1 | Phase 1 — tạo Hook + form + settings slot; API/UI editor Planned |
