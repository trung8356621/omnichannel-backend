> Status: Historical
> Not canonical
> Archived on: 2026-08-01
> Superseded by: ../../modules/ARTICLE_EDITOR.md
> Purpose: implementation history only
# SeoContentAi â€” React Editor & EditArticle

[â† Quay láº¡i Báº£n Ä‘á»“ tá»•ng](SUPER_MAP_INDEX.md)

**LiÃªn quan:** [Media / upload](MAP_SEO_MEDIA.md) Â· [WordPress sync](MAP_SEO_WP.md) Â· [Content Projects & Workflow](MAP_SEO_PROJECTS.md) Â· **[SEO Scoring (Rules + Violations)](MAP_SEO_EDITOR_SCORING.md)**

---

## 2.4 Danh sÃ¡ch bÃ i viáº¿t (ListArticles)

| ThÃ´ng tin | GiÃ¡ trá»‹ |
|-----------|---------|
| **URL panel** | `/seo/{connection_hash}/articles` |
| **Resource** | `Filament/Resources/ArticleResource.php` |
| **Page** | `Filament/Resources/ArticleResource/Pages/ListArticles.php` |
| **View** | `resources/views/filament/resources/article-resource/pages/list-articles.blade.php` |

**Tab ná»™i dung** (`?tab=`):

| Tab | Constant | Query máº·c Ä‘á»‹nh |
|-----|----------|----------------|
| BÃ i viáº¿t | `ListArticles::TAB_POSTS` (`posts`) | `type` post/product; **`is_reviewed = 0`**; **loáº¡i `skip_seo_audit`** |
| Danh má»¥c | `TAB_CATEGORIES` (`categories`) | `type` category/product_category; **`is_reviewed = 0`**; **loáº¡i `skip_seo_audit`** |
| HÃ ng Ä‘á»£i WP | `TAB_QUEUE` (`queue`) | Meta `wp_sync_queue`; **`is_reviewed = 0`**; **loáº¡i `skip_seo_audit`**; badge `getSyncQueueBadgeCount()` + CSS `seo-internal-tabs__queue` / `__queue-badge` (amber + pulse khi count > 0; `domain-overview.css`) |
| ÄÃ£ duyá»‡t | `TAB_REVIEWED` (`reviewed`) | `is_reviewed = 1` + `reviewed_at` not null â€” partial `reviewed-articles-tab.blade.php`; **loáº¡i `skip_seo_audit`** |
| Bá» qua | `TAB_SKIPPED` (`skipped`) | Chá»‰ bÃ i cÃ³ `article_meta.skip_seo_audit=1` (áº©n khá»i cÃ¡c tab kia + SEO Audit) |

**Skip list/audit:** action hÃ ng `toggle_skip_seo_audit` â†’ `ArticleResource::toggleSkipSeoAudit()` ghi `article_meta.skip_seo_audit`. Bulk: `skip_seo_audit` (áº©n khá»i tab thÆ°á»ng) / `unskip_seo_audit` (chá»‰ tab **Bá» qua**, via `isArticlesSkippedTab()`). Scope: `applyExcludeSkipSeoAuditScope` / `applyOnlySkipSeoAuditScope`. Reviewed group UI: `buildReviewedArticlesGrouped()` cÅ©ng loáº¡i skip. CÃ¹ng flag vá»›i SEO Audit skip. KhÃ´i phá»¥c tá»« tab **Bá» qua**.

**Filter máº·c Ä‘á»‹nh (tab BÃ i viáº¿t):** `language=vi`, `post_type=post` â€” `SelectFilter::default()` + `ListArticles::ensureDefaultPostsTableFilters()`; URL tÆ°Æ¡ng Ä‘Æ°Æ¡ng `?tableFilters[language][value]=vi&tableFilters[post_type][value]=post`. CÃ³ `tableFilters` trÃªn query thÃ¬ khÃ´ng ghi Ä‘Ã¨. Link tab Posts (`getContentTabUrl`) bá»• sung default náº¿u thiáº¿u. **Mount:** chá»‰ ghi `$this->tableFilters` â€” khÃ´ng gá»i `getTableFiltersForm()` / `handleTableFilterUpdates()` (trÃ¡nh `$table` chÆ°a init trÆ°á»›c `bootedInteractsWithTable`).

**Cá»™t báº£ng:** khÃ´ng cÃ³ cá»™t **Reviewed** (`is_reviewed`) â€” tráº¡ng thÃ¡i duyá»‡t chá»‰ xem á»Ÿ tab **Reviewed**. Cá»™t `reviewed_at` váº«n toggle áº©n máº·c Ä‘á»‹nh.

**Route liÃªn quan:** `/seo/{connection_hash}/articles/queue` (`ListArticleSyncQueue`), `/seo/{connection_hash}/articles/{id}/edit` (`EditArticle`).

---

## 2.5 Cáº¥u trÃºc chi tiáº¿t EditArticle (React Component Graph)

> MCP `search_graph` (`SeoArticleEditor`, out_degree **112**), `search_code` (`callEditArticleLivewire`). Files: `article-editor.jsx`, `edit-article.blade.php`, `EditArticle.php`.

### 2.5.1 Component React chÃ­nh


| Vai trÃ²          | File                                           | Ghi chÃº                                                                                          |
| ---------------- | ---------------------------------------------- | ------------------------------------------------------------------------------------------------ |
| **Vite entry**   | `resources/js/article-editor.jsx`              | Bundle `article-editor`. Phase 3: **1 main React root** (+ optional light AI launcher root). |
| **Editor chÃ­nh** | `resources/js/components/SeoArticleEditor.jsx` | Core TipTap + editor-hosted heavy modules (seo/images/reviews) one-at-a-time via `activeHeavyModule`. |
| **Module host**  | `resources/js/components/ArticleEditorModuleHost.jsx` | Links / FAQ / CTA / AI Chat â€” dynamic import + portal + AbortController. |
| **Blade host**   | `resources/views/.../edit-article.blade.php`   | `#seo-article-editor-root` (`wire:ignore`) + `#seo-article-core-bootstrap`. |
| **Backend page** | `Filament/.../EditArticle.php`                 | Livewire `/seo/articles/{record}/edit`. SSR core bootstrap + lazy `/editor/*`. Menu **Viáº¿t láº¡i toÃ n bá»™ bÃ i hiá»‡n cÃ³** â†’ `queueEditorFullRewrite()` â†’ `TaskTestInputResolver::resolveEditorFullRewrite` â†’ `ArticleWritingExecutionService` (`existing_article`, DirectGenerate). KhÃ´ng Publish graph. |

**Image block picker:** `ImageBlockPickerBox` chá» 2Ã—`requestAnimationFrame` má»›i enable nÃºt. `handleClickOutside` giá»¯ block active khi click trong slot block Ä‘ang chá»n; guard ~360ms sau activate/insert image; whitelist outline rail, media/generate modal. Outline focus clear khi click ra ngoÃ i heading (`headingCommand.action=clear`).

**Paste Ctrl+V áº£nh:** `processClipboardImagePaste` â†’ `uploadSeoMediaFromFile` (`source=clipboard`) â†’ server slug random `paste-{hex}` (trÃ¡nh `image.png` cache). `ImageBlockEditor.applyUploadedImageToBlock` xÃ³a `wpAttachmentId`/`wpSrc` cÅ© khi paste local má»›i (trÃ¡nh rename WP báº±ng ID stale). `shouldRenameSlugOnWordPress` / `isImageReadyForWpSlugFix` chá»‰ tin ID qua `resolveImageRefIds` â€” khÃ´ng fallback `rawWp`. Chi tiáº¿t: [MAP_SEO_MEDIA.md](MAP_SEO_MEDIA.md), [MAP_SEO_WP.md](MAP_SEO_WP.md) Â§rename.

**Featured image sidebar:** `articleFeaturedImageStorage.saveFeaturedImage()` lÆ°u localStorage rá»“i dispatch `seo-featured-image-updated`; Alpine trong `edit-article.blade.php` nháº­n `onFeaturedImageUpdated()` Ä‘á»ƒ cáº­p nháº­t `featuredImageDraft` ngay (khÃ´ng chá» reload). Clear váº«n dÃ¹ng `seo-featured-image-cleared`.

**Äá»•i `post_type` (Article tab, khÃ´ng sync WP ngay):** `publish-sidebar` `applyPostType()` â†’ event `seo-publish-post-type-changed` + `pushToWire()` (`EditArticle::applyPublishBoxFromClient`, `skipRender`). UI Featured/Album/Reviews **khÃ´ng** phá»¥ thuá»™c `@if supportsProductGallery()` SSR: luÃ´n render cáº£ panel áº£nh Ä‘áº¡i diá»‡n + album + Reviews; Alpine `supportsProductGalleryUi` + `data-assistant-requires-(non-)product` gate chip/panel. `seoAssistantNavigator.applyEditorPostType()` rediscover dock. React `SeoArticleEditor` giá»¯ `supportsProductGallery` state theo event (Make Featured / Reviews / distribute gallery).


**Luá»“ng bootstrap (khÃ´ng REST lÃºc má»Ÿ trang):**

```mermaid
flowchart LR
    subgraph PHP["EditArticle.php (SSR)"]
        MOUNT["mount() â†’ hydrateArticleState()"]
        HTML["$editorHtml"]
        PAYLOAD["getEditor*Payload()"]
    end

    subgraph Blade["edit-article.blade.php"]
        JSON["#seo-article-initial-*"]
        ROOT["#seo-article-editor-root"]
    end

    subgraph JS["article-editor.jsx"]
        READ["readArticleEditorBootstrap()"]
        MOUNT_R["createRoot â†’ SeoArticleEditor"]
    end

    MOUNT --> HTML & PAYLOAD --> JSON
    JSON --> READ --> MOUNT_R --> ROOT
```





### 2.5.2 CÃ¢y component React (mount graph) â€” Phase 3 + post-Phase-4 stabilization

**Roots:** 1 main (`#seo-article-editor-root` = `SeoArticleEditor` + `ArticleEditorModuleHost`) + 1 light optional (`#seo-article-ai-launcher-root` = FAB). KhÃ´ng cÃ²n `createRoot` riÃªng cho Links / FAQ / AI Chat.

**Policy:** tá»‘i Ä‘a **1** heavy sidebar module mounted. Switch = unmount + cleanup (khÃ´ng giá»¯ React tree báº±ng CSS hide). **Má»™t SEO widget** (`SeoModule` trong SEO Assistant portal). CTA = cÃ¹ng `LinksModule` (`domain_cta_list` tá»« `/editor/links`).

**Google Preview:** paint tá»« `#seo-article-core-bootstrap` (title/slug/metaDescription/permalink*) + local field state â€” **khÃ´ng** chá» `/editor/seo-summary`.

**Adapters:** `utils/articleEditorPayloadAdapters.js` â€” `normalizeSeoSummary` / `normalizeFaqPayload` / `normalizeLinksPayload` / `normalizeCtaPayload` / `normalizeReviewStatus`.

```mermaid
flowchart TB
    ENTRY["article-editor.jsx"]
    ENTRY --> MAIN["Main root<br/>SeoArticleEditor + ModuleHost"]
    ENTRY --> FAB["AI Launcher root<br/>light only"]

    MAIN --> CORE["TipTap core editor"]
    MAIN --> PREVIEW["Google Preview<br/>core + local"]
    MAIN --> HOST["ArticleEditorModuleHost"]
    MAIN --> PORTALS["createPortal shells<br/>seo / images / reviews"]

    HOST -->|active=links or cta| L["lazy LinksModule"]
    HOST -->|active=faq| F["lazy FaqModule"]
    HOST -->|active=ai-chat| A["lazy AiChatModule"]

    PORTALS -->|activeHeavy=seo| S["lazy SeoModule"]
    PORTALS -->|activeHeavy=images| I["lazy ImagesModule"]
    PORTALS -->|activeHeavy=reviews| R["lazy ReviewsModule"]
```

| Module | Chunk | Mount when | Fetch | Unmount |
| --- | --- | --- | --- | --- |
| SEO | `modules/SeoModule.jsx` | default + panel SEO | `/editor/seo-summary` (+ settings); loading always ends | leave panel |
| Images | `modules/ImagesModule.jsx` | panel Images | `/editor/images` + `/meta` + AbortController | leave â†’ abort |
| Reviews | `modules/ReviewsModule.jsx` | panel Reviews | WP reviews + `product-review-status` when active | leave â†’ clear list |
| Links / CTA | `modules/LinksModule.jsx` | links/cta | `/editor/links`; suggestions on button | leave â†’ unmount |
| FAQ | `modules/FaqModule.jsx` | `article-editor:module-open` (+ compat `seo-faq-panel-activate`) | `/editor/faqs` â†’ normalize `{cached,items,count}` â€” never null | leave â†’ drop rows |
| AI Chat | `modules/AiChatModule.jsx` | FAB open | module-owned | close â†’ unmount |
| Publishing | Blade/Alpine | open publishing | `$wire.getPublishCategoryOptions` | N/A React |

**Events (official):** `seo-assistant-switch-panel`, `seo-editor-active-module`, `seo-faq-panel-activate`, `seo-assistant-link-section`, `seo-editor-seo-summary-loaded`, `seo-editor-links-updated`.

**Guards:** `__seoArticleEditorNavigatedBound`, `__seoArticleLivewireBridgeRegistered`, `__seoMountedLivewireId`, `pageCleanups` abort idle fetches.

**Regression matrix:** `docs/audits/ARTICLE_EDITOR_POST_PHASE4_REGRESSIONS.md`.

### 2.5.2b Phase 4 â€” client utilities

**Canonical document (session):** editor `blocks[]` (per-block HTML). TipTap instance per active block. Full HTML chá»‰ táº¡i boundary: local draft flush, Save, Preview, Sync WP (`getExportHtml`).

**Utility scheduler:** `utils/articleEditorUtilityScheduler.js` â€” `schedule` / `cancel` / `cancelAll` / version gate; stale task bá» káº¿t quáº£.

| Utility | Source | When | Server? | Debounce |
| --- | --- | --- | --- | --- |
| Outline display/nav | `buildClientOutlineTree(blocks)` | heading fingerprint Ä‘á»•i | **No** GET | 400ms idle |
| Outline AI / duplicate check | existing API | explicit button | Yes | â€” |
| Word count (section) | `countWordsFromHtmlLight` | section stats | No | via stats memo |
| Find/Replace | block plain-text scan | toolbar | No | 350ms |
| SERP preview | local title/slug/meta | field edit | No (save SEO riÃªng) | local |
| Content hash | `hashContent` = SHA-256(trim HTML) | draft/save only | No | draft interval |

**Outline:** `ArticleOutlineTab` `preferClientSource` + `clientOutline`. Jump dÃ¹ng `block_id` / `client:{blockId}`. Endpoint `/outline` **giá»¯** cho generate / check-duplicates / compare bÃ i khÃ¡c â€” khÃ´ng gá»i lÃºc má»Ÿ editor.

**Local draft:** schema v2 HTML-only (khÃ´ng lÆ°u blocks + TipTap JSON trÃ¹ng).

**Modal táº¡o áº£nh AI (**`.seo-generate-image-modal`**):** `GenerateImageModal.jsx` â€” má»Ÿ qua event `seo-open-generate-image-modal` (`target: 'product-gallery'` tá»« album sidebar). Cháº¿ Ä‘á»™ product gallery: layout 2 cá»™t (form + preview).

**Editor media AI (queue):** `ArticleEditorMediaAiService` resolve Prompt|Workflow tá»« `SeoSettingsWorkflows` â†’ `GenerateMediaJob` (`source=prompt|workflow`). Workflow full graph: `EditorWorkflowExecutionService` + `TaskWorkflowTestRunner::run()`; BC `extract_last_prompt_bc`. Image routing qua `ImageRoutingStrategy` (Gemini major â‰¥ 3). Typography: `TypographyPipelineService` + metadata `validation_model`/`render_model` trong history.


| Pháº§n preview   | HÃ nh vi                                                                                                      |
| -------------- | ------------------------------------------------------------------------------------------------------------ |
| Image preview  | Album hiá»‡n táº¡i + áº£nh AI Ä‘Ã£ káº¿t ná»‘i bÃ i (`connected`); áº£nh káº¿t ná»‘i khÃ´ng bá»‹ xÃ³a khá»i preview                  |
| Split grid     | Chá»n thumbnail cÃ³ `seo_media_id` â†’ `ImageSplitterPanel` inline; sau split giá»¯ áº£nh gá»‘c, append máº£nh vÃ o album |
| Prompt preview | Render prompt workflow qua `preview-generate-article-image-prompt`                                           |


Split toÃ n trang (eraser/splitter tab): [MAP_SEO_MEDIA.md Â§2.2](MAP_SEO_MEDIA.md) â€” `/seo/media-image-editor`.

**FAQ manager vs content:** block `[omi_faq]` trong TipTap = compact shortcode card (count / Create|Edit). KhÃ´ng placeholder Â«FAQ chÆ°a táº£iÂ» dÆ°á»›i editor. `ArticleFaqEditor` lazy qua ModuleHost khi: click shortcode / SEO action Generate FAQ (`article-editor:module-open`). Count nháº¹ tá»« core `faqCount` hoáº·c `GET .../editor/faqs/count` â€” khÃ´ng fetch full FAQ rows lÃºc mount. FAQ Ä‘Ã£ bá» khá»i assistant tags vÃ  Links accordion.

**FAQ persist / sync (Phase 2 anti-wipe):** `article-editor.jsx` luÃ´n `initialFaqs={[]}`. `utils/articleEditorApi.js` â†’ `resolveFaqsPersistPayload()` + `faqs_source` (`editor`|`panel`|`none`). Module FAQ chÆ°a hydrate â†’ **khÃ´ng** gá»­i `faqs:[]`. `ArticleEditorBundleApplyService` / `EditArticle` skip wipe DB khi `faqs:[]` + source â‰  `editor` mÃ  bÃ i váº«n cÃ³ `seo_faqs`. `SeoArticle::resolveFaqs()` khÃ´ng tin relation rá»—ng stale; `SeoFaqPersistenceService` `unsetRelation('faqs')` sau delete.

**SEO idle auto-analysis:** content Ä‘á»•i â†’ `seoStale` â†’ debounce **4000ms** (`seo-idle-analyze` qua utility scheduler). GÃµ tiáº¿p = cancel prior (same task id). Single-flight + document version guard. KhÃ´ng loop 150ms. Module SEO Ä‘Ã³ng váº«n cáº­p nháº­t summary (score/violations) vÃ¬ analysis sá»‘ng á»Ÿ `SeoArticleEditor`.

**SEO violation action map:** `utils/seoViolationActions.js` â€” `faq_missing` â†’ Generate FAQ (flow `generate-article-faqs` cÅ©); `featured_snippet_missing` â†’ Create prompt (`FeaturedSnippetPromptModal`, hook `article.featured_snippet.generate`). KhÃ´ng hardcode action ráº£i JSX.

**Existing links vs suggestions:** client scan document (`existingLinkScanner` + debounce 750ms). Links base/suggestions **khÃ´ng** ghi Ä‘Ã¨ existing links (`source: links-base|links-suggestions`). Domain catalog riÃªng â€” chÆ°a insert thÃ¬ khÃ´ng tÃ­nh internal.

**FAQ heading detection:** source of truth = `SeoOverviewSettingsService::KEY_FAQ_CATCH_KEYWORDS` (`faq_catch_keywords`, UI SeoSettingsEditor â†’ Nháº­n diá»‡n FAQ). Matcher canonical: `Support/FaqHeadingMatcher` (`keywords()`, `matches()`) qua `faqHeadingMatcher()`. `WorkflowParserService` dÃ¹ng matcher cho má»i path (Markdown/HTML extract, `[omi_faq]` strip/cut). Normalize match-only (UTF-8 lower, trim, collapse space, decode entity, bá» emphasis / prefix sá»‘ / trailing `:`); token-boundary trÃ¡nh false positive. Trong khá»‘i FAQ: bÃ³c `Q:` / bullet+bold / `ul>li` / H3+; Ä‘Ã³ng block á»Ÿ heading cÃ¹ng cáº¥p hoáº·c cao hÆ¡n. Default song ngá»¯ VI+EN khi setting trá»‘ng â€” khÃ´ng ghi Ä‘Ã¨ giÃ¡ trá»‹ Ä‘Ã£ lÆ°u.

**FAB:** `ArticleAiFloatingLauncher` â€” light; má»Ÿ AI â†’ ModuleHost dynamic import `AiChatModule`.

### 2.5.3 Backend phá»¥c vá»¥ EditArticleExcept

**A. Load (SSR) â€” Phase 2: core bootstrap + lazy `/editor/*` endpoints**

Blade **chá»‰** embed `#seo-article-core-bootstrap` = `EditArticle::getEditorCoreBootstrap()` (identity + `content` + conflict tokens + `endpoints` map + `faqCount` + settings tá»‘i thiá»ƒu â€” **khÃ´ng** scoring rules/messages). KhÃ´ng cÃ²n script `initial-html` / `initial-seo` / `editor-settings` / `meta` / `initial-images` / `initial-faqs` trÃªn render shell. `#seo-article-faq-root` rá»—ng â€” khÃ´ng placeholder Â«Táº£i FAQÂ». Media picker chá»‰ cÃ²n `window.__SEO_ARTICLE_MEDIA_PICKER__` minimal.

| Nguá»“n                                  | Method                                          | Dá»¯ liá»‡u                           |
| --------------------------------------- | ------------------------------------------------ | --------------------------------- |
| `EditArticle::mount()`                  | `hydrateArticleState()` local only                | **No** remote WP HTTP; body/featured tá»« meta; `productGallery=[]`; `wordpressMetadataStale` náº¿u cÃ³ `wp_post_id` |
| `ArticleResource::resolveRecordRouteBinding()` | `getRecordRouteBindingEloquentQuery()` (`includeGlobalSiteScope: false`) | Edit/view **khÃ´ng** 404 khi global domain â‰  `article.site_id`. List váº«n filter qua `getEloquentQuery()`. |
| `#seo-article-core-bootstrap` (SSR duy nháº¥t) | `getEditorCoreBootstrap()`                   | `articleId`, `connectionHash`, `siteId`, `title`, `slug`, `content`, `status`, `postType`, conflict tokens, `featuredImageUrl`, `faqCount`, `endpoints.*`, `settings` (autosave/permission flags â€” khÃ´ng rules) |
| `getBootstrapEditorHtml()`              | protected bootstrap                               | Initial HTML once â€” **not** Livewire public snapshot |
| `GET .../editor/seo-summary`            | `ArticleEditorSeoPayloadService::forEditorSeoSummary()` | score/focus keyword/title/desc â€” meta score only, khÃ´ng SERP, khÃ´ng catalogs |
| `GET .../editor/links`                  | `ArticleEditorLinksPayloadService::base()`        | domain/CTA catalogs â€” existing links tá»« **client document scan** |
| `POST .../editor/links/suggestions`     | `ArticleEditorLinksPayloadService::withSuggestions()` / `withFallbackOnly()` | Â«Táº¡o gá»£i Ã½ liÃªn káº¿tÂ»: `mode=full` (primary + content-keyword fallback náº¿u internal &lt; `target_internal_suggestions`); nÃºt debug Â«Táº¡o gá»£i Ã½ bá»• sungÂ»: `mode=fallback`. Content = HTML editor (`seo-editor-document-html-request`) â†’ `resolveScoringContentForArticle` (body / `wp_post_content`) â€” **khÃ´ng** chá»‰ `articles.body`. GET cÃ¹ng path váº«n cÃ²n (compat). |
| `GET .../editor/images` + `/editor/meta`| post images + product gallery/supplemental        | fetch **khi má»Ÿ Images panel** |
| `GET .../editor/faqs`                   | FAQ rows `{cached,items,count,can_generate}`      | fetch **khi má»Ÿ FAQ module** (tab / shortcode / SEO action) |
| `GET .../editor/faqs/count`             | light count only                                  | shortcode badge khi cáº§n |
| `GET .../editor/settings`               | scoring rules/messages                            | idle sau mount (cÃ¹ng SEO summary) |
| `GET .../editor/media-picker-config`    | minimal picker config                             | full picker config on demand |
| `GET .../editor-seo-payload` (legacy)   | `forArticle()`                                    | Links **khÃ´ng** dÃ¹ng path nÃ y |
| `ArticleMetaMap`                        | request meta index                                | 1 load `articleMetas`, reuse |
| `ArticleEditorPerfDebug`                | bootstrap + Livewire snapshot estimate            | khi `ARTICLE_EDITOR_PERF_DEBUG=true` |

**Sizes (fixture, xem [ARTICLE_EDITOR_PHASE2_BOOTSTRAP_SIZES.md](audits/ARTICLE_EDITOR_PHASE2_BOOTSTRAP_SIZES.md)):** core bootstrap (trá»« `content`) â‰ˆ **1.5 KB** so vá»›i tá»•ng script Phase 1 (trá»« `content`) â‰ˆ **25.2 KB** â†’ giáº£m ~94%. Production: `storage/logs/article_editor_bootstrap_sizes.json` + log `article_editor_livewire_snapshot_estimate` trÃªn channel **web_app**.

### 2.5.3b Web vs cron logging

**Váº¥n Ä‘á» production:** cron/root sá»Ÿ há»¯u `storage/logs/laravel.log` (+ `queue-cron.log`, `watchdog.log`). PHP-FPM (`www`) ghi `laravel.log` â†’ `Permission denied` (vd. Save SEO fields).

**Giáº£i phÃ¡p:** channel HTTP riÃªng â€” **khÃ´ng** Ä‘á»•i cron, **khÃ´ng** `chown` / rename log root.

| Runtime | Channel | File thá»±c táº¿ |
|---------|---------|--------------|
| HTTP / PHP-FPM (editor, SEO panel, REST, Livewire, API trÃ¬nh duyá»‡t) | `web_app` | `storage/logs/web-app-YYYY-MM-DD.log` (daily; user `www` táº¡o láº§n Ä‘áº§u) |
| CLI / cron / queue / watchdog | `logging.default` (`stack` â†’ `single`) | `laravel.log`, `queue-cron.log`, `watchdog.log` â€” **giá»¯ nguyÃªn** |

| Symbol / path | Vai trÃ² |
|---------------|---------|
| `config/logging.php` â†’ `channels.web_app` | `daily`, path `web-app.log`, `WEB_APP_LOG_LEVEL` / `WEB_APP_LOG_DAYS` |
| `App\Support\RuntimeLogger` | HTTP â†’ `web_app` (thiáº¿u channel â†’ `null`, trÃ¡nh LogManager EMERGENCY); console â†’ default/`stack`; `error`/`warning`/`info`/`debug`/`report`; khÃ´ng fallback sang `laravel.log` |
| `AppServiceProvider::boot` | HTTP chá»‰ set `logging.default=web_app` khi `logging.channels.web_app` Ä‘Ã£ cÃ³ (stale `config:cache` thÃ¬ bá» qua) |
| `bootstrap/app.php` `withExceptions` | HTTP: `RuntimeLogger::report` rá»“i `return false` (cháº·n default log) |
| `ArticleEditorSyncController` | SEO meta catch â†’ `RuntimeLogger::report` |
| `ArticleEditorLazyPayloadController` | editor meta catch â†’ `RuntimeLogger::report` |
| `KeywordReviewController`, `GlobalAiChatController` | web catch â†’ `RuntimeLogger::report` |
| `ArticleEditorPerfDebug` / `ArticleEditorBootstrapSizer` | perf (khi `ARTICLE_EDITOR_PERF_DEBUG`) â†’ `RuntimeLogger` |

**Env (production):**

```env
WEB_APP_LOG_LEVEL=warning
WEB_APP_LOG_DAYS=14
# KhÃ´ng Ä‘á»•i LOG_CHANNEL vÃ¬ cron/queue
```

**Context log (nháº¹):** `user_id`, `route`, `path`, `method`, `request_id`, `article_id`. KhÃ´ng log body bÃ i, token, cookie, Authorization, WP credentials.

**Ops:** xem lá»—i editor â†’ `tail -f storage/logs/web-app-$(date +%F).log`. Cron váº«n Ä‘á»c `laravel.log` / `queue-cron.log` / `watchdog.log`.

**Test:** `tests/Unit/RuntimeLoggerWebAppChannelTest.php`.

**B. Save â€” Livewire** (`articleEditorLivewire.js`)


| Trigger                          | Livewire method                                                                                                      |
| -------------------------------- | -------------------------------------------------------------------------------------------------------------------- |
| `editor-html-collected`          | `persistArticleLocal`                                                                                                |
| `__seoExecuteHeavyArticleAction` | `executeHeavyArticleAction`                                                                                          |
| Sync shortcut                    | `syncArticleToWordPress`                                                                                             |
| SEO modal                        | `POST /api/seo/articles/{id}/seo-meta` â†’ `ArticleEditorSeoMetaService` (khÃ´ng Livewire; cháº¥m Ä‘iá»ƒm queue)            |
| Prompt Hooks (title / meta desc) | API `POST /api/seo/prompt-hooks/{hookKey}/execute`; UI: nÃºt AI title (`articleTitlePromptHook.js`) + nÃºt AI meta (`ArticleGoogleSerpPreview`); docs [prompt-hooks/README.md](prompt-hooks/README.md) |
| FAQ                              | `saveArticleFaqs`, `generateArticleFaqs`, `renewArticleFaq`, `checkFaqQuestionDuplicate`, `extractFaqsFromSelection` |
| AI image / snippet               | `generateArticleImageFromEditor`, `generateFeaturedSnippetFromEditor`                                                |
| AI video                         | `generateArticleVideoFromEditor`                                                                                     |
| Links                            | `searchInternalLinkArticles`                                                                                         |
| Assign keyword â†’ Content Project | `mountAction('assignKeywordAnchorToContentProject')` (`LinkEditBubble`) â†’ `completeKeywordAnchorContentProjectAssign()` â†’ `ArticlePendingInternalLinkService::assignFromEditor()` |
| Pending internal link event      | `pending-internal-link-ready` â†’ chÃ¨n placeholder `#hash` vÃ o anchor Ä‘Ã£ bÃ´i Ä‘en                                      |
| Reviews                          | `generateQuickPostReviews`, `refreshVirtualReviewsForEditor` â†’ event `virtual-reviews-updated`                       |
| Keyboard shortcuts               | Logic JS `articleEditorShortcuts.js` (`articleShortcutActionFromEvent` â†’ Save/Sync/Preview/SEO) váº«n hoáº¡t Ä‘á»™ng; **UI panel shortcuts Ä‘Ã£ gá»¡** (khÃ´ng cÃ²n `article-editor-shortcuts-rail` / `mountShortcutsBelowOutline`) |
| Sticky action header (Edit Article) | `data-seo-sticky-editor-header` trong `edit-article.blade.php`: Back + save status + nÃºt `!` draft (`data-seo-sticky-draft-alert`, bridge `articleEditorStickyHeader.js` / events `article-editor:draft-alert` + `article-editor:open-draft-choice`) + actions. Partial `article-editor-page-actions.blade.php`: **Save â†’ Sync WP â†’ Preview â†’ Approve â†’ More â†’ Help**. Help luÃ´n visible (`seo-article-editor-help-btn`, label `Help`), dispatch `article-editor:help-open` `{ topic: 'article-editor.overview' }` â€” khÃ´ng phá»¥ thuá»™c permission / React mount |
| Filament topbar (Edit Article only) | `EditArticle::getExtraBodyAttributes()` â†’ body class `article-editor-page`; CSS `body.article-editor-page .fi-topbar { display:none }` trong `article-edit-page.css`. KhÃ´ng áº©n topbar trang Filament khÃ¡c |
| Global Help modal                | `ArticleEditorHelpModal.jsx` mount 1 láº§n trong `article-editor.jsx`; marker `data-article-editor-help-modal` (host khi Ä‘Ã³ng); registry `help/articleEditorHelpTopics.js`; Esc Ä‘Ã³ng |
| FAQ entry point                  | Shortcode / Edit FAQ â†’ `article-editor:module-open` `{ module:'faq' }`. Compat: `seo-faq-panel-activate`. **KhÃ´ng** assistant tag FAQ; **khÃ´ng** accordion FAQ trong Links. Runtime FAQ = Vite `public/build` (`FaqModule` + `article-editor`); source JSX alone khÃ´ng Ä‘á»§ |
| Existing links scanner           | Pre-refactor `extractLinksFromBlocks` (`articleLinkScroll.js`) â†’ `existingLinkScanner.scanExistingLinksCompat` + debounce 750ms + `seo-editor-links-rescan-request`. Source = editor blocks, khÃ´ng DB body |
| Save conflict / force overwrite  | `ArticleContentConflictGuard`: `updated_at` lá»‡ch nhÆ°ng `expected_content_hash` khá»›p body â†’ cho qua. `SeoAccessControl::canForceArticleContentOverwrite()` â€” `actualRole` rank > `content_manager` (Owner/Admin map manager). `UpdateArticleContentAction` bá» conflict khi force. `ArticleEditorSyncController::save` ghi content trÆ°á»›c, `bundleApply` sau |
| Persist local (anti Lock wait) | `ArticleEditorPersistService`: `writeArticleRow` (UPDATE `articles` ngáº¯n) + `runAfterPersistSideEffects` (images/revision/keyword **sau** commit). `UpdateArticleContentAction::persistUnderShortRowLock` â€” TX chá»‰ quanh row write; retry InnoDB 1205/deadlock Ã—3; message thÃ¢n thiá»‡n khi váº«n timeout. Sync WP toast `wp_sync_blocked` thÆ°á»ng fail á»Ÿ bÆ°á»›c persist nÃ y (trÆ°á»›c enqueue) |
| Láº§n cuá»‘i lÆ°u (Content Project Run) | Max timestamp: `articles.last_manual_saved_at` / `last_synced_at` / `last_ai_content_at` via `ArticleLastContentChangeResolver` â†’ `ArticleLastContentChange` (`occurred_at` + `source`). Manual: `ArticleLastSavedTimestampService::touchManualSaved` (Save REST `origin=article_editor` + Livewire persist). Sync: `touchSynced` sau WP push/pull. AI body: `touchAiContent` sau `PromptTestPublishService::publishArticle` khi hash body Ä‘á»•i. Row status: `ContentProjectArticleRowStatusResolver` (active / fail / ignored_stale / manual_edit / completed). **KhÃ´ng** Ä‘á»¥ng `updated_at`; FAQ/meta/image-only / ignored_stale / fail khÃ´ng touch `last_ai_content_at` |
| Article Information author       | `publish-sidebar.blade.php`: Author tá»« `articles.user_id`; badge Â«Báº¡nÂ» náº¿u trÃ¹ng auth. Core bootstrap: `authorName` / `authorUserId` / `authorIsCurrentUser` |
| SEO keyword in meta              | `seoAnalyzer.js` + `SeoScoringEngine`: lowercase keyword trÆ°á»›c so `meta_description` (`keyword_missing_in_meta`) |
| Page action bar (Edit Article)   | CÃ¹ng partial sticky header â€” **khÃ´ng** cÃ²n action bar trÃ¹ng trong content. More = History, Prompts, Assign/Open project, Restore, Debug MD, Delete (+ Preview/Approve compact â‰¤1024px). `getHeaderActions()` trá»‘ng; `articleEditorHeaderActions.js` dedupe |
| Polylang                         | `quickTranslateLinkedArticle`, `importMissingTranslation`, `requestTranslationGeneration`                            |
| WP Attachment meta               | `renameAttachmentSlugsOnWordPress($items, $silent=false)`, `updateAttachmentMetaOnWordPress($items, $silent=false)` â€” bulk Fix all dÃ¹ng `$silent` + 1 toast client; sá»­a 1 áº£nh giá»¯ toast Filament |
| Gallery picker                   | `confirmGallerySelectionFromPicker` (multi-select â†’ album)                                                           |
| Outline                          | `rewriteOutlineFromWorkflow`                                                                                         |
| AI Prompt preview                | `previewGenerateArticleImagePrompt`                                                                                  |
| Debug                            | `importMarkdownDebug`, `importMarkdownFaqDebug` â€” MD import qua `ArticleMarkdownToHtmlService::prepareImport()` â†’ `ArticleMarkdownImportParser` (plain numbered meta/structure labels + allowlist) |
| Notification forwarding          | `handleEditorNotify` (Alpine â†’ Filament notification)                                                                |
| Slug                             | `confirmArticleSlug`                                                                                                 |
| SEO description                  | `updateSeoMetaDescriptionFromEditor`                                                                                 |


```mermaid
sequenceDiagram
    participant LW as EditArticle
    participant Alpine as edit-article.blade
    participant SE as SeoArticleEditor

    LW->>Alpine: collect-editor-html
    Alpine->>SE: getExportHtml()
    SE->>Alpine: editor-html-collected
    Alpine->>LW: persistArticleLocal / syncArticleToWordPress
```



**Dual save path:**

- **Path cÅ©:** Alpine event `editor-html-collected` â†’ Livewire `persistArticleLocal` / `syncArticleToWordPress`
- **Path má»›i (keyboard shortcut):** JS function `__seoExecuteHeavyArticleAction` â†’ `wire.executeHeavyArticleAction()` â€” dÃ¹ng cho Ctrl+S / Ctrl+Shift+S

**Overlay system (JS):** Blade `__seoArticleHeavyActionOverlay` (guard timer, keyboard blocker, `inert`, `persistUntilUnload`, custom `title`/`message`). **`articleOperationTracker.js`:** WP sync `queued`/`processing` â†’ `exitEditorAfterWordpressSyncQueued()` (Ä‘Ã³ng tab / `location.replace` Sync Queue) â€” **khÃ´ng** poll Elapsed trÃªn editor. Poll 2.5s chá»‰ cÃ²n cho op khÃ¡c (vd. media slug fix). Terminal non-WP: `success`/`failed`/`cancelled`/`stale`. Bootstrap F5 / Alpine init / `EditArticle::mount`: náº¿u cÃ²n active WP sync â†’ redirect queue, khÃ´ng khÃ³a overlay chá».

**Autosave / local draft (Phase 1 perf):** React â†’ debounce (`autosave_interval_seconds`, 0â€“30s, default 2) â†’ `localStorage` key `seo-editor:draft:{connection_hash}:{site}:{article_id}` schema v2 (`content` HTML + `content_hash` / `normalized_hash` / `base_*` / `version` / `dirty_fields` / `synced`). **KhÃ´ng** Livewire / server. Hydrate: `resolveLocalDraftDecision` tá»± chá»n báº£n gáº§n nháº¥t (normalize hash + timestamp); náº¿u localâ‰ server tháº­t â†’ giá»¯ offer + nÃºt `!` sticky; báº¥m `!` má»Ÿ modal chá»n láº¡i (KhÃ´i phá»¥c / Giá»¯ server / Bá» nhÃ¡p). Save/Sync: `cancel` debounce â†’ `clearDraft` â†’ `writeSyncedLocalSnapshot`. SEO analyze: **stale flag** when typing; full `runLocalSeoAnalysis` only on Analyze. Manual Save: REST + single-flight + conflict tokens (409 giá»¯ draft). **Lock:** `articleAutosaveLock.js` â€” `quick-fix-slug-all`, `article-operation`, `article-heavy-action`.

**Deferred modules:** Links/AI chat mount on panel open; Images/Reviews tab body after activation; product reviews WP fetch only when Reviews active; outline API only on `seo-outline-rail-opened` / interact â€” not on editor open.

**Tab HÃ¬nh áº£nh â€” Quick fix & Except** (`ArticleImagesTab.jsx` â†’ handlers trong `SeoArticleEditor.jsx`, utils `articleImagesUtils.js`):


| NÃºt                       | Pháº¡m vi                                            | HÃ nh vi                                                                                                                                                                                                                                                                                                                                               |
| ------------------------- | -------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Fix slug all**          | áº¢nh trong block (khÃ´ng Except) + supplemental-only | **Canonical:** [docs/article-editor/image-slug-rename.md](article-editor/image-slug-rename.md). LuÃ´n `saveCurrentArticleFromEditor` trÆ°á»›c + sau. WP: `renameAttachmentSlugsOnWordPress` + rewrite. Local: `POST .../fix-media-slugs` â†’ `SeoMediaArticleSlugFixService` tráº£ `renamed[]` exact map. Client: `applySlugRenameFinished` rewrite **má»i block HTML + TipTap setContent** + invalidate picker/gallery/Images; `clearDraft` + synced snapshot. KhÃ´ng chá»‰ rename PHP / khÃ´ng chá»‰ DOM `img.src`. |
| **Fix slug** (1 áº£nh)      | Má»™t dÃ²ng                                           | áº¢nh local Ä‘á»•i slug local ngay; áº£nh WP confirm rá»“i `renameAttachmentSlugsOnWordPress` (toast Filament). KhÃ´ng gate â€œpháº£i Sync WP trÆ°á»›câ€ trÃªn áº£nh local. |
| **Fix alt/title all**     | áº¢nh khÃ´ng Except                                   | `alt`+`title`=focus keyword. **Gá»™p batch:** 1 `updateSeoMediaMeta(items)` + tá»‘i Ä‘a 1 `updateAttachmentMetaOnWordPress` (chá»‰ WP chÆ°a sync qua SEO media) â†’ **1 toast** tá»•ng (khÃ´ng spam tá»«ng áº£nh). |
| **Fix alt/title** (1 áº£nh) | Má»™t dÃ²ng                                           | Confirm rá»“i patch block/supplemental + `pushAltTitleMetaToStores` (1 toast). |
| **Except**                | áº¢nh cÃ³ `blockId`                                   | Toggle `excludeQuickFix` trÃªn block image â†’ lÆ°u `localStorage` draft + `data-exclude-quick-fix="1"` trÃªn `<img>`/`<figure>`. **Tá»± Ä‘á»™ng Except** khi chá»n áº£nh tab **Gá»‘c (WP)** (`pickerTab === 'original'`, `withWpPickerExcludeQuickFix` trong `onEditorBlockImageSelected`). áº¢nh Except: disable Fix slug/alt; khÃ´ng tÃ­nh slug `-N`; khÃ´ng bá»‹ `finalizeBlocksAfterWpRename` ghi Ä‘Ã¨.                                                                                                    |
| **UI hÃ ng áº£nh**           | Má»—i dÃ²ng trong tab                                 | Chá»‰ nÃºt **Except** hiá»ƒn thá»‹ trá»±c tiáº¿p; thao tÃ¡c cÃ²n láº¡i gom menu `â‹¯`. **XÃ³a:** `resolveArticleImageRemoveTarget` â€” disable náº¿u áº£nh 404/stale khÃ´ng khá»›p block/supplemental (`image_tab_remove_unmatched_404`). XÃ³a block â†’ dá»n supplemental orphan cÃ¹ng identity. **404 load:** `brokenImageGuard.js` + thumb `onError` â†’ placeholder tÄ©nh (khÃ´ng retry). |

**Má»Ÿ Ä‘áº§u â€” khÃ´ng chÃ¨n áº£nh:** `BlockInsertMenuBar` (`BlockInsertMenu.jsx`) nháº­n `imageInsertDisabled={section.isIntro}` cho menu **trÆ°á»›c** vÃ  **sau** block (`SeoArticleEditor.jsx`). `ImageBlockEditor` `imagesLocked` khi block thuá»™c section intro.


Logic slug/index: `assignInArticleQuickFixIndices`, `quickFixSlugIndexForBlock`, `applyQuickFixSlugToBlocks` / `applyQuickFixAltTitleToBlocks` Ä‘á»u filter `!excludeQuickFix`.

**C. REST routes**


| Prefix                                  | Controller                                                   | Client                               |
| --------------------------------------- | ------------------------------------------------------------ | ------------------------------------ |
| `/api/seo/articles/{id}/outline*`       | `ArticleOutlineController`                                   | `ArticleOutlineTab`                  |
| `/api/seo/media/*`                      | `SeoMediaController`                                         | [MAP_SEO_MEDIA.md](MAP_SEO_MEDIA.md) |
| `/seo/articles/{article}/media-picker`  | `ArticleMediaPickerController`                               | Alpine `fetchPickerImages`           |
| `/api/ai/chat`                          | `GlobalAiChatController`                                     | `ArticleAiChatPanel`                 |
| `/api/seo/articles/{article}/save`      | `ArticleEditorSyncController::save`                          | `saveArticleViaApi`                  |
| `/api/seo/articles/{article}/sync-wp`   | `ArticleEditorSyncController::syncWp`                        | `syncArticleToWordPressViaApi`       |
| `/api/seo/articles/{article}/operation-status` | `ArticleEditorOperationController::status`            | `articleOperationTracker.js` (poll)  |
| `/api/seo/articles/{article}/fix-media-slugs` | `ArticleEditorOperationController::fixMediaSlugs`     | `fixArticleMediaSlugs` (`seoMediaApi.js`) |
| `/api/seo/articles/{article}/seo-meta`  | `ArticleEditorSyncController::saveSeoMeta`                   | `saveSeoMetaViaApi` (`ArticleGoogleSerpPreview`) |
| `/seo/articles/{article}/seo-preview`   | `ArticleSeoPreviewController`                                | `ArticleGoogleSerpPreview`           |
| `/seo/articles/{article}/preview`       | `ArticlePreviewController`                                   | Frontend preview                     |
| `/api/seo/articles/{article}/revisions` | `ArticleRevisionController` + `SeoArticleRevisionController` | Revision tab                         |
| `/seo/articles/{article}/revisions`     | `SeoArticleRevisionController`                               | Revision compare/restore             |


> **LÆ°u Ã½ route change:**
>
> - Media picker: `/api/seo/articles/{id}/media-picker` â†’ `/seo/articles/{article}/media-picker` (bá» `api/` prefix)
> - AI chat: `/api/seo/global-ai/chat` â†’ `/api/ai/chat` (bá» segment `seo/`)



### 2.5.4 Media picker modal (`.seo-article-media-modal`)

Alpine `x-data` trong `edit-article.blade.php` (wrapper trang, khÃ´ng `wire:ignore`).


| Trigger              | HÃ m                                                                                |
| -------------------- | ---------------------------------------------------------------------------------- |
| áº¢nh Ä‘áº¡i diá»‡n / album | `openArticleMediaModal('featured'                                                  |
| Editor block         | `seo-open-article-media-picker` â†’ `openArticleMediaModal('editor-block', blockId)` |


**Tabs:** `article` (catalog tá»« React), `original` / `local` (REST `GET .../media-picker?page&search=`), **custom WP search tabs** (client-only, sau tab Gá»‘c WP).

**Custom WP search tabs (Ä‘Ã£ implement):**

| ThÃ nh pháº§n | HÃ nh vi |
|------------|---------|
| NÃºt `+` sau **Gá»‘c (WP)** | `prompt` tá»« khÃ³a (máº·c Ä‘á»‹nh = focus keyword bÃ i viáº¿t) â†’ táº¡o tab `custom:{id}` |
| Tab custom | Fetch `tab=original&search={keyword}`, cache fetch + metadata trong `localStorage` (`articleMediaPickerCustomTabs.js`) |
| NÃºt `Ã—` trÃªn tab | XÃ³a tab + staged images + fetch cache cá»§a tab |
| NÃºt `â†—` trÃªn áº£nh tab Gá»‘c (WP) | Chá»n tab Ä‘Ã­ch â†’ lÆ°u táº¡m áº£nh vÃ o `localStorage` staged cá»§a tab Ä‘Ã³; hiá»ƒn thá»‹ Ä‘áº§u danh sÃ¡ch tab custom (badge Â«ÄÃ£ chuyá»ƒnÂ») |

**Giá»¯ state khi Ä‘Ã³ng/má»Ÿ (khÃ´ng refetch):**


| HÃ m                      | HÃ nh vi                                                                                                                                                 |
| ------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `openArticleMediaModal`  | Náº¿u `pickerWasOpened` hoáº·c `pickerImages.length > 0` â†’ chá»‰ `mediaModalOpen = true`, **return** (khÃ´ng `fetchPickerImages` / `loadArticleTabFromEditor`) |
| `closeArticleMediaModal` | Chá»‰ `mediaModalOpen = false` â€” giá»¯ `pickerImages`, `pickerSearchQuery`, `pickerPage`                                                                    |
| Láº§n má»Ÿ Ä‘áº§u               | Fetch bÃ¬nh thÆ°á»ng, set `pickerWasOpened = true`                                                                                                         |


Cache trang (khÃ´ng search): `articleMediaPickerCache.js` â†’ `localStorage`. Bootstrap bundle: `article-media-picker-cache-bootstrap`.

`article-media-picker-loaded` chá»‰ apply khi `mediaModalOpen === true`.

**Äá»“ng bá»™ tab WP â†’ tab HÃ¬nh áº£nh (Â§2.5.2):**


| BÆ°á»›c                         | HÃ nh vi                                                                                                                               |
| ---------------------------- | ------------------------------------------------------------------------------------------------------------------------------------- |
| Chá»n áº£nh tab `original` (WP) | `selectPickerImage` gá»­i `pickerTab: 'original'`; `withWpPickerExcludeQuickFix` â†’ `excludeQuickFix: true` + `data-exclude-quick-fix` qua `renderImageFigure` + draft `localStorage` |
| React                        | `SeoArticleEditor` cáº­p nháº­t block/supplemental, `publishEditorImagesCatalog()` â†’ event `seo-editor-images-catalog` (`autoSync: true`) |
| Tab HÃ¬nh áº£nh                 | `ArticleImagesTab` nháº­n `blocks` + `supplementalImages` má»›i; `imagesReloadKey++` khi nguá»“n WP                                         |
| Tab Â«Trong bÃ iÂ» (picker)     | Alpine láº¯ng `seo-editor-images-catalog` â†’ cáº­p nháº­t `pickerCatalog` náº¿u modal Ä‘ang má»Ÿ                                                  |


**Product Album Gallery:** Blade secondary Alpine `seoProductAlbumBoxData` (drag-reorder). Multi-select shift+click (`galleryPickerSelectedKeys`). `confirmGallerySelectionFromPicker` â†’ album. Hiá»‡n khi `supportsProductGalleryUi` (live `post_type=product`); article â†’ panel Featured Image thay album (xem má»¥c Featured image sidebar / Ä‘á»•i post_type).

**Polylang Widget:** Blade include `seo-polylang-widget` (line 1145). Livewire methods: `quickTranslateLinkedArticle`, `importMissingTranslation`, `requestTranslationGeneration`.

**Video Generation:** Event `generate-article-video`, Livewire method `generateArticleVideoFromEditor`, setting flag `can_generate_video`.

### 2.5.4.1 Assistant Dock â€” sidebar pháº£i Edit Article (Ä‘Ã£ implement)

Cá»™t pháº£i `edit-article.blade.php` dÃ¹ng **Alpine-only** (khÃ´ng Livewire round-trip cho tab/search). CSS trong `article-editor.css`; logic `utils/seoAssistantNavigator.js` (import tá»« `article-editor.jsx` â†’ `Alpine.data('seoAssistantNavigator')`).

| ThÃ nh pháº§n | File | Vai trÃ² |
|------------|------|---------|
| Host + slots | `edit-article.blade.php` | `.seo-assistant-host` â€” má»—i widget cÃ³ `data-assistant-widget`, `data-assistant-widget-id`, `data-assistant-tab-label` |
| Navigator | `seoAssistantNavigator.js` | `discoverWidgets({ preservePanel })`, `applyEditorPostType()`, `switchPanel()`, search, badge; nghe `seo-publish-post-type-changed`; **khÃ´ng** scroll-to-widget |
| Links filter | `ArticleLinksSidebar.jsx` | `linkSectionFilter` qua event `seo-assistant-link-section` (`links` / `faq` / `cta` / `all`). Gá»£i Ã½ keyword: 2 nÃºt **Cáº£nh bÃ¡o** / **Nguy hiá»ƒm** (`Mark needs optimization` / `Mark ineffective`) â†’ `openReviewPopover` â†’ `KeywordReviewPopover.jsx` â†’ `POST /api/seo/keywords/{id}/review` (`KeywordReviewController`, `source=article_suggestion`, khÃ´ng báº¯t buá»™c link map). Thiáº¿u `keyword_id` (thÆ°á»ng fallback phrase chÆ°a cÃ³ catalog): `POST /api/seo/keywords/ensure-for-review` (`ensureForReview`, upsert `TYPE_SUGGEST` + site meta) rá»“i má»Ÿ popover â€” khÃ´ng silent return. Fallback resolve `keyword_id` náº¿u phrase Ä‘Ã£ cÃ³ (`ArticleLinkSuggestionContentKeywordFallback::resolveKeywordIdForPhrase`). Keyword `review_status` warning/danger bá»‹ loáº¡i khá»i gá»£i Ã½ server (`ArticleInternalLinkSuggestionService`). **Gá»£i Ã½ Links:** `POST` suggestions + HTML live editor; primary = keyword catalog + `KeywordLinkTargetResolver` + `ArticleLinkSuggestionCandidateRetriever`; náº¿u internal há»£p lá»‡ &lt; target â†’ content-keyword fallback (phrase highlight/noun; search = `ArticleInternalLinkSearchService`). Stop phrase chung `LinkSuggestionStopPhraseFilter`. Score 0â€“100 (`LinkSuggestionScoreScale`). Debug: `LINK_SUGGESTION_DEBUG` â†’ `[LINK_FALLBACK_DEBUG]` + `suggestion_debug`. Internal chá»‰ URL cÃ¹ng `site_id`/domain; external/wiki â†’ External; `tel`/`mailto` khÃ´ng Ä‘áº¿m external (`articleLinkSuggestionFilter.js`). |
| Keywords dictionary tabs | `ListKeywords.php` + `KeywordResource::getReviewedDictionaryQuery()` | Tháº» **Cáº§n tá»‘i Æ°u** / **KhÃ´ng hiá»‡u quáº£** lá»c `review_status` warning/danger; scope site qua `forSite` hoáº·c `keyword_review_histories.article_id` (keyword Ä‘Ã¡nh dáº¥u tá»« editor chÆ°a cÃ³ link map váº«n hiá»‡n). |
| Portals React | `SeoArticleEditor.jsx` | `createPortal` â†’ `#seo-article-seo-assistant-root`, `#seo-article-image-assistant-root`, `#seo-article-links-root`, â€¦ |

**Tabs:** auto-discover tá»« DOM; chip áº£o **CTA** inject sau tab **Links** (cÃ¹ng slot `links`, filter section). **FAQ khÃ´ng cÃ²n chip** â€” má»Ÿ tá»« shortcode `[omi_faq]` / `article-editor:module-open`.

**Cháº¿ Ä‘á»™ hiá»ƒn thá»‹:**

| State | `panelFilterActive` | UI |
|-------|---------------------|-----|
| Máº·c Ä‘á»‹nh (load trang) | `false` | Táº¥t cáº£ widget xáº¿p chá»“ng nhÆ° sidebar cÅ© |
| Sau khi báº¥m tab dock | `true` | Chá»‰ panel `activePanel`; class `is-panel-filter` trÃªn host |

**Sticky (desktop â‰¥1024px):**

| Lá»›p | CSS | HÃ nh vi |
|-----|-----|---------|
| `.wp-article-edit-sidebar` | `position: sticky` + `max-height` viewport | Cá»™t pháº£i dÃ­nh khi scroll bÃ i dÃ i |
| `.wp-article-edit-sidebar-scroll` | `overflow-y: auto` | Scroll ná»™i bá»™ widget |
| `.seo-assistant-dock` | `position: sticky; top: 0` | Tab bar + search luÃ´n trÃªn cÃ¹ng vÃ¹ng scroll |

**Custom events (dock â†” React):**

| Event | Publisher | Subscriber |
|-------|-----------|------------|
| `seo-assistant-switch-panel` | `SeoArticleEditor` (má»Ÿ tab áº£nh), â€¦ | `seoAssistantNavigator` â†’ `switchPanel()` |
| `seo-assistant-navigator-badges` | `SeoArticleEditor`, `ArticleLinksSidebar` | Cáº­p nháº­t badge tab (SEO, Images, **Reviews** `{count}` ká»ƒ cáº£ 0, Links, CTA) â€” **khÃ´ng** badge FAQ |
| `virtual-reviews-updated` | `EditArticle::generateQuickPostReviews`, `refreshVirtualReviewsForEditor` | `ArticleReviewsTab`, `SeoArticleEditor` â€” Ä‘á»“ng bá»™ danh sÃ¡ch + count |
| `seo-assistant-link-section` | `seoAssistantNavigator` | `ArticleLinksSidebar` filter section |
| `seo-assistant-widget-control` | `seoAssistantNavigator` | React widgets (`set-collapsed`) |
| `seo-sidebar-open-publish-tab` | Widget xuáº¥t báº£n / shortcut | Má»Ÿ panel Publishing |
| `seo-publish-post-type-changed` | `publish-sidebar` `applyPostType()` | Navigator (Ä‘á»•i Featured/Album/Reviews), `SeoArticleEditor` (`supportsProductGallery` state), categories |

**LÆ°u Ã½ perf:** badge chá»‰ cáº­p nháº­t qua event â€” khÃ´ng dÃ¹ng `MutationObserver` + `characterData` trÃªn subtree sidebar (gÃ¢y freeze khi React SEO render).

**Reviews / Táº¡o bÃ¬nh luáº­n nhanh:**

| ThÃ nh pháº§n | File | Vai trÃ² |
|------------|------|---------|
| UI panel | `ArticleReviewsTab.jsx` | Status: real/generated/pending/reviewed + **Target count** / **Missing**; Refresh / Create / Sync |
| Policy | `ProductReviewCreationPolicy` | Idempotent: maintain `target_count` AI reviews; reasons: `not_product`, `wordpress_real_reviews_exist`, `target_count_reached`, â€¦ |
| Settings | `ProductReviewAutomationSettingsResolver` | Äá»c `target_count` tá»« Automation Rule action; Manual Sync + editor API dÃ¹ng chung |
| Status | `WordPressProductReviewStatusService` + `GET .../product-review-status` | WP SoT; real vs generated tá»« meta `source=seo_content_ai` / `generated` |
| Create/Sync API | `POST .../product-reviews/create` + `.../sync` | Backend re-check policy; `ArticleWordPressBusinessSequence` |
| Automation | `product-review.create` + `product-review.sync-wp` | Linear trÃªn rule `article > wordpress` (sau `wordpress.article.sync`) |
| Store | `ArticleProductReviewStoreService` / `ProductReviewLocalBatchCreator` | Local pending only; lifecycle `pendingâ†’syncingâ†’reviewed` |
| Reviewed cleanup | `ProductReviewPendingRepository::deleteLocalForArticle` | `markArticleReviewed` xÃ³a **toÃ n bá»™** local review; khÃ´ng auto-gen |
| Livewire | `EditArticle::generateQuickPostReviews()` | `ArticleQuickPostReviewService` (manual quick create only â€” **khÃ´ng** gá»i sau Reviewed) |
| WP plugin | `Virtual_Comments` + REST (â‰¥ 1.0.59) | Meta `_omi_seo_virtual_comments`; generated metadata `_omi_*` |
| Legacy | schedule/queue/publish rules + delayed job | deprecated + hidden + no-op |

### 2.5.5 Publish sidebar â€” lÃªn lá»‹ch & SEO score (gap / cáº§n sá»­a)

> LiÃªn quan cron publish: [Â§2.6.3](#263-tráº¡ng-thÃ¡i-Ä‘Äƒng-bÃ i--lÃªn-lá»‹ch). Settings Ä‘á»™ dÃ i bÃ i: **SEO â†’ Settings â†’ Prompt** â†’ *Article content rules*.

#### C. Äá»“ng bá»™ WordPress qua queue + tab Publish (Ä‘Ã£ implement)

| ThÃ nh pháº§n | File | HÃ nh vi |
|------------|------|---------|
| Lease SoT | `seo_article_wp_sync_jobs` + `ArticleWpSyncLeaseService` | Claim TX ngáº¯n â†’ `processing` + `locked_until` (+2m); heartbeat qua `WpSyncLeaseHeartbeat` / `WordPressGateway`; terminal: `completed`/`failed`/`cancelled`/`stale`; article `wp_sync_status`/`wp_sync_job_id`; **`markStale` â†’ `maybeAutoRetryAfterStale`** (max `MAX_STALE_AUTO_RETRIES=3`, settings `stale_auto_retries`; force unlock táº¯t retry) |
| Queue meta (projection) | `ArticleWpSyncQueueService` (`article_meta.wp_sync_queue`) | Mirror lease; heal orphan pending/processing khÃ´ng cÃ³ lease / lease háº¿t háº¡n / pending khÃ´ng cÃ²n row `jobs`; `isActive` sau stale-auto-retry coi job má»›i lÃ  active |
| Manual job | `ManualWordPressSyncJob` | Queue `seo` + `syncJobId`; claim â†’ heartbeat â†’ complete/fail; `failed()` nháº£ lease; source `stale_auto_retry` khi watchdog/heal tá»± enqueue |
| Watchdog | `seo:wordpress-sync-lease-watchdog` (`WordpressSyncLeaseWatchdogCommand`) | Schedule má»—i phÃºt; `--article=` / `--force` (khÃ´ng auto-retry); stale lease + orphan meta + `cache_locks` |
| Enqueue | `WordPressManualSyncService` | `Cache::lock` + `isActive` (force-stale expired); dedupe theo `request_id` |
| API | `ArticleEditorSyncController::syncWp` | Save trÆ°á»›c â†’ enqueue; `queued: true`; overlay giá»¯ + poll |
| Operation UI | `articleOperationTracker.js` + `finishArticleSyncFromApi` | Poll `operation-status`; attempt/worker/elapsed; Retry khi failed/stale |
| Tab Publish | `publish-sync-panel.blade.php` | Checkbox **ÄÄƒng ngay** â†’ Laravel `published` + sync WP `publish` (khÃ´ng +5 phÃºt / khÃ´ng WP schedule); lá»‹ch tÃ¹y chá»‰nh khi uncheck chá»‰ áº£nh hÆ°á»Ÿng Laravel |
| NÃºt Ä‘á»“ng bá»™ CSS | `article-editor.css` â†’ `.seo-publish-sync-btn` | Primary full-width; dark mode `.dark .wp-article-edit â€¦` (khÃ´ng dÃ¹ng Tailwind utility trong Blade) |
| Widget Xuáº¥t báº£n | `publish-sidebar.blade.php` | Bá» UI lÃªn lá»‹ch; icon sync chá»‰ má»Ÿ tab Publish (`seo-sidebar-open-publish-tab`). **Article Information** hiá»‡n Author (`articles.user_id`) + badge Â«Báº¡nÂ» náº¿u trÃ¹ng auth |
| Shortcut | `Ctrl+Shift+S` | `seo-publish-tab-request-sync` â†’ tab Publish + queue sync |
| Submenu Articles | `ListArticleSyncQueue` (`/seo/{connection_hash}/articles/queue`) | Sidebar **Articles â†’ HÃ ng Ä‘á»£i** |
| Tab nhanh list | `ListArticles::TAB_QUEUE` (`?tab=queue`) | Unfinished: pending/processing/failed/stale; `is_reviewed = 0`; badge count + CSS ná»•i báº­t (`seo-internal-tabs__queue-badge`) |
| Queue table | `ArticleResource::queueTable()` | Cá»™t: tiÃªu Ä‘á», domain, tráº¡ng thÃ¡i, queued/started/finished, lá»—i; filter tráº¡ng thÃ¡i; retry / cancel / edit |

**Luá»“ng lease + meta (`wp_sync_queue`):**

| Tráº¡ng thÃ¡i | Ã nghÄ©a |
|------------|---------|
| `pending` | ÄÃ£ enqueue, chá» worker claim |
| `processing` | ÄÃ£ claim; heartbeat gia háº¡n `locked_until` |
| `completed` | Äá»“ng bá»™ xong (meta giá»¯ láº¡i Ä‘á»ƒ theo dÃµi) |
| `failed` | Lá»—i â€” Retry / queue list |
| `cancelled` | User Reset/Cancel â€” article idle |
| `stale` | Watchdog / heal: worker cháº¿t, pending khÃ´ng cÃ³ `jobs` row, orphan meta; **tá»± enqueue láº¡i tá»‘i Ä‘a 3 láº§n** (`stale_auto_retries`); háº¿t â†’ error `(auto-retry exhausted n/3)` |

**Client sau enqueue:** `finishArticleSyncFromApi` â€” **giá»¯** overlay (`persistUntilUnload`), poll `operation-status`, reload khi terminal (trá»« failed giá»¯ Retry). Event `article-wordpress-sync-queued` (khÃ´ng unlock).

```mermaid
flowchart LR
    UI["Tab Publish â†’ Äá»“ng bá»™"]
    API["POST /sync-wp"]
    META["article_meta.wp_sync_queue"]
    JOB["ManualWordPressSyncJob"]
    SEQ["ArticleWordPressBusinessSequence"]

    UI --> API --> META --> JOB --> SEQ
```

#### A. Tráº¡ng thÃ¡i lÃªn lá»‹ch â€” reconcile khi load trang (Ä‘Ã£ implement)

**Triá»‡u chá»©ng (Ä‘Ã£ sá»­a):** Sidebar **Xuáº¥t báº£n** tá»«ng hiá»ƒn thá»‹ `BÃ i lÃªn lá»‹ch: â€¦` dÃ¹ bÃ i Ä‘Ã£ **Published** vÃ  `published_at` quÃ¡ háº¡n.

**Implementation:**

| ThÃ nh pháº§n | File | HÃ nh vi |
|------------|------|---------|
| Reconcile | `ArticleScheduleReconcileService::reconcileForEditor()` | `scheduled` + `published_at â‰¤ now()` â†’ WP publish náº¿u cÃ³ `wp_post_id`, else `status=published` local |
| SSR hydrate | `EditArticle::hydrateArticleState()` | Gá»i reconcile sau `record.refresh()` |
| Label lá»‹ch | `getPublishWhenLabel()` | Chá»‰ format khi `status === scheduled` |
| Sidebar | `publish-sidebar.blade.php` | `x-show="status === 'scheduled'"`; published hiá»‡n Â«NgÃ y Ä‘ÄƒngÂ»; `applyStatus()` xÃ³a `publishWhenLabel` |
| Cron publish | `seo:publish-scheduled-articles` | Váº«n cháº¡y theo schedule (bá»• sung, khÃ´ng thay reconcile on load) |

```mermaid
flowchart TD
    LOAD["EditArticle mount / hydrateArticleState"]
    CHECK{"status = scheduled<br/>AND published_at â‰¤ now?"}
    RECON["publishScheduledArticle()<br/>hoáº·c sync status tá»« WP"]
    REFRESH["record.refresh()<br/>syncPublishDatePartsFromRecord()"]
    LABEL["Cáº­p nháº­t publishWhenLabel<br/>theo status má»›i"]
    HIDE["status â‰  scheduled â†’<br/>áº©n / reset label lá»‹ch"]

    LOAD --> CHECK
    CHECK -->|cÃ³| RECON --> REFRESH --> LABEL
    CHECK -->|khÃ´ng| HIDE
```

**Files cáº§n cháº¡m khi implement:** `EditArticle.php` (`hydrateArticleState`, `getPublishWhenLabel`), `publish-sidebar.blade.php` (`init`, Ä‘iá»u kiá»‡n `x-show` dÃ²ng lá»‹ch), cÃ³ thá»ƒ tÃ¡ch `ArticleScheduleReconcileService`.

#### B. SEO score â€” Â«Content lengthÂ» theo Article content rules

**ÄÃ£ triá»ƒn khai:** rule *Content length* cháº¥m **pass/fail** (+15 hoáº·c 0), khÃ´ng cÃ²n partial 10 Ä‘iá»ƒm.

| Äiá»u kiá»‡n | Äiá»ƒm (`MAX_LENGTH = 15`) |
|-----------|--------------------------|
| `wordCount >= target` | +15 (`seo.length.pass`) |
| `wordCount < target` | 0 (`seo.length`) â€” máº¥t trá»n 15 Ä‘iá»ƒm |

**Target** láº¥y tá»« **SEO â†’ Settings â†’ Prompt â†’ Article content rules**, theo `post_type` bÃ i:


| Post type | Setting key | Máº·c Ä‘á»‹nh |
|-----------|-------------|----------|
| `product` | `article_length_product` | 1000 |
| CÃ²n láº¡i (`article`, `page`, â€¦) | `article_length_default` | 2000 |

Parser láº¥y sá»‘ nguyÃªn Ä‘áº§u tiÃªn trong chuá»—i setting (`SeoPromptSettingsService::parseArticleLengthTarget`).

**Luá»“ng:**


| Layer | File |
|-------|------|
| Settings | `SeoPromptSettingsService::resolveArticleLengthTarget()` |
| Bootstrap editor | `EditArticle::getEditorSettingsPayload()` â†’ `article_length_product`, `article_length_default` |
| Scorer client | `seoAnalyzer.js` â†’ `resolveArticleLengthTarget(postType, settings)` |
| Scorer server | `SeoEngineService::scoreLength($html, $target)` â€” context `article_length_target` |
| Backend analyze | `SeoAnalyzerService`, `ArticlesOptimal` truyá»n target theo `ArticlePostTypeResolver` â€” chi tiáº¿t [MAP_SEO_AUDIT.md](MAP_SEO_AUDIT.md) |
| i18n | `lang/{vi,en}/seo.php` â€” `:count/:target` |

---



## 5. Frontend cluster: React Editor

### 5.0 Sticky header + Help (Edit Article only)

| Má»¥c | Chi tiáº¿t |
|-----|----------|
| Body class | `article-editor-page` via `EditArticle::getExtraBodyAttributes()` (+ page class) |
| áº¨n Filament topbar | Chá»‰ `body.article-editor-page .fi-topbar` â€” domain/user/lang/notify áº©n trÃªn editor |
| Sticky header | `seo-article-editor-sticky-header` / `data-seo-sticky-editor-header` â€” `position:sticky; top:0; z-index:40` |
| Save status | Event `article-editor:save-status` tá»« `SeoArticleEditor` â†’ `articleEditorStickyHeader.js` |
| Help modal | `ArticleEditorHelpModal` + registry `ARTICLE_EDITOR_HELP_TOPICS`; event `article-editor:help-open` |
| Shortcuts UI | ÄÃ£ gá»¡ panel; giá»¯ `articleEditorShortcuts.js` |
| Test | `tests/Unit/ArticleEditorStickyHeaderHelpTest.php` |

### 5.1 CÃ¢y component (cluster 528 members)

```mermaid
flowchart TB
    ENTRY["article-editor.jsx"]

    subgraph Main["SeoArticleEditor.jsx"]
        BLOCK["BlockEditor / ActiveBlockEditor"]
        OUTLINE_REQ["outlineApiRequest()"]
    end

    subgraph Tabs["Tab Components"]
        FAQ["ArticleFaqEditor"]
        LINKS["ArticleLinksSidebar"]
        OUTLINE["ArticleOutlineTab"]
        IMAGES["ArticleImagesTab"]
        REVIEWS["ArticleReviewsTab"]
        DOMAIN["ArticleDomainWidgetsSidebar"]
    end

    subgraph Utils["JS Utils"]
        MEDIA_API["seoMediaApi.js"]
        LIVEWIRE["articleEditorLivewire.js"]
        STORAGE["articleEditorStorage.js"]
        SEO_ANALYZER["seoAnalyzer.js"]
    end

    ENTRY --> Main --> Tabs & Utils
    MEDIA_API --> SeoMediaController
    OUTLINE_REQ --> ArticleOutlineController
    LIVEWIRE --> EditArticle
```





### 5.2 API surface tá»« frontend


| Module                           | Endpoints / bridge                   |
| -------------------------------- | ------------------------------------ |
| `seoMediaApi.js`                 | [MAP_SEO_MEDIA.md](MAP_SEO_MEDIA.md) |
| `outlineApiRequest` / `ArticleOutlineTab.requestJson` | `GET/POST/PUT/DELETE .../outline*` â€” `Accept: application/json` + `seoArticleApiHeaders()` (`X-SEO-Connection`); truncate `heading_text` 255 (khá»›p DB/`Str::limit`); cháº·n id `pending-*` trÆ°á»›c PUT |
| `articleEditorLivewire.js`       | Livewire save/sync (khÃ´ng REST)      |
| `articleFeaturedImageStorage.js` | Livewire featured image + event `seo-featured-image-updated` cho sidebar Alpine |
| `articleWpCategoriesStorage.js`  | Livewire categories                  |


**Hybrid:** REST cho media + outline; Livewire cho persist bÃ i + sync WP.

---

## 2.6 Quy trÃ¬nh Ä‘á»“ng bá»™ WordPress (Ä‘áº§y Ä‘á»§)

> Chi tiáº¿t service/HTTP: [MAP_SEO_WP.md](MAP_SEO_WP.md). Media trong body: [MAP_SEO_MEDIA.md](MAP_SEO_MEDIA.md).

### 2.6.1 Hai hÆ°á»›ng Ä‘á»“ng bá»™

| HÆ°á»›ng | Entry | Hub |
|-------|-------|-----|
| **Outbound** (SEO â†’ WP) | NÃºt Sync editor, list/queue/scheduled, Business Hook `wordpress.article.sync` (rule enabled) | `WordPressArticleSyncService` â€” **khÃ´ng** tá»« Content Project workflow táº¡o bÃ i |
| **Inbound** (WP â†’ SEO) | Plugin push `POST /api/seo-wp-bridge/push-content` | `SyncDomainContentService` |

### 2.6.2 Outbound tá»« EditArticle

```mermaid
sequenceDiagram
    participant UI as Publish sidebar / Ctrl+Shift+S
    participant Alpine as edit-article.blade
    participant SE as SeoArticleEditor
    participant LW as EditArticle
    participant SYNC as WordPressArticleSyncService
    participant MEDIA as WordPressLocalMediaSyncService
    participant WP as WP editor-sync REST

    UI->>Alpine: sync / executeHeavyArticleAction
    Alpine->>SE: getExportHtml()
    SE->>Alpine: editor-html-collected
    Alpine->>Alpine: __seoRunWordPressPhasedSync (4 bÆ°á»›c overlay)
    Alpine->>LW: syncWpPhaseSaveLocal
    Note over LW: skip náº¿u fingerprint local khá»›p
    Alpine->>LW: syncWpPhasePreparePayload
    LW->>SYNC: ensureWordPressPost + prepareEditorSyncPayload
    SYNC->>MEDIA: syncHtml â€” áº£nh local â†’ WP URL
    Alpine->>LW: syncWpPhaseEditorSync
    Note over LW,SYNC: skip náº¿u ná»™i dung WP chÆ°a Ä‘á»•i
    LW->>SYNC: executeEditorSyncRequest
    SYNC->>WP: POST /posts/{id}/editor-sync
    Alpine->>LW: syncWpPhaseFinalize
    LW->>SYNC: completeEditorSyncResponse
    SYNC->>SYNC: featured/gallery, WebP backfill, permalink
    LW->>LW: refreshSlugAndPermalinkFromWordPress, reload editor
```

**Livewire entry:** `EditArticle::syncArticleToWordPress()` â€” gá»i `__seoRunWordPressPhasedSync` (Alpine) thay vÃ¬ má»™t request `syncForArticle` monolithic.

**4 bÆ°á»›c overlay** (`edit-article.blade.php` â†’ `__seoRunWordPressPhasedSync`):

| # | Livewire | MÃ´ táº£ | Skip khi |
|---|----------|-------|----------|
| 1 | `syncWpPhaseSaveLocal` | LÆ°u local + SEO analyzer | Fingerprint `META_WP_LOCAL_SAVE_FINGERPRINT` khá»›p, featured khÃ´ng Ä‘á»•i |
| 2 | `syncWpPhasePreparePayload` | Táº¡o/link WP post + `syncHtml` upload áº£nh | â€” |
| 3 | `syncWpPhaseEditorSync` | `editor-sync` content/FAQ/SEO | `shouldSkipEditorSyncRequest` â€” khÃ´ng sá»­a local + fingerprint/meta content khá»›p |
| 4 | `syncWpPhaseFinalize` | Featured, dirty media, WebP backfill, permalink | â€” |

CSS bÆ°á»›c: `article-edit-page.css` â€” `.seo-article-sync-overlay__steps`.

**Payload editor-sync (post/product):** `title`, `slug`, `status`, `post_date`, `post_type`, `post_content`, `faqs`, `seo`, `category_ids`. Khi `faqs` rá»—ng tháº­t trÃªn Laravel â†’ thÃªm `clear_faqs: true` (`WordPressArticleSyncService::buildEditorSyncPayload`) Ä‘á»ƒ WP Ä‘Æ°á»£c phÃ©p xÃ³a meta.

### 2.6.3 Tráº¡ng thÃ¡i Ä‘Äƒng bÃ i & lÃªn lá»‹ch

| Tráº¡ng thÃ¡i Laravel (`articles.status`) | Khi **Ä‘á»“ng bá»™** lÃªn WP | Ghi chÃº |
|----------------------------------------|------------------------|---------|
| `draft` / `published` / `private` / `scheduled` | **`publish`** + `post_date` (clamp â‰¤ now) | Outbound chá»‰ má»™t status: publish |
| `trash` / `deleted` | (khÃ´ng gá»­i status) | KhÃ´ng Ä‘á»¥ng WP |

**LÃªn lá»‹ch chá»‰ trÃªn Laravel** â€” khÃ´ng Ä‘áº·t WP `future` / `draft` khi sync:

1. Editor: `status=scheduled` + `published_at` tÆ°Æ¡ng lai (local).
2. Sync thá»§ cÃ´ng / queue â†’ WP nháº­n **`publish`** (`resolveWordPressStatusPayload`; `applyPublishImmediatelyToBundle` Ã©p Laravel `published` khi ÄÄƒng ngay).
3. **Cron** `seo:publish-scheduled-articles` â†’ `ScheduledArticlePublishRunner` â†’ `publishScheduledArticle()` khi `published_at <= now()` (retry náº¿u lá»—i).
4. Plugin â‰¥ 1.0.57: cháº·n demote publishâ†’draft; clamp `post_date` tÆ°Æ¡ng lai; elevate admin + `force_post_status`.

```mermaid
flowchart LR
    subgraph Editor
        SCH["Laravel scheduled<br/>published_at tÆ°Æ¡ng lai"]
    end

    subgraph Sync
        WP_PUB_NOW["WP: publish<br/>(clamp post_date)"]
    end

    subgraph Cron["Laravel schedule má»—i phÃºt"]
        CMD["seo:publish-scheduled-articles"]
        RUN["ScheduledArticlePublishRunner"]
        PUB["publishScheduledArticle()"]
    end

    SCH -->|"Sync thá»§ cÃ´ng"| WP_PUB_NOW
    SCH -->|"Ä‘áº¿n giá»"| CMD --> RUN --> PUB --> WP_PUB_NOW
    PUB -->|"OK"| LAR["Laravel: published"]
```

**Inbound tá»« WP:** `SyncDomainContentService` váº«n map `future` â†’ `scheduled` khi pull. Outbound **khÃ´ng** táº¡o `future` / demote `draft` trÃªn WP.

### 2.6.4 CÃ¡c bÆ°á»›c xá»­ lÃ½ Ä‘á»“ng bá»™ (phased)

**UI:** 4 bÆ°á»›c overlay (xem sequence diagram Â§2.6.2). **Backend tÆ°Æ¡ng Ä‘Æ°Æ¡ng** `syncForArticle` khi gá»i má»™t láº§n:

| BÆ°á»›c UI | Service / method | MÃ´ táº£ |
|---------|------------------|--------|
| 1 | `syncWpPhaseSaveLocal` | `persistArticleLocalSilent`, SEO analyzer, fingerprint local |
| 2 | `prepareEditorSyncPayload` | Sanitize, CTA, FAQ; **`syncHtml`** upload áº£nh body |
| 3 | `executeEditorSyncRequest` | HTTP `editor-sync` (cÃ³ thá»ƒ skip) |
| 4 | `completeEditorSyncResponse` | Featured/gallery, dirty media, WebP backfill, flags, timestamp |

Chi tiáº¿t trong `syncForArticle` / `buildEditorSyncPayload`:

| BÆ°á»›c | Service | MÃ´ táº£ |
|------|---------|--------|
| â€” | `ArticleEditorHtmlSanitizeService` | Chuáº©n hÃ³a HTML trÆ°á»›c khi Ä‘áº©y |
| â€” | `ArticleCtaPlaceholderService` | Thay CTA placeholder |
| â€” | `WorkflowParserService` + `FaqHeadingMatcher` | FAQ detect theo `faq_catch_keywords` â†’ shortcode `[omi_faq]` |
| 2 | `WordPressLocalMediaSyncService::syncHtml` | Upload áº£nh local trong body, thay URL (dedupe `seo_media.id`) |
| 3 | HTTP `editor-sync` | Title, slug, status, content, FAQ, SEO meta |
| 4 | `ArticleMediaLocalService` | Featured + product gallery pending |
| 4 | `WordPressLocalMediaSyncService::syncDirtyLocalMediaForArticle` | Ghi Ä‘Ã¨ áº£nh Ä‘Ã£ edit |
| 4 | `syncWebpBackfillMediaForArticle` | Chá»‰ khi sibling `.webp` local OK â€” [MAP_SEO_MEDIA.md](MAP_SEO_MEDIA.md) |
| 4 | `syncPromptMediaLinksToWordPressUrls` | Cáº­p nháº­t link prompt AI |
| 4 | `ArticleWordPressSyncFlagService::clearAll` | XÃ³a cá» pending sync |
| 4 | `WordPressArticleTimestampService` | Äá»“ng bá»™ timestamp WP |

Sau sync thÃ nh cÃ´ng: `body` Laravel cÃ³ thá»ƒ set `null` (ná»™i dung authoritative trÃªn WP); editor reload tá»« WP khi cáº§n.

### 2.6.5 Attachment & meta (khÃ´ng qua full sync)

| Livewire method | Service | Khi dÃ¹ng |
|-----------------|---------|----------|
| `renameAttachmentSlugsOnWordPress` | `WordPressAttachmentRenameService` + `SeoMediaUrlReplacementService` | Fix slug all (`silent`) / tá»«ng áº£nh â€” enrich `renamed[]` vá»›i `block_id`/`old_url`; **rewrite Laravel body/meta** theo `old_urlâ†’new_url` (stem WP `-WxH`) trÆ°á»›c event finished |
| `updateAttachmentMetaOnWordPress` | `WordPressAttachmentMetaUpdateService` | Fix alt/title â€” bulk 1 láº§n / batch; `$silent` khi client tá»± toast |

### 2.6.6 Entry points khÃ¡c (ngoÃ i editor)

| Nguá»“n | File |
|-------|------|
| Workflow publish | `TaskWorkflowTestRunner`, `PromptTestPublishService` |
| Duyá»‡t project | `SeoProjectApprovalService` |
| List articles | `ArticleResource` actions |
| Skip SEO audit | `ArticlesOptimal::skipSeoAudit` â†’ `article_meta.skip_seo_audit` â€” xem [MAP_SEO_AUDIT.md](MAP_SEO_AUDIT.md) |

### HÆ°á»›ng dáº«n prompt â€” Ä‘á»“ng bá»™ tá»« editor

```
Sync hub: Services/WordPressArticleSyncService.php (syncForArticle, prepareEditorSyncPayload, executeEditorSyncRequest, completeEditorSyncResponse).
Queue: ArticleWpSyncLeaseService + `seo_article_wp_sync_jobs` (SoT) + ArticleWpSyncQueueService (`QUEUE_NAME=seo`, meta projection) + ManualWordPressSyncJob; watchdog `seo:wordpress-sync-lease-watchdog`; POST sync-wp enqueue (ArticleEditorSyncController).
UI: publish-sync-panel.blade.php (.seo-publish-sync-btn trong article-editor.css); submenu ListArticleSyncQueue /seo/articles/queue.
Scheduled cron: Console/PublishScheduledArticlesCommand.php + Services/ScheduledArticlePublishRunner.php.
HTML/media: WordPressLocalMediaSyncService, ArticleMediaLocalService, SeoImageOptimizationService.
WP REST: posts/{id}/editor-sync (plugin omi-seo-ai-bridge â‰¥ 1.0.50).
Worker: php artisan queue:work --queue=seo,media_generation,default --timeout=360 (ops only â€” Queue Manager UI /seo/.../queue-manager Ä‘Ã£ gá»¡)
```

### HÆ°á»›ng dáº«n prompt â€” React Editor

```
Hub: resources/js/components/SeoArticleEditor.jsx
Entry: resources/js/article-editor.jsx
Livewire: resources/js/utils/articleEditorLivewire.js
Blade: edit-article.blade.php (Alpine media modal + Assistant Dock seoAssistantNavigator + $wire events)
Outline: ArticleOutlineTab.jsx â†’ ArticleOutlineController (`requestJson`/`outlineApiRequest` + tenant headers; `heading_text` truncate 255; PUT cháº·n `pending-*`)
Quick-fix toast: Fix alt/title all + Fix slug all = 1 toast batch (`quickFixAltTitleAllImages` / `quickFixSlugAllImages`)
```

### AI image placeholder â†’ replace (editor)

| ThÃ nh pháº§n | Vai trÃ² |
|------------|---------|
| `requestGenerateArticleImage` | ChÃ¨n placeholder client (`awaitingServer`) â†’ `callEditArticleLivewire('generateArticleImageFromEditor')` â†’ gáº¯n `seoMediaId` + poll tá»« return / `ai-jobs` (khÃ´ng chá»‰ Livewire event) |
| `generateImageInFlightRef` | KhÃ³a client â€” cháº·n double-click táº¡o nhiá»u job |
| `onArticleAiImageGenerated` | Completed trÆ°á»›c `refBlockId`; bind `awaitingServer`; `applyCompletedMediaToPlaceholder` |
| `startMediaStatusPolling` | `fetchSeoMediaStatus` â€” chá»‰ dá»«ng khi apply thÃ nh cÃ´ng; bá» qua URL `placeholder-loading` |
| `useEffect` reconcile | `fetchArticleAiMediaJobs` má»—i 8s khi cÃ²n block `isProcessing` |
| `article-editor.jsx` | `normalizeLivewireEventDetail` + `mergeLivewireForwardArgs` cho `article-ai-image-generated` |

