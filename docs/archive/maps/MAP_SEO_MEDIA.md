> Status: Historical
> Not canonical
> Archived on: 2026-08-01
> Superseded by: ../../modules/MEDIA_AND_GALLERY.md
> Purpose: implementation history only
# SeoContentAi â€” Media API & ThÆ° viá»‡n áº£nh

[â† Quay láº¡i Báº£n Ä‘á»“ tá»•ng](SUPER_MAP_INDEX.md)

**LiÃªn quan:** [React Editor](MAP_SEO_EDITOR.md) Â· [Content Projects](MAP_SEO_PROJECTS.md)

---

## 2.1 Media API (`/api/seo/media/*`)

```mermaid
flowchart TB
    subgraph Client["React / Browser"]
        SEOAPI["seoMediaApi.js<br/>fetch + FormData + CSRF"]
        EDITOR["SeoArticleEditor.jsx<br/>renameSeoMedia, updateSeoMediaMeta"]
    end

    subgraph Middleware["Middleware Stack"]
        AUTH["Authenticate + CheckMainRole"]
        DBBOOT["SetDynamicSeoDatabase<br/>â†’ SeoDatabaseRequestBootstrap"]
        CTX["SeoConnectionContext<br/>hash / site_id"]
    end

    subgraph Routes["SeoPanelProvider routes"]
        R_UPLOAD["POST /api/seo/media/upload"]
        R_IMPORT["POST /api/seo/media/import-url"]
        R_RENAME["POST /api/seo/media/rename-by-url"]
        R_META["POST /api/seo/media/update-meta"]
        R_SPLIT["POST /api/seo/media/save-split"]
        R_SPLIT_SRC["GET /api/seo/media/splitter-source"]
        R_PREP["POST /api/seo/media/prepare-editor"]
        R_WM["POST /api/seo/media/apply-watermark"]
        R_STATUS["GET /api/seo/media/{media}/status"]
        R_AI_JOBS["GET /api/seo/media/article/{article}/ai-jobs"]
        R_RETRY["POST /api/seo/media/{media}/retry-generation"]
        R_DEL_AI["DELETE /api/seo/media/{media}/ai-job"]
        R_RENAME_MEDIA["POST /api/seo/media/{media}/rename"]
        R_SAVE_EDITED["POST /api/seo/media/{media}/save-edited"]
        R_WP_PICKER["GET /api/seo/media/workspace-picker"]
    end

    subgraph Controller["SeoMediaController"]
        UPLOAD["upload()"]
        IMPORT["importFromUrl()<br/>random_filename? â†’ storeFromRemoteUrl"]
        RENAME_URL["renameByUrl()"]
        META["updateMeta()"]
        SPLIT["saveSplit()"]
        SPLIT_SRC["splitterSource()"]
        PREP["prepareEditor()"]
        WM["applyWatermark()"]
        STATUS["status()"]
        AI_JOBS["articleAiJobs()"]
        RETRY["retryGeneration()"]
        DEL_AI["deleteAiJob()"]
        RENAME["rename()"]
        SAVE_EDITED["saveEditedImage()"]
        ACL["canAccessSite() / canAccessArticle()"]
    end

    subgraph Services["Services Layer"]
        STORAGE["SeoMediaStorageService<br/>storeUpload, storeFromRemoteUrl"]
        IMGOPT["SeoImageOptimizationService<br/>processUpload, processBinary,<br/>prepareWordPressUploadFile"]
        PIPELINE["SeoImagePipeline<br/>resize + encode<br/>Imagick native â†’ Intervention"]
        RESIZE["SeoMediaResizeService<br/>resizeLocal, resizeBinary"]
        MATH["SeoImageResizeMath<br/>dimensions + progressive steps"]
        DRIVER["ImageDriverResolver<br/>app/Support â€” imagick/gd"]
        WM_SVC["SeoWatermarkService<br/>applyToMediaIfEnabled"]
        SPLIT_SVC["SeoImageSplitterService"]
        URL_RES["SeoMediaUrlImportResolverService"]
        PATH["SeoMediaPathAllocator"]
        HIST["SeoMediaProcessingHistoryService"]
    end

    subgraph DB["omi_seo_ai"]
        T_MEDIA["seo_media"]
        T_META["seo_media_meta"]
        T_WM["seo_watermark_settings"]
        T_HIST["seo_media_processing_history"]
        T_IMG_OPT["seo_image_optimization_settings"]
        T_WP_BACKUP["seo_wp_media_backups"]
        T_WP_EDITED["seo_wp_media_edited_pending"]
    end

    subgraph Core["Core mysql (cross-DB)"]
        SITE["sites"]
    end

    SEOAPI --> R_UPLOAD & R_IMPORT & R_RENAME & R_META & R_SPLIT & R_SPLIT_SRC & R_PREP & R_WM & R_STATUS & R_AI_JOBS & R_RETRY & R_DEL_AI & R_RENAME_MEDIA & R_SAVE_EDITED
    EDITOR --> SEOAPI

    R_UPLOAD & R_IMPORT & R_RENAME & R_META & R_SPLIT & R_SPLIT_SRC & R_PREP & R_WM & R_STATUS & R_AI_JOBS & R_RETRY & R_DEL_AI & R_RENAME_MEDIA & R_SAVE_EDITED --> AUTH --> DBBOOT --> CTX
    CTX --> UPLOAD & IMPORT & RENAME_URL & META & SPLIT & SPLIT_SRC & PREP & WM & STATUS & AI_JOBS & RETRY & DEL_AI & RENAME & SAVE_EDITED
    UPLOAD & IMPORT & RENAME_URL --> ACL
    ACL --> SITE

    UPLOAD --> STORAGE
    IMPORT --> URL_RES --> STORAGE
    STORAGE --> IMGOPT --> WM_SVC
    IMGOPT --> PIPELINE
    RESIZE --> PIPELINE
    PIPELINE --> MATH
    PIPELINE --> DRIVER
    STORAGE --> PATH
    SPLIT --> SPLIT_SVC --> STORAGE
    WM --> WM_SVC
    STATUS --> HIST
    RETRY --> AI_JOBS

    STORAGE --> T_MEDIA
    STORAGE --> T_META
    WM_SVC --> T_WM
    HIST --> T_HIST
```

**Trace MCP (`upload` outbound, depth 4):** `SeoMediaController.upload` â†’ `SeoMediaStorageService.storeUpload` â†’ `SeoImageOptimizationService.processUpload` â†’ `processOriginalBytes` (temp source â†’ encode temp out â†’ `SeoConvertedImageValidator.validate`) â†’ `SeoWatermarkService.applyToMediaIfEnabled` â†’ `SeoMedia::create`.

**Clipboard Ctrl+V (`source=clipboard`):** `storeUpload` truyá»n `$randomFilename=true` â†’ `processUpload` slug `paste-{16 hex}` (khÃ´ng dÃ¹ng tÃªn OS `image.png`) â€” trÃ¡nh URL trÃ¹ng sau xÃ³a áº£nh cÅ© â†’ browser cache áº£nh cÅ©. Import URL váº«n dÃ¹ng body `random_filename` â†’ slug `import-{hex}`.

**Validate sau convert (`SeoConvertedImageValidator` + `ImageContentSignature`):** dÃ¹ng Ä‘á»ƒ **chá»n** WebP vs fallback â€” khÃ´ng cháº·n sync WordPress náº¿u áº£nh gá»‘c cÃ²n decode Ä‘Æ°á»£c. Reject WebP blank/collapsed â†’ fallback original. Upload paste: nguá»“n undecodeable â†’ khÃ´ng táº¡o `seo_media`.

**Imagick encode:** bá» `ALPHACHANNEL_ACTIVATE` (trÃ¡nh WebP alpha=0). Fresh decode má»—i attempt.

**Imagick pixel sample:** `ImagickPixelColor::normalized()` bá»c `ImagickPixel::getColor()` Ä‘á»ƒ tÆ°Æ¡ng thÃ­ch extension má»›i (`int $normalized`) vÃ  cÅ© (`bool $normalized`); trÃ¡nh `getColor(true)` lÃ m fail encode rá»“i kÃ©o dÃ i sync WP.

**WP upload:** WebP Æ°u tiÃªn; fail â†’ fallback gá»‘c/compress; **khÃ´ng** return null chá»‰ vÃ¬ WebP fail hoáº·c >100KB. `diagnoseLocalMedia` chá»‰ cho repair dá»¯ liá»‡u cÅ© â€” khÃ´ng báº¯t buá»™c trÆ°á»›c sync.

**Trace resize (Media Library / workflow):** `SeoMediaLibraryImageActionService` hoáº·c `PromptPostProcessingApplyService` â†’ `SeoMediaResizeService.resizeLocal|resizeBinary` â†’ `SeoImagePipeline.resizeFile`.

**Trace Ä‘á»“ng bá»™ WP:** `WordPressArticleSyncService.prepareEditorSyncPayload` â†’ `WordPressLocalMediaSyncService.syncHtml` â†’ `syncMedia` â†’ `SeoImageOptimizationService.prepareWordPressUploadFile` â†’ `POST â€¦/attachments/import` hoáº·c `â€¦/replace-binary` (plugin **â‰¥ 1.0.50** Ä‘á»•i extension sang `.webp` khi mime `image/webp`) â€” **khÃ´ng ghi Ä‘Ã¨ file PNG/JPG gá»‘c** trÃªn disk Laravel.

**File upload WordPress (`prepareWordPressUploadFile`):**
1. WebP há»£p lá»‡ (Æ°u tiÃªn) â†’ dÃ¹ng WebP; >100KB thÃ¬ ladder shrink; váº«n lá»›n â†’ váº«n dÃ¹ng WebP há»£p lá»‡ + log `SEO_MEDIA_FALLBACK_OVER_TARGET_SIZE` (khÃ´ng cháº·n sync).
2. WebP blank/fail â†’ log `SEO_MEDIA_WEBP_VALIDATION_FAILED` + `SEO_MEDIA_FALLBACK_FROM_ORIGINAL` â†’ **khÃ´ng** return null.
3. Fallback: original â‰¤100KB â†’ dÃ¹ng gá»‘c; original lá»›n â†’ `ensureLocalOptimizedUploadCopy` (fresh decode, format gá»‘c rá»“i JPEG) â†’ báº£n nhá» nháº¥t há»£p lá»‡ ká»ƒ cáº£ >100KB.
4. Chá»‰ `null` khi file gá»‘c thiáº¿u / undecodeable (`getimagesize` fail).
5. Log sync: `SEO_MEDIA_SYNC_CONTINUED_WITH_FALLBACK`, `SEO_MEDIA_FALLBACK_COMPRESSED`.

| Æ¯u tiÃªn | Äiá»u kiá»‡n | File |
|---------|-----------|------|
| 1 | WebP OK | Sibling `.webp` (shrink náº¿u cáº§n; >100KB váº«n dÃ¹ng náº¿u há»£p lá»‡) |
| 2 | WebP fail, gá»‘c â‰¤100KB | File gá»‘c |
| 3 | Gá»‘c >100KB | `-wp-upload.{ext}` best-effort |
| 4 | Compress fail | File gá»‘c (**váº«n sync**) |

**`syncHtml` â€” trÃ¡nh import trÃ¹ng (má»—i lÆ°á»£t sync):**

1. QuÃ©t `<img>` local â†’ Æ°u tiÃªn `data-seo-media-id` (khÃ´ng chá»‰ lookup theo `path`).
2. Má»—i `seo_media.id` chá»‰ gá»i `syncMedia` **má»™t láº§n** trong lÆ°á»£t (`$syncedThisPass`).
3. VÃ²ng `applyWpUrlsToSeoMediaImages`: náº¿u áº£nh Ä‘Ã£ sync trong lÆ°á»£t â†’ chá»‰ patch `src` tá»« cache, **khÃ´ng** `forgetMediaCache` + re-import.

**WebP backfill** (`syncWebpBackfillMediaForArticle`, sau `completeEditorSyncResponse`):

Chá»‰ cháº¡y khi **báº£n WebP local tháº­t sá»± dÃ¹ng Ä‘Æ°á»£c** (`hasUsableLocalWebpCopy`). **KhÃ´ng** backfill khi Ä‘Ã£ fallback JPEG (`hasPersistentOptimizedUploadFallback` â€” file `-wp-upload.jpg` tá»“n táº¡i): trÃ¡nh vÃ²ng láº·p â€œURL WP lÃ  JPG â†’ sync láº¡i â†’ import attachment má»›iâ€ (nguyÃªn nhÃ¢n 3 file WP cho 2 áº£nh bÃ i).

| HÃ m | Khi nÃ o `true` |
|-----|----------------|
| `needsWordPressWebpBackfill` | `auto_convert_webp` + file local há»£p lá»‡ + sibling `.webp` há»£p lá»‡ + URL WP chÆ°a `.webp` + **khÃ´ng** cÃ³ `-wp-upload.jpg` |
| `hasPersistentOptimizedUploadFallback` | Sibling `-wp-upload.{jpg\|png\|â€¦}` há»£p lá»‡ (signature OK) |

**Attachment WP Ä‘Ã£ xÃ³a thá»§ cÃ´ng:** `syncMedia` tháº¥y `wp_attachment_id > 0` nhÆ°ng `fetchWordPressAttachmentUrl` rá»—ng â†’ clear `wp_attachment_id` / `wp_synced_at` â†’ **import má»›i** (khÃ´ng cá»‘ `replace-binary` lÃªn ID cháº¿t).

**áº¢nh local sau sync:** KhÃ´ng set `status=trash`. Chá»‰ xÃ³a local khi **Reviewed** (`ArticleResource::markArticleReviewed` â†’ `deleteLocalMediaForArticle`). áº¢nh `trash` Ä‘Æ°á»£c restore `completed` khi sync láº¡i.

**Workspace Picker route** (`GET /api/seo/media/workspace-picker`): Xá»­ lÃ½ bá»Ÿi `WorkspaceMediaPickerController` riÃªng, khÃ´ng pháº£i `SeoMediaController`.

### SeoMediaBuilder

`SeoMedia` override `newEloquentBuilder()` â†’ `SeoMediaBuilder`. `where`/`update` trÃªn field meta Ä‘Æ°á»£c route sang `seo_media_meta`.

---

## 2.1.1 Pipeline resize & encode áº£nh

Pipeline trung tÃ¢m cho má»i thao tÃ¡c resize/encode trong addon SEO. Thay tháº¿ `Intervention::scaleDown()` trá»±c tiáº¿p â€” Æ°u tiÃªn **native Imagick** (Lanczos, sRGB, progressive scale, unsharp mask), fallback **Intervention Image** (driver Imagick hoáº·c GD).

```mermaid
flowchart TB
    subgraph Entry["Äiá»ƒm gá»i"]
        UP["processUpload / processBinary"]
        RL["resizeLocal / resizeBinary"]
        WP["prepareWordPressUploadFile"]
        LIM["applyMaxDimensions"]
    end

    subgraph Opt["SeoImageOptimizationService"]
        CFG["SeoImageOptimizationSetting<br/>max_width/height, quality, auto_convert_webp"]
    end

    subgraph Pipe["SeoImagePipeline"]
        DIM["SeoImageResizeMath<br/>outputDimensions, progressiveScaleSteps"]
        TRY_I["tryResizeWithImagick / tryEncodeImagickSourceToPath"]
        ENC_DST["encodeSourceToPath<br/>source â†’ dest Ä‘Ãºng extension"]
        FALL["resizeWithIntervention / encode fallback"]
    end

    subgraph Driver["ImageDriverResolver (core)"]
        IMAGICK["supportsImagick()"]
        GD["supportsGd()"]
        ENV["env IMAGE_DRIVER (optional)"]
    end

    subgraph Out["Káº¿t quáº£"]
        LOCAL["Disk local: PNG chá»§ Ä‘áº¡o<br/>lossless, giá»¯ alpha"]
        WP_OUT["Upload WP: .webp hoáº·c -wp-upload.jpg<br/>sibling persistent, gá»‘c khÃ´ng Ä‘á»•i"]
    end

    UP & LIM --> CFG --> Pipe
    RL --> Pipe
    WP --> CFG --> Pipe
    Pipe --> DIM
    TRY_I -->|"extension_loaded('imagick')"| IMAGICK
    TRY_I -->|"catch Throwable"| FALL
    FALL --> ENV --> IMAGICK & GD
    UP & RL & LIM --> LOCAL
    WP --> WP_OUT
```

| ThÃ nh pháº§n | File | Vai trÃ² |
|------------|------|---------|
| Pipeline | `Support/SeoImagePipeline.php` | `resizeFile`, `encodeFile`, `encodeSourceToPath` (Imagick coalesce + alpha), `applyMaxDimensions`; log driver qua `lastDriver()` |
| Validate convert | `Support/SeoConvertedImageValidator.php`, `ImageContentSignature.php`, `ImageContentSignatureSampler.php` | Signature sourceâ†”output; `fully_transparent_canvas` / `content_collapsed_*` |
| Imagick pixel compat | `Support/ImagickPixelColor.php` | `normalized()` gá»i `getColor(1)` trÆ°á»›c, fallback `getColor(true)` cho Imagick cÅ©; dÃ¹ng trong pipeline encode + signature sampler |
| Pipeline encode | `Support/SeoImagePipeline.php` | Bá» `ALPHACHANNEL_ACTIVATE`; assert visible trÆ°á»›c flatten; fresh decode má»—i encode |
| ToÃ¡n resize | `Support/SeoImageResizeMath.php` | Má»™t chiá»u (width **hoáº·c** height); upscale ~1.5Ã—/bÆ°á»›c; downscale >2Ã— chia ~50%/bÆ°á»›c |
| Driver | `app/Support/ImageDriverResolver.php` | `supportsImagick()`, `supportsGd()`, `shouldUseNativeImagickPipeline()`; Intervention Æ°u tiÃªn Imagick |
| Tá»‘i Æ°u + upload | `Services/SeoImageOptimizationService.php` | `processUpload`/`processBinary` â†’ `processOriginalBytes` (transactional); `prepareWordPressUploadFile`, `ensureLocalWebpCopy`, `ensureLocalWebpUnderMaxBytes`, `validateConvertedImage`; log `SEO_MEDIA_WEBP_*` / `SEO_MEDIA_SOURCE_DECODE_FAILED` |
| Resize thá»§ cÃ´ng | `Services/SeoMediaResizeService.php` | `resizeLocal` (ghi Ä‘Ã¨ file `public`), `resizeBinary` (in-memory / workflow) |
| Media Library UI | `Services/SeoMediaLibraryImageActionService.php` | Quick resize â†’ `resizeLocal` |
| Post-processing | `Services/PromptPostProcessingApplyService.php` | Quick Split NÃ—N tá»« snapshot `quick_split` + `QuickSplitCanvasValidator`; fail-safe giá»¯ áº£nh gá»‘c; resize child sau split |
| Runtime output mode | `Services/ImageOutputModePromptInjector.php` | Block `[IMAGE_OUTPUT_MODE_*]` khi compile image prompt (khÃ´ng DB Hook) |
| Config normalize | `Support/PromptPostProcessing.php` | `split_grid_size` (legacy rows/cols â†’ square) |
| Sync WP | `Services/WordPressLocalMediaSyncService.php` | `syncHtml`, `syncMedia`, `syncWebpBackfillMediaForArticle`, `prepareWordPressUploadFile` trÆ°á»›c khi push attachment |

### Chiáº¿n lÆ°á»£c Ä‘á»‹nh dáº¡ng

| Giai Ä‘oáº¡n | Äá»‹nh dáº¡ng | Ghi chÃº |
|-----------|-----------|---------|
| LÆ°u local (upload, import, save-edited, resize) | **PNG** chá»§ Ä‘áº¡o | `normalizeExtension` fallback `png`; Imagick PNG compression level 3, quality 100 |
| Äá»“ng bá»™ WordPress | **WebP** khi encode OK vÃ  â‰¤ 100KB | Sibling `{basename}.webp`. Ladder long-edge náº¿u >100KB. **Fallback:** original â‰¤100KB â†’ `-wp-upload.{origExt}` â†’ JPEG chá»‰ khi cáº§n size. **Backfill:** chá»‰ sibling `.webp` há»£p lá»‡. Plugin **â‰¥ 1.0.50**. |
| JPEG / GIF / WebP | Há»— trá»£ khi nguá»“n yÃªu cáº§u | Encode quality tá»« `SeoImageOptimizationSetting.quality` (máº·c Ä‘á»‹nh pipeline 95 qua `ImageDriverResolver::ENCODE_QUALITY`) |

### Native Imagick (khi cÃ³ extension)

1. `transformImageColorspace(SRGB)` (khÃ´ng dÃ¹ng `setImageColorspace` â€” dá»… WebP blank); PNG/WebP giá»¯ alpha; multi-frame `coalesceImages`.
2. `progressiveScaleSteps` â€” nhiá»u bÆ°á»›c Lanczos thay vÃ¬ má»™t láº§n thu/phÃ³ng lá»›n.
3. `unsharpMaskImage` sau upscale (máº¡nh) hoáº·c downscale nháº¹.
4. `try/catch` â€” lá»—i Imagick â†’ log warning â†’ Intervention fallback.
5. Sau encode: `SeoConvertedImageValidator` â€” signature sourceâ†”output; reject transparent/collapsed.

### Fallback Intervention

- Driver: `ImageDriverResolver::interventionDriverClass()` â€” Imagick náº¿u cÃ³, khÃ´ng thÃ¬ GD.
- CÃ¹ng progressive steps + `sharpen()` tÆ°Æ¡ng á»©ng upscale/downscale.
- KhÃ´ng cÃ³ imagick **vÃ ** gd â†’ `RuntimeException` khi resolve driver.

### Cáº£nh bÃ¡o Dashboard

`Filament/Pages/Dashboard.php` â†’ `mount()` â†’ `notifyImageDriverStatus()`:

| Äiá»u kiá»‡n | Má»©c | i18n key |
|-----------|-----|----------|
| Thiáº¿u Imagick, cÃ²n GD | `warning` | `dashboard.imagick_missing_*` |
| KhÃ´ng cÃ³ imagick vÃ  gd | `danger` | `dashboard.image_driver_missing_*` |

**LÆ°u Ã½ hosting:** Imagick pháº£i báº­t cho **PHP-FPM** (khÃ´ng chá»‰ CLI). `Imagick::queryFormats()` cÃ³ `WEBP` nhÆ°ng thiáº¿u `libwebp` runtime váº«n cÃ³ thá»ƒ fail encode â†’ há»‡ thá»‘ng tá»± fallback JPEG. Sau khi báº­t extension: `php artisan config:clear`.

### Test liÃªn quan

| Test | Pháº¡m vi |
|------|---------|
| `tests/Unit/SeoImageResizeMathTest.php` | `outputDimensions`, `progressiveUpscaleSteps`, `progressiveScaleSteps` |
| `tests/Unit/ImageDriverResolverTest.php` | Driver preference, `hasAnyDriver` |
| `tests/Unit/SeoConvertedImageValidatorTest.php` | Transparent blank; content collapse black/white vs source; solid color source OK; logo alpha OK |
| `tests/Unit/SeoImageOptimizationServiceTest.php` | WebP/original/JPEG fallback, ladder, block blank, diagnose, immutable source, PNG bytesâ‰ `.webp` |

---

## HÆ°á»›ng dáº«n prompt Cursor â€” Upload / thÆ° viá»‡n / watermark

```
Route: SeoPanelProvider.php prefix api/seo/media.
Controller: Http/Controllers/SeoMediaController.php.
Services: SeoMediaStorageService, SeoImageOptimizationService, SeoMediaResizeService, SeoWatermarkService, **SeoMediaUrlReplacementService**, **SeoMediaArticleSlugFixService**.
Pipeline: Support/SeoImagePipeline.php + Support/SeoImageResizeMath.php.
Driver: app/Support/ImageDriverResolver.php (imagick/gd, env IMAGE_DRIVER).
Model/Query: Models/SeoMedia.php + Models/SeoMediaBuilder.php (meta routing).
Frontend: seoMediaApi.js, components/ArticleImagesTab.jsx, ImageBlockEditor.jsx, `utils/brokenImageGuard.js` (404 â†’ placeholder tÄ©nh, khÃ´ng retry), `resolveArticleImageRemoveTarget` (disable XÃ³a khi áº£nh stale khÃ´ng khá»›p).
Article Editor slug fix: `POST /api/seo/articles/{id}/fix-media-slugs` â€” batch rename + rewrite `article.body`/meta (`SeoMediaArticleSlugFixService`) tráº£ `renamed[]` exact map. Single rename (`rename`/`rename-by-url`) nháº­n `article_id` â†’ rewrite article refs. **WP Fix slug:** `renameAttachmentSlugsOnWordPress` cÅ©ng rewrite Laravel refs qua `SeoMediaUrlReplacementService` (stem + `-WxH`). **Fix slug all** (editor): save â†’ rename â†’ apply map vÃ o TipTap/blocks â†’ invalidate Gallery/Images/picker â†’ save láº¡i â€” chi tiáº¿t [docs/article-editor/image-slug-rename.md](article-editor/image-slug-rename.md). KhÃ´ng full page reload.
Watermark batch: POST /api/seo/watermark/* â†’ SeoWatermarkController.
Image Optimization Settings: SeoImageOptimizationSetting model + ImageOptimizationSettings page.
AI Image Processing: ImageProcessingPage.php + /api/seo/media/prepare-editor.
WP Media Backup: Models SeoWpMediaBackup, SeoWpMediaEditedPending.
Dashboard: cáº£nh bÃ¡o thiáº¿u Imagick/GD khi mount Filament Dashboard.
```

### API surface (frontend)

| Client module | Endpoints |
|---------------|-----------|
| `seoMediaApi.js` | `POST upload`, `import-url`, `prepare-editor`, `apply-watermark`, `rename-by-url`, `rename` (optional `article_id`), `update-meta`, `save-split`, `save-edited`, `retry-generation`, `delete-ai-job`; `POST /api/seo/articles/{id}/fix-media-slugs` (`fixArticleMediaSlugs`); `GET splitter-source`, `article/{id}/ai-jobs`, `{media}/status`, `workspace-picker` |
| `watermarkApi.js` | `POST /api/seo/watermark/*` (settings, batch, save, save-new) |

**LiÃªn quan editor:** [MAP_SEO_EDITOR.md](MAP_SEO_EDITOR.md) â€” tab Images, media picker modal, video generation.

---

## 2.2 Trang chá»‰nh sá»­a áº£nh (`/seo/media-image-editor`)

Filament page toÃ n mÃ n hÃ¬nh cho magic eraser + image splitter. KhÃ´ng náº±m trong menu; má»Ÿ qua query `?media={seo_media_id}&tab=eraser|splitter`.

```mermaid
flowchart LR
    subgraph Entry["Má»Ÿ editor"]
        TAB_IMG["ArticleImagesTab<br/>Edit Image / Split grid"]
        MEDIA_LIB["Media Library"]
    end

    subgraph Page["MediaImageEditor.php"]
        MOUNT["mount(media, tab)"]
        VIEW["media-image-editor.blade.php"]
    end

    subgraph JS["media-image-editor-page.jsx"]
        APP["MagicEraserApp"]
        SPLIT["ImageSplitterPanel"]
    end

  subgraph API["REST"]
        PREP["POST /api/seo/media/prepare-editor"]
        SPLIT_API["POST /api/seo/media/save-split"]
    end

    TAB_IMG --> MOUNT
    MEDIA_LIB --> MOUNT
    MOUNT --> VIEW --> APP & SPLIT
    APP --> PREP
    SPLIT --> SPLIT_API
```

| ThÃ nh pháº§n | File |
|------------|------|
| Filament page | `Filament/Pages/MediaImageEditor.php` â€” slug `media-image-editor` |
| Blade + Vite | `resources/views/filament/pages/media-image-editor.blade.php` â†’ `media-image-editor-page.jsx` |
| URL builder | `seoMediaApi.js` â†’ `buildMediaImageEditorUrl({ seoMediaId, tab })` |
| Multi-tenant hash | `/seo/{connectionHash}/media-image-editor?media=â€¦` |
| Split lÆ°á»›i (product gallery) | Modal `.seo-generate-image-modal` â€” `GenerateImageModal.jsx` + `ImageSplitterPanel` (`canDeleteOriginal=false`, giá»¯ áº£nh gá»‘c) |

**Product gallery:** split nhanh trÃªn thumbnail sidebar Ä‘Ã£ bá»; split album sáº£n pháº©m thá»±c hiá»‡n trong modal táº¡o áº£nh AI (chá»n áº£nh preview â†’ panel Split grid).

---

## 2.3 Image Processing & AI Enhance

### ImageProcessingPage (`/seo/image-processing`)

Filament page riÃªng cho AI image enhancement (magic eraser, background removal, upscale). Entry tá»« Media Library hoáº·c Image Editor.

- **Page:** `Filament/Pages/ImageProcessingPage.php`
- **Entry:** Via `POST /api/seo/media/prepare-editor` Ä‘á»ƒ chuáº©n bá»‹ áº£nh cho AI processing
- **Jobs tracking:** `GET /api/seo/media/article/{article}/ai-jobs` â€” `processing`/`failed` + **completed 2h gáº§n Ä‘Ã¢y** (editor reconcile placeholder); `SeoMediaController::articleAiJobs`
- **Retry:** `POST /api/seo/media/{media}/retry-generation` retry AI job failed
- **Delete:** `DELETE /api/seo/media/{media}/ai-job` xÃ³a job

### Image routing stack (Phase 1 + 2 + version policy)

| Symbol | File | Vai trÃ² |
|--------|------|---------|
| `ImageToolType` | `Support/ImageToolType.php` | Enum tool: image, image_typography, video, â€¦ |
| `ImageCapability` | `Support/ImageCapability.php` | Capability matrix (render, typography_supported, image_input, â€¦) |
| `ImageCapabilityResolver` | `Support/ImageCapabilityResolver.php` | Map slug â†’ capability; unknown slug â†’ `unknown` (khÃ´ng gÃ¡n text_generation) |
| `ImageRoutingStrategy` | `Support/ImageRoutingStrategy.php` | Chá»n model/render policy; gate `GeminiModelVersionPolicy`; typography `executionPolicy()` |
| `ImageRoutingExecutionPolicy` | `Support/ImageRoutingExecutionPolicy.php` | DTO candidate count, resolution, validation threshold |
| `GeminiModelVersionPolicy` | `Support/GeminiModelVersionPolicy.php` | Auto-routing chá»‰ major â‰¥ 3; `routing_status`/`disabled_reason` |
| `VisionValidationModelRouter` | `Support/VisionValidationModelRouter.php` | Failover Vision models cho typography validation |
| `GeminiMediaGenerationService` | `Services/GeminiMediaGenerationService.php` | Render + log `render_model`; unavailable â†’ mark + retry next |
| `MediaGenerationService` | `Services/MediaGenerationService.php` | Entry image gen; delegate typography â†’ `TypographyPipelineService` |
| `TypographyPipelineService` | `Services/TypographyPipelineService.php` | N candidate â†’ Vision â†’ winner; validation fail khÃ´ng há»§y áº£nh Ä‘Ã£ render |
| `EditorWorkflowExecutionService` | `Services/EditorWorkflowExecutionService.php` | Editor `source=workflow` â†’ full graph qua `TaskWorkflowTestRunner::run()`; BC `extract_last_prompt_bc` náº¿u graph khÃ´ng tráº£ media |
| `TaskWorkflowTestRunner` | `Services/TaskWorkflowTestRunner.php` | Tool image/`image_typography`: `runFullDependentChain=false` (khÃ´ng Ã©p text Flash trÃªn parent) â€” parity Test Prompt |

**Settings routing UI:** `SeoSettingsAiAdvanced` (priority + typography validation); Editor/Workflows chá»‰ Prompt\|Workflow slot â€” xem [MAP_SEO_SETTINGS.md](MAP_SEO_SETTINGS.md).

### Typography candidate (khÃ´ng spam thÆ° viá»‡n)

| Service | Vai trÃ² |
|---------|---------|
| `TypographyCandidateGenerationService` | Sinh N candidate qua `GeminiMediaGenerationService::generateImageBinary` â€” chá»‰ **temp disk** (`TypographyTemporaryStorageService`), khÃ´ng `seo_media` |
| `TypographyValidationService` | Vision scoring qua `VisionValidationModelRouter`; log `validation_model` |
| `GeminiMediaGenerationService` | `generateImageBinary` = render binary; `generateImage` = persist qua `PromptMediaStorageService::storeBinaryMedia` (gáº¯n placeholder) |
| `TypographyPipelineService` | Chá»n winner â†’ **má»™t** láº§n `storeBinaryMedia` vÃ o job placeholder |
| `GenerateMediaJob` | Skip náº¿u media Ä‘Ã£ `failed` hoáº·c `completed`; nhÃ¡nh `source=workflow` vs `prompt`; freeze snapshot `quick_split` lÃºc cháº¡y; apply post-processing + `quick_split_error*` khi fail |

### ImageOptimizationSettings (`/seo/settings/image-optimization`)

- **Page:** `Filament/Pages/ImageOptimizationSettings.php`
- **Model:** `SeoImageOptimizationSetting` (table `seo_image_optimization_settings`)
- Cáº¥u hÃ¬nh: `auto_convert_webp`, `quality`, `limit_dimensions`, `max_width` / `max_height` (má»™t chiá»u hoáº·c Æ°u tiÃªn width khi cáº£ hai > 0), `clean_filename`, `auto_alt_tag`
- **Local:** upload/import qua `processUpload` / `processBinary` â€” giá»›i háº¡n kÃ­ch thÆ°á»›c + encode PNG (pipeline)
- **WordPress:** `prepareWordPressUploadFile` â€” WebP Æ°u tiÃªn; fail â†’ gá»‘c/`-wp-upload.*`; chá»‰ fail khi gá»‘c undecodeable; >100KB khÃ´ng cháº·n sync.

### Save Edited Image

`POST /api/seo/media/{media}/save-edited` â†’ lÆ°u áº£nh Ä‘Ã£ chá»‰nh sá»­a (crop/resize/AI edit). Táº¡o báº£n backup trong `seo_wp_media_backups` trÆ°á»›c khi ghi Ä‘Ã¨. Náº¿u bÃ i Ä‘Ã£ sync WP â†’ táº¡o pending record trong `seo_wp_media_edited_pending`.

**Models backup:**
- `SeoWpMediaBackup` (table `seo_wp_media_backups`) â€” backup áº£nh gá»‘c trÆ°á»›c khi edit
- `SeoWpMediaEditedPending` (table `seo_wp_media_edited_pending`) â€” pending changes cáº§n push lÃªn WP

---

## 2.4 Watermark

### Route group (`/api/seo/watermark/*`)

| Method | Path | Controller Action |
|--------|------|-------------------|
| GET | `/api/seo/watermark/settings` | `SeoWatermarkController@showSettings` |
| POST | `/api/seo/watermark/settings` | `SeoWatermarkController@saveSettings` |
| POST | `/api/seo/watermark/batch` | `SeoWatermarkController@applyBatch` |
| POST | `/api/seo/watermark/media/{media}/save` | `SeoWatermarkController@saveMediaWatermark` |
| POST | `/api/seo/watermark/save-new` | `SeoWatermarkController@saveNewFromCanvas` |

**Controller:** `Http/Controllers/SeoWatermarkController.php` (riÃªng, khÃ´ng láº«n vá»›i SeoMediaController)

### Filament Pages

| Page | Route | Vai trÃ² |
|------|-------|---------|
| `WatermarkEditor.php` | `/seo/watermark-editor` | **Watermark design suite** â€” thiáº¿t káº¿ Ä‘Ã³ng dáº¥u theo domain (canvas React, lÆ°u `design_config` + overlay PNG). Domain máº·c Ä‘á»‹nh tá»« `SeoAccessControl::globalSiteId()`. |
| `WatermarkSettingsPage.php` | `/seo/watermark-settings-page` | **Batch apply** â€” Ä‘Ã³ng dáº¥u hÃ ng loáº¡t + tá»‘i Æ°u áº£nh (local + WordPress). KhÃ´ng cÃ²n form Â«Automatic watermark settingsÂ»; cáº¥u hÃ¬nh thiáº¿t káº¿ chá»‰ qua design suite. |

**Luá»“ng cáº¥u hÃ¬nh:** Design suite lÆ°u thiáº¿t káº¿ â†’ `auto_watermark=true` (tá»± Ä‘á»™ng Ä‘Ã³ng dáº¥u khi upload/paste). Batch page chá»‰ cháº¡y xá»­ lÃ½ hÃ ng loáº¡t trÃªn áº£nh Ä‘Ã£ cÃ³.

### Watermark Service

`SeoWatermarkService` â€” `applyToMediaIfEnabled()` Ä‘Æ°á»£c gá»i tá»« upload pipeline khi `auto_watermark` vÃ  thiáº¿t káº¿ Ä‘Ã£ lÆ°u. Batch processing qua `applyBatchAllForSite()` / `applyBatch()`.

### Save New From Canvas

`POST /api/seo/watermark/save-new` â†’ nháº­n canvas data URL tá»« WatermarkEditor â†’ táº¡o watermark image má»›i â†’ lÆ°u vÃ o storage.
