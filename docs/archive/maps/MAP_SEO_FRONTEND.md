> Status: Historical
> Not canonical
> Archived on: 2026-08-01
> Superseded by: ../../modules/ARTICLE_EDITOR.md
> Purpose: implementation history only
# SeoContentAi â€” Báº£n Ä‘á»“ Frontend (React / Vite / Alpine)

[â† Quay láº¡i Báº£n Ä‘á»“ tá»•ng](SUPER_MAP_INDEX.md)

**LiÃªn quan backend:** [React Editor](MAP_SEO_EDITOR.md) Â· [Media / Watermark](MAP_SEO_MEDIA.md) Â· [Content Projects](MAP_SEO_PROJECTS.md) Â· [Settings & AI](MAP_SEO_SETTINGS.md) Â· [Audit / List](MAP_SEO_AUDIT.md)

> QuÃ©t tá»« `vite.config.js` + toÃ n bá»™ `app/Addons/SeoContentAi/resources/js/` (123 file, cáº­p nháº­t 2026-07-10).  
> Alias Vite: `@seo-addon` â†’ `app/Addons/SeoContentAi/resources/js`.  
> **LÆ°u Ã½:** `FRONTEND_MAP_RAW.txt` khÃ´ng cÃ³ trong repo â€” cÃ¢y thÆ° má»¥c dÆ°á»›i Ä‘Ã¢y Ä‘Æ°á»£c sinh trá»±c tiáº¿p tá»« filesystem.

---

## 1. Tá»•ng quan kiáº¿n trÃºc Frontend SEO

```mermaid
flowchart TB
    subgraph Vite["vite.config.js â€” laravel-vite-plugin input"]
        CORE["resources/js/app.js<br/>(core, khÃ´ng pháº£i SEO)"]
        SEO_REACT["*.jsx React entry Ã— 7"]
        SEO_JS["*.js Alpine/bridge Ã— 4"]
        SEO_CSS["*.css bundle Ã— 9"]
    end

    subgraph Host["Filament Blade / Livewire"]
        BLADE["edit-article, media-library,<br/>watermark-editor, task-workflow-builder, â€¦"]
    end

    subgraph Bridge["KhÃ´ng dÃ¹ng React Router / Context"]
        LW["Livewire $wire / callEditArticleLivewire"]
        WIN["window.__seo* globals + CustomEvent"]
        ALP["Alpine.data / Alpine.store"]
        LS["localStorage utils"]
    end

    subgraph API["Laravel REST /api/seo/*"]
        MEDIA["/api/seo/media/*"]
        WM["/api/seo/watermark/*"]
        ART["/api/seo/articles/*"]
        PICKER["workspace-picker, media-picker, seo-preview"]
    end

    Vite --> Host
    Host --> Bridge
    SEO_REACT & SEO_JS --> API
    Bridge --> LW
```

| Äáº·c Ä‘iá»ƒm | GiÃ¡ trá»‹ |
|----------|---------|
| **KhÃ´ng cÃ³** | React Router, Redux, Zustand, React Context toÃ n cá»¥c |
| **State chÃ­nh** | `useState` / `useRef` trong component hub; bootstrap JSON tá»« Blade; Livewire snapshot |
| **Giao tiáº¿p chÃ©o** | `window.dispatchEvent(CustomEvent)`, `Livewire.on`, `postMessage` (media editor popup) |
| **Persist client** | `articleEditorStorage`, `articleMediaPickerCache`, `articleProductAlbumStorage`, â€¦ |
| **Chunking build** | `manualChunks`: `react-vendor`, `tiptap-vendor`, `vendor` |

---

## 2. Vite entry points (`vite.config.js`)

### 2.1 React / JS applications (cÃ³ logic UI)

| # | Vite input | Loáº¡i | Entry file | Host Blade / Route |
|---|------------|------|------------|-------------------|
| 1 | `task-builder.jsx` | **React** | `resources/js/task-builder.jsx` | `task-workflow-builder.blade.php` â†’ `#seo-task-workflow-builder-root` |
| 2 | `article-editor.jsx` | **React** (multi-root) | `resources/js/article-editor.jsx` | `edit-article.blade.php` â†’ `#seo-article-editor-root` + 4 root phá»¥ |
| 3 | `article-seo-preview.jsx` | **React** (lazy mount) | `article-seo-preview.jsx` â†’ `articleSeoPreviewMount.jsx` | `list-articles.blade.php` â€” modal SEO point |
| 4 | `keyword-detail-panel.jsx` | **Vanilla JS** | `keyword-detail-panel.jsx` â†’ `keywordDetailPanel.js` | `list-keywords.blade.php` â€” drawer chi tiáº¿t keyword |
| 5 | `keyword-destinations-modal.jsx` | **Vanilla JS** | `keyword-destinations-modal.jsx` â†’ `keywordDestinationsListModal.js` | Keywords â€” modal destinations |
| 6 | `watermark-editor-page.jsx` | **React** | `watermark-editor-page.jsx` | `watermark-editor.blade.php` â†’ `/seo/watermark-editor` |
| 7 | `media-image-editor-page.jsx` | **React** | `media-image-editor-page.jsx` | `media-image-editor.blade.php` â†’ `/seo/media-image-editor` |
| 8 | `article-media-picker-cache-bootstrap.js` | **Alpine bridge** | cache + workspace picker factory | `edit-article`, `workspace-media-picker.blade.php` |
| 9 | `media-library-actions.js` | **Alpine** | `seoMediaLibraryActions` | `media-library.blade.php` |
| 10 | `project-run-queue.js` | **Alpine** | `seoProjectRunQueue` + store `seoRunQueue` | `view-project-run.blade.php` |
| 11 | `automation-workflow-viewer.jsx` | **React** (read-only) | `automation-workflow-viewer.jsx` â†’ `AutomationWorkflowViewerApp` | Admin `/admin/automation/flows` â€” list ~17rem \| canvas `flex-1` `min-h: calc(100vh-â€¦)` \| inspector ~20rem (collapse); CSS `automation-workflow-viewer.css` |

### 2.2 CSS-only bundles (khÃ´ng mount React)

| Vite input | DÃ¹ng táº¡i |
|------------|----------|
| `article-edit-page.css` | EditArticle layout |
| `media-library.css` | Media Library, Image Processing, test-prompt |
| `image-splitter.css` | Editor + media-image-editor (import kÃ¨m JSX) |
| `watermark-editor.css` | Watermark editor page |
| `image-optimization-settings.css` | Image optimization settings |
| `ai-result.css` | Blade component `ai-result` |
| `project-run-step.css` | Project run step views |
| `project-run-queue.css` | Project run queue |
| `global-ai-chat.css` | Global AI chat component |

### 2.3 File JS/JSX **khÃ´ng** Ä‘Äƒng kÃ½ Vite (legacy / orphan)

| File | Ghi chÃº |
|------|---------|
| `magic-eraser-mount.jsx` | Modal eraser cÅ© (`seo-open-magic-eraser`); thay báº±ng trang `/seo/media-image-editor` |
| `media-library-page.jsx` | React `MediaLibraryTools` cÅ©; Media Library hiá»‡n dÃ¹ng Livewire + `media-library-actions.js` |
| `components/WatermarkConfigPanel.jsx` | Panel cáº¥u hÃ¬nh WM cÅ© (type/text/logo); khÃ´ng mount trÃªn Filament page â€” thay báº±ng design suite + batch page |
| `components/ArticleDomainWidgetsSidebar.jsx` | Component tá»“n táº¡i nhÆ°ng **khÃ´ng** Ä‘Æ°á»£c import/mount á»Ÿ entry nÃ o |

---

## 3. CÃ¢y thÆ° má»¥c `resources/js/`

```
resources/js/
â”œâ”€â”€ article-editor.jsx              # Hub EditArticle â€” 5 React roots
â”œâ”€â”€ article-seo-preview.jsx         # Boot modal SEO list
â”œâ”€â”€ articleSeoPreviewMount.jsx      # mount SeoScorePanel trong modal
â”œâ”€â”€ articleSeoListModal.js
â”œâ”€â”€ articleListTableLoading.js
â”œâ”€â”€ article-media-picker-cache-bootstrap.js
â”œâ”€â”€ task-builder.jsx
â”œâ”€â”€ watermark-editor-page.jsx
â”œâ”€â”€ media-image-editor-page.jsx
â”œâ”€â”€ media-library-actions.js
â”œâ”€â”€ project-run-queue.js
â”œâ”€â”€ keyword-detail-panel.jsx
â”œâ”€â”€ keywordDetailPanel.js
â”œâ”€â”€ keyword-destinations-modal.jsx
â”œâ”€â”€ keywordDestinationsListModal.js
â”œâ”€â”€ magic-eraser-mount.jsx          # (orphan)
â”œâ”€â”€ media-library-page.jsx          # (orphan)
â”œâ”€â”€ components/                     # 50+ React components
â”‚   â”œâ”€â”€ SeoArticleEditor.jsx        # Hub editor ~8.7k dÃ²ng
â”‚   â”œâ”€â”€ ArticleFlowBuilder.jsx      # Task workflow canvas
â”‚   â”œâ”€â”€ WatermarkEditorApp.jsx      # Watermark design suite
â”‚   â”œâ”€â”€ MagicEraserApp.jsx          # Eraser + splitter tabs
â”‚   â”œâ”€â”€ ImageSplitterPanel.jsx â†’ ImageSplitterApp.jsx
â”‚   â”œâ”€â”€ ArticleImagesTab.jsx
â”‚   â”œâ”€â”€ GenerateImageModal.jsx
â”‚   â”œâ”€â”€ ArticleFaqEditor.jsx
| `keywordReviewApi.js` | `utils/keywordReviewApi.js` | `ensureKeywordForReview` (`POST /api/seo/keywords/ensure-for-review`); review/restore (`POST /api/seo/keywords/{id}/review|restore`, `reason_id` hoáº·c `custom_reason_text`) |
| `keywordReviewReasonUtils.js` | `utils/keywordReviewReasonUtils.js` | Xáº¿p háº¡ng/lá»c lÃ½ do + recent reason (`sessionStorage`) cho popover |
| `KeywordReviewPopover.jsx` | `components/KeywordReviewPopover.jsx` | Popover inline cáº¡nh dÃ²ng gá»£i Ã½: 2 nÃºt warning/danger; `ArticleLinksSidebar.openReviewPopover` Ä‘áº£m báº£o `keyword_id` (ensure náº¿u thiáº¿u) trÆ°á»›c khi má»Ÿ |
â”‚   â”œâ”€â”€ ArticleAiChatPanel.jsx
â”‚   â”œâ”€â”€ MediaLibraryTools.jsx       # (chá»‰ qua orphan entry)
â”‚   â”œâ”€â”€ ImageWatermarkEditor.jsx    # Canvas WM Ä‘Æ¡n giáº£n (modal cÅ©)
â”‚   â”œâ”€â”€ WatermarkConfigPanel.jsx
â”‚   â”œâ”€â”€ SeoSelect.jsx               # Shared select UI
â”‚   â”œâ”€â”€ imageMeta/                  # ImageMetaFormFields, ImageMetaEditForm
â”‚   â””â”€â”€ watermark*.js               # Draw utils, position, CTA icons
â”œâ”€â”€ utils/                          # API clients, storage, TipTap helpers
â”‚   â”œâ”€â”€ seoMediaApi.js              # /api/seo/media/*
â”‚   â”œâ”€â”€ watermarkApi.js             # /api/seo/watermark/*
â”‚   â”œâ”€â”€ articleEditorApi.js         # save / sync-wp / finishArticleSyncFromApi
â”‚   â”œâ”€â”€ articleOperationTracker.js  # overlay poll + reload (WP sync, slug fix)
â”‚   â”œâ”€â”€ seoArticleApi.js            # fetch wrapper + CSRF
â”‚   â”œâ”€â”€ articleEditorLivewire.js    # callEditArticleLivewire bridge
â”‚   â”œâ”€â”€ seoAssistantNavigator.js    # Alpine Assistant Dock (Edit Article sidebar)
â”‚   â”œâ”€â”€ seoWorkspaceMediaPicker.js  # Alpine workspace picker
â”‚   â””â”€â”€ â€¦ (40+ util modules)
â”œâ”€â”€ hooks/
â”‚   â”œâ”€â”€ useArticleEditorHistory.js
â”‚   â””â”€â”€ useDebouncedCallback.js
â”œâ”€â”€ extensions/
â”‚   â””â”€â”€ imageMarkerExtension.js
â””â”€â”€ data/
    â”œâ”€â”€ emojiCatalog.js
    â””â”€â”€ google-fonts.json
```

---

## 4. á»¨ng dá»¥ng React / JS theo tá»«ng entry

### 4.1 Article Editor Suite â€” `article-editor.jsx`

**Filament:** `EditArticle.php` Â· **Route:** `/seo/articles/{id}/edit`  
**Chi tiáº¿t backend:** [MAP_SEO_EDITOR.md](MAP_SEO_EDITOR.md)

#### Mount graph (5 React roots)

```mermaid
flowchart TB
    ENTRY["article-editor.jsx<br/>mountArticleEditorPage()"]

    ENTRY --> EDITOR["SeoArticleEditor<br/>#seo-article-editor-root"]
    ENTRY --> FAQ["ArticleFaqEditor<br/>#seo-article-faq-root"]
    ENTRY --> LINKS["ArticleLinksSidebar<br/>#seo-article-links-root"]
    ENTRY --> LAUNCHER["ArticleAiFloatingLauncher<br/>#seo-article-ai-launcher-root"]
    ENTRY --> CHAT["ArticleAiChatPanel<br/>#seo-article-ai-chat-root"]

    EDITOR --> PORTAL_SEO["createPortal â†’ SeoScorePanel<br/>#seo-article-seo-assistant-root"]
    EDITOR --> PORTAL_IMG["createPortal â†’ ArticleImagesTab<br/>#seo-article-image-assistant-root"]
    EDITOR --> PORTAL_REV["createPortal â†’ ArticleReviewsTab<br/>#seo-article-reviews-assistant-root"]
    EDITOR --> MODAL["GenerateImageModal"]
    EDITOR --> TIPTAP["TipTap BlockEditor / ImageBlockEditor"]
```

#### Component hierarchy (SeoArticleEditor)

| Lá»›p | Component | Vai trÃ² |
|-----|-----------|---------|
| Left rail | `ArticleGoogleSerpPreview` | SERP preview, focus keyword; modal SEO + nÃºt AI Prompt Hook meta description |
| Title toolbar | `articleTitlePromptHook.js` (mount tá»« `article-editor.jsx`) | NÃºt AI gá»£i Ã½ tiÃªu Ä‘á» cáº¡nh `.wp-title-input` |
| Left rail | `ArticleOutlineTab` | Outline tree, REST outline API |
| Left rail | `article-editor-shortcuts-rail.blade.php` â†’ host `[data-seo-outline-shortcuts-host]` | Keyboard shortcuts dÆ°á»›i Outline; Prev/Next Ä‘á»•i nhÃ³m; `mountShortcutsBelowOutline` trong `articleEditorHeaderActions.js` |
| Top bar | `article-editor-page-actions.blade.php` | Primary: **Save â†’ Sync WP â†’ Preview (split WP/ná»™i bá»™) â†’ Approve**; More: History, Prompts, Assign/Open project, Restore, Debug MD (icon+chá»¯), Delete. `EditArticle::getHeaderActions()` trá»‘ng â€” UI More Blade |
| Center | `BlockFormatToolbar`, `BlockInsertMenu`, `LinkEditBubble` | TipTap formatting; delete paragraph trong `.seo-toolbar-end-actions` (`margin-left: auto`); link bubble tÃ¬m bÃ i + **PhÃ¢n vÃ o Content Projects** |
| Center | `ImageBlockEditor`, `BlockImagesPanel` | Khá»‘i áº£nh; `ImageBlockPickerBox` **Quick download** â†’ `importSeoMediaFromUrl({ randomFilename: true })` |
| Overlay | `ArticleAiFloatingLauncher` (`#seo-article-ai-launcher-root`) | Click â†’ `seo-article-ai-chat-open` (AI images & videos); khÃ´ng menu phá»¥ |
| FAQ root | `ArticleFaqEditor` (`#seo-article-faq-root`) | FAQ bar: Generate / Import / Extract FAQ / Add; Extract disable tá»›i khi cÃ³ selection |
| Portal tabs | `ArticleImagesTab` | Quáº£n lÃ½ áº£nh bÃ i, AI jobs, má»Ÿ media editor; UI hÃ ng: **Except** + menu `â‹¯` (`.seo-article-images-more-menu`) |
| Portal tabs | `SeoScorePanel` | PhÃ¢n tÃ­ch SEO client-side + violations |
| Portal tabs | `ArticleReviewsTab` | Product reviews local + WP: real/generated/pending/reviewed + **Target count** / **Missing**; Refresh / Create / Sync; `{count}` + **Táº¡o bÃ¬nh luáº­n nhanh** + **LÃ m má»›i**; `StarRating` 1â€“5; Livewire `generateQuickPostReviews` / `refreshVirtualReviewsForEditor` |
| Modal | `GenerateImageModal` â†’ `ImageSplitterPanel` | Táº¡o áº£nh AI + split album |
| Overlay | `EditorBusyOverlay` | Lock UI khi heavy action |

#### State & persistence (khÃ´ng Context)

| Nguá»“n | Module / pattern | Dá»¯ liá»‡u |
|-------|------------------|---------|
| SSR bootstrap | `#seo-article-initial-*` JSON scripts | HTML, SEO, images, FAQs, settings |
| React local | `useState` trong `SeoArticleEditor` | blocks, tabs, analysis, modals |
| History | `useArticleEditorHistory` hook | undo/redo TipTap |
| localStorage | `articleEditorStorage`, `articleFeaturedImageStorage`, `articleProductAlbumStorage` | draft FAQ, featured, album |
| Window API | `window.__seoCollectEditorHeavyBundle`, `__seoExecuteHeavyArticleAction` | save/sync tá»« Filament header |
| Livewire | `callEditArticleLivewire(method, â€¦)` | search links, persist album, WP slug rename, reviews refresh |
| Events | `seo-product-gallery-updated`, `virtual-reviews-updated`, `seo-open-generate-image-modal` | cross-widget sync |

#### Livewire methods gá»i tá»« JS

| Method | Caller |
|--------|--------|
| `searchInternalLinkArticles` | `LinkEditBubble.jsx` â€” popup Â«TÃ¬m bÃ i viáº¿t (cÃ¹ng domain)Â»; cÃ¹ng `ArticleInternalLinkSearchService` tÃ¡i dá»¥ng bá»Ÿi content-keyword fallback |
| `POST .../editor/links/suggestions` | `ArticleLinksSidebar.jsx` â€” Â«Táº¡o gá»£i Ã½ liÃªn káº¿tÂ» (`mode=full`) / Â«Táº¡o gá»£i Ã½ bá»• sungÂ» (`mode=fallback`); HTML qua event `seo-editor-document-html-request` â† `SeoArticleEditor` |
| `POST /api/seo/keywords/ensure-for-review` | `ArticleLinksSidebar.openReviewPopover` khi suggestion thiáº¿u `keyword_id` (fallback phrase) â€” trÆ°á»›c `KeywordReviewPopover` |
| `mountAction('assignKeywordAnchorToContentProject')` | `LinkEditBubble.jsx` â€” `anchorPhrase` tá»« text bÃ´i Ä‘en editor (khÃ´ng láº¥y Ã´ search) |
| `persistProductAlbumFromClient` | `articleProductAlbumStorage.js` |
| `renameAttachmentSlugsOnWordPress` | `SeoArticleEditor.jsx` |
| `refreshVirtualReviewsForEditor` | `SeoArticleEditor.jsx` â†’ `ArticleReviewsTab` |
| `generateQuickPostReviews` | `SeoArticleEditor.jsx` â†’ `ArticleReviewsTab` (quick create) |
| `mountEditArticleAction` | Filament header actions (qua `articleEditorLivewire.js`) |

#### Assistant Dock (Alpine â€” cÃ¹ng bundle `article-editor.jsx`)

| ThÃ nh pháº§n | Chi tiáº¿t |
|------------|----------|
| Blade | `edit-article.blade.php` â†’ `.seo-assistant-host` + `.seo-assistant-dock` |
| Alpine | `seoAssistantNavigator()` â€” tab auto tá»« `data-assistant-widget*` |
| CSS | `article-editor.css` â€” sticky sidebar cá»™t + dock `top: 0` trong scroll ná»™i bá»™ |
| Chi tiáº¿t luá»“ng | [MAP_SEO_EDITOR.md Â§2.5.4.1](MAP_SEO_EDITOR.md#2541-assistant-dock--sidebar-pháº£i-edit-article-Ä‘Ã£-implement) |

---

### 4.2 Task Workflow Builder â€” `task-builder.jsx`

**Filament:** Task Workflow Builder Â· **Root:** `#seo-task-workflow-builder-root`

```mermaid
flowchart LR
    TB["task-builder.jsx"] --> BRIDGE["AppBridge<br/>toast + save state"]
    BRIDGE --> AFB["ArticleFlowBuilder<br/>canvas drag-drop nodes"]
    AFB -->|"CustomEvent save-task-flow"| LW["Livewire Task page<br/>lÆ°u flow_data"]
    LW -->|"task-flow-saved / failed"| BRIDGE
```

| ThÃ nh pháº§n | File | State |
|------------|------|-------|
| Entry + bridge | `task-builder.jsx` | `useState`: taskName, saving, toast |
| Canvas | `ArticleFlowBuilder.jsx` | nodes, edges, zoom â€” `useState` + `useRef`; Prompt node **khÃ´ng** chá»n model (routing tá»« Settings â†’ AI Advanced) |
| Theme/helpers | `flowTheme.js` | pure functions |
| Prompt list SSR | `window.__SEO_PROMPTS__` | tá»« Blade, khÃ´ng REST lÃºc má»Ÿ |

**API:** KhÃ´ng gá»i REST trá»±c tiáº¿p â€” persist qua Livewire event `save-task-flow`.

---

### 4.3 Watermark Editor Suite â€” `watermark-editor-page.jsx`

**Route:** `/seo/watermark-editor` (design suite) Â· `/seo/watermark-settings-page` (batch apply) Â· **Backend:** [MAP_SEO_MEDIA.md Â§2.4](MAP_SEO_MEDIA.md)

```mermaid
flowchart TB
    PAGE["watermark-editor-page.jsx"] --> APP["WatermarkEditorApp"]

    subgraph Config["Panel trÃ¡i â€” design"]
        PAT["Pattern presets<br/>cta_button, classic_grid, â€¦"]
        GC["GradientColorPicker"]
        PC["PreciseControl"]
        WMP["WatermarkMediaPicker"]
    end

    subgraph Preview["Panel pháº£i"]
        CANVAS["HTML Canvas<br/>watermarkDrawUtils"]
        WOPP["WatermarkOverlayPreviewPanel"]
    end

    subgraph API["watermarkApi.js"]
        GET["GET /settings"]
        POST_S["POST /settings"]
        POST_M["POST /media/{id}/save"]
        POST_N["POST /save-new"]
    end

    APP --> Config & Preview
    APP --> API
```

| Component con | Vai trÃ² |
|---------------|---------|
| `WatermarkEditorApp` | Hub state: pattern, colors, position, opacity, CTA text |
| `WatermarkOverlayPreviewPanel` | Preview multi-overlay export |
| `watermarkDrawUtils.js` | Váº½ pattern lÃªn canvas |
| `watermarkOverlayExport.js` | Export blob variants cho lÆ°u settings |
| `WatermarkMediaPicker` | Chá»n áº£nh máº«u tá»« library |
| `ImageWatermarkEditor` | **Legacy** canvas Ä‘Æ¡n giáº£n (dÃ¹ng trong `MediaLibraryTools`, khÃ´ng qua Vite entry hiá»‡n táº¡i) |
| `WatermarkConfigPanel` | **Legacy** â€” panel cáº¥u hÃ¬nh WM cÅ© (khÃ´ng cÃ²n gáº¯n Filament); dÃ¹ng `WatermarkEditorApp` + `WatermarkSettingsPage` |

**Bootstrap:** `dataset` trÃªn `#seo-watermark-editor-root` (`siteId`, `initialConfig`, `mediaSamples`, â€¦).

---

### 4.4 Media Image Editor â€” `media-image-editor-page.jsx`

**Route:** `/seo/media-image-editor?media={id}&tab=eraser|splitter` Â· **Backend:** [MAP_SEO_MEDIA.md Â§2.2](MAP_SEO_MEDIA.md)

```mermaid
flowchart TB
    ENTRY["media-image-editor-page.jsx"] --> APP["MagicEraserApp"]

    APP --> TABBAR["MediaEditorTabBar"]
    TABBAR --> ERASER["MagicEraserPanel<br/>canvas brush / shapes"]
    TABBAR --> SPLIT["ImageSplitterPanel â†’ ImageSplitterApp"]

    ERASER -->|"POST save-edited"| API1["seoMediaApi.saveEditedSeoMedia"]
    SPLIT -->|"GET splitter-source<br/>POST save-split"| API2["seoMediaApi"]

    APP -->|"postMessage seo-magic-eraser-saved"| OPENER["window.opener<br/>(editor / library)"]
```

| Tab | Component | API |
|-----|-----------|-----|
| Eraser | `MagicEraserPanel` | `POST /api/seo/media/{id}/save-edited` |
| Splitter | `ImageSplitterApp` | `GET /api/seo/media/splitter-source`, `POST /api/seo/media/save-split` |

**Má»Ÿ tá»«:** `ArticleImagesTab` (`buildMediaImageEditorUrl`), Media Library, `GenerateImageModal` (split inline, `canDeleteOriginal=false`).

---

### 4.5 Article SEO Preview Modal â€” `article-seo-preview.jsx`

| Layer | File | HÃ nh vi |
|-------|------|---------|
| Boot | `article-seo-preview.jsx` | `DOMContentLoaded` + `livewire:navigated` |
| Modal logic | `articleSeoListModal.js` | Má»Ÿ modal, fetch preview JSON |
| React mount | `articleSeoPreviewMount.jsx` | `SeoScorePanel` read-only |

**API:** `GET` route `seo.articles.seo-preview` â€” template `previewUrlTemplate` trong `list-articles.blade.php` (`/__ID__` â†’ article id).

---

### 4.6 Keyword UI â€” `keyword-detail-panel.jsx` / `keyword-destinations-modal.jsx`

| Entry | Pattern | Livewire |
|-------|---------|----------|
| Detail drawer | Vanilla JS + DOM | `selectKeyword`, load panel qua `$wire` |
| Destinations modal | Vanilla JS modal | Livewire list keywords page |

**KhÃ´ng** gá»i `/api/seo/*` â€” data qua Livewire Filament.

---

### 4.7 Media Library â€” `media-library-actions.js` (Alpine, khÃ´ng React)

```mermaid
flowchart LR
    ALP["seoMediaLibraryActions<br/>Alpine.data"] --> UP["uploadLocalMediaFiles<br/>seoLocalMediaUpload"]
    UP --> API["POST /api/seo/media/upload"]
    ALP --> LW["$wire.deleteLibraryImage<br/>resizeSelectedImagesFromClient<br/>refreshAfterLocalUpload"]
```

| HÃ nh Ä‘á»™ng | Client | Backend |
|-----------|--------|---------|
| Upload local | `uploadSeoMediaFromFile` | `POST /api/seo/media/upload` |
| XÃ³a / resize batch | `$wire.*` | Livewire `MediaLibrary` page |
| Selection persist | `sessionStorage` key `seo-media-library:selected:{scope}` | â€” |

---

### 4.8 Project Run Queue â€” `project-run-queue.js` (Alpine)

| ThÃ nh pháº§n | Vai trÃ² |
|------------|---------|
| `Alpine.store('seoRunQueue')` | `isRunning`, `stopRequested`, `currentTaskId` |
| `Alpine.data('seoProjectRunQueue')` | Queue `taskIds`, bulk Article actions, generic step modal |
| Livewire | `ViewSeoProjectRun`: `retryWorkflowStep` (â†’ StepRerun), `previewBulkRerunByAction` / `bulkRerunByAction`, `previewBulkGenericStep` / `bulkRerunGenericStep`, `cancelWorkflowStep` |
| Bulk Article | `regenerate_outline` / `regenerate_article` / `regenerate_outline_and_article` + confirm modal |
| Generic step modal | `genericStepOpen` â€” **khÃ´ng** `window.prompt`; preview valid/invalid rá»“i serial rerun |
| `cancelWorkflowStep` | Chá»‰ xÃ³a `[data-run-busy-step]` + reload khi `success && (cancelled>0 \|\| already_idle)`; khÃ´ng `applyItemFailure` hÃ ng chÃ­nh |
| Busy badge / row status | Blade `data-run-busy-step` + `row_status_*` tá»« `ContentProjectArticleRowStatusResolver` |

**KhÃ´ng** gá»i REST â€” orchestration workflow qua Livewire. KhÃ´ng `$refresh` khi `seoRunQueue.isRunning`; `init()` bá» qua náº¿u queue Ä‘ang cháº¡y (trÃ¡nh re-init máº¥t hÃ ng). Chi tiáº¿t Phase 2.0/2.1: [MAP_SEO_PROJECTS.md](MAP_SEO_PROJECTS.md).

---

### 4.9 Workspace / Article Media Picker â€” `article-media-picker-cache-bootstrap.js`

| Global | Module |
|--------|--------|
| `window.__seoArticleMediaPickerCache` | `articleMediaPickerCache.js` |
| `window.__seoArticleMediaPickerCustomTabs` | `articleMediaPickerCustomTabs.js` |
| `window.__seoWorkspaceMediaPicker` | `createSeoWorkspaceMediaPicker()` |

**Picker REST (Alpine `fetch`):**

| Endpoint | Route name | Consumer |
|----------|------------|----------|
| `GET /api/seo/media/workspace-picker` | `seo.media.workspace-picker` | Global workspace picker, AI chat |
| `GET /seo/articles/{article}/media-picker` | `seo.articles.media-picker` | EditArticle modal (`media_picker_url` trong meta JSON) |

---

## 5. Báº£n Ä‘á»“ API Frontend â†’ Laravel

### 5.1 `seoMediaApi.js` â†’ `/api/seo/media/*`

| Export function | HTTP | Path | Component tiÃªu thá»¥ chÃ­nh |
|-----------------|------|------|--------------------------|
| `uploadSeoMediaFromFile` | POST | `/upload` | `media-library-actions`, clipboard paste |
| `importSeoMediaFromUrl` | POST | `/import-url` | `ImageBlockEditor` |
| `prepareImageEditorUrl` | POST | `/prepare-editor` | `ArticleImagesTab` |
| `applyWatermarkToImage` | POST | `/apply-watermark` | `ArticleImagesTab` |
| `saveEditedSeoMedia` | POST | `/{id}/save-edited` | `MagicEraserPanel` |
| `renameSeoMedia` | POST | `/{id}/rename` | `SeoArticleEditor` |
| `renameSeoMediaByUrl` | POST | `/rename-by-url` | `SeoArticleEditor` |
| `updateSeoMediaMeta` | POST | `/update-meta` | `SeoArticleEditor`, image meta panels |
| `fetchSplitterSource` | GET | `/splitter-source` | `ImageSplitterApp` |
| `saveSplitPiecesToLibrary` | POST | `/save-split` | `ImageSplitterApp` |
| `fetchArticleAiMediaJobs` | GET | `/article/{id}/ai-jobs` | `ArticleImagesTab` |
| `fetchSeoMediaStatus` | GET | `/{id}/status` | `GenerateImageModal` |
| `retryAiMediaGeneration` | POST | `/{id}/retry-generation` | `ArticleImagesTab` |
| `deleteAiMediaJob` | DELETE | `/{id}/ai-job` | `ArticleImagesTab` |
| `processClipboardImagePaste` | POST | `/upload` (implicit) | TipTap paste handler |
| `buildMediaImageEditorUrl` | â€” | navigates `/seo/media-image-editor` | `ArticleImagesTab` |

**Controller:** `SeoMediaController` Â· **Picker riÃªng:** `WorkspaceMediaPickerController`, `ArticleMediaPickerController`  
**Chi tiáº¿t pipeline:** [MAP_SEO_MEDIA.md Â§2.1](MAP_SEO_MEDIA.md)

### 5.2 `watermarkApi.js` â†’ `/api/seo/watermark/*`

| Export function | HTTP | Path | Component |
|-----------------|------|------|-----------|
| `fetchWatermarkSettings` | GET | `/settings?site_id=` | `WatermarkEditorApp`, `MediaLibraryTools` |
| `saveWatermarkSettings` | POST | `/settings` | `WatermarkEditorApp` (design suite â€” báº­t `auto_watermark` khi lÆ°u thiáº¿t káº¿) |
| `applyWatermarkBatch` | POST | `/batch` | Settings UI (batch) |
| `saveWatermarkedMedia` | POST | `/media/{id}/save` | `WatermarkEditorApp`, `ImageWatermarkEditor` |
| `saveNewWatermarkedImage` | POST | `/save-new` | `WatermarkEditorApp`, `ImageWatermarkEditor` |

**Controller:** `SeoWatermarkController`

### 5.3 `articleEditorApi.js` â†’ `/api/seo/articles/*`

| Function | HTTP | Path | Trigger |
|----------|------|------|---------|
| `saveArticleViaApi` | POST | `/{article}/save` | Filament header Save, `__seoExecuteHeavyArticleAction` |
| `syncArticleToWordPressViaApi` | POST | `/{article}/sync-wp` | Filament header Sync WP |
| `fetchArticleOperationStatus` | GET | `/{article}/operation-status` | `articleOperationTracker.js` (poll) |

**Controller:** `ArticleEditorSyncController`, `ArticleEditorOperationController` Â· **Wrapper:** `seoArticleApi.js` (tá»± gáº¯n `X-CSRF-TOKEN` cho POST/PUT/PATCH/DELETE + JSON)

### 5.4 Outline API (inline fetch)

| Path | Methods | File |
|------|---------|------|
| `/api/seo/articles/{id}/outline` | GET, POST | `ArticleOutlineTab.jsx`, `SeoArticleEditor.jsx` |
| `/api/seo/articles/{id}/outline/refresh` | POST | `ArticleOutlineTab.jsx` |
| `/api/seo/articles/{id}/outline/check-duplicates` | POST | `ArticleOutlineTab.jsx` |
| `/api/seo/articles/{id}/outline/{heading}` | PUT, DELETE | `ArticleOutlineTab.jsx` |
| `/api/seo/articles/{id}/outline/{heading}/generate` | POST | `ArticleOutlineTab.jsx` |

**Controller:** `ArticleOutlineController`

### 5.5 Preview & picker (khÃ´ng qua module `*Api.js` chuyÃªn dá»¥ng)

| Route | Method | Client |
|-------|--------|--------|
| `seo.articles.seo-preview` | GET | `articleSeoListModal.js` |
| `seo.articles.media-picker` | GET | EditArticle Alpine modal (`seoWorkspaceMediaPicker` pattern) |
| `seo.media.workspace-picker` | GET | `seoWorkspaceMediaPicker.js` |

### 5.6 SÆ¡ Ä‘á»“ data flow tá»•ng há»£p (media + editor)

```mermaid
flowchart TB
    subgraph Editor["Article Editor"]
        SAE["SeoArticleEditor"]
        AIT["ArticleImagesTab"]
        IBE["ImageBlockEditor"]
        GIM["GenerateImageModal"]
    end

    subgraph MediaPages["Media pages"]
        MIE["media-image-editor-page"]
        WME["watermark-editor-page"]
        ML["media-library-actions"]
    end

    subgraph ApiModules["JS API modules"]
        SMA["seoMediaApi.js"]
        WMA["watermarkApi.js"]
        AEA["articleEditorApi.js"]
    end

    subgraph Laravel["Laravel /api/seo"]
        SMC["SeoMediaController"]
        SWC["SeoWatermarkController"]
        AOC["ArticleOutlineController"]
        AES["ArticleEditorSyncController"]
        WPC["WorkspaceMediaPickerController"]
    end

    SAE --> AEA & SMA & AOC
    AIT & IBE & GIM --> SMA
    MIE --> SMA
    WME --> WMA
    ML --> SMA

  SMA --> SMC & WPC
    WMA --> SWC
    AEA --> AES
```

---

## 6. Shared components & utilities

### 6.1 UI primitives (dÃ¹ng chÃ©o nhiá»u app)

| Component | Apps |
|-----------|------|
| `SeoSelect.jsx` | Editor, Task builder, Watermark, Generate modal |
| `GradientColorPicker`, `PreciseControl` | Watermark editor |
| `SeoScorePanel` | Editor portal, SEO preview modal |
| `ImageSplitterPanel` / `ImageSplitterApp` | Media editor page, Generate modal |
| `ArticleAssistantWidget` | Editor sidebar portals |

### 6.2 Hooks

| Hook | DÃ¹ng bá»Ÿi |
|------|----------|
| `useArticleEditorHistory` | `SeoArticleEditor` â€” undo/redo |
| `useDebouncedCallback` | `SeoArticleEditor` â€” debounce analysis |

### 6.3 TipTap / Editor extensions

| Module | Vai trÃ² |
|--------|---------|
| `editorExtensions.js` | Bundle TipTap extensions |
| `articleImageExtension.js` | Custom image node |
| `imageMarkerExtension.js` | Image markers |
| `editorHtmlUtils.js`, `editorSelectionUtils.js` | HTML transform / selection |

### 6.4 i18n client

`utils/i18n.js` â€” `t('key')` cho watermark strings vÃ  labels UI.

---

## 7. Custom events quan trá»ng

| Event | Publisher | Subscriber |
|-------|-----------|------------|
| `save-task-flow` | `ArticleFlowBuilder` | Livewire task page |
| `seo-open-generate-image-modal` | Editor / sidebar | `SeoArticleEditor` |
| `generate-article-image` | `ArticleAiChatPanel`, quick section | `SeoArticleEditor` â†’ `requestGenerateArticleImage` |
| `article-ai-image-generated` | Livewire `EditArticle` (bridge `article-editor.jsx`) | `SeoArticleEditor` poll/replace placeholder |
| `article-ai-media-job-updated` | Poll / apply completed | `ArticleImagesTab` refresh jobs |
| `seo-open-workspace-media-picker` | Global AI chat | `seoWorkspaceMediaPicker` |
| `seo-product-gallery-updated` | `GenerateImageModal` | Alpine album box |
| `seo-magic-eraser-saved` | `media-image-editor-page` | `window.opener` |
| `seo-media-library-dom-refreshed` | Livewire media library | `media-library-actions` |
| `seo-article-editor-notify` | API save/sync | Filament notifications |
| `editor-html-collected` | TipTap collect | Alpine save handler |
| `seo-assistant-switch-panel` | `SeoArticleEditor` | `seoAssistantNavigator` (Assistant Dock) |
| `seo-assistant-navigator-badges` | `SeoArticleEditor`, `ArticleLinksSidebar` | Badge tab dock |
| `seo-assistant-link-section` | `seoAssistantNavigator` | `ArticleLinksSidebar` (FAQ/CTA filter) |
| `seo-assistant-widget-control` | `seoAssistantNavigator` | React sidebar widgets (`seo`, `images`, `links`, `reviews`) |
| `seo-sidebar-open-publish-tab` | Publish UI / shortcut | Alpine `syncOpen` + panel Publishing |

---

## 8. Blade â†” Vite mapping (quick reference)

| Filament view | Vite bundle |
|---------------|-------------|
| `edit-article.blade.php` | `article-media-picker-cache-bootstrap.js`, `article-edit-page.css`, `article-editor.jsx` |
| `list-articles.blade.php` | `article-seo-preview.jsx` |
| `list-keywords.blade.php` | `keyword-detail-panel.jsx` |
| `task-workflow-builder.blade.php` | `task-builder.jsx` |
| `watermark-editor.blade.php` | `watermark-editor-page.jsx` |
| `media-image-editor.blade.php` | `media-image-editor-page.jsx` |
| `media-library.blade.php` | `media-library.css`, `media-library-actions.js` |
| `image-processing.blade.php` | `media-library.css` |
| `view-project-run.blade.php` | `project-run-queue.css`, `project-run-queue.js` |
| `workspace-media-picker.blade.php` | `article-media-picker-cache-bootstrap.js` |
| `global-ai-chat.blade.php` | `global-ai-chat.css` |

---

## 9. HÆ°á»›ng dáº«n prompt Cursor â€” Frontend SEO

```
Vite entries: vite.config.js â†’ app/Addons/SeoContentAi/resources/js/*.jsx
Alias: @seo-addon â†’ resources/js (import ná»™i bá»™ addon).

Editor hub: article-editor.jsx â†’ SeoArticleEditor.jsx (TipTap, multi-root, Livewire bridge).
Media API client: utils/seoMediaApi.js â†’ SeoMediaController.
Watermark client: utils/watermarkApi.js â†’ SeoWatermarkController.
Save/Sync: utils/articleEditorApi.js â†’ ArticleEditorSyncController.
Livewire bridge: utils/articleEditorLivewire.js â†’ callEditArticleLivewire.

Media editor page: media-image-editor-page.jsx â†’ MagicEraserApp.
Watermark page: watermark-editor-page.jsx â†’ WatermarkEditorApp.
Task canvas: task-builder.jsx â†’ ArticleFlowBuilder.jsx.

KhÃ´ng thÃªm React Router/Context â€” theo pattern bootstrap JSON + window events + Livewire.
Select UI: SeoSelect.jsx (React), x-select (Blade).
Sau Ä‘á»•i JS/CSS: npm run build + kiá»ƒm tra vite.config.js input náº¿u thÃªm entry má»›i.

Backend maps: MAP_SEO_EDITOR.md, MAP_SEO_MEDIA.md, MAP_SEO_PROJECTS.md.
```

---

## 10. Verify sau thay Ä‘á»•i Frontend

```bash
# Build assets
npm run build

# Test PHP liÃªn quan API editor/sync (náº¿u Ä‘á»•i contract)
php artisan test app/Addons/SeoContentAi/tests/Unit/ArticleWpSyncQueueServiceTest.php
php artisan test app/Addons/SeoContentAi/tests --filter=Media
```

| Thay Ä‘á»•i | Kiá»ƒm tra thá»§ cÃ´ng |
|----------|-------------------|
| `article-editor.jsx` | Má»Ÿ EditArticle â€” 5 roots mount, save/sync header |
| `seoMediaApi.js` | Upload library, eraser save, splitter |
| `watermark-editor-page.jsx` | LÆ°u settings + save áº£nh WM |
| `media-library-actions.js` | Chá»n batch, upload, xÃ³a |
| Entry má»›i | ThÃªm vÃ o `vite.config.js` `input[]` + `@vite` trong Blade |
