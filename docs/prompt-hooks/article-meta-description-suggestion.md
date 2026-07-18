---
hook_key: article.meta_description_suggestion
version: 1
status: active
manifest: app/Addons/SeoContentAi/resources/prompt-hooks/article-meta-description-suggestion.json
---

# Prompt Hook: Gợi ý thẻ mô tả SEO

## Định danh

| Thuộc tính | Giá trị |
|---|---|
| Hook key | `article.meta_description_suggestion` |
| Version | `1` |
| Capability | `text` |
| Output | Plain text |
| Manifest | `app/Addons/SeoContentAi/resources/prompt-hooks/article-meta-description-suggestion.json` |
| Locale | `seo-content-ai::prompt_hooks.article_meta_description_suggestion.*` |

## Mục đích

Tạo hoặc cải thiện thẻ mô tả SEO từ tiêu đề và mô tả hiện tại.

Chỉ đề xuất local. **Không** tự lưu SEO (`saveSeoMeta`), không publish, không sync WP.

## Điểm gọi

| Trạng thái | Điểm |
|---|---|
| **Done** | Form Prompt + Hook settings |
| **Done** | Settings → Workflows — slot `article_meta_description_suggestion_prompt_id` |
| **Done** | `PromptHookExecutionService` |
| **Done** | HTTP execute + `executePromptHookViaApi` |
| **Done** | Editor bootstrap `prompt_hooks.meta_description_suggestion.configured` |
| **Done** | Nút AI trong modal SEO — `ArticleGoogleSerpPreview.jsx` (cạnh «Thẻ mô tả») |

Lưu SEO hiện tại (không phải hook): `POST /api/seo/articles/{id}/seo-meta` → `ArticleEditorSyncController::saveSeoMeta` → `ArticleEditorSeoMetaService`. Hook **không** gọi endpoint này.

## Entity context

Entity: `article`  
Resolver: `ArticlePromptHookEntityResolver`  
Normalize `description` qua `SeoAnalyzerService::resolveMetaDescriptionForArticle()`:

1. Duyệt `article_meta` theo thứ tự key: `meta_description`, `seo_meta_description`, `_yoast_wpseo_metadesc`, `rank_math_description` — lấy giá trị trim đầu tiên khác rỗng
2. Fallback: cột `articles.excerpt`
3. Vẫn rỗng → `null` trong context

**Không có** cột `articles.description`. Field logic `article.description` trong normalized context = gộp trên.

Persist SEO editor ghi cả `seo_meta_description` và `meta_description` (`ArticleEditorBundleApplyService::persistSeoMetaFields`).

Normalized:

```php
[
    'article' => [
        'id' => …,
        'title' => …,          // articles.title
        'description' => …,    // meta SEO ∪ excerpt | null
        'focus_keyword' => …,
        'keyword' => …,
    ],
]
```

Mapping nghiệp vụ:

```text
old_description  ←  article.description (normalized)
                 ←  article_meta (seo_meta_description / meta_description / …) ∪ articles.excerpt
```

Manifest chỉ đọc `article.description` — không hard-code tên meta_key.

## Input contract

### `article_id`

Request-level, bắt buộc, không expose prompt. Auth + load như title hook.

### `title`

- String, required sau resolve
- Sources: runtime `title` → entity `article.title`
- Normalize: `trim`, `empty_to_null`
- Runtime ưu tiên (title editor có thể chưa save)

### `old_description`

- `string|null`, optional
- Sources: runtime `old_description` → entity `article.description` → constant `null`
- Chuỗi rỗng UI → `empty_to_null`

## Request mẫu

```json
{
  "article_id": 123,
  "input": {
    "title": "Mách bạn cách giữ form balo đúng cách",
    "old_description": "Mô tả SEO hiện tại"
  }
}
```

Route: `POST /api/seo/prompt-hooks/article.meta_description_suggestion/execute`  
Không gọi `saveSeoMeta` / WP sync.
## Prompt payload

Expose: `title`, `old_description` + settings `min_length` / `max_length` + `[HOOK_INPUT]` qua `{{input}}`.

Không expose: `article_id`, `site_id`, `user_id`, credentials.

Assemble giống title hook (`PromptHookPromptAssembler`).

## Prompt resolution

| Mục | Giá trị thật |
|---|---|
| Settings key | `KEY_ARTICLE_META_DESCRIPTION_SUGGESTION_PROMPT_ID` = `article_meta_description_suggestion_prompt_id` |
| Getter | `getArticleMetaDescriptionSuggestionPromptId()` |
| Options | `activePromptOptionsForHook('article.meta_description_suggestion')` |
| Match | `prompt.hook_key === article.meta_description_suggestion` |

Chưa cấu hình → `HOOK_PROMPT_NOT_CONFIGURED`. Không hard-code ID.

## Model requirement

`capability: text`, `structured_output: false`. Routing qua `PromptRunnerService` / `AiModelRouterService`.

## Hook settings

| Setting | Kiểu | Default | Min–Max | Ý nghĩa |
|---|---|---:|---|---|
| `max_length` | integer | 160 | 100–200 | Độ dài tối đa mong muốn |
| `min_length` | integer | 120 | 50–180 | Độ dài tối thiểu mong muốn |

## Template

- Key: `prompt_hooks.article_meta_description_suggestion.template`
- Position: `after_prompt`
- Ép: một đoạn meta description; không prefix/markdown; dựa `title`; dùng `old_description` làm ngữ cảnh; không bịa fact; tôn trọng min/max length

## Output contract

Plain text một đoạn. Normalize giống title hook (`trim`, fence, quotes, first non-empty line, `not_empty`).

## UI behavior

| Trạng thái | |
|---|---|
| **Done** | Cập nhật `draftDescription` only; counter cập nhật; **không** gọi `saveSeoMetaViaApi`; không đóng modal |
| **Done** | Stale: user sửa textarea lúc request → không ghi đè |
| **Done** | Lỗi: giữ mô tả cũ |

## Errors (đã implement)

Cùng bộ enum `PromptHookErrorCode` như title hook (`HOOK_NOT_FOUND`, `HOOK_PROMPT_NOT_CONFIGURED`, `HOOK_PROMPT_MISMATCH`, `HOOK_INPUT_INVALID`, `HOOK_ARTICLE_NOT_FOUND`, `HOOK_ARTICLE_FORBIDDEN`, `HOOK_MODEL_UNSUPPORTED`, `HOOK_EXECUTION_FAILED`, `HOOK_OUTPUT_INVALID`, `HOOK_MANIFEST_INVALID`).

## Tests (đã có)

| File | Liên quan meta hook |
|---|---|
| `PromptHookFoundationTest` | title/old_description resolve, override, empty title fail, fallback `article.description` |
| `PromptHookAssemblerVariablesTest` | không leak `article_id` |
| `PromptHookFormSchemaTest` | normalize save |
| `PromptHookDocumentationTest` | doc link + locale |

**Chưa có:** HTTP feature test, assert không gọi `saveSeoMeta`, fallback chi tiết meta_key trong unit entity (đang cover qua InputResolver + context giả lập; entity+analyzer DB test Planned).

## Files liên quan

- Manifest / locale / PromptHooks (như title hook, file meta-description)
- Meta resolve: `SeoAnalyzerService::resolveMetaDescriptionForArticle`
- Persist SEO (không phải hook): `ArticleEditorSeoMetaService`, `ArticleEditorBundleApplyService`
- Modal UI: `resources/js/components/ArticleGoogleSerpPreview.jsx`
- API save SEO: `ArticleEditorSyncController::saveSeoMeta`
- Settings slot + Prompt form: như README

## Change history

| Version | Ghi chú |
|---|---|
| 1 | Phase 1 — backend + form + settings; API/UI modal Planned |
