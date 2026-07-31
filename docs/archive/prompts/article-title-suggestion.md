> Status: Historical
> Not canonical
> Archived on: 2026-08-01
> Superseded by: ../../modules/PROMPTS_AND_AI.md
> Purpose: implementation history only
---
hook_key: article.title_suggestion
version: 1
status: active
manifest: app/Addons/SeoContentAi/resources/prompt-hooks/article-title-suggestion.json
---

# Prompt Hook: Gá»£i Ã½ tiÃªu Ä‘á» bÃ i viáº¿t

## Äá»‹nh danh

| Thuá»™c tÃ­nh | GiÃ¡ trá»‹ |
|---|---|
| Hook key | `article.title_suggestion` |
| Version | `1` |
| Capability | `text` |
| Output | Plain text |
| Manifest | `app/Addons/SeoContentAi/resources/prompt-hooks/article-title-suggestion.json` |
| Locale | `seo-content-ai::prompt_hooks.article_title_suggestion.*` (`lang/vi|en/prompt_hooks.php`) |

## Má»¥c Ä‘Ã­ch

Táº¡o hoáº·c cáº£i thiá»‡n tiÃªu Ä‘á» bÃ i viáº¿t tá»« tá»« khÃ³a chÃ­nh vÃ  tiÃªu Ä‘á» hiá»‡n táº¡i.

Hook chá»‰ táº¡o Ä‘á» xuáº¥t. **KhÃ´ng** tá»± lÆ°u bÃ i, publish, hay sync WordPress (ExecutionService cÅ©ng khÃ´ng gá»i cÃ¡c flow Ä‘Ã³).

## Äiá»ƒm gá»i

| Tráº¡ng thÃ¡i | Äiá»ƒm |
|---|---|
| **Done** | Form Prompt create/edit â€” chá»n Hook (`PromptHookFormSchema`) |
| **Done** | Settings â†’ Workflows â€” slot `article_title_suggestion_prompt_id` |
| **Done** | Service: `PromptHookExecutionService::execute()` / `resolveOnly()` |
| **Done** | HTTP `POST /api/seo/prompt-hooks/{hookKey}/execute` â†’ `PromptHookExecuteController` |
| **Done** | Client helper `executePromptHookViaApi` (`articleEditorApi.js`) |
| **Done** | Editor bootstrap `prompt_hooks.title_suggestion.configured` |
| **Done** | NÃºt AI cáº¡nh title â€” `articleTitlePromptHook.js` + `.wp-postbox-title-toolbar` |

Title hiá»‡n táº¡i náº±m **ngoÃ i** React hub (`SeoArticleEditor`): Blade Livewire `EditArticle::$articleTitle`.

## Entity context

Entity key: `article`  
Resolver: `App\Addons\SeoContentAi\PromptHooks\Entities\ArticlePromptHookEntityResolver`

HÃ nh vi:

1. `SeoArticle::query()->find($articleId)` trÃªn connection `omi_seo_ai`
2. `SeoAccessControl::canAccessArticle($article)`
3. Eager-load `articleMetas`, `site`
4. Normalize context (khÃ´ng expose raw meta rows)

Normalized context thá»±c táº¿:

```php
[
    'article' => [
        'id' => (int) $article->id,
        'title' => trim($article->title) ?: null,
        'focus_keyword' => $resolvedKeyword, // string|null
        'keyword' => $resolvedKeyword,       // alias cÃ¹ng giÃ¡ trá»‹
        'description' => $resolvedDescription, // dÃ¹ng hook meta; title hook khÃ´ng cáº§n
    ],
]
```

### CÃ¡ch resolve `keyword` / `focus_keyword`

Delegate `SeoAnalyzerService::resolveFocusKeywordForArticle()`:

1. `article_meta.meta_key = seo_focus_keyword` (normalize phrase)
2. Fallback: `Keyword` gáº¯n article qua `KeywordMetaKey::MainArticleId`
3. Rá»—ng â†’ `null` trong context (InputResolver bá» qua entity null â†’ fail náº¿u required)

## Input contract

### `article_id` (request-level, khÃ´ng pháº£i field manifest)

- Integer, báº¯t buá»™c khi gá»i `execute` / `resolveOnly`
- KhÃ´ng náº±m trong `prompt_payload`
- KhÃ´ng tin site/tenant do client giáº£ máº¡o â€” auth qua `SeoAccessControl`

### `keyword`

- String, **required sau resolve**
- Sources (manifest):
  1. runtime `input.keyword`
  2. entity `article.focus_keyword`
  3. entity `article.keyword`
- Normalize: `trim`, `empty_to_null`
- Thiáº¿u sau resolve â†’ `HOOK_INPUT_INVALID`

### `old_title`

- `string|null`, khÃ´ng báº¯t buá»™c
- Sources:
  1. runtime `input.old_title`
  2. entity `article.title`
  3. constant `null`
- Mapping nghiá»‡p vá»¥: `old_title` â†” `articles.title`
- Runtime Æ°u tiÃªn vÃ¬ UI title cÃ³ thá»ƒ dirty chÆ°a save

## Request máº«u

```json
{
  "article_id": 123,
  "input": {
    "keyword": "cÃ¡ch giá»¯ form balo",
    "old_title": "MÃ¡ch báº¡n cÃ¡ch giá»¯ form balo"
  }
}
```

Route: `POST /api/seo/prompt-hooks/article.title_suggestion/execute`  
Name: `seo.prompt-hooks.execute`  
Middleware: session auth + `CheckMainRole` + `SetDynamicSeoDatabase` (+ CSRF).  
KhÃ´ng nháº­n `prompt_id` tá»« client.

## Prompt payload

Expose tá»›i PromptRunner (`PromptHookInputResolver::exposeToPrompt` + `PromptHookPromptAssembler`):

- `keyword`, `old_title` (tá»«ng biáº¿n `{{keyword}}`, `{{old_title}}`)
- Block serialize `[HOOK_INPUT]â€¦[/HOOK_INPUT]` gÃ¡n vÃ o `{{input}}` vÃ  `{{hook_input}}`
- Settings: `{{max_length}}`, `{{preserve_meaning}}`

**KhÃ´ng** gá»­i: `article_id`, `user_id`, `site_id`, credentials.

Thá»© tá»± assemble (`PromptHookPromptAssembler`):

1. Base = `PromptRunnerService::compilePrompt($prompt, $variables)`
2. Template locale append (`after_prompt`)
3. Cháº¡y `runWithCompiledPrompt`

## Prompt resolution

| Má»¥c | GiÃ¡ trá»‹ tháº­t |
|---|---|
| Settings key | `SeoCreateArticleSettingsService::KEY_ARTICLE_TITLE_SUGGESTION_PROMPT_ID` = `article_title_suggestion_prompt_id` |
| Storage | `WpOption` option `seo_create_article_task` |
| Getter | `getArticleTitleSuggestionPromptId()` |
| Resolve | `PromptHookExecutionService::resolveConfiguredPrompt()` â†’ `SeoPrompt` active |
| Match | `prompt.hook_key === article.title_suggestion` |
| Options UI | `SeoPromptSettingsOptionsService::activePromptOptionsForHook('article.title_suggestion')` |

KhÃ´ng hard-code Prompt ID. ChÆ°a cáº¥u hÃ¬nh â†’ `HOOK_PROMPT_NOT_CONFIGURED`. Sai hook â†’ `HOOK_PROMPT_MISMATCH`.

## Model requirement

- Manifest: `capability: text`, `structured_output: false`
- Prompt `tools` pháº£i **khÃ´ng** image pipeline (`ImageToolType::isImagePipeline()`); form Ã©p/reject
- Model runtime: `PromptRunnerService` + `AiModelRouterService` (khÃ´ng hard-code Gemini)

## Hook settings

| Setting | Kiá»ƒu | Default | Minâ€“Max | Locale label |
|---|---|---:|---|---|
| `max_length` | integer | 65 | 30â€“100 | `prompt_hooks.article_title_suggestion.settings.max_length` |
| `preserve_meaning` | boolean | true | â€” | `â€¦settings.preserve_meaning` |

LÆ°u trÃªn Prompt: cá»™t `hook_settings` (JSON). Äá»•i Hook â†’ normalize bá» key rÃ¡c (`PromptHookSettingsResolver`).

## Template

- Locale key: `prompt_hooks.article_title_suggestion.template`
- Position: `after_prompt`
- `nullable: false`
- Ã‰p: má»™t tiÃªu Ä‘á» plain text; khÃ´ng giáº£i thÃ­ch / prefix / markdown / quotes; Æ°u tiÃªn keyword; tÃ´n trá»ng `max_length` / `preserve_meaning`

KhÃ´ng copy full template vÃ o Ä‘Ã¢y â€” sá»­a táº¡i `lang/vi|en/prompt_hooks.php`.

## Output contract

Plain text má»™t dÃ²ng. Normalize (`PromptHookOutputNormalizer`):

1. `trim`
2. `strip_markdown_fence`
3. `strip_wrapping_quotes`
4. `first_non_empty_line`
5. `validation.not_empty` â†’ `HOOK_OUTPUT_INVALID` náº¿u rá»—ng

## UI behavior

| Tráº¡ng thÃ¡i | |
|---|---|
| **Done** | NÃºt Sparkles cáº¡nh `.wp-title-input`; loading trÃªn nÃºt; disable khi Ä‘ang cháº¡y / thiáº¿u keyword / chÆ°a cáº¥u hÃ¬nh Prompt |
| **Done** | ThÃ nh cÃ´ng: set Livewire `articleTitle` + input; **khÃ´ng** auto-save / WP sync |
| **Done** | Stale: náº¿u user Ä‘á»•i title trong lÃºc request â†’ khÃ´ng ghi Ä‘Ã¨, toast warning kÃ¨m gá»£i Ã½ |
| **Done** | Lá»—i: giá»¯ title cÅ©, toast danger |

## Errors (Ä‘Ã£ implement)

| Code | Khi nÃ o |
|---|---|
| `HOOK_NOT_FOUND` | Hook key khÃ´ng cÃ³ trong registry |
| `HOOK_PROMPT_NOT_CONFIGURED` | ChÆ°a gÃ¡n / prompt inactive |
| `HOOK_PROMPT_MISMATCH` | `prompt.hook_key` â‰  hook |
| `HOOK_INPUT_INVALID` | Field láº¡, required thiáº¿u sau resolve, settings out of range, `article_id` â‰¤ 0 |
| `HOOK_ARTICLE_NOT_FOUND` | Article khÃ´ng tá»“n táº¡i |
| `HOOK_ARTICLE_FORBIDDEN` | KhÃ´ng cÃ³ quyá»n article |
| `HOOK_MODEL_UNSUPPORTED` | Prompt image pipeline vá»›i capability text |
| `HOOK_EXECUTION_FAILED` | PromptRunner lá»—i |
| `HOOK_OUTPUT_INVALID` | Output rá»—ng sau normalize |
| `HOOK_MANIFEST_INVALID` | Manifest lá»—i (loader) |

## Tests (Ä‘Ã£ cÃ³)

| File | Ná»™i dung liÃªn quan |
|---|---|
| `tests/Unit/PromptHookFoundationTest.php` | Load 2 manifests; keyword/old_title resolve + override; empty keyword fail; unknown field; settings; output strip; no `article_id` in exposed payload |
| `tests/Unit/PromptHookAssemblerVariablesTest.php` | `[HOOK_INPUT]` khÃ´ng chá»©a `article_id` |
| `tests/Unit/PromptHookFormSchemaTest.php` | Clear hook / version+settings / reject image tool |
| `PromptHookExecuteControllerTest` | Success shape; map `HOOK_PROMPT_NOT_CONFIGURED` / forbidden |
| `PromptHookHttpStatusTest` | HTTP status theo error code |

## Files liÃªn quan

- Manifest: `app/Addons/SeoContentAi/resources/prompt-hooks/article-title-suggestion.json`
- Locale: `app/Addons/SeoContentAi/lang/vi|en/prompt_hooks.php`
- Entity: `PromptHooks/Entities/ArticlePromptHookEntityResolver.php`
- Analyzer keyword: `Services/SeoAnalyzerService.php` (`resolveFocusKeywordForArticle`)
- Registry / execution / assembler / form schema: `PromptHooks/*`
- Settings: `Services/SeoCreateArticleSettingsService.php`, `Filament/Pages/SeoSettingsWorkflows.php`
- Prompt form: `Filament/Resources/PromptResource.php`
- Title UI (Planned nÃºt AI): `resources/views/.../edit-article.blade.php`, `EditArticle.php`
- Tests: `tests/Unit/PromptHook*.php`

## Change history

| Version | Ghi chÃº |
|---|---|
| 1 | Phase 1 â€” táº¡o Hook + form + settings slot; API/UI editor Planned |
