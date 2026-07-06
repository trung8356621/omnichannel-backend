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
        IMGOPT["SeoImageOptimizationService<br/>processUpload"]
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

**Trace MCP (`upload` outbound, depth 4):** `SeoMediaController.upload` → `SeoMediaStorageService.storeUpload` → `SeoImageOptimizationService.processUpload` → `SeoWatermarkService.applyToMediaIfEnabled` → `SeoMedia::create` qua `SeoMediaBuilder.where/update`.

**Workspace Picker route** (`GET /api/seo/media/workspace-picker`): Xử lý bởi `WorkspaceMediaPickerController` riêng, không phải `SeoMediaController`.

### SeoMediaBuilder

`SeoMedia` override `newEloquentBuilder()` → `SeoMediaBuilder`. `where`/`update` trên field meta được route sang `seo_media_meta`.

---

## Hướng dẫn prompt Cursor — Upload / thư viện / watermark

```
Route: SeoPanelProvider.php prefix api/seo/media.
Controller: Http/Controllers/SeoMediaController.php.
Services: SeoMediaStorageService, SeoImageOptimizationService, SeoWatermarkService.
Model/Query: Models/SeoMedia.php + Models/SeoMediaBuilder.php (meta routing).
Frontend: seoMediaApi.js, components/ArticleImagesTab.jsx, ImageBlockEditor.jsx.
Watermark batch: POST /api/seo/watermark/* → SeoWatermarkController.
Image Optimization Settings: SeoImageOptimizationSetting model + services.
AI Image Processing: ImageProcessingPage.php + /api/seo/media/prepare-editor.
WP Media Backup: Models SeoWpMediaBackup, SeoWpMediaEditedPending.
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
- Cấu hình: format ảnh (WebP/AVIF), quality, kích thước tối đa, auto-compression

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

- `WatermarkSettingsPage.php` — cấu hình watermark mặc định (vị trí, opacity, size)
- `WatermarkEditor.php` — design suite cho watermark (canvas editor + preview)

### Watermark Service

`SeoWatermarkService` — `applyToMediaIfEnabled()` được gọi từ upload pipeline. Batch processing qua `applyBatch()`.

### Save New From Canvas

`POST /api/seo/watermark/save-new` → nhận canvas data URL từ WatermarkEditor → tạo watermark image mới → lưu vào storage.
