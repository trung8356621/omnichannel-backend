> Status: Historical
> Not canonical
> Archived on: 2026-08-01
> Superseded by: ../../modules/PROMPTS_AND_AI.md
> Purpose: implementation history only
# SeoContentAi â€” Settings, Prompts & AI Connections

[â† Quay láº¡i Báº£n Ä‘á»“ tá»•ng](SUPER_MAP_INDEX.md)

**LiÃªn quan:** [React Editor & EditArticle](MAP_SEO_EDITOR.md) Â· [WordPress sync](MAP_SEO_WP.md) Â· [Team & PhÃ¢n quyá»n](MAP_SEO_TEAM.md)

---

## 1. Há»‡ thá»‘ng Settings

### 1.1 Tá»•ng quan

Settings Ä‘Æ°á»£c tá»• chá»©c thÃ nh cÃ¡c page Filament dÆ°á»›i nhÃ¡nh `/seo/{connection_hash}/settings/`. Táº¥t cáº£ Ä‘á»u yÃªu cáº§u **Manager role** (`SeoAccessControl::canAccessManagerFeatures()`).

### 1.2 SÆ¡ Ä‘á»“ Ä‘iá»u hÆ°á»›ng

```mermaid
flowchart TB
    SETTINGS["/seo/.../settings"]
    SETTINGS --> OVERVIEW["/overview<br/>Tá»•ng quan"]
    SETTINGS --> EDITOR["/editor<br/>Article editor"]
    SETTINGS --> KEYWORDS["/keywords<br/>Keywords"]
    SETTINGS --> PROMPT["/prompt<br/>AI Prompts"]
    SETTINGS --> SCORING["/scoring<br/>SEO scoring rules"]
    SETTINGS --> WORKFLOWS["/workflows<br/>Workflows"]
    SETTINGS --> AI_ADV["/ai-advanced<br/>AI Advanced"]
    SETTINGS --> REC["/recommendations<br/>Recommendations"]
    SETTINGS --> AI["/settings/api<br/>API Connections<br/>(Resource)"]
    SETTINGS --> IMG["Image Optimization<br/>(Media parent)"]
```

`SeoSettings.php` (slug `/settings`) lÃ  page redirect, `mount()` chuyá»ƒn hÆ°á»›ng sang `SeoSettingsOverview`.

### 1.3 Settings Pages

| Page | Slug | File | MÃ´ táº£ |
|------|------|------|-------|
| **SeoSettingsOverview** | `settings/overview` | `Filament/Pages/SeoSettingsOverview.php` | AI model status (sync, capability groups, `routing_status`/`disabled_reason`), team chat limits; teaser â†’ Recommendations |
| **SeoSettingsWorkflows** | `settings/workflows` | `Filament/Pages/SeoSettingsWorkflows.php` | GÃ¡n task/prompt nghiá»‡p vá»¥; Editor Media (Prompt\|Workflow) â€” khÃ´ng chá»n model per-node |
| **SeoSettingsAiAdvanced** | `settings/ai-advanced` | `Filament/Pages/SeoSettingsAiAdvanced.php` | Rendering Preference, Image/Typography/Video model priority, typography validation |
| **SeoSettingsEditor** | `settings/editor` | `Filament/Pages/SeoSettingsEditor.php` | Cáº¥u hÃ¬nh Editor: **local draft interval** (`autosave_interval_seconds`, localStorage only, clamp 0â€“30s, default 2), undo steps, publish; **Nháº­n diá»‡n FAQ** (`faq_catch_keywords`, 1 keyword/dÃ²ng) |
| **ArticleEditorHistoryService** | â€” | `Services/ArticleEditorHistoryService.php` | Option `seo_article_editor_settings`: `history_step`, `autosave_interval_seconds` (browser local draft â€” **khÃ´ng** ghi DB), `wiki_trust_domains` |
| **SeoOverviewSettingsService** | â€” | `Services/SeoOverviewSettingsService.php` | Option `seo_overview_settings`; key `faq_catch_keywords` + `outline_skip_words` + team chat limits; `getFaqCatchKeywords()`, `faqHeadingMatcher()`; default FAQ song ngá»¯ VI+EN khi trá»‘ng (khÃ´ng merge/ghi Ä‘Ã¨ setting Ä‘Ã£ lÆ°u) |
| **FaqHeadingMatcher** | â€” | `Support/FaqHeadingMatcher.php` | So khá»›p tiÃªu Ä‘á» H2â€“H6 vá»›i `faq_catch_keywords` (normalize + token-boundary); dÃ¹ng chung parser/editor/form_faq |
| **SeoSettingsKeywords** | `settings/keywords` | `Filament/Pages/SeoSettingsKeywords.php` | CTA blacklist (`SeoKeywordSettingsService`, default phrases) + **LÃ½ do Ä‘Ã¡nh giÃ¡ tá»« khÃ³a** (`keyword_review_reasons`, `KeywordReviewReasonService`) |

**CTA blacklist pháº¡m vi:** `CtaKeywordBlacklistFilter` â€” import keyword tá»« bÃ i, child/related Topic Cluster (skip im láº·ng). **KhÃ´ng** cháº·n tá»« khÃ³a chÃ­nh khi `WorkflowKeywordResearchService::syncTopicCluster()` (action `save_vocabulary_research`).
| **KeywordReviewService** | â€” | `Services/KeywordReviewService.php` | `submitReview()` lÆ°u `review_status` + history; `article_suggestion` khÃ´ng `assertKeywordLinkedToArticle`; custom reason â†’ `review_note`, `reason_id` null |
| **SeoSettingsPrompt** | `settings/prompt` | `Filament/Pages/SeoSettingsPrompt.php` | Cáº¥u hÃ¬nh prompt máº·c Ä‘á»‹nh, model selection, system prompts |
| **SeoSettingsScoring** | `settings/scoring` | `Filament/Pages/SeoSettingsScoring.php` | **Quy táº¯c cháº¥m Ä‘iá»ƒm SEO** â€” báº­t/táº¯t tá»«ng rule, chá»‰nh Ä‘iá»ƒm trá»« (lÆ°u `wp_options.seo_scoring_rules_settings`) |
| **SeoSettingsRecommendations** | `settings/recommendations` | `Filament/Pages/SeoSettingsRecommendations.php` | Best-practices admin (hard-coded); badge Current Recommendation; khÃ´ng áº£nh hÆ°á»Ÿng runtime |
| **SeoSettingsRecommendationsContent** | â€” | `Support/SeoSettingsRecommendationsContent.php` | Constants + cáº¥u trÃºc card (Image Routing, Typography, Prompt Design, Workflow, AI Models, Experimental) |
| **SeoSettingsMenu** | â€” | `Support/SeoSettingsMenu.php` | Sidebar Settings: Overview â†’ Workflows â†’ AI Advanced â†’ â€¦ â†’ SEO scoring â†’ **Recommendations** |
| **SeoSettings** | `settings` | `Filament/Pages/SeoSettings.php` | Redirect â†’ overview |

### 1.4 Image Optimization Settings

| Page | Slug | File | MÃ´ táº£ |
|------|------|------|-------|
| **ImageOptimizationSettings** | `image-optimization` | `Filament/Pages/ImageOptimizationSettings.php` | WebP/AVIF, quality %, dimension limits, alt tag pattern |

**Site-aware:** DÃ¹ng `#[Url] $siteId` Ä‘á»ƒ lÆ°u setting theo tá»«ng site. Náº¿u khÃ´ng cÃ³ global site scope, hiá»ƒn thá»‹ dropdown chá»n site.

**CÃ¡c setting lÆ°u vÃ o model** `SeoImageOptimizationSetting` (table `seo_image_optimization_settings`):
- `auto_convert_webp` (bool)
- `quality` (int 10-100)
- `limit_dimensions` (bool), `max_width`, `max_height`
- `clean_filename` (bool)
- `auto_alt_tag` (bool), `alt_tag_pattern` (string â€” máº«u nhÆ° `{post_title} - {focus_keyword}`)

---

## 1.5 SEO Scoring Rules (`SeoSettingsScoring`)

Page quáº£n lÃ½ **quy táº¯c trá»« Ä‘iá»ƒm** khi cháº¥m SEO on-page. Rules cá»‘ Ä‘á»‹nh trong `SeoScoringRulesRegistry`; override báº­t/táº¯t vÃ  Ä‘iá»ƒm trá»« lÆ°u `wp_options` key `seo_scoring_rules_settings` qua `SeoScoringSettingsService`.

| ThÃ nh pháº§n | File | MÃ´ táº£ |
|------------|------|-------|
| Page | `Filament/Pages/SeoSettingsScoring.php` | Repeater: label, toggle enabled, deduction |
| Service | `Services/SeoScoringSettingsService.php` | `effectiveRules()`, `auditFilterDefinitions()`, `aggregateFilterDefinitions()` |
| Registry | `Support/SeoScoringRulesRegistry.php` | `defaultRules()`, `effectiveRuleDefinitions()`, `auditFilterDefinitions()`, `activeViolations()`, `AUDIT_LOW_SCORE_THRESHOLD` |
| Messages | `Support/SeoScoringRuleMessageResolver.php` | Map legacy `seo.*` â†’ canonical rule key |
| Violations DB | `Support/SeoRuleViolationsResolver.php` | Äá»c `seo_rule_violations`; runtime bá» rule disabled |

**Effective rule contract** (má»—i rule qua `enrichRuleDefinition()`): `key`, `label`, `short_label`, `category`, `enabled`, `deduction`, `filterable`, `violation_keys`, `threshold` (resolve tá»« `SeoPromptSettingsService` cho Ä‘á»™ dÃ i bÃ i).

**Rule disabled:** khÃ´ng trá»« Ä‘iá»ƒm, khÃ´ng filter audit, khÃ´ng hiá»ƒn thá»‹ badge; violation cÅ© trong DB váº«n giá»¯ nguyÃªn nhÆ°ng runtime bá» qua.

**HÃ nh vi quan trá»ng:** LÆ°u settings **khÃ´ng** bulk cáº­p nháº­t `article_meta.seo_rule_violations`. Äiá»ƒm hiá»ƒn thá»‹ tÃ­nh Ä‘á»™ng tá»« violations Ä‘Ã£ lÆ°u + rules hiá»‡n táº¡i. Violations trong DB chá»‰ Ä‘á»•i khi analyze/save bÃ i hoáº·c **Refresh article metadata (domain)**.

Äiá»ƒm: `100 - sum(deduction)` vá»›i rule disabled â†’ deduction = 0.

---

## 1.6 Recommendations (`SeoSettingsRecommendations`)

Trang tÃ i liá»‡u ná»™i bá»™ admin â€” **khÃ´ng áº£nh hÆ°á»Ÿng runtime routing**.

| ThÃ nh pháº§n | File | MÃ´ táº£ |
|------------|------|-------|
| Page | `Filament/Pages/SeoSettingsRecommendations.php` | Slug `settings/recommendations`; badge Current Recommendation; grid card Info/Success/Warning |
| Content | `Support/SeoSettingsRecommendationsContent.php` | Hard-coded card blocks (Image Routing, Typography, Prompt Design, Workflow, AI Models, Experimental) |
| View | `resources/views/filament/pages/seo-settings-recommendations.blade.php` | Heroicons + responsive CSS (`seo-settings.css`) |
| Menu | `Support/SeoSettingsMenu.php` | Má»¥c cuá»‘i sidebar Settings |
| Overview teaser | `seo-settings-overview.blade.php` | Link Â«Best practicesÂ» â†’ Recommendations |

Lang: `filament.settings_recommendations` (`lang/en|vi/filament.php`).

---

## 2. Workflow Settings (`SeoSettingsWorkflows`)

Page cáº¥u hÃ¬nh workflow quan trá»ng nháº¥t. **Prompt ownership model (2026-07):**

- **Hook** = loáº¡i/capability contract (`settings_visible` trong Runtime Registry).
- **Settings binding** = `prompt_hook_bindings` map `hook_key â†’ prompt_id` (runtime SoT).
- **UI labels:** section Settings + Hook dropdown + Prompt table hiá»‡n `Display [hook_key]` (`PromptHookEditorCatalog::labelWithHookKey` / `option_label`) â€” khá»›p `seo:workflow:doctor`.
- **`article.content.generate`:** `settings_visible=true` (Editor full rewrite + Stable Gate); Publish CP váº«n dÃ¹ng Prompt trÃªn Workflow node.
- **Form encoding:** Filament coi `.` lÃ  nested path â€” form dÃ¹ng `article__title_suggestion` rá»“i decode vá» `article.title_suggestion` khi save (`encodeHookKeyForForm` / `decodePromptHookBindingsFromForm`).
- **Presentation metadata** (optional trÃªn Hook JSON): `presentation.default_instructions`, `output_format`, `variables[].label` â€” UI Settings/Prompt Edit; khÃ´ng áº£nh hÆ°á»Ÿng resolver.
- **Task Prompt Block** = `prompt_id` trá»±c tiáº¿p trong workflow graph.
- **Prompt khÃ´ng cÃ²n status** (`is_active` legacy column giá»¯ DB, app khÃ´ng Ä‘á»c Ä‘á»ƒ gate runtime).
- Unassigned Prompt (khÃ´ng Hook, khÃ´ng binding, khÃ´ng Task) váº«n há»£p lá»‡ â€” khÃ´ng tá»± cháº¡y.

### 2.1 Form Schema

| Section | Field | Key / Hook | MÃ´ táº£ |
|---------|-------|------------|-------|
| **Task Workflows** | Publish / Rewrite / Post comment | `KEY_*_TASK` | Task orchestration â€” khÃ´ng pháº£i Prompt binding |
| **Prompt Hooks** | Dynamic selectors | `KEY_PROMPT_HOOK_BINDINGS` | Render tá»« `PromptHookEditorCatalog::settingsVisibleHooks()` |
| **Editor Media** | Typography / Video | `KEY_CREATE_TYPOGRAPHY_*`, `KEY_CREATE_VIDEO_*` | Prompt\|Workflow (chÆ°a Hook-hÃ³a háº¿t) |
| | Product gallery source | `KEY_CREATE_PRODUCT_GALLERY_SOURCE` + binding `product.gallery.generate` | Prompt path dÃ¹ng Settings binding |

Legacy fields (`article_title_suggestion_prompt_id`, `renew_faq_prompt_id`, â€¦) cÃ²n trong option JSON Ä‘á»ƒ rollback; **runtime Ä‘á»c bindings** (migrate-on-read).

Resolver: `SettingsPromptBindingResolver` â€” khÃ´ng tÃ¬m â€œactive prompt by hookâ€.

Used by / delete safety: `PromptUsageLocator`, `PromptDeleteGuard`.

Default comment: `DefaultCommentPromptInstaller` + hook `article.comment.generate`.

### 2.2 Service: `SeoCreateArticleSettingsService`

```php
// LÆ°u vÃ  Ä‘á»c settings cho workflows
SeoCreateArticleSettingsService::KEY_PUBLISH_ARTICLE  // => tráº£ vá» task_id
SeoCreateArticleSettingsService::getPublishArticleTaskId()
SeoCreateArticleSettingsService::KEY_PROMPT_HOOK_BINDINGS
SeoCreateArticleSettingsService::getBoundPromptId('article.title_suggestion')
```

DÃ¹ng trong:
- `CreateArticlesFromTaskService.runFromKeywords()` â€” láº¥y task_id Ä‘á»ƒ cháº¡y workflow táº¡o bÃ i tá»« keyword
- `SettingsPromptBindingResolver` â€” capability Settings â†’ Prompt
- `TaskWorkflowTestRunner` â€” resolve task/workflow cho tá»«ng action node

---

## 3. Prompt Management (`PromptResource`)

### Prompt Hook documentation

Danh sÃ¡ch vÃ  contract tá»«ng Hook: [Prompt Hooks Index](prompt-hooks/README.md)

- Form chá»n Hook: `PromptHooks/PromptHookFormSchema.php`
- Settings slots (title / meta description): `SeoSettingsWorkflows` + `SeoCreateArticleSettingsService`
- Execute API: `POST /api/seo/prompt-hooks/{hookKey}/execute` (`PromptHookExecuteController`) â€” khÃ´ng save article / SEO / WP

### 3.1 Tá»•ng quan

- **Resource:** `Filament/Resources/PromptResource.php`
- **Model:** `SeoPrompt` (table `prompts`, connection `omi_seo_ai`)
- **Slug:** `prompts` â†’ `/seo/{connection_hash}/prompts`
- **Navigation:** "Prompt management" â†’ SEO Workspace
- **Permission:** `canAccessPlannerFeatures()` Ä‘á»ƒ view, `allowsSeoPanelMutation() && canAccessPlannerFeatures()` Ä‘á»ƒ create/edit/delete

### 3.2 Form Schema

Layout 2 cá»™t (4 + 8):

**Cá»™t trÃ¡i (4): ThÃ´ng tin chung**
- `name` (TextInput, required)
- `description` (Textarea)
- `ai_connection_id` (Select: chá»n API connection) + nÃºt "Sync models"
- `model_category` (Select: model category tá»« connection â€” gemini_pro, gemini_flash, ...)
- `tool` (Radio: `text` | `image`) â€” quyáº¿t Ä‘á»‹nh hiá»ƒn thá»‹ post-processing section
- Name, Hook (Unassigned náº¿u null), AI connection, **Used by**, Actions (Test/Edit/Delete)
- **KhÃ´ng cÃ²n** cá»™t Status / Toggle `is_active`
- Delete bá»‹ cháº·n náº¿u Settings binding hoáº·c Task Prompt Block Ä‘ang tham chiáº¿u
- Äá»•i Hook bá»‹ cháº·n náº¿u Prompt Ä‘ang bá»‹ Settings binding theo Hook cÅ©
- `variables` (Repeater: key + label + required + type) â€” **auto-sync tá»« ná»™i dung markdown**

**Cá»™t pháº£i (8): Ná»™i dung**
- `content` (MarkdownEditor) â€” ná»™i dung prompt, cÃ³ thá»ƒ chá»©a biáº¿n `{{variable_name}}`
- **Post-Processing** (chá»‰ visible khi tool=image):
  - Quick split: `split_enabled` + **má»™t** `split_grid_size` (NÃ—N; legacy `split_rows`/`split_columns` normalize vá» square)
  - Quick resize: enabled + width + height (chá»‰ sau split thÃ nh cÃ´ng)
  - **Runtime Image Output Mode** (UI): preview + full block tá»« `ImageOutputModePromptInjector::buildBlock()` â€” khÃ´ng pháº£i Manual Prompt Hook, khÃ´ng lÆ°u vÃ o template
  - Manual Prompt Hook dropdown (`PromptHookFormSchema`) Ä‘á»™c láº­p; Quick Split khÃ´ng cáº§n chá»n Hook

| Symbol | Path | Vai trÃ² |
|--------|------|---------|
| `PromptPostProcessing` | `Support/PromptPostProcessing.php` | Normalize `split_grid_size`, snapshot `quick_split` vÃ o variables |
| `ImageOutputModePromptInjector` | `Services/ImageOutputModePromptInjector.php` | Inject idempotent `[IMAGE_OUTPUT_MODE_*]`; `buildBlock` / `summarize` / `auditMeta` |
| `QuickSplitCanvasValidator` | `Support/QuickSplitCanvasValidator.php` | Validate canvas vuÃ´ng chia háº¿t trÆ°á»›c split |
| `PromptManualGridWarning` | `Support/PromptManualGridWarning.php` | Soft warning template vs grid_size |

### 3.3 Variables System

Prompt há»— trá»£ biáº¿n Ä‘á»™ng `{{variable_name}}` trong ná»™i dung markdown:
- **`extractVariableNamesFromMarkdown()`** â€” tá»± Ä‘á»™ng trÃ­ch xuáº¥t biáº¿n tá»« markdown
- **`mergeVariablesFromMarkdown()`** â€” gá»™p biáº¿n Ä‘Ã£ khai bÃ¡o vá»›i biáº¿n má»›i phÃ¡t hiá»‡n
- **`variableDefinitionsForPrompt()`** â€” tráº£ vá» Ä‘á»‹nh nghÄ©a biáº¿n cho runtime
- **`defaultVariableLabels()`** â€” nhÃ£n máº·c Ä‘á»‹nh: article_title, focus_keyword, language,...
- **`defaultRuntimeVariableNames()`** â€” tÃªn biáº¿n runtime: content, seo_data, ...

### 3.4 Query Scope

```php
getEloquentQuery() {
    if (shouldScopeToAccountOwner()) {
        $query->where('user_id', accountSiteOwnerId());
    }
}
```

### 3.5 Model: `SeoPrompt`

| Thuá»™c tÃ­nh | Kiá»ƒu |
|-----------|------|
| `$connection` | `omi_seo_ai` |
| `$table` | `prompts` |
| `$casts` | `content` â†’ json, `schema` â†’ json, `is_active` â†’ boolean |
| Relations | `aiConnection()` â†’ `ApiConnection`, `user()` â†’ `User` (cross-db via trait) |

---

## 4. API Connections (`AiConnectionResource`)

### 4.1 Tá»•ng quan

- **Resource:** `Filament/Resources/AiConnectionResource.php`
- **Slug:** `settings/api` â†’ `/seo/.../settings/api` (canonical); legacy `/settings/ai` redirect trong `SeoPanelProvider`
- **List page:** `Pages/ListAiConnections.php` â€” view `seo-settings-api-list.blade.php`, Filament table `contentGrid` + cá»™t **Provider**
- **Navigation:** KhÃ´ng register navigation (truy cáº­p tá»« settings sidebar)
- **Permission:** `canAccessManagerFeatures()` view; `allowsSeoPanelMutation()` create/edit/delete (chá»‰ AI providers)

### 4.2 Provider & lÆ°u trá»¯

| Provider | Form fields | Báº£ng lÆ°u |
|----------|-------------|----------|
| `gemini`, `claude` | name, api_key, status | `api_connections` (mysql) + `connection_type` |
| `google_search_console` | email, tokens, property URL | `seo_gsc_master_connections` (mysql) |
| `dataforseo` | login, password, location, language | `seo_dataforseo_connections` (mysql) |
| `serpapi`, `serper`, `searchapi` | api_key, defaults | `seo_serp_provider_connections` (mysql) |
| `keywords_everywhere`, `seranking` | name, api_key/token, status | `seo_extended_provider_connections` (mysql) |

Support: `Support/ApiConnectionProviders.php` (delegate `SeoProviderRegistry`), `Support/ApiConnectionFormSchema.php`.

**Connection type (`connection_type`):** `ai` | `seo` â€” cá»™t **Loáº¡i** + filter Táº¥t cáº£/AI/SEO trÃªn list (`ListAiConnections`, URL `?type=`). AI: `api_connections.connection_type` (migration backfill idempotent). External rows: expose qua `ApiConnectionListRow::connection_type` tá»« registry.

**SEO Provider Registry (single source of truth):** `Services/SeoProviderRegistry.php`, `DataTransfer/SeoProviderDefinition.php`, `DataTransfer/SeoProviderCapabilityState.php`, enums `ApiConnectionType`, `SeoProviderCategory`, `SeoProviderCapabilityKey`, `PerformanceHubSectionKey`. Resolver runtime: `Services/SeoProviderCapabilityResolver.php`, `Services/SeoProviderConnectionStatusService.php`.

**Capability matrix helper:** icon `?` header list â†’ modal Alpine `api-capability-matrix-modal` (data tá»« registry, khÃ´ng hard-code Blade).

**Providers má»›i (settings only, chÆ°a data adapter):**
- `keywords_everywhere` â†’ `Models/SeoExtendedProviderConnection`, `Services/SeoExtendedProviderConnectionService`, edit `EditExtendedProviderApiConnection` (`settings/api/extended/{provider}/edit`). Test: credits endpoint, khÃ´ng tiÃªu keyword credit.
- `seranking` â†’ cÃ¹ng báº£ng `seo_extended_provider_connections`. Test: balance endpoint. `partial_implementation` â€” chÆ°a Performance tab.

**List columns:** Káº¿t ná»‘i API | Loáº¡i | NhÃ  cung cáº¥p | Tráº¡ng thÃ¡i | Thao tÃ¡c.

Create/Edit: dropdown Provider Ä‘á»•i form; GSC/DataForSEO cÃ³ page riÃªng `edit-gsc`, `edit-dataforseo`.

**Chi tiáº¿t GSC (OAuth, route `{id}`, gap, debug):** [MAP_SEO_GSC_API_CONNECTIONS.md](MAP_SEO_GSC_API_CONNECTIONS.md).

### 4.3 List thá»‘ng nháº¥t

- **Service:** `Services/ApiConnectionsListService.php` â†’ `recordsForUser()` gá»™p AI + GSC + DataForSEO
- **Model áº£o:** `Models/ApiConnectionListRow.php` â€” row GSC/DataForSEO; GSC status = `GoogleSearchConsoleConnectionService::resolveEffectiveStatus()`
- **Override records:** `ListAiConnections::getTableRecords()` â€” search/sort; `notifyOAuthFlash()` sau OAuth callback; Edit URL tÃ¹y provider; Delete AI/GSC/DataForSEO

### 4.4 Form AI (gemini/claude)

- `provider` (Select: `gemini` | `claude` | `google_search_console` | `dataforseo`)
- `name`, `api_key` (encrypted), `status` (`active` | `inactive`)

### 4.5 Query Scope (AI)

```php
getEloquentQuery() {
    where('user_id', auth()->id())->orWhere('is_global', true);
}
```

### 4.6 Model: `ApiConnection`

| Thuá»™c tÃ­nh | Kiá»ƒu |
|-----------|------|
| `$connection` | `mysql` |
| `$table` | `api_connections` |
| `$casts` | `api_key` â†’ encrypted, `metadata` â†’ json, `is_global` â†’ boolean |
| Relations | `seoAiModels()` â†’ HasMany â†’ `SeoAiModel` |

### 4.7 External connection models

| Model | Table | Service resolve |
|-------|-------|-----------------|
| `SeoGscMasterConnection` | `seo_gsc_master_connections` | `GoogleSearchConsoleConnectionService` |
| `SeoDataForSeoConnection` | `seo_dataforseo_connections` | `DataForSeoConnectionService` |

Migration: `2026_07_11_100000_create_seo_external_api_connections_tables.php` (mysql).

### 4.8 SeoAiModel (Model phá»¥ thuá»™c)

- **Table:** `seo_ai_models` (connection `mysql`)
- LÆ°u danh sÃ¡ch model tá»« API provider (Gemini models), sync qua `AiModelsSyncService`
- **Columns:** `api_connection_id`, `category` (gemini_pro, gemini_flash,...), `raw_model_name`, `display_name`, `priority`, `status`, `capabilities` (JSON)

---

## 5. AI Execution Pipeline

```mermaid
flowchart TB
    subgraph Configuration["Configuration"]
        AC["ApiConnection<br/>API key + provider"]
        AM["SeoAiModel<br/>Model list + capabilities"]
        PR["SeoPrompt<br/>Prompt template + variables"]
        WS["SeoCreateArticleSettingsService<br/>Workflow assignment"]
    end

    subgraph Runtime["Runtime Execution"]
        PRS["PromptRunnerService"]
        AMR["AiModelRouterService<br/>Model routing + failover"]
        AES["AiExecutionService<br/>Claude execution"]
        MGS["MediaGenerationService<br/>Image generation (Imagen/...)"]
        PMS["PromptMediaStorageService<br/>Save media"]
    end

    subgraph Results["Results"]
        PRES["PromptResult<br/>Output text + structured"]
        GM["SeoGeneratedImage"]
        LNK["SeoPromptResultLink<br/>Link â†’ article/task/run"]
    end

    AC --> AM
    AM --> PRS
    PR --> PRS
    WS --> PRS

    PRS --> AMR
    AMR --> AES
    AMR --> MGS
    MGS --> PMS

    PRS --> PRES
    PRS --> GM
    PRS --> LNK
```

### 5.1 PromptRunnerService (`Services/PromptRunnerService.php`)

Engine AI trung tÃ¢m, 1181 dÃ²ng. **Dependencies:**
- `AiExecutionService` â€” gá»i Claude
- `MediaGenerationService` â€” pipeline sinh áº£nh (Imagen/Nano Banana)
- `PromptMediaStorageService` â€” lÆ°u media tá»« remote
- `AiModelRouterService` â€” router model vá»›i failover
- `AiModelsReadinessService` â€” kiá»ƒm tra káº¿t ná»‘i AI sáºµn sÃ ng

**Methods chÃ­nh:**

| Method | MÃ´ táº£ |
|--------|-------|
| `run(prompt, variables, ...)` | Entry point: compile prompt â†’ route provider â†’ xá»­ lÃ½ chain |
| `runWithCompiledPrompt()` | Cháº¡y vá»›i prompt Ä‘Ã£ compile sáºµn |
| `runDirectImagePreview()` | Image tool (+ optional sub_task): compile parts â†’ `executeImage` **khÃ´ng** cháº¡y planner text Flash |
| `runChainStepOutput()` | Cháº¡y 1 bÆ°á»›c trong chain (dÃ¹ng cho ImageGenerationChainService) |
| `compilePrompt()` | Compile parts + variables; **image pipeline** prepend Runtime Image Output Mode via `ImageOutputModePromptInjector` (snapshot `quick_split` náº¿u cÃ³) |
| `callProvider()` | Router cuá»‘i: gemini â†’ `callGemini()`, claude â†’ `callClaude()`, image â†’ `MediaGenerationService` |
| `callGemini()` | Gá»i Gemini API vá»›i retry model/version |
| `callClaude()` | Delegate sang `AiExecutionService::executeClaude()` |
| `executeWithModelRouting()` | Gá»i `AiModelRouterService::executeWithFailover()` |

**Run audit:** `PromptResult.input_snapshot` lÆ°u `compiled_prompt` + `image_output_mode` (`auditMeta`: mode / grid / expected_children / `generation_snapshot`). Test Prompt hiá»ƒn thá»‹ template vs final tá»« snapshot, khÃ´ng rebuild tá»« form.

**Image path parity:** `GenerateMediaJob` vÃ  `TaskWorkflowTestRunner` (tool image/`image_typography`) dÃ¹ng `runFullDependentChain=false` â†’ cÃ¹ng pipeline Test Prompt / Editor. KhÃ´ng Ã©p `modelOverride` category Flash lÃªn image node.

### 5.2 AiModelRouterService

Router model vá»›i failover mechanism:
- Thá»­ model theo priority
- Náº¿u model bá»‹ `exhausted` hoáº·c lá»—i â†’ fallback sang model tiáº¿p theo
- LÆ°u tráº¡ng thÃ¡i `last_error` vÃ o `SeoAiModel`
- `overviewForUser()` gáº¯n `routing_status` + `disabled_reason` tá»« `GeminiModelVersionPolicy::routingDecision()`
- `getNextActiveModel()` / planner failover bá» model khÃ´ng eligible; `markModelUnavailableForAutoRouting()` khi provider unavailable

### 5.2.1 Gemini version routing (`Support/GeminiModelVersionPolicy.php`)

Gate auto-routing Gemini/Imagen: **major â‰¥ 3** (`MIN_MAJOR_VERSION = 3`).

| Symbol | Vai trÃ² |
|--------|---------|
| `routingDecision()` | Tráº£ `routing_status` (`enabled`/`disabled`) + `disabled_reason` (`legacy_version`, `provider_unavailable`, â€¦) |
| `filterEligibleForAutoRouting()` | Lá»c slug list theo version + capability `auto_routing` |
| `preferStableFirst()` | Typography/render Æ°u tiÃªn stable trÆ°á»›c preview |
| `markCapabilitiesUnavailable()` | Ghi `auto_routing=false` khi API tráº£ unavailable |
| `isProviderUnavailableError()` | Nháº­n diá»‡n lá»—i provider Ä‘á»ƒ retry model káº¿ |

Model 2.x váº«n seed/sync trong DB vÃ  hiá»ƒn thá»‹ Model Status â€” chá»‰ **khÃ´ng** vÃ o auto-routing.

**Wired:** `GoogleAiModelRegistry`, `SeoCreateArticleSettingsService` (default/normalize priority), `ImageRoutingStrategy`, `GeminiModelCatalog`, `GeminiMediaGenerationService`, `AiModelRouterService`.

Default image priority runtime (3.x): `gemini-3.1-flash-image-preview` â†’ `gemini-3-pro-image-preview` â†’ `imagen-4.0-generate-001`.

### 5.2.2 Vision validation router (`Support/VisionValidationModelRouter.php`)

Typography Vision chá»n model text cÃ³ `ImageCapability::ImageInput`, major â‰¥ 3, failover multi-model. Primary máº·c Ä‘á»‹nh: `gemini-3.5-flash-preview`. DÃ¹ng trong `TypographyValidationService`.

### 5.3 PromptResult

**Table:** `prompt_results` (connection `omi_seo_ai`)

| Column | Type | MÃ´ táº£ |
|--------|------|-------|
| `prompt_id` | FK nullable | Prompt gá»‘c |
| `user_id` | FK nullable | NgÆ°á»i cháº¡y |
| `article_id` | FK nullable | Article liÃªn káº¿t |
| `status` | varchar | pending, running, completed, failed |
| `input_snapshot` | JSON | Input variables snapshot |
| `output_text` | longText | Output text tá»« AI |
| `output_structured` | JSON | Output structured (náº¿u cÃ³) |
| `error_message` | text | Lá»—i náº¿u failed |

### 5.4 SeoPromptResultLink

**Table:** `seo_prompt_result_links` â€” cross-reference giá»¯a PromptResult, article, project run, project task.

Cho phÃ©p truy xuáº¥t nguá»“n gá»‘c cá»§a má»—i output AI (prompt result nÃ o sinh ra article nÃ o, thuá»™c project run/task nÃ o).

---

## HÆ°á»›ng dáº«n prompt â€” Settings, Prompts, AI

```
Settings Pages: Filament/Pages/SeoSettings*.php, ImageOptimizationSettings.php
AI Advanced: Filament/Pages/SeoSettingsAiAdvanced.php â†’ SeoCreateArticleSettingsService (routing keys)
Recommendations: Filament/Pages/SeoSettingsRecommendations.php â†’ Support/SeoSettingsRecommendationsContent.php (docs only)
Workflows Settings: Filament/Pages/SeoSettingsWorkflows.php â†’ SeoCreateArticleSettingsService
API Connections: `AiConnectionResource` (`settings/api`) â†’ `ApiConnection` + external models; list `ApiConnectionsListService` + `ApiConnectionListRow`; registry `SeoProviderRegistry` + `SeoProviderCapabilityResolver`
Prompt Management: Filament/Resources/PromptResource.php â†’ SeoPrompt model (omi_seo_ai)
Prompt Engine: Services/PromptRunnerService.php (1181 dÃ²ng)
Model Router: Services/AiModelRouterService.php â†’ SeoAiModel (mysql)
Image Gen: Services/MediaGenerationService.php
Claude Exec: Services/AiExecutionService.php
```
