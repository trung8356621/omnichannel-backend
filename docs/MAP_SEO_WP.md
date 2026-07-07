# SeoContentAi — WordPress Bridge & Sync

[← Quay lại Bản đồ tổng](SUPER_MAP_INDEX.md)

**Liên quan:** [React Editor](MAP_SEO_EDITOR.md) · [Content Projects & Workflow](MAP_SEO_PROJECTS.md)

---

## 2.3 WordPress Bridge (inbound từ plugin WP)

```mermaid
flowchart LR
    WP["WordPress Plugin<br/>omi-seo-ai-bridge"]
    API["/api/seo-wp-bridge/*<br/>routes/api.php"]
    CTRL["SeoWpBridgeController<br/>(Api/ subfolder)"]
    DB_SVC["SeoDatabaseConnectionService<br/>bootstrapBySiteId"]
    SYNC["SyncDomainContentService.sync"]
    DB["omi_seo_ai"]
    CORE["sites (mysql)<br/>seo_read_token"]

    WP -->|"Bearer token"| API --> CTRL
    CTRL --> CORE
    CTRL --> DB_SVC --> DB
    CTRL -->|"pushContent"| SYNC --> DB
```

**Endpoints:**
| Method | Path | Action |
|--------|------|--------|
| GET | `/api/seo-wp-bridge/ping` | Kiểm tra token + domain |
| POST | `/api/seo-wp-bridge/push-content` | Đồng bộ nội dung bài viết từ WP |

**Middleware:** `api` (không auth, không session) — authentication dùng Bearer token từ `sites.seo_read_token`.

---

## 2.4 WordPress Sync (outbound Laravel → WP)

```mermaid
flowchart TB
    subgraph Triggers["Entry Points"]
        EDIT["EditArticle.syncArticleToWordPress"]
        LIST["ListArticles actions"]
        TASK["TaskWorkflowTestRunner<br/>executeActionNode"]
        PROMPT["PromptTestPublishService.publishArticle"]
        APPROVE["SeoProjectApprovalService.approveLinkedProject"]
    end

    subgraph Hub["WordPressArticleSyncService"]
        SYNC["syncForArticle()"]
        CTX["resolveEditorSyncContext()"]
        SLUG["syncSlugForArticle()"]
        SEO["syncSeoMetaForArticle()"]
        MEDIA_URL["syncPromptMediaLinksToWordPressUrls()"]
    end

    subgraph WP_Services["WP Integration Services"]
        CONTENT["WordPressArticleContentService<br/>buildEditorSyncUrl → HTTP REST"]
        LOCAL_MEDIA["WordPressLocalMediaSyncService<br/>syncDirtyLocalMediaForArticle"]
        MEDIA_LOCAL["ArticleMediaLocalService<br/>pushPendingMediaToWordPress"]
        SANITIZE["ArticleEditorHtmlSanitizeService"]
        FAQ["WorkflowParserService<br/>parseFaqs, removeFaqAndAppendShortcode"]
        WP_MEDIA["WordPressArticleMediaService<br/>setFeaturedImage, setProductGallery"]
        ATTACH["WordPressArticleAttachmentService<br/>renameSlug, updateAltTitle"]
    end

    subgraph CoreBridge["Core ↔ Addon"]
        REG["ExternalPluginRegistry<br/>resolve('omi-seo-ai-bridge')"]
        SITE["Site model (mysql)"]
    end

    subgraph WP["WordPress REST"]
        REST["WP REST API<br/>posts, media, meta"]
    end

    subgraph WP_Sync_Status["Sync Monitoring"]
        SYNC_TABLE["WpSyncStatusTable widget"]
        RELEASE["WpPluginReleaseWidget"]
    end

    EDIT & LIST & TASK & PROMPT & APPROVE --> SYNC
    SYNC --> CTX --> CONTENT
    SYNC --> SANITIZE --> FAQ
    SYNC --> LOCAL_MEDIA --> MEDIA_LOCAL
    SYNC --> WP_MEDIA
    SYNC --> ATTACH
    CONTENT -->|"HTTP_CALLS"| REST
    LOCAL_MEDIA -->|"HTTP_CALLS"| REST
    ATTACH -->|"HTTP_CALLS"| REST

    CONTENT --> SITE
    REG -.->|"manifest version, download URL"| CONTENT
```

**Trace MCP (`syncForArticle` outbound):** 30+ callees — `ArticleEditorHtmlSanitizeService`, `WorkflowParserService`, `WordPressLocalMediaSyncService`, `ArticleMediaLocalService`, `SeoMediaBuilder`, `ExternalPluginRegistry`.

**Trace MCP (inbound callers):** `EditArticle.syncArticleToWordPress`, `TaskWorkflowTestRunner`, `PromptTestPublishService`, `ArticlesOptimal.demoteToDraft`, `SeoProjectApprovalService.approveLinkedProject`.

### Đăng bài mới — tránh bài trùng / `post_content` trắng

Luồng cũ (trước fix): `createForArticle` → `POST /posts` (chỉ title/slug/status) tạo bài **rỗng**, sau đó `syncForArticle` → `editor-sync` đẩy nội dung. Nếu hai request song song (double-click, workflow + editor) hoặc `editor-sync` lỗi trước khi `wp_post_id` kịp lưu → **hai bài WP** (một trống, một đầy đủ).

| Thành phần | Hành vi sau fix |
|------------|-----------------|
| `WordPressArticleSyncService::publishForArticle()` | Cache lock theo `article_id`; gọi `createForArticle` + `syncForArticle` tuần tự |
| `createForArticle()` | Gửi kèm `post_content`, `faqs`, `seo`, `category_ids` (plugin **≥ 1.0.49**) |
| Plugin `handle_create_post` | Ghi `post_content` ngay `wp_insert_post`; FAQ/SEO/category qua `apply_supplementary_sync_fields` |
| Plugin **&lt; 1.0.49** | Vẫn tạo bài rỗng nếu chưa nâng cấp — **nên đồng bộ plugin** trên mọi site |

`EditArticle.syncArticleToWordPress` gọi `publishForArticle()` thay vì tách `create` + `sync`.

### Lên lịch đăng bài (Laravel cron, không WP `future`)

Khi `articles.status = scheduled`, outbound sync gửi WP **`draft`** (xem `resolveWordPressStatusPayload`). Cron `seo:publish-scheduled-articles` (mỗi phút) gọi `ScheduledArticlePublishRunner` → `publishScheduledArticle()` → editor-sync `status=publish`. Chi tiết luồng editor: [MAP_SEO_EDITOR.md §2.6](MAP_SEO_EDITOR.md).

### Plugin WP — tắt WP-Cron & sửa «Lịch trình bị bỏ lỡ»

Repo: `wp-seo-ai` (`omi-seo-ai-bridge.php`). Trang admin: `/wp-admin/admin.php?page=omi-seo-ai`.

| Thành phần | File | Vai trò |
|------------|------|---------|
| Tắt WP-Cron | `includes/class-wp-cron-disabler.php` | `remove_action('init','wp_cron')` + chặn `pre_schedule_event` / `pre_reschedule_event` — lịch mới do Laravel, không spawn cron trên request |
| Sửa lỡ lịch | `includes/class-missed-schedule-fixer.php` | Query `post_status=future` + `post_date_gmt <= now` (post/product) → `wp_update_post(status=publish)` |
| UI | `views/welcome.php` | Bảng **Bài viết (link) \| Trạng thái \| Giờ lên lịch**; nút «Đăng tất cả» / «Đăng ngay» từng dòng |

**Luồng xử lý bài cũ bị `future` trên WP:**

```mermaid
flowchart LR
    subgraph WP_Admin["WP Admin page=omi-seo-ai"]
        LIST["Missed_Schedule_Fixer::list_missed_posts()"]
        BTN["Đăng tất cả / Đăng ngay"]
    end

    subgraph Fix["publish_post()"]
        SUP["Laravel_Push_Sync::suppress(true)"]
        PUB["wp_update_post status=publish"]
    end

    LIST --> BTN --> SUP --> PUB
```

- Khi đăng thủ công trên WP, `Laravel_Push_Sync` bị suppress để tránh push ngược không cần thiết.
- Bài mới từ Laravel **không** tạo `future` trên WP nữa — chỉ còn legacy `future` cần dọn qua UI này.

**Lưu ý hosting:** Server vẫn cần `php artisan schedule:run` (Laravel) để đăng bài theo lịch SEO. WP-Cron tắt không thay thế cron hệ thống Laravel.

### ExternalPluginRegistry

Core hub đọc `services.config.external_plugins`. Trong addon: `GeneralDomain.php`, `WordPressPluginWidget.php`, `WordPressPluginDomainsOverviewService.php` — slug `omi-seo-ai-bridge`.

---

## 2.5 Attachment Management

**Service:** `WordPressArticleAttachmentService.php`

Các Livewire methods trong `EditArticle`:
- `renameAttachmentSlugsOnWordPress(array)` — đổi slug của WP attachment (dùng cho SEO URL)
- `updateAttachmentMetaOnWordPress(array)` — cập nhật alt text + title của WP media

Luồng: Alpine event `seo-rename-attachment-slugs` / `seo-update-attachment-meta` → Livewire → `WordPressArticleAttachmentService` → WP REST API.

---

## 2.5.1 Đồng bộ media local → WordPress

**Hub:** `WordPressLocalMediaSyncService.php` · Chi tiết encode/WebP: [MAP_SEO_MEDIA.md §Trace đồng bộ WP](MAP_SEO_MEDIA.md)

```mermaid
flowchart TB
    subgraph syncHtml["syncHtml (trong prepareEditorSyncPayload)"]
        A["extractLocalSeoMediaImageRefs<br/>ưu tiên data-seo-media-id"]
        B["syncMedia — mỗi seo_media.id 1 lần"]
        C["applyWpUrlsToSeoMediaImages<br/>patch src, không re-import"]
    end

    subgraph syncMedia["syncMedia"]
        P["prepareWordPressUploadFile"]
        R["replace-binary nếu wp_attachment_id hợp lệ"]
        I["import nếu mới / replace fail"]
        STALE["ID còn DB nhưng WP đã xóa → clear ID → import"]
    end

    subgraph finalize["completeEditorSyncResponse"]
        F1["pushPendingMediaToWordPress — featured/gallery"]
        F2["syncDirtyLocalMediaForArticle"]
        F3["syncWebpBackfillMediaForArticle<br/>chỉ khi sibling .webp OK"]
    end

    A --> B --> C
    B --> syncMedia
    P --> R --> I
    STALE --> I
    finalize --> F1 & F2 & F3
```

| REST endpoint (plugin) | Khi dùng |
|------------------------|----------|
| `POST …/attachments/import` | Ảnh chưa có trên WP, hoặc attachment cũ đã mất |
| `POST …/attachments/{id}/replace-binary` | Đã có `wp_attachment_id` + URL WP còn sống |
| `POST …/attachments/{id}/delete` | Sau `reimportWebpRetiringOldAttachment` (replace giữ URL JPG) |

**Plugin `omi-seo-ai-bridge` ≥ 1.0.50:** `class-attachment-binary-replacer.php` đổi extension file sang `.webp` khi mime `image/webp`.

**Tránh file WP thừa:** Không backfill WebP khi upload thực tế là JPEG `-wp-upload.jpg` (`needsWordPressWebpBackfill` = false). Mỗi lượt `syncHtml` dedupe theo `seo_media.id`. Xem log: `WordPress attachment đã bị xóa trên WP — import mới`, `WordPress upload fallback: ảnh đã nén dưới ngưỡng`.

**Ảnh local:** Sync **không** chuyển `seo_media.status` sang `trash`. Xóa disk chỉ khi duyệt bài (Reviewed).

---

## 2.6 Sync Monitoring Widgets

### WpSyncStatusTable

**File:** `Filament/Widgets/WpSyncStatusTable.php`

Widget hiển thị bảng trạng thái đồng bộ WP của articles. Cho biết:
- Article đã sync chưa
- WP post ID
- WP permalink
- Trạng thái đồng bộ gần nhất

### WpPluginReleaseWidget

**File:** `Filament/Widgets/WpPluginReleaseWidget.php`

Widget quản lý release của WP plugin `omi-seo-ai-bridge`:
- Hiển thị version hiện tại
- Check for updates từ Update Server
- Download/manifest URL từ `ExternalPluginRegistry`

---

## Hướng dẫn prompt Cursor — Sync WordPress

### Push bài / media lên WP

```
Hub: Services/WordPressArticleSyncService.php → syncForArticle().
HTTP: Services/WordPressArticleContentService.php (buildEditorSyncUrl).
Media: Services/WordPressLocalMediaSyncService.php, ArticleMediaLocalService.php.
Upload encode: Services/SeoImageOptimizationService.php (prepareWordPressUploadFile, fallback 100KB).
Plugin binary replace: wp-seo-ai/includes/class-attachment-binary-replacer.php (≥ 1.0.50).
Attachment: Services/WordPressArticleAttachmentService.php.
Entry UI: Filament/Resources/ArticleResource/Pages/EditArticle.php.
Plugin manifest: app/Services/ExternalPlugin/ExternalPluginRegistry.php (omi-seo-ai-bridge).
WP plugin repo: wp-seo-ai (omi-seo-ai-bridge.php).
```

### Pull từ WP → Laravel

```
Inbound API: routes/api.php → SeoWpBridgeController (Api/ subfolder).
Service: SyncDomainContentService.php.
Auth: site seo_read_token (mysql.sites).
DB bootstrap: SeoDatabaseConnectionService.bootstrapBySiteId().
```

### Sync Monitoring

```
Sync status widget: Filament/Widgets/WpSyncStatusTable.php.
Plugin release widget: Filament/Widgets/WpPluginReleaseWidget.php.
```

### Plugin WP — cron & missed schedule

```
WP-Cron off: wp-seo-ai/includes/class-wp-cron-disabler.php.
Missed schedule UI: views/welcome.php + includes/class-missed-schedule-fixer.php.
Admin: /wp-admin/admin.php?page=omi-seo-ai.
```

**Liên quan editor:** [MAP_SEO_EDITOR.md](MAP_SEO_EDITOR.md) — `executeHeavyArticleAction`, `syncArticleToWordPress`, `renameAttachmentSlugsOnWordPress`, `updateAttachmentMetaOnWordPress`, Livewire collect HTML.
