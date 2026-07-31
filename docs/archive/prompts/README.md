> Status: Historical
> Not canonical
> Archived on: 2026-08-01
> Superseded by: ../../modules/PROMPTS_AND_AI.md
> Purpose: implementation history only
# Prompt Hooks

Prompt Hook lÃ  contract gáº¯n vÃ o Prompt: khai bÃ¡o input (sources + entity), model capability, settings, template locale vÃ  output. Runtime Ä‘á»c manifest; UI/template Ä‘á»c locale; mapping DB náº±m trong entity resolver.

| Hook key | TÃ i liá»‡u | Manifest | Version | Tráº¡ng thÃ¡i |
|---|---|---|---:|---|
| `article.title_suggestion` | [Gá»£i Ã½ tiÃªu Ä‘á»](article-title-suggestion.md) | `app/Addons/SeoContentAi/resources/prompt-hooks/article-title-suggestion.json` | 1 | **EXPERIMENTAL** (UI Active) â€” Spec engine chÆ°a khÃ³a; xem `docs/automation/prompt/` |
| `article.meta_description_suggestion` | [Gá»£i Ã½ tháº» mÃ´ táº£](article-meta-description-suggestion.md) | `app/Addons/SeoContentAi/resources/prompt-hooks/article-meta-description-suggestion.json` | 1 | **EXPERIMENTAL** (UI Active) â€” Spec engine chÆ°a khÃ³a |

## Quy táº¯c nguá»“n sá»± tháº­t

| Nguá»“n | Chá»‹u trÃ¡ch nhiá»‡m |
|---|---|
| Manifest JSON | Runtime contract (input sources, settings schema, output normalize, model) |
| `lang/{vi,en}/prompt_hooks.php` | Label, description, template text |
| `ArticlePromptHookEntityResolver` | Load article, authorize, normalize context |
| Markdown tá»«ng hook | Giáº£i thÃ­ch, Ä‘iá»ƒm gá»i, mapping, test, báº£o trÃ¬ |

- KhÃ´ng nhÃ©t vÄƒn báº£n giáº£i thÃ­ch dÃ i vÃ o JSON.
- Má»—i hook má»™t file Markdown; `documentation.path` trong manifest trá» tá»›i file Ä‘Ã³.
- Manifest khÃ´ng tham gia execution cá»§a field `documentation`.

## Code ná»n (Phase 1)

| ThÃ nh pháº§n | ÄÆ°á»ng dáº«n |
|---|---|
| Registry / loader | `app/Addons/SeoContentAi/PromptHooks/` |
| Entity article | `PromptHooks/Entities/ArticlePromptHookEntityResolver.php` |
| Execution | `PromptHooks/PromptHookExecutionService.php` |
| API | `POST /api/seo/prompt-hooks/{hookKey}/execute` â€” `Http/Controllers/PromptHookExecuteController.php` |
| Form Prompt | `PromptHooks/PromptHookFormSchema.php` + `Filament/Resources/PromptResource.php` |
| Settings slots | `SeoCreateArticleSettingsService` + `Filament/Pages/SeoSettingsWorkflows.php` |
