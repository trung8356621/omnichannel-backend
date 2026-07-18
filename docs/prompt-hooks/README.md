# Prompt Hooks

Prompt Hook là contract gắn vào Prompt: khai báo input (sources + entity), model capability, settings, template locale và output. Runtime đọc manifest; UI/template đọc locale; mapping DB nằm trong entity resolver.

| Hook key | Tài liệu | Manifest | Version | Trạng thái |
|---|---|---|---:|---|
| `article.title_suggestion` | [Gợi ý tiêu đề](article-title-suggestion.md) | `app/Addons/SeoContentAi/resources/prompt-hooks/article-title-suggestion.json` | 1 | Active — backend + form + API + **UI title** |
| `article.meta_description_suggestion` | [Gợi ý thẻ mô tả](article-meta-description-suggestion.md) | `app/Addons/SeoContentAi/resources/prompt-hooks/article-meta-description-suggestion.json` | 1 | Active — backend + form + API + **UI modal SEO** |

## Quy tắc nguồn sự thật

| Nguồn | Chịu trách nhiệm |
|---|---|
| Manifest JSON | Runtime contract (input sources, settings schema, output normalize, model) |
| `lang/{vi,en}/prompt_hooks.php` | Label, description, template text |
| `ArticlePromptHookEntityResolver` | Load article, authorize, normalize context |
| Markdown từng hook | Giải thích, điểm gọi, mapping, test, bảo trì |

- Không nhét văn bản giải thích dài vào JSON.
- Mỗi hook một file Markdown; `documentation.path` trong manifest trỏ tới file đó.
- Manifest không tham gia execution của field `documentation`.

## Code nền (Phase 1)

| Thành phần | Đường dẫn |
|---|---|
| Registry / loader | `app/Addons/SeoContentAi/PromptHooks/` |
| Entity article | `PromptHooks/Entities/ArticlePromptHookEntityResolver.php` |
| Execution | `PromptHooks/PromptHookExecutionService.php` |
| API | `POST /api/seo/prompt-hooks/{hookKey}/execute` — `Http/Controllers/PromptHookExecuteController.php` |
| Form Prompt | `PromptHooks/PromptHookFormSchema.php` + `Filament/Resources/PromptResource.php` |
| Settings slots | `SeoCreateArticleSettingsService` + `Filament/Pages/SeoSettingsWorkflows.php` |
