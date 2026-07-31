> Status: Historical
> Not canonical
> Archived on: 2026-08-01
> Superseded by: ../../modules/PROMPTS_AND_AI.md
> Purpose: implementation history only
---
hook_key: article.meta_description_suggestion
version: 1
status: active
manifest: app/Addons/SeoContentAi/resources/prompt-hooks/article-meta-description-suggestion.json
---

# Prompt Hook: Gá»£i Ã½ tháº» mÃ´ táº£ SEO

## Äá»‹nh danh

| Thuá»™c tÃ­nh | GiÃ¡ trá»‹ |
|---|---|
| Hook key | `article.meta_description_suggestion` |
| Version | `1` |
| Capability | `text` |
| Output | Plain text |
| Manifest | `app/Addons/SeoContentAi/resources/prompt-hooks/article-meta-description-suggestion.json` |
| Locale | `seo-content-ai::prompt_hooks.article_meta_description_suggestion.*` |

## Má»¥c Ä‘Ã­ch

Táº¡o hoáº·c cáº£i thiá»‡n tháº» mÃ´ táº£ SEO tá»« tiÃªu Ä‘á» vÃ  mÃ´ táº£ hiá»‡n táº¡i.

Chá»‰ Ä‘á» xuáº¥t local. **KhÃ´ng** tá»± lÆ°u SEO (`saveSeoMeta`), khÃ´ng publish, khÃ´ng sync WP.

## Äiá»ƒm gá»i

| Tráº¡ng thÃ¡i | Äiá»ƒm |
|---|---|
| **Done** | Form Prompt + Hook settings |
| **Done** | Settings â†’ Workflows â€” slot `article_meta_description_suggestion_prompt_id` |
| **Done** | `PromptHookExecutionService` |
| **Done** | HTTP execute + `executePromptHookViaApi` |
| **Done** | Editor bootstrap `prompt_hooks.meta_description_suggestion.configured` |
| **Done** | NÃºt AI trong modal SEO â€” `ArticleGoogleSerpPreview.jsx` (cáº¡nh Â«Tháº» mÃ´ táº£Â») |

LÆ°u SEO hiá»‡n táº¡i (khÃ´ng pháº£i hook): `POST /api/seo/articles/{id}/seo-meta` â†’ `ArticleEditorSyncController::saveSeoMeta` â†’ `ArticleEditorSeoMetaService`. Hook **khÃ´ng** gá»i endpoint nÃ y.

## Entity context

Entity: `article`  
Resolver: `ArticlePromptHookEntityResolver`  
Normalize `description` qua `SeoAnalyzerService::resolveMetaDescriptionForArticle()`:

1. Duyá»‡t `article_meta` theo thá»© tá»± key: `meta_description`, `seo_meta_description`, `_yoast_wpseo_metadesc`, `rank_math_description` â€” láº¥y giÃ¡ trá»‹ trim Ä‘áº§u tiÃªn khÃ¡c rá»—ng
2. Fallback: cá»™t `articles.excerpt`
3. Váº«n rá»—ng â†’ `null` trong context

**KhÃ´ng cÃ³** cá»™t `articles.description`. Field logic `article.description` trong normalized context = gá»™p trÃªn.

Persist SEO editor ghi cáº£ `seo_meta_description` vÃ  `meta_description` (`ArticleEditorBundleApplyService::persistSeoMetaFields`).

Normalized:

```php
[
    'article' => [
        'id' => â€¦,
        'title' => â€¦,          // articles.title
        'description' => â€¦,    // meta SEO âˆª excerpt | null
        'focus_keyword' => â€¦,
        'keyword' => â€¦,
    ],
]
```

Mapping nghiá»‡p vá»¥:

```text
old_description  â†  article.description (normalized)
                 â†  article_meta (seo_meta_description / meta_description / â€¦) âˆª articles.excerpt
```

Manifest chá»‰ Ä‘á»c `article.description` â€” khÃ´ng hard-code tÃªn meta_key.

## Input contract

### `article_id`

Request-level, báº¯t buá»™c, khÃ´ng expose prompt. Auth + load nhÆ° title hook.

### `title`

- String, required sau resolve
- Sources: runtime `title` â†’ entity `article.title`
- Normalize: `trim`, `empty_to_null`
- Runtime Æ°u tiÃªn (title editor cÃ³ thá»ƒ chÆ°a save)

### `old_description`

- `string|null`, optional
- Sources: runtime `old_description` â†’ entity `article.description` â†’ constant `null`
- Chuá»—i rá»—ng UI â†’ `empty_to_null`

## Request máº«u

```json
{
  "article_id": 123,
  "input": {
    "title": "MÃ¡ch báº¡n cÃ¡ch giá»¯ form balo Ä‘Ãºng cÃ¡ch",
    "old_description": "MÃ´ táº£ SEO hiá»‡n táº¡i"
  }
}
```

Route: `POST /api/seo/prompt-hooks/article.meta_description_suggestion/execute`  
KhÃ´ng gá»i `saveSeoMeta` / WP sync.
## Prompt payload

Expose: `title`, `old_description` + settings `min_length` / `max_length` + `[HOOK_INPUT]` qua `{{input}}`.

KhÃ´ng expose: `article_id`, `site_id`, `user_id`, credentials.

Assemble giá»‘ng title hook (`PromptHookPromptAssembler`).

## Prompt resolution

| Má»¥c | GiÃ¡ trá»‹ tháº­t |
|---|---|
| Settings key | `KEY_ARTICLE_META_DESCRIPTION_SUGGESTION_PROMPT_ID` = `article_meta_description_suggestion_prompt_id` |
| Getter | `getArticleMetaDescriptionSuggestionPromptId()` |
| Options | `activePromptOptionsForHook('article.meta_description_suggestion')` |
| Match | `prompt.hook_key === article.meta_description_suggestion` |

ChÆ°a cáº¥u hÃ¬nh â†’ `HOOK_PROMPT_NOT_CONFIGURED`. KhÃ´ng hard-code ID.

## Model requirement

`capability: text`, `structured_output: false`. Routing qua `PromptRunnerService` / `AiModelRouterService`.

## Hook settings

| Setting | Kiá»ƒu | Default | Minâ€“Max | Ã nghÄ©a |
|---|---|---:|---|---|
| `max_length` | integer | 160 | 100â€“200 | Äá»™ dÃ i tá»‘i Ä‘a mong muá»‘n |
| `min_length` | integer | 120 | 50â€“180 | Äá»™ dÃ i tá»‘i thiá»ƒu mong muá»‘n |

## Template

- Key: `prompt_hooks.article_meta_description_suggestion.template`
- Position: `after_prompt`
- Ã‰p: má»™t Ä‘oáº¡n meta description; khÃ´ng prefix/markdown; dá»±a `title`; dÃ¹ng `old_description` lÃ m ngá»¯ cáº£nh; khÃ´ng bá»‹a fact; tÃ´n trá»ng min/max length

## Output contract

Plain text má»™t Ä‘oáº¡n. Normalize giá»‘ng title hook (`trim`, fence, quotes, first non-empty line, `not_empty`).

## UI behavior

| Tráº¡ng thÃ¡i | |
|---|---|
| **Done** | Cáº­p nháº­t `draftDescription` only; counter cáº­p nháº­t; **khÃ´ng** gá»i `saveSeoMetaViaApi`; khÃ´ng Ä‘Ã³ng modal |
| **Done** | Stale: user sá»­a textarea lÃºc request â†’ khÃ´ng ghi Ä‘Ã¨ |
| **Done** | Lá»—i: giá»¯ mÃ´ táº£ cÅ© |

## Errors (Ä‘Ã£ implement)

CÃ¹ng bá»™ enum `PromptHookErrorCode` nhÆ° title hook (`HOOK_NOT_FOUND`, `HOOK_PROMPT_NOT_CONFIGURED`, `HOOK_PROMPT_MISMATCH`, `HOOK_INPUT_INVALID`, `HOOK_ARTICLE_NOT_FOUND`, `HOOK_ARTICLE_FORBIDDEN`, `HOOK_MODEL_UNSUPPORTED`, `HOOK_EXECUTION_FAILED`, `HOOK_OUTPUT_INVALID`, `HOOK_MANIFEST_INVALID`).

## Tests (Ä‘Ã£ cÃ³)

| File | LiÃªn quan meta hook |
|---|---|
| `PromptHookFoundationTest` | title/old_description resolve, override, empty title fail, fallback `article.description` |
| `PromptHookAssemblerVariablesTest` | khÃ´ng leak `article_id` |
| `PromptHookFormSchemaTest` | normalize save |
| `PromptHookDocumentationTest` | doc link + locale |

**ChÆ°a cÃ³:** HTTP feature test, assert khÃ´ng gá»i `saveSeoMeta`, fallback chi tiáº¿t meta_key trong unit entity (Ä‘ang cover qua InputResolver + context giáº£ láº­p; entity+analyzer DB test Planned).

## Files liÃªn quan

- Manifest / locale / PromptHooks (nhÆ° title hook, file meta-description)
- Meta resolve: `SeoAnalyzerService::resolveMetaDescriptionForArticle`
- Persist SEO (khÃ´ng pháº£i hook): `ArticleEditorSeoMetaService`, `ArticleEditorBundleApplyService`
- Modal UI: `resources/js/components/ArticleGoogleSerpPreview.jsx`
- API save SEO: `ArticleEditorSyncController::saveSeoMeta`
- Settings slot + Prompt form: nhÆ° README

## Change history

| Version | Ghi chÃº |
|---|---|
| 1 | Phase 1 â€” backend + form + settings; API/UI modal Planned |
