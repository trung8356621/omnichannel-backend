# Bản đồ Tính năng Toàn diện (Feature Map) — Omnichannel Backend

> **Ngày khảo sát:** 06/07/2026
> **Phạm vi:** Core + Addon `SeoContentAi` (không gồm `WpHeadless`)
> **Mục đích:** Index dẫn đến các MAP file chi tiết.

---

## Menu MAP Files

| Tài liệu | Nội dung |
|----------|----------|
| **Core System** | Controllers, Middleware, Services, Models, Filament, Auth, Plugin Distribution |
| **SeoContentAi (tổng quan)** | Controllers, Models, Routes, Services groups, 57 Support classes |
| **Domain Management** | Menu Domain, 14 services, settings 3-layer, sync cache, 10 pages |
| **React Editor** | EditArticle, SeoArticleEditor, Livewire bridge, media picker modal |
| **Media & Watermark** | `/api/seo/media/*`, SeoMediaController, upload pipeline |
| **WordPress Bridge** | WP bridge inbound/outbound, sync, plugin release |
| **Content Projects** | SeoProject, Workflow, SeoProjectRun |
| **Settings, Prompts, AI** | Settings pages, PromptResource, PromptRunnerService |
| **Team & Authorization** | SeoAccessControl, RBAC, SEO roles |

---

## Thống kê tổng quan

| Khu vực | Số lượng |
|---------|----------|
| **Core Routes** | ~24 routes (web, api, auth, console) |
| **Core Controllers** | 11 controllers |
| **Core Middleware** | 4 middleware |
| **Core Models** | 18 models |
| **Core Services** | 7 services |
| **Core Filament Resources** | 4 resources |
| **SeoContentAi Routes** | ~45 routes |
| **SeoContentAi Controllers** | 15 controllers |
| **SeoContentAi Models** | 38 models |
| **SeoContentAi Services** | 150 files |
| **SeoContentAi Support** | 57 files |
| **SeoContentAi Migrations** | 80 files |
| **SeoContentAi Jobs** | 7 jobs |
| **SeoContentAi Filament** | ~50+ pages/resources/widgets |
| **SeoContentAi Frontend** | ~20+ React components |

---

## Queue Jobs (7 Jobs trong `app/Addons/SeoContentAi/Jobs/`)

Tất cả jobs dispatch đơn lẻ (không dùng `Bus::batch` hay `::withChain`).

### Domain Sync Jobs (3 jobs)

| Job | Queue | Timeout | Tries | Unique | Chức năng | Dispatch trigger |
|-----|-------|---------|-------|--------|-----------|-----------------|
| **RunIncrementalDomainSyncJob** | default | 3600s | 1 | ✅ 2h key: `seo-incr-sync:{siteId}:{userId}` | Đồng bộ bài viết mới/cập nhật từ WordPress. Trong `handle()` gọi `IncrementalDomainSyncRunner::run()` | Filament action (GeneralDomain page) |
| **RunMetadataDomainSyncJob** | default | 3600s | 1 | ✅ 2h key: `seo-meta-sync:{siteId}:{userId}` | Đồng bộ metadata WP (ngôn ngữ, Polylang, SEO meta). Trong `handle()` gọi `MetadataDomainSyncRunner::run()` | Filament action (GeneralDomain page) |
| **RunKeywordDomainResyncJob** | default | 3600s | 1 | ❌ | Reset + resync keywords từ articles. Trong `handle()` gọi `KeywordDomainResyncService::resetAndResync()`. Gửi Filament notification khi xong | Filament action (GeneralDomain page) |

### Link Audit Jobs (1 job)

| Job | Queue | Timeout | Tries | Chức năng | Dispatch trigger |
|-----|-------|---------|-------|-----------|-----------------|
| **AuditLinkStatusJob** | default | 45s | 2 | HTTP GET target URL → classify response (broken/active/needs_audit) → update SeoLinkMap + upsert audit cache. Dispatch single hoặc chunk per site | `LinkMapStatusAuditService::queueLinkMap()` (single) / `queueDomainAudit()` (chunk all link maps của site) |

### Media Generation Jobs (1 job)

| Job | Queue | Timeout | Tries | Chức năng | Dispatch trigger |
|-----|-------|---------|-------|-----------|-----------------|
| **GenerateMediaJob** | `media_generation` **(riêng)** | 360s | 1 (`$failOnTimeout=true`) | Sinh ảnh/video bằng AI: load SeoMedia + SeoPrompt → PromptRunnerService → lưu URL → post-processing → evaluate article readiness. Dispatched với `->afterResponse()` | `ArticleEditorMediaAiService` (generate + retry) |

### Article Review Jobs (1 job)

| Job | Queue | Timeout | Tries | Chức năng | Dispatch trigger |
|-----|-------|---------|-------|-----------|-----------------|
| **GenerateArticleReviewsJob** | default | 600s | 1 | Sinh review cho article: load article → auth → `ArticleQuickPostReviewService::runForArticle()` → notify user | Filament action |

### Database Import Jobs (1 job)

| Job | Queue | Timeout | Tries | Chức năng | Dispatch trigger |
|-----|-------|---------|-------|-----------|-----------------|
| **ImportSeoDatabaseJob** | default | 3600s | 1 | Import SQL backup vào SEO DB với progress callback. Chỉ dispatch khi file ≥5MB (`config db_import_queue_threshold`) và queue !== sync | `SeoDatabaseBackupService::importConnection()` |

### Queue Manager UI (đã gỡ)

- Đã xóa `Filament/Pages/SeoQueueManager` (`/queue-manager`), banner `global-queue-worker-alert`, và `Services/SeoQueueControlService`.
- Laravel Queue chỉ còn infrastructure (worker CLI / Supervisor). Không còn pause/resume/stop audit từ UI.
- Automation nav: Rules / Executions / Operations — không thay bằng dashboard queue mới.
- Regression: `tests/Unit/QueueManagerRemovalTest.php`.

### Runtime Runners (không phải Job — chạy trong handle() của Job)

| Runner | File | Mô tả |
|--------|------|-------|
| **IncrementalDomainSyncRunner** | `Services/IncrementalDomainSyncRunner.php` | Chạy incremental sync đồng bộ (trong process) với chunk + cache progress |
| **MetadataDomainSyncRunner** | `Services/MetadataDomainSyncRunner.php` | Chạy metadata resync đồng bộ (trong process) |

### Lưu ý Queue
- **Queue connection**: `config('queue.default')` → fallback `config('database.default')`
- **Queue name mặc định**: `config('queue.connections.{default}.queue', 'default')`
- **Cần worker riêng**: cho `media_generation` queue → `php artisan queue:work --queue=media_generation`
- **Không có**: Laravel Scheduler, `Bus::batch`, `::withChain`, `app/Jobs/` global

---

## Đối chiếu — Những gì còn thiếu trong docs/ MAP

### ADDON SeoContentAi — Đã có trong MAP nhưng còn thiếu chi tiết

| # | Tính năng / Class | MAP hiện tại | Mức độ thiếu |
|---|------------------|-------------|-------------|
| 1 | **SeoEngineService** (core) dùng trong SEO analysis | SUPER_MAP_INDEX §3 | Chưa có link |
| 2 | **SeoDatabaseBackupService** — backup/restore SEO DB | MAP_SEO_SETTINGS | Chưa có |
| 3 | **SeoAnalyzerService** — SEO tổng thể (1160 dòng) | MAP_SEO_EDITOR §5 | Chưa có section riêng |
| 4 | **ArticlePolylangSyncService** — Polylang sync | MAP_SEO_WP | Chỉ nhắc qua Editor |
| 5 | **ArticleQuickTranslateService** — translate nhanh | MAP_SEO_EDITOR | Chỉ nhắc Livewire method |
| 6 | **ArticleQuickPostReviewService** — post review nhanh | MAP_SEO_EDITOR | Chỉ nhắc tab name |
| 7 | **ArticleInternalLinkSuggestionService** | MAP_SEO_EDITOR | Chỉ nhắc Livewire method |
| 8 | **ArticleInternalLinkSearchService** | MAP_SEO_EDITOR | Chỉ nhắc Livewire method |
| 9 | **ArticlePendingInternalLinkService** | KHÔNG MAP nào | Chưa có |
| 10 | **ArticleKeywordLinkReconcileService** | KHÔNG MAP nào | Chưa có |
| 11 | **ArticleFeaturedSnippetGeneratorService** | MAP_SEO_SETTINGS §2 | Đã nhắc prompt config, thiếu service detail |
| 12 | **VirtualCommentService** (507 dòng) | KHÔNG MAP nào | Chưa có |
| 14 | **GlobalAiChatService** | MAP_SEO_EDITOR | Chỉ nhắc route, thiếu service detail |
| 15 | **Các FAQ services** (12 files: BodySync, ManualExtract, WordPressImport/Restore, ExtractDebug, PromptVariables, ContentFaq, Persistence, HtmlRenderer...) | KHÔNG MAP nào / rải rác | Chưa có phần FAQ riêng |
| 16 | **Các Article services** (CtaPlaceholder, EditorReadiness, EditorHistory, EditorMediaAi, ProductGalleryDistribute, PostImages, MediaLocal, MarkdownToHtml, ContentSeoBonus, TextTranslateTool) | KHÔNG MAP nào | Chưa có |
| 17 | **Các Media/AI utilities** (TagPersistence, ImageGenerationChain, GeminiMediaGeneration, AiImageProcessing, AiModelsReadiness, PromptPostProcessingApply, PromptMediaStorage) | KHÔNG MAP nào | Chưa có |
| 18 | **Các utilities** (SeoMigrationReconciler, SeoSqlStreamParser, CommentReview*, Utf8Sanitizer, SeoImageResizeMath, CreateArticleWorkflowNotification) | KHÔNG MAP nào | Chưa có |
| 19 | **PromptResultLinkService**, **ArticlePromptRunHistoryService**, **SeoNotificationService** | MAP_SEO_PROJECTS §4.2 | Đã nhắc |
| 20 | **Shortcode** `[omi_faq]`, **Placeholder** `[phone]`/`[website]`/`[zalo]` | KHÔNG MAP nào | Chưa có |

---

## File lớn nhất cần chú ý

| File | Dòng | Lý do |
|------|------|-------|
| `Services/WorkflowParserService.php` | 2614 | Parser workflow output (lớn nhất) |
| `Services/TaskWorkflowTestRunner.php` | 2082 | Engine chạy workflow |
| `Services/SyncDomainContentService.php` | 1273 | Đồng bộ domain từ WP |
| `Services/SeoAnalyzerService.php` | 1160 | Phân tích SEO tổng thể |
| `Services/PromptRunnerService.php` | 1156 | Engine AI trung tâm |

---

## Lưu ý kiến trúc

1. **Không có Event/Listener**: `app/Events/` và `app/Listeners/` đều rỗng — không dùng Laravel events
2. **Không có Console Kernel**: Laravel 11+ dùng `bootstrap/app.php` + `routes/console.php`
3. **Không có Schedule**: Không có cron jobs trong codebase
4. **Không có Broadcasting/Channels**: Không có `routes/channels.php`
5. **Intervention Image**: Singleton ImageManager với GD/Imagick fallback (log ghi khi fallback)
6. **Cross-DB pattern**: Core models dùng `UsesCoreDatabaseConnection` trait; SEO models dùng `BelongsToOnDefaultConnection` trait
7. **Sanctum**: SPA authentication + token-based API
8. **Dynamic Addon Registration**: Không đăng ký static trong `config/app.php` — đọc từ `services` table runtime
