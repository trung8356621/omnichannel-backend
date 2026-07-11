# SeoContentAi — Media API & Thư viện ảnh

[← Quay lại Bản đồ tổng](SUPER_MAP_INDEX.md)

**Liên quan:** [React Editor](MAP_SEO_EDITOR.md) · [Content Projects](MAP_SEO_PROJECTS.md)

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
        DBBOOT["SetDynamicSeoDatabase<br/>→ SeoDatabaseRequestBootstrap"]
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
        IMPORT["importFromUrl()"]
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
        PIPELINE["SeoImagePipeline<br/>resize + encode<br/>Imagick native → Intervention"]
        RESIZE["SeoMediaResizeService<br/>resizeLocal, resizeBinary"]
        MATH["SeoImageResizeMath<br/>dimensions + progressive steps"]
        DRIVER["ImageDriverResolver<br/>app/Support — imagick/gd"]
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

**Trace MCP (`upload` outbound, depth 4):** `SeoMediaController.upload` → `SeoMediaStorageService.storeUpload` → `SeoImageOptimizationService.processUpload` → `SeoImagePipeline.applyMaxDimensions` + `encodeFile` → `SeoWatermarkService.applyToMediaIfEnabled` → `SeoMedia::create` qua `SeoMediaBuilder.where/update`.

**Trace resize (Media Library / workflow):** `SeoMediaLibraryImageActionService` hoặc `PromptPostProcessingApplyService` → `SeoMediaResizeService.resizeLocal|resizeBinary` → `SeoImagePipeline.resizeFile`.

**Trace đồng bộ WP:** `WordPressArticleSyncService.prepareEditorSyncPayload` → `WordPressLocalMediaSyncService.syncHtml` → `syncMedia` → `SeoImageOptimizationService.prepareWordPressUploadFile` → `POST …/attachments/import` hoặc `…/replace-binary` (plugin **≥ 1.0.50** đổi extension sang `.webp` khi mime `image/webp`) — **không ghi đè file PNG/JPG gốc** trên disk Laravel.

**File upload WordPress (`prepareWordPressUploadFile`):**

| Thứ tự | Điều kiện | File upload | Ghi chú |
|--------|-----------|-------------|---------|
| 1 | `auto_convert_webp` + encode WebP OK | `{slug}.webp` cạnh file gốc | Persistent sibling; `encodeSourceToPath` ghi đúng extension |
| 2 | WebP fail | `{slug}-wp-upload.jpg` ≤ **100KB** | `ensureLocalOptimizedUploadCopy` — giảm quality rồi scale |
| 3 | WebP fail + không nén được < 100KB nhưng gốc < 100KB | File gốc | Log warning |
| 4 | Còn lại | `null` → sync ảnh thất bại | |

**`syncHtml` — tránh import trùng (mỗi lượt sync):**

1. Quét `<img>` local → ưu tiên `data-seo-media-id` (không chỉ lookup theo `path`).
2. Mỗi `seo_media.id` chỉ gọi `syncMedia` **một lần** trong lượt (`$syncedThisPass`).
3. Vòng `applyWpUrlsToSeoMediaImages`: nếu ảnh đã sync trong lượt → chỉ patch `src` từ cache, **không** `forgetMediaCache` + re-import.

**WebP backfill** (`syncWebpBackfillMediaForArticle`, sau `completeEditorSyncResponse`):

Chỉ chạy khi **bản WebP local thật sự dùng được** (`hasUsableLocalWebpCopy`). **Không** backfill khi đã fallback JPEG (`hasPersistentOptimizedUploadFallback` — file `-wp-upload.jpg` tồn tại): tránh vòng lặp “URL WP là JPG → sync lại → import attachment mới” (nguyên nhân 3 file WP cho 2 ảnh bài).

| Hàm | Khi nào `true` |
|-----|----------------|
| `needsWordPressWebpBackfill` | `auto_convert_webp` + file local hợp lệ + sibling `.webp` hợp lệ + URL WP chưa `.webp` + **không** có `-wp-upload.jpg` |
| `hasPersistentOptimizedUploadFallback` | Sibling `-wp-upload.jpg` mới hơn hoặc bằng mtime file gốc |

**Attachment WP đã xóa thủ công:** `syncMedia` thấy `wp_attachment_id > 0` nhưng `fetchWordPressAttachmentUrl` rỗng → clear `wp_attachment_id` / `wp_synced_at` → **import mới** (không cố `replace-binary` lên ID chết).

**Ảnh local sau sync:** Không set `status=trash`. Chỉ xóa local khi **Reviewed** (`ArticleResource::markArticleReviewed` → `deleteLocalMediaForArticle`). Ảnh `trash` được restore `completed` khi sync lại.

**Workspace Picker route** (`GET /api/seo/media/workspace-picker`): Xử lý bởi `WorkspaceMediaPickerController` riêng, không phải `SeoMediaController`.

### SeoMediaBuilder

`SeoMedia` override `newEloquentBuilder()` → `SeoMediaBuilder`. `where`/`update` trên field meta được route sang `seo_media_meta`.

---

## 2.1.1 Pipeline resize & encode ảnh

Pipeline trung tâm cho mọi thao tác resize/encode trong addon SEO. Thay thế `Intervention::scaleDown()` trực tiếp — ưu tiên **native Imagick** (Lanczos, sRGB, progressive scale, unsharp mask), fallback **Intervention Image** (driver Imagick hoặc GD).

```mermaid
flowchart TB
    subgraph Entry["Điểm gọi"]
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
        ENC_DST["encodeSourceToPath<br/>source → dest đúng extension"]
        FALL["resizeWithIntervention / encode fallback"]
    end

    subgraph Driver["ImageDriverResolver (core)"]
        IMAGICK["supportsImagick()"]
        GD["supportsGd()"]
        ENV["env IMAGE_DRIVER (optional)"]
    end

    subgraph Out["Kết quả"]
        LOCAL["Disk local: PNG chủ đạo<br/>lossless, giữ alpha"]
        WP_OUT["Upload WP: .webp hoặc -wp-upload.jpg<br/>sibling persistent, gốc không đổi"]
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

| Thành phần | File | Vai trò |
|------------|------|---------|
| Pipeline | `Support/SeoImagePipeline.php` | `resizeFile`, `encodeFile`, `encodeSourceToPath`, `applyMaxDimensions`; log driver qua `lastDriver()` |
| Toán resize | `Support/SeoImageResizeMath.php` | Một chiều (width **hoặc** height); upscale ~1.5×/bước; downscale >2× chia ~50%/bước |
| Driver | `app/Support/ImageDriverResolver.php` | `supportsImagick()`, `supportsGd()`, `shouldUseNativeImagickPipeline()`; Intervention ưu tiên Imagick |
| Tối ưu + upload | `Services/SeoImageOptimizationService.php` | `prepareWordPressUploadFile`, `ensureLocalWebpCopy`, `ensureLocalOptimizedUploadCopy`, `needsWordPressWebpBackfill`, `WORDPRESS_UPLOAD_FALLBACK_MAX_BYTES` (102400) |
| Resize thủ công | `Services/SeoMediaResizeService.php` | `resizeLocal` (ghi đè file `public`), `resizeBinary` (in-memory / workflow) |
| Media Library UI | `Services/SeoMediaLibraryImageActionService.php` | Quick resize → `resizeLocal` |
| Post-processing | `Services/PromptPostProcessingApplyService.php` | Resize ảnh AI / block → `resizeBinary` hoặc `resizeLocal` |
| Sync WP | `Services/WordPressLocalMediaSyncService.php` | `syncHtml`, `syncMedia`, `syncWebpBackfillMediaForArticle`, `prepareWordPressUploadFile` trước khi push attachment |

### Chiến lược định dạng

| Giai đoạn | Định dạng | Ghi chú |
|-----------|-----------|---------|
| Lưu local (upload, import, save-edited, resize) | **PNG** chủ đạo | `normalizeExtension` fallback `png`; Imagick PNG compression level 3, quality 100 |
| Đồng bộ WordPress | **WebP** khi encode OK | Sibling `{basename}.webp`. **Fallback:** `-wp-upload.jpg` ≤ 100KB. **Backfill:** chỉ khi sibling `.webp` hợp lệ; không backfill khi đã JPEG fallback. Plugin **≥ 1.0.50**. |
| JPEG / GIF / WebP | Hỗ trợ khi nguồn yêu cầu | Encode quality từ `SeoImageOptimizationSetting.quality` (mặc định pipeline 95 qua `ImageDriverResolver::ENCODE_QUALITY`) |

### Native Imagick (khi có extension)

1. `setImageColorspace(SRGB)`; PNG bật alpha channel.
2. `progressiveScaleSteps` — nhiều bước Lanczos thay vì một lần thu/phóng lớn.
3. `unsharpMaskImage` sau upscale (mạnh) hoặc downscale nhẹ.
4. `try/catch` — lỗi Imagick → log warning → Intervention fallback.

### Fallback Intervention

- Driver: `ImageDriverResolver::interventionDriverClass()` — Imagick nếu có, không thì GD.
- Cùng progressive steps + `sharpen()` tương ứng upscale/downscale.
- Không có imagick **và** gd → `RuntimeException` khi resolve driver.

### Cảnh báo Dashboard

`Filament/Pages/Dashboard.php` → `mount()` → `notifyImageDriverStatus()`:

| Điều kiện | Mức | i18n key |
|-----------|-----|----------|
| Thiếu Imagick, còn GD | `warning` | `dashboard.imagick_missing_*` |
| Không có imagick và gd | `danger` | `dashboard.image_driver_missing_*` |

**Lưu ý hosting:** Imagick phải bật cho **PHP-FPM** (không chỉ CLI). `Imagick::queryFormats()` có `WEBP` nhưng thiếu `libwebp` runtime vẫn có thể fail encode → hệ thống tự fallback JPEG. Sau khi bật extension: `php artisan config:clear`.

### Test liên quan

| Test | Phạm vi |
|------|---------|
| `tests/Unit/SeoImageResizeMathTest.php` | `outputDimensions`, `progressiveUpscaleSteps`, `progressiveScaleSteps` |
| `tests/Unit/ImageDriverResolverTest.php` | Driver preference, `hasAnyDriver` |
| `tests/Unit/SeoImageOptimizationServiceTest.php` | WebP/JPEG fallback upload, `needsWordPressWebpBackfill`, `ensureLocalOptimizedUploadCopy`, không mutate file local |

---

## Hướng dẫn prompt Cursor — Upload / thư viện / watermark

```
Route: SeoPanelProvider.php prefix api/seo/media.
Controller: Http/Controllers/SeoMediaController.php.
Services: SeoMediaStorageService, SeoImageOptimizationService, SeoMediaResizeService, SeoWatermarkService.
Pipeline: Support/SeoImagePipeline.php + Support/SeoImageResizeMath.php.
Driver: app/Support/ImageDriverResolver.php (imagick/gd, env IMAGE_DRIVER).
Model/Query: Models/SeoMedia.php + Models/SeoMediaBuilder.php (meta routing).
Frontend: seoMediaApi.js, components/ArticleImagesTab.jsx, ImageBlockEditor.jsx.
Watermark batch: POST /api/seo/watermark/* → SeoWatermarkController.
Image Optimization Settings: SeoImageOptimizationSetting model + ImageOptimizationSettings page.
AI Image Processing: ImageProcessingPage.php + /api/seo/media/prepare-editor.
WP Media Backup: Models SeoWpMediaBackup, SeoWpMediaEditedPending.
Dashboard: cảnh báo thiếu Imagick/GD khi mount Filament Dashboard.
```

### API surface (frontend)

| Client module | Endpoints |
|---------------|-----------|
| `seoMediaApi.js` | `POST upload`, `import-url`, `prepare-editor`, `apply-watermark`, `rename-by-url`, `update-meta`, `save-split`, `save-edited`, `rename`, `retry-generation`, `delete-ai-job`; `GET splitter-source`, `article/{id}/ai-jobs`, `{media}/status`, `workspace-picker` |
| `watermarkApi.js` | `POST /api/seo/watermark/*` (settings, batch, save, save-new) |

**Liên quan editor:** [MAP_SEO_EDITOR.md](MAP_SEO_EDITOR.md) — tab Images, media picker modal, video generation.

---

## 2.2 Trang chỉnh sửa ảnh (`/seo/media-image-editor`)

Filament page toàn màn hình cho magic eraser + image splitter. Không nằm trong menu; mở qua query `?media={seo_media_id}&tab=eraser|splitter`.

```mermaid
flowchart LR
    subgraph Entry["Mở editor"]
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

| Thành phần | File |
|------------|------|
| Filament page | `Filament/Pages/MediaImageEditor.php` — slug `media-image-editor` |
| Blade + Vite | `resources/views/filament/pages/media-image-editor.blade.php` → `media-image-editor-page.jsx` |
| URL builder | `seoMediaApi.js` → `buildMediaImageEditorUrl({ seoMediaId, tab })` |
| Multi-tenant hash | `/seo/{connectionHash}/media-image-editor?media=…` |
| Split lưới (product gallery) | Modal `.seo-generate-image-modal` — `GenerateImageModal.jsx` + `ImageSplitterPanel` (`canDeleteOriginal=false`, giữ ảnh gốc) |

**Product gallery:** split nhanh trên thumbnail sidebar đã bỏ; split album sản phẩm thực hiện trong modal tạo ảnh AI (chọn ảnh preview → panel Split grid).

---

## 2.3 Image Processing & AI Enhance

### ImageProcessingPage (`/seo/image-processing`)

Filament page riêng cho AI image enhancement (magic eraser, background removal, upscale). Entry từ Media Library hoặc Image Editor.

- **Page:** `Filament/Pages/ImageProcessingPage.php`
- **Entry:** Via `POST /api/seo/media/prepare-editor` để chuẩn bị ảnh cho AI processing
- **Jobs tracking:** `GET /api/seo/media/article/{article}/ai-jobs` trả về danh sách job AI của article
- **Retry:** `POST /api/seo/media/{media}/retry-generation` retry AI job failed
- **Delete:** `DELETE /api/seo/media/{media}/ai-job` xóa job

### ImageOptimizationSettings (`/seo/settings/image-optimization`)

- **Page:** `Filament/Pages/ImageOptimizationSettings.php`
- **Model:** `SeoImageOptimizationSetting` (table `seo_image_optimization_settings`)
- Cấu hình: `auto_convert_webp`, `quality`, `limit_dimensions`, `max_width` / `max_height` (một chiều hoặc ưu tiên width khi cả hai > 0), `clean_filename`, `auto_alt_tag`
- **Local:** upload/import qua `processUpload` / `processBinary` — giới hạn kích thước + encode PNG (pipeline)
- **WordPress:** `prepareWordPressUploadFile` chỉ lúc sync — sibling `.webp` hoặc `-wp-upload.jpg` (≤ 100KB); không đổi file gốc `uploads/seo_media/*.png`

### Save Edited Image

`POST /api/seo/media/{media}/save-edited` → lưu ảnh đã chỉnh sửa (crop/resize/AI edit). Tạo bản backup trong `seo_wp_media_backups` trước khi ghi đè. Nếu bài đã sync WP → tạo pending record trong `seo_wp_media_edited_pending`.

**Models backup:**
- `SeoWpMediaBackup` (table `seo_wp_media_backups`) — backup ảnh gốc trước khi edit
- `SeoWpMediaEditedPending` (table `seo_wp_media_edited_pending`) — pending changes cần push lên WP

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

**Controller:** `Http/Controllers/SeoWatermarkController.php` (riêng, không lẫn với SeoMediaController)

### Filament Pages

| Page | Route | Vai trò |
|------|-------|---------|
| `WatermarkEditor.php` | `/seo/watermark-editor` | **Watermark design suite** — thiết kế đóng dấu theo domain (canvas React, lưu `design_config` + overlay PNG). Domain mặc định từ `SeoAccessControl::globalSiteId()`. |
| `WatermarkSettingsPage.php` | `/seo/watermark-settings-page` | **Batch apply** — đóng dấu hàng loạt + tối ưu ảnh (local + WordPress). Không còn form «Automatic watermark settings»; cấu hình thiết kế chỉ qua design suite. |

**Luồng cấu hình:** Design suite lưu thiết kế → `auto_watermark=true` (tự động đóng dấu khi upload/paste). Batch page chỉ chạy xử lý hàng loạt trên ảnh đã có.

### Watermark Service

`SeoWatermarkService` — `applyToMediaIfEnabled()` được gọi từ upload pipeline khi `auto_watermark` và thiết kế đã lưu. Batch processing qua `applyBatchAllForSite()` / `applyBatch()`.

### Save New From Canvas

`POST /api/seo/watermark/save-new` → nhận canvas data URL từ WatermarkEditor → tạo watermark image mới → lưu vào storage.
