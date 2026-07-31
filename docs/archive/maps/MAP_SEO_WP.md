> Status: Historical
> Not canonical
> Archived on: 2026-08-01
> Superseded by: ../../modules/WORDPRESS_BRIDGE.md
> Purpose: implementation history only
# SeoContentAi â€” WordPress Bridge & Sync

[â† Quay láº¡i Báº£n Ä‘á»“ tá»•ng](SUPER_MAP_INDEX.md)

**LiÃªn quan:** [React Editor](MAP_SEO_EDITOR.md) Â· [Content Projects & Workflow](MAP_SEO_PROJECTS.md)

### NguyÃªn táº¯c: Laravel báº£n táº¡m â†” WordPress nguá»“n sá»‘ng

- BÃ i trÃªn Laravel = báº£n táº¡m: **Ä‘Æ°á»£c** sync ná»™i dung/SEO/media, sá»­a local, trash/xÃ³a **chá»‰ trÃªn Laravel**.
- Outbound Laravel â†’ WP **khÃ´ng** xÃ³a / trash WP. Sync status **chá»‰** gá»­i `publish` (+ `post_date` clamp â‰¤ now) â€” `WordPressArticleSyncService::resolveWordPressStatusPayload()`.
- Lá»‹ch Ä‘Äƒng (`scheduled`) sá»‘ng **chá»‰ trÃªn Laravel**; cron tá»›i giá» má»›i sync. **KhÃ´ng** gá»­i `draft` / WP `future` khi Ä‘á»“ng bá»™.
- Plugin `omi-seo-ai-bridge` â‰¥ **1.0.57**: cháº·n demote `publish/private/future` â†’ `draft`; elevate admin + `force_post_status`; clamp `post_date` tÆ°Æ¡ng lai khi publish.
- Plugin `omi-seo-ai-bridge` â‰¥ **1.0.61**: `editor-sync` / `apply_supplementary_sync_fields` â€” `faqs:[]` **khÃ´ng** xÃ³a `_omi_seo_faqs` náº¿u meta Ä‘ang cÃ³, trá»« khi `clear_faqs` (trÃ¡nh sync Laravel gá»­i [] nháº§m â†’ shortcode `[omi_faq]` trá»‘ng trÃªn frontend).
- Plugin `omi-seo-ai-bridge` â‰¥ **1.0.68**: capability `product_category_taxonomy_export`; `map_term` canonical (`term_id`, `parent_term_id` ká»ƒ cáº£ `0`, `taxonomy`, `url`, `post_count`, `page_type=taxonomy`); `GET /omi-seo-ai/v1/taxonomies/{taxonomy}/terms`. Laravel Site MCP draft Æ°u tiÃªn live taxonomy; sync persist `wp_parent_id="0"` (khÃ´ng xÃ³a khi parent=0).
- Inbound WP â†’ Laravel (push trash / force_delete) váº«n pháº£n Ã¡nh tráº¡ng thÃ¡i WP â€” xem bridge bÃªn dÆ°á»›i.

---

## 2.3 WordPress Bridge (inbound tá»« plugin WP)

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
| GET | `/api/seo-wp-bridge/ping` | Kiá»ƒm tra token + domain |
| POST | `/api/seo-wp-bridge/push-content` | Compat: legacy article import; **V2 writer skips** links/keywords/scores enrich (delta/snapshot own those) |
| POST | `/api/seo-wp-bridge/snapshot-callback` | Site Sync V2 snapshot apply |
| POST | `/api/seo-wp-bridge/delta-event` | Site Sync V2 delta inbox |

**Middleware:** `api` (khÃ´ng auth, khÃ´ng session) â€” authentication dÃ¹ng Bearer token tá»« `sites.seo_read_token`.

---

## 2.4 WordPress Sync (outbound Laravel â†’ WP)

```mermaid
flowchart TB
    subgraph Triggers["Entry Points"]
        EDIT["EditArticle.syncArticleToWordPress"]
        LIST["ListArticles actions"]
        QUEUE["ArticleWpSyncQueueService / scheduled publish"]
        APPROVE["SeoProjectApprovalService.approveLinkedProject"]
        HOOK["Business Hook rule enabled<br/>wordpress.article.sync"]
    end

    Note1["Content Project workflow + PromptTestPublishService.publishArticle<br/>chá»‰ lÆ°u Laravel â€” khÃ´ng gá»i sync outbound"]

    subgraph Hub["WordPressArticleSyncService"]
        SYNC["syncForArticle()"]
        CTX["resolveEditorSyncContext()"]
        SLUG["syncSlugForArticle()"]
        SEO["syncSeoMetaForArticle()"]
        MEDIA_URL["syncPromptMediaLinksToWordPressUrls()"]
    end

    subgraph WP_Services["WP Integration Services"]
        CONTENT["WordPressArticleContentService<br/>buildEditorSyncUrl â†’ HTTP REST"]
        LOCAL_MEDIA["WordPressLocalMediaSyncService<br/>syncDirtyLocalMediaForArticle"]
        MEDIA_LOCAL["ArticleMediaLocalService<br/>pushPendingMediaToWordPress"]
        SANITIZE["ArticleEditorHtmlSanitizeService"]
        FAQ["WorkflowParserService<br/>parseFaqs, removeFaqAndAppendShortcode"]
        WP_MEDIA["WordPressArticleMediaService<br/>setFeaturedImage, setProductGallery"]
        ATTACH["WordPressArticleAttachmentService<br/>renameSlug, updateAltTitle"]
    end

    subgraph CoreBridge["Core â†” Addon"]
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

    EDIT & LIST & QUEUE & APPROVE & HOOK --> SYNC
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

**Business Hook:** `article.completed` (tá»« task completed) chá»‰ gá»i `wordpress.article.sync` khi rule **enabled + published** (seed máº·c Ä‘á»‹nh disabled). Action: `SyncArticleToWordPressHookAction` via `WordPressAutomationModuleProvider` â€” queue `automation-external`. Rule disabled â†’ event váº«n ghi, **0** execution side effect (pending job cancel khi disable). Outcome `wordpress.synced` dedupe: `event_uuid` = `sync_operation_id` (ManualJob = requestId UUID; HookAction = sha256 64 hex) â†’ cá»™t `business_events.event_uuid` VARCHAR(64) (`2026_07_22_120000_widen_business_events_event_uuid`).

**Invariant (cutover 2026-07-20):**
- Automatic WordPress side effects require an enabled published Automation Rule.
- A disabled rule blocks future automatic executions, not an explicit manual user sync and not necessarily an execution already mid-flight before disable (pending/processing get `cancellation_requested_at`).
- Content Project and Article completion never dispatch WordPress jobs directly.
- WordPress automation jobs use `automation-external` (action) / `automation-critical` (rule bootstrap). Legacy manual queue job uses `seo` â€” not `default`.
- Manual entry: `WordPressManualSyncService` â†’ local persist `article.content.update` (`UpdateArticleContentAction` + `ArticleEditorPersistService` TX ngáº¯n / retry Lock wait 1205) â†’ `ArticleWpSyncLeaseService::enqueue` (`seo_article_wp_sync_jobs` + meta `wp_sync_queue`) â†’ `ManualWordPressSyncJob` (queue `seo`, `syncJobId`) â†’ claim/heartbeat â†’ `ArticleWordPressBusinessSequence`. **KhÃ´ng** cáº§n Automation Rule. Content Project active â†’ `ContentProjectWorkspaceSaveService` (Laravel-only, khÃ´ng WP API). Enqueue lock: `acquireEnqueueLock` Æ°u tiÃªn `Cache::store('database')` (`cache_locks`), retry; file-driver `fopen` fail khÃ´ng cháº·n enqueue (DB `lockForUpdate` váº«n serialize) + log `manual_wordpress_sync.lock_failed`. `isActive` (force-stale expired; sau auto-retry coi job má»›i active). Terminal: complete/fail/cancel/stale. **`markStale` auto-retry tá»‘i Ä‘a 3** (`MAX_STALE_AUTO_RETRIES`, settings `stale_auto_retries`, source `stale_auto_retry`); force unlock/`--force` táº¯t. Watchdog `seo:wordpress-sync-lease-watchdog`. Idempotency create: WP meta `_teamvia_article_id` / `_teamvia_sync_key` + `GET .../posts/find-by-article`. **Editor UX sau enqueue:** `finishArticleSyncFromApi` / `exitEditorAfterWordpressSyncQueued` â€” Ä‘Ã³ng tab hoáº·c `location.replace` Sync Queue ngay; **khÃ´ng** poll Elapsed trÃªn Edit Article. Controller `POST .../sync-wp` (`queued` + `close_editor`), EditArticle sync button.
- `ArticleScheduleReconcileService` = Laravel status only â€” **no** WordPress API.
- System cron `ScheduledArticlePublishRunner` = due scheduled posts already linked (`wp_post_id>0`); not `article.completed`.

**Trace MCP (inbound callers):** `WordPressManualSyncService` / `ManualWordPressSyncJob` (editor/list), `ScheduledArticlePublishRunner` (emit only), Business Hook `wordpress.article.sync`. **KhÃ´ng** tá»« Content Project run / `PromptTestPublishService.publishArticle` / `ArticleScheduleReconcileService`. Product reviews: **cÃ¹ng** `SyncArticleToWordPressPipeline` (khÃ´ng rule `publish-pending-*` riÃªng).

| Flow | Manual/Automatic | Entry point | Queue | Requires enabled rule |
|---|---|---|---|---|
| Editor sync | Manual | `WordPressManualSyncService` | `seo` | No |
| Article completed sync | Automatic | `article.completed` â†’ `sync-article-to-wordpress` | `automation-external` | Yes |
| Scheduled due linked | Automatic | `article.publish_requested` â†’ `dispatch-publish-request` | `automation-external` | Yes |
| Product review publish | Automatic | review rules | `automation-external` | Yes |

Audit: `php artisan automation:audit-wordpress-coupling [--strict]`

### ÄÄƒng bÃ i má»›i â€” trÃ¡nh bÃ i trÃ¹ng / `post_content` tráº¯ng

Luá»“ng cÅ© (trÆ°á»›c fix): `createForArticle` â†’ `POST /posts` (chá»‰ title/slug/status) táº¡o bÃ i **rá»—ng**, sau Ä‘Ã³ `syncForArticle` â†’ `editor-sync` Ä‘áº©y ná»™i dung. Náº¿u hai request song song (double-click, workflow + editor) hoáº·c `editor-sync` lá»—i trÆ°á»›c khi `wp_post_id` ká»‹p lÆ°u â†’ **hai bÃ i WP** (má»™t trá»‘ng, má»™t Ä‘áº§y Ä‘á»§).

| ThÃ nh pháº§n | HÃ nh vi sau fix |
|------------|-----------------|
| `WordPressArticleSyncService::publishForArticle()` | Cache lock theo `article_id`; gá»i `createForArticle` + `syncForArticle` tuáº§n tá»± |
| `createForArticle()` | Gá»­i kÃ¨m `post_content`, `faqs`, `seo`, `category_ids` (plugin **â‰¥ 1.0.49**) |
| Plugin `handle_create_post` | Ghi `post_content` ngay `wp_insert_post`; FAQ/SEO/category qua `apply_supplementary_sync_fields` |
| Plugin **&lt; 1.0.49** | Váº«n táº¡o bÃ i rá»—ng náº¿u chÆ°a nÃ¢ng cáº¥p â€” **nÃªn Ä‘á»“ng bá»™ plugin** trÃªn má»i site |

`EditArticle.syncArticleToWordPress` gá»i `publishForArticle()` thay vÃ¬ tÃ¡ch `create` + `sync`.

### LÃªn lá»‹ch Ä‘Äƒng bÃ i (Laravel cron, khÃ´ng WP `future` / `draft`)

Outbound sync **luÃ´n** `status=publish` (ká»ƒ cáº£ Laravel `draft` / `scheduled`). `post_date` tÆ°Æ¡ng lai bá»‹ clamp vá» now â€” trÃ¡nh WP Ä‘á»•i thÃ nh `future`. Lá»‹ch chá» ngÃ y X: Laravel giá»¯ `articles.status=scheduled` + `published_at`; cron `seo:publish-scheduled-articles` (má»—i phÃºt) â†’ `ScheduledArticlePublishRunner` â†’ `publishScheduledArticle()` â†’ editor-sync publish. Queue: `ArticleWpSyncQueueService::applyPublishImmediatelyToBundle()` Ã©p `publish_box.status=published` trÆ°á»›c persist/worker. Chi tiáº¿t editor: [MAP_SEO_EDITOR.md Â§2.6](MAP_SEO_EDITOR.md).

### Plugin WP â€” táº¯t WP-Cron & sá»­a Â«Lá»‹ch trÃ¬nh bá»‹ bá» lá»¡Â»

Repo: `wp-seo-ai` (`omi-seo-ai-bridge.php`). Trang admin: `/wp-admin/admin.php?page=omi-seo-ai`.

| ThÃ nh pháº§n | File | Vai trÃ² |
|------------|------|---------|
| Táº¯t WP-Cron | `includes/class-wp-cron-disabler.php` | `remove_action('init','wp_cron')` + cháº·n `pre_schedule_event` / `pre_reschedule_event` â€” lá»‹ch má»›i do Laravel, khÃ´ng spawn cron trÃªn request |
| Sá»­a lá»¡ lá»‹ch | `includes/class-missed-schedule-fixer.php` | Query `post_status=future` + `post_date_gmt <= now` (post/product) â†’ `wp_update_post(status=publish)` |
| UI | `views/welcome.php` | Báº£ng **BÃ i viáº¿t (link) \| Tráº¡ng thÃ¡i \| Giá» lÃªn lá»‹ch**; nÃºt Â«ÄÄƒng táº¥t cáº£Â» / Â«ÄÄƒng ngayÂ» tá»«ng dÃ²ng |

**Luá»“ng xá»­ lÃ½ bÃ i cÅ© bá»‹ `future` trÃªn WP:**

```mermaid
flowchart LR
    subgraph WP_Admin["WP Admin page=omi-seo-ai"]
        LIST["Missed_Schedule_Fixer::list_missed_posts()"]
        BTN["ÄÄƒng táº¥t cáº£ / ÄÄƒng ngay"]
    end

    subgraph Fix["publish_post()"]
        SUP["Laravel_Push_Sync::suppress(true)"]
        PUB["wp_update_post status=publish"]
    end

    LIST --> BTN --> SUP --> PUB
```

- Khi Ä‘Äƒng thá»§ cÃ´ng trÃªn WP, `Laravel_Push_Sync` bá»‹ suppress Ä‘á»ƒ trÃ¡nh push ngÆ°á»£c khÃ´ng cáº§n thiáº¿t.
- BÃ i má»›i tá»« Laravel **khÃ´ng** táº¡o `future` trÃªn WP ná»¯a â€” chá»‰ cÃ²n legacy `future` cáº§n dá»n qua UI nÃ y.

**LÆ°u Ã½ hosting:** Server váº«n cáº§n `php artisan schedule:run` (Laravel) Ä‘á»ƒ Ä‘Äƒng bÃ i theo lá»‹ch SEO. WP-Cron táº¯t khÃ´ng thay tháº¿ cron há»‡ thá»‘ng Laravel.

**Site Sync reconcile (cron):** `seo:site-sync-reconcile` (`ReconcileSiteSyncCommand`) â€” scan site cÃ³ meta `seo_read_token` (khÃ´ng dÃ¹ng cá»™t `sites.settings`; cá»™t Ä‘Ã³ khÃ´ng tá»“n táº¡i). Auth WP = `seo_read_token` + `sites.domain` (`WordPressSiteSyncClient::authContext`). Schedule hourly `seo-content-ai:site-sync-reconcile-quick`. Chi tiáº¿t V2: [SITE_SYNC_V2_OPERATIONS.md](SITE_SYNC_V2_OPERATIONS.md).

### ExternalPluginRegistry

Core hub Ä‘á»c `services.config.external_plugins`. Trong addon: `GeneralDomain.php`, `WordPressPluginWidget.php`, `WordPressPluginDomainsOverviewService.php` â€” slug `omi-seo-ai-bridge`.

---

## 2.5 Attachment Management

**Service:** `WordPressArticleAttachmentService.php`

CÃ¡c Livewire methods trong `EditArticle`:
- `renameAttachmentSlugsOnWordPress(array)` â€” `WordPressAttachmentRenameService::renameBatch` â†’ WP `POST â€¦/attachments/rename`; response `renamed[]` gá»“m `attachment_id`, `old_url`, `new_url`, `new_slug` (slug thá»±c táº¿ trÃªn Ä‘Ä©a, cÃ³ thá»ƒ â‰  `new_slug` request náº¿u WP dedupe). Livewire enrich `block_id` tá»« request; **sau rename thÃ nh cÃ´ng** gá»i `SeoMediaUrlReplacementService::rewriteArticleReferences` (body + featured/gallery, kÃ¨m variant sized WP) rá»“i refresh `editorHtml`. Event `seo-attachment-slugs-rename-finished`. Fix slug all client: `clearDraft` trÆ°á»›c reload.
- **Plugin â‰¥ 1.0.54** â€” `includes/class-attachment-renamer.php` `resolve_attachment_id()`: náº¿u `attachment_id` request stale (post Ä‘Ã£ xÃ³a/reimport) â†’ tÃ¬m láº¡i theo `old_url` (`attachment_url_to_postid`) hoáº·c basename `_wp_attached_file` trÆ°á»›c khi rename.
- `updateAttachmentMetaOnWordPress(array)` â€” cáº­p nháº­t alt text + title cá»§a WP media

Luá»“ng editor: `callEditArticleLivewire('renameAttachmentSlugsOnWordPress')` â†’ `WordPressAttachmentRenameService` â†’ WP REST. Legacy Alpine `seo-rename-attachment-slugs` váº«n cÃ³ cho flow khÃ¡c. áº¢nh local chÆ°a sync khÃ´ng gá»i endpoint nÃ y; editor rename local trÆ°á»›c, rá»“i `syncHtml` import áº£nh lÃªn WP báº±ng slug Ä‘Ã£ chuáº©n hÃ³a.

---

## 2.5.1 Äá»“ng bá»™ media local â†’ WordPress

**Hub:** `WordPressLocalMediaSyncService.php` Â· Chi tiáº¿t encode/WebP: [MAP_SEO_MEDIA.md Â§Trace Ä‘á»“ng bá»™ WP](MAP_SEO_MEDIA.md)

```mermaid
flowchart TB
    subgraph syncHtml["syncHtml (trong prepareEditorSyncPayload)"]
        A["extractLocalSeoMediaImageRefs<br/>Æ°u tiÃªn data-seo-media-id"]
        B["syncMedia â€” má»—i seo_media.id 1 láº§n"]
        C["applyWpUrlsToSeoMediaImages<br/>patch src, khÃ´ng re-import"]
    end

    subgraph syncMedia["syncMedia"]
        P["prepareWordPressUploadFile"]
        R["replace-binary náº¿u wp_attachment_id há»£p lá»‡"]
        I["import náº¿u má»›i / replace fail"]
        STALE["ID cÃ²n DB nhÆ°ng WP Ä‘Ã£ xÃ³a â†’ clear ID â†’ import"]
    end

    subgraph finalize["completeEditorSyncResponse"]
        F1["pushPendingMediaToWordPress â€” featured/gallery"]
        F2["syncDirtyLocalMediaForArticle"]
        F3["syncWebpBackfillMediaForArticle<br/>chá»‰ khi sibling .webp OK"]
    end

    A --> B --> C
    B --> syncMedia
    P --> R --> I
    STALE --> I
    finalize --> F1 & F2 & F3
```

| REST endpoint (plugin) | Khi dÃ¹ng |
|------------------------|----------|
| `POST â€¦/attachments/import` | áº¢nh chÆ°a cÃ³ trÃªn WP, hoáº·c attachment cÅ© Ä‘Ã£ máº¥t |
| `POST â€¦/attachments/{id}/replace-binary` | ÄÃ£ cÃ³ `wp_attachment_id` + URL WP cÃ²n sá»‘ng |
| `POST â€¦/attachments/{id}/delete` | Sau `reimportWebpRetiringOldAttachment` (replace giá»¯ URL JPG) |

**Plugin `omi-seo-ai-bridge` â‰¥ 1.0.51:** `GET /omi-seo-ai/v1/posts/{id}/comment-reviews` Ä‘á»c `_omi_seo_virtual_comments` (meta) + merge `wp_comments` â€” editor Reviews tab dÃ¹ng endpoint nÃ y khi báº¥m **LÃ m má»›i**.

**Product reviews â†’ WP (linear 3-action):** Rule `article > wordpress` = `wordpress.article.sync` â†’ `product-review.create` â†’ `product-review.sync-wp`. Shared `ProductReviewCreationPolicy` + `WordPressProductReviewStatusService`. **Idempotent create:** `target_count` = duy trÃ¬ tá»•ng AI reviews (`missing = max(0, target âˆ’ max(wp_generated, local_generated))`); `block_if_real_reviews_exist` dá»«ng khi cÃ³ real. **Settings source:** `ProductReviewAutomationSettingsResolver` Ä‘á»c `target_count` tá»« action `product-review.create` (Æ°u tiÃªn rule `sync-article-to-wordpress`) â€” Manual Sync / editor API dÃ¹ng chung, khÃ´ng hardcode 10. Generated WP meta: `source=seo_content_ai` / `generated=true`. Local lifecycle `pendingâ†’syncingâ†’reviewed`. **Reviewed article:** `ProductReviewPendingRepository::deleteLocalForArticle` xÃ³a toÃ n bá»™ local review (WP SoT); `approveArticle` **khÃ´ng** cháº¡y `ArticleQuickPostReviewService`. Edit Article: `GET .../product-review-status`. Legacy schedule/queue/publish = deprecated.

**Frontend WP (plugin â‰¥ 1.0.59):** CusRev (`cr-reviews-ajax-*`) chiáº¿m tab Reviews â€” `Virtual_Comments::filter_product_review_tab` priority 999 Ã©p callback `render_virtual_reviews_tab` khi cÃ³ meta; template `single-product-reviews-virtual.php`; save meta purge WP Rocket/LiteSpeed. Format payload khÃ´ng Ä‘á»•i (`author`/`content`/`date`/`rating` + `_omi_*`).

**Plugin `omi-seo-ai-bridge` â‰¥ 1.0.54:** `class-attachment-renamer.php` â€” rename resolve attachment theo URL khi ID stale.

**Plugin `omi-seo-ai-bridge` â‰¥ 1.0.50:** `class-attachment-binary-replacer.php` Ä‘á»•i extension file sang `.webp` khi mime `image/webp`.

**TrÃ¡nh file WP thá»«a:** KhÃ´ng backfill WebP khi upload thá»±c táº¿ lÃ  JPEG `-wp-upload.jpg` (`needsWordPressWebpBackfill` = false). Má»—i lÆ°á»£t `syncHtml` dedupe theo `seo_media.id`. Xem log: `WordPress attachment Ä‘Ã£ bá»‹ xÃ³a trÃªn WP â€” import má»›i`, `WordPress upload fallback: áº£nh Ä‘Ã£ nÃ©n dÆ°á»›i ngÆ°á»¡ng`.

**Tá»‘i Æ°u áº£nh trÆ°á»›c upload:** `SyncArticleToWordPressFromQueueJob` Ä‘i qua `SeoImageOptimizationService.prepareWordPressUploadFile` â†’ `SeoImagePipeline`; pixel alpha dÃ¹ng `ImagickPixelColor::normalized()` Ä‘á»ƒ trÃ¡nh `ImagickPixel::getColor(true)` fail trÃªn Imagick má»›i vÃ  lÃ m sync cháº­m do fallback/retry.

**áº¢nh local:** Sync **khÃ´ng** chuyá»ƒn `seo_media.status` sang `trash`. XÃ³a disk chá»‰ khi duyá»‡t bÃ i (Reviewed).

---

## 2.6 Sync Monitoring Widgets

### WpSyncStatusTable

**File:** `Filament/Widgets/WpSyncStatusTable.php`

Widget hiá»ƒn thá»‹ báº£ng tráº¡ng thÃ¡i Ä‘á»“ng bá»™ WP cá»§a articles. Cho biáº¿t:
- Article Ä‘Ã£ sync chÆ°a
- WP post ID
- WP permalink
- Tráº¡ng thÃ¡i Ä‘á»“ng bá»™ gáº§n nháº¥t

### WpPluginReleaseWidget

**File:** `Filament/Widgets/WpPluginReleaseWidget.php`

Widget quáº£n lÃ½ release cá»§a WP plugin `omi-seo-ai-bridge`:
- Hiá»ƒn thá»‹ version hiá»‡n táº¡i
- Check for updates tá»« Update Server
- Download/manifest URL tá»« `ExternalPluginRegistry`

---

## HÆ°á»›ng dáº«n prompt Cursor â€” Sync WordPress

### Push bÃ i / media lÃªn WP

```
Hub: Services/WordPressArticleSyncService.php â†’ syncForArticle() / createForArticle (find-by-article idempotent).
Lease: Services/ArticleWpSyncLeaseService.php + Models/SeoArticleWpSyncJob.php; meta projection ArticleWpSyncQueueService.php (`QUEUE_NAME=seo`).
  Stale auto-retry: markStale â†’ maybeAutoRetryAfterStale (MAX_STALE_AUTO_RETRIES=3, settings.stale_auto_retries); force unlock táº¯t.
Jobs: Jobs/ManualWordPressSyncJob.php (queue `seo`); source `stale_auto_retry` khi tá»± enqueue.
Watchdog: Console/WordpressSyncLeaseWatchdogCommand.php (`seo:wordpress-sync-lease-watchdog`).
HTTP: Services/WordPressArticleContentService.php (buildEditorSyncUrl); Gateway getJson/postJson + WpSyncLeaseHeartbeat.
Media: Services/WordPressLocalMediaSyncService.php, ArticleMediaLocalService.php.
Upload encode: Services/SeoImageOptimizationService.php (prepareWordPressUploadFile, fallback 100KB).
Plugin binary replace: wp-seo-ai/includes/class-attachment-binary-replacer.php (â‰¥ 1.0.50).
Attachment: Services/WordPressArticleAttachmentService.php.
Entry UI: Filament/Resources/ArticleResource/Pages/EditArticle.php.
Plugin manifest: app/Services/ExternalPlugin/ExternalPluginRegistry.php (omi-seo-ai-bridge).
WP plugin repo: wp-seo-ai (omi-seo-ai-bridge.php).
```

### Pull tá»« WP â†’ Laravel

```
Inbound API: routes/api.php â†’ SeoWpBridgeController (Api/ subfolder).
Service: SyncDomainContentService.php.
Sau `importSingleSyncItem`, dispatch `AnalyzeArticleSeoJob` qua `SeoArticleScoringQueueService::dispatchIfSyncItemChanged()` (fingerprint sync payload) â€” khÃ´ng cÃ²n `analyzeFromSyncItem()` Ä‘á»“ng bá»™ trong HTTP.
Auth: site seo_read_token (mysql.sites).
DB bootstrap: SeoDatabaseConnectionService.bootstrapBySiteId().
```

### Sync Monitoring

```
Sync status widget: Filament/Widgets/WpSyncStatusTable.php.
Plugin release widget: Filament/Widgets/WpPluginReleaseWidget.php.
```

### Plugin WP â€” cron & missed schedule

```
WP-Cron off: wp-seo-ai/includes/class-wp-cron-disabler.php.
Missed schedule UI: views/welcome.php + includes/class-missed-schedule-fixer.php.
Admin: /wp-admin/admin.php?page=omi-seo-ai.
```

**LiÃªn quan editor:** [MAP_SEO_EDITOR.md](MAP_SEO_EDITOR.md) â€” `executeHeavyArticleAction`, `syncArticleToWordPress`, `renameAttachmentSlugsOnWordPress`, `updateAttachmentMetaOnWordPress`, Livewire collect HTML. **Phase 1 perf:** `EditArticle::mount` **khÃ´ng** gá»i remote WP HTTP (title/categories/FAQ/heal taxonomy); Sync-from-WP / explicit refresh váº«n dÃ¹ng service HTTP.
