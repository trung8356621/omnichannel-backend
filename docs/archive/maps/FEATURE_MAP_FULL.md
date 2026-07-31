> Status: Historical
> Not canonical
> Archived on: 2026-08-01
> Superseded by: ../../architecture/SYSTEM_OVERVIEW.md
> Purpose: implementation history only
# Báº£n Ä‘á»“ TÃ­nh nÄƒng ToÃ n diá»‡n (Feature Map) â€” Omnichannel Backend

> **NgÃ y kháº£o sÃ¡t:** 06/07/2026
> **Pháº¡m vi:** Core + Addon `SeoContentAi` (khÃ´ng gá»“m `WpHeadless`)
> **Má»¥c Ä‘Ã­ch:** Index dáº«n Ä‘áº¿n cÃ¡c MAP file chi tiáº¿t.

---

## Menu MAP Files

| TÃ i liá»‡u | Ná»™i dung |
|----------|----------|
| **Core System** | Controllers, Middleware, Services, Models, Filament, Auth, Plugin Distribution |
| **SeoContentAi (tá»•ng quan)** | Controllers, Models, Routes, Services groups, 57 Support classes |
| **Domain Management** | Menu Domain, 14 services, settings 3-layer, sync cache, 10 pages |
| **React Editor** | EditArticle, SeoArticleEditor, Livewire bridge, media picker modal |
| **Media & Watermark** | `/api/seo/media/*`, SeoMediaController, upload pipeline |
| **WordPress Bridge** | WP bridge inbound/outbound, sync, plugin release |
| **Content Projects** | SeoProject, Workflow, SeoProjectRun |
| **Settings, Prompts, AI** | Settings pages, PromptResource, PromptRunnerService |
| **Team & Authorization** | SeoAccessControl, RBAC, SEO roles |

---

## Thá»‘ng kÃª tá»•ng quan

| Khu vá»±c | Sá»‘ lÆ°á»£ng |
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

Táº¥t cáº£ jobs dispatch Ä‘Æ¡n láº» (khÃ´ng dÃ¹ng `Bus::batch` hay `::withChain`).

### Domain Sync Jobs (3 jobs)

| Job | Queue | Timeout | Tries | Unique | Chá»©c nÄƒng | Dispatch trigger |
|-----|-------|---------|-------|--------|-----------|-----------------|
| **RunIncrementalDomainSyncJob** | default | 3600s | 1 | âœ… 2h key: `seo-incr-sync:{siteId}:{userId}` | Äá»“ng bá»™ bÃ i viáº¿t má»›i/cáº­p nháº­t tá»« WordPress. Trong `handle()` gá»i `IncrementalDomainSyncRunner::run()` | Filament action (GeneralDomain page) |
| **RunMetadataDomainSyncJob** | default | 3600s | 1 | âœ… 2h key: `seo-meta-sync:{siteId}:{userId}` | Äá»“ng bá»™ metadata WP (ngÃ´n ngá»¯, Polylang, SEO meta). Trong `handle()` gá»i `MetadataDomainSyncRunner::run()` | Filament action (GeneralDomain page) |
| **RunKeywordDomainResyncJob** | default | 3600s | 1 | âŒ | Reset + resync keywords tá»« articles. Trong `handle()` gá»i `KeywordDomainResyncService::resetAndResync()`. Gá»­i Filament notification khi xong | Filament action (GeneralDomain page) |

### Link Audit Jobs (1 job)

| Job | Queue | Timeout | Tries | Chá»©c nÄƒng | Dispatch trigger |
|-----|-------|---------|-------|-----------|-----------------|
| **AuditLinkStatusJob** | default | 45s | 2 | HTTP GET target URL â†’ classify response (broken/active/needs_audit) â†’ update SeoLinkMap + upsert audit cache. Dispatch single hoáº·c chunk per site | `LinkMapStatusAuditService::queueLinkMap()` (single) / `queueDomainAudit()` (chunk all link maps cá»§a site) |

### Media Generation Jobs (1 job)

| Job | Queue | Timeout | Tries | Chá»©c nÄƒng | Dispatch trigger |
|-----|-------|---------|-------|-----------|-----------------|
| **GenerateMediaJob** | `media_generation` **(riÃªng)** | 360s | 1 (`$failOnTimeout=true`) | Sinh áº£nh/video báº±ng AI: load SeoMedia + SeoPrompt â†’ PromptRunnerService â†’ lÆ°u URL â†’ post-processing â†’ evaluate article readiness. Dispatched vá»›i `->afterResponse()` | `ArticleEditorMediaAiService` (generate + retry) |

### Article Review Jobs (1 job)

| Job | Queue | Timeout | Tries | Chá»©c nÄƒng | Dispatch trigger |
|-----|-------|---------|-------|-----------|-----------------|
| **GenerateArticleReviewsJob** | default | 600s | 1 | Sinh review cho article: load article â†’ auth â†’ `ArticleQuickPostReviewService::runForArticle()` â†’ notify user | Filament action |

### Database Import Jobs (1 job)

| Job | Queue | Timeout | Tries | Chá»©c nÄƒng | Dispatch trigger |
|-----|-------|---------|-------|-----------|-----------------|
| **ImportSeoDatabaseJob** | default | 3600s | 1 | Import SQL backup vÃ o SEO DB vá»›i progress callback. Chá»‰ dispatch khi file â‰¥5MB (`config db_import_queue_threshold`) vÃ  queue !== sync | `SeoDatabaseBackupService::importConnection()` |

### Queue Manager UI (Ä‘Ã£ gá»¡)

- ÄÃ£ xÃ³a `Filament/Pages/SeoQueueManager` (`/queue-manager`), banner `global-queue-worker-alert`, vÃ  `Services/SeoQueueControlService`.
- Laravel Queue chá»‰ cÃ²n infrastructure (worker CLI / Supervisor). KhÃ´ng cÃ²n pause/resume/stop audit tá»« UI.
- Automation nav: Rules / Executions / Operations â€” khÃ´ng thay báº±ng dashboard queue má»›i.
- Regression: `tests/Unit/QueueManagerRemovalTest.php`.

### Runtime Runners (khÃ´ng pháº£i Job â€” cháº¡y trong handle() cá»§a Job)

| Runner | File | MÃ´ táº£ |
|--------|------|-------|
| **IncrementalDomainSyncRunner** | `Services/IncrementalDomainSyncRunner.php` | Cháº¡y incremental sync Ä‘á»“ng bá»™ (trong process) vá»›i chunk + cache progress |
| **MetadataDomainSyncRunner** | `Services/MetadataDomainSyncRunner.php` | Cháº¡y metadata resync Ä‘á»“ng bá»™ (trong process) |

### LÆ°u Ã½ Queue
- **Queue connection**: `config('queue.default')` â†’ fallback `config('database.default')`
- **Queue name máº·c Ä‘á»‹nh**: `config('queue.connections.{default}.queue', 'default')`
- **Cáº§n worker riÃªng**: cho `media_generation` queue â†’ `php artisan queue:work --queue=media_generation`
- **KhÃ´ng cÃ³**: Laravel Scheduler, `Bus::batch`, `::withChain`, `app/Jobs/` global

---

## Äá»‘i chiáº¿u â€” Nhá»¯ng gÃ¬ cÃ²n thiáº¿u trong docs/ MAP

### ADDON SeoContentAi â€” ÄÃ£ cÃ³ trong MAP nhÆ°ng cÃ²n thiáº¿u chi tiáº¿t

| # | TÃ­nh nÄƒng / Class | MAP hiá»‡n táº¡i | Má»©c Ä‘á»™ thiáº¿u |
|---|------------------|-------------|-------------|
| 1 | **SeoEngineService** (core) dÃ¹ng trong SEO analysis | SUPER_MAP_INDEX Â§3 | ChÆ°a cÃ³ link |
| 2 | **SeoDatabaseBackupService** â€” backup/restore SEO DB | MAP_SEO_SETTINGS | ChÆ°a cÃ³ |
| 3 | **SeoAnalyzerService** â€” SEO tá»•ng thá»ƒ (1160 dÃ²ng) | MAP_SEO_EDITOR Â§5 | ChÆ°a cÃ³ section riÃªng |
| 4 | **ArticlePolylangSyncService** â€” Polylang sync | MAP_SEO_WP | Chá»‰ nháº¯c qua Editor |
| 5 | **ArticleQuickTranslateService** â€” translate nhanh | MAP_SEO_EDITOR | Chá»‰ nháº¯c Livewire method |
| 6 | **ArticleQuickPostReviewService** â€” post review nhanh | MAP_SEO_EDITOR | Chá»‰ nháº¯c tab name |
| 7 | **ArticleInternalLinkSuggestionService** | MAP_SEO_EDITOR | Chá»‰ nháº¯c Livewire method |
| 8 | **ArticleInternalLinkSearchService** | MAP_SEO_EDITOR | Chá»‰ nháº¯c Livewire method |
| 9 | **ArticlePendingInternalLinkService** | KHÃ”NG MAP nÃ o | ChÆ°a cÃ³ |
| 10 | **ArticleKeywordLinkReconcileService** | KHÃ”NG MAP nÃ o | ChÆ°a cÃ³ |
| 11 | **ArticleFeaturedSnippetGeneratorService** | MAP_SEO_SETTINGS Â§2 | ÄÃ£ nháº¯c prompt config, thiáº¿u service detail |
| 12 | **VirtualCommentService** (507 dÃ²ng) | KHÃ”NG MAP nÃ o | ChÆ°a cÃ³ |
| 14 | **GlobalAiChatService** | MAP_SEO_EDITOR | Chá»‰ nháº¯c route, thiáº¿u service detail |
| 15 | **CÃ¡c FAQ services** (12 files: BodySync, ManualExtract, WordPressImport/Restore, ExtractDebug, PromptVariables, ContentFaq, Persistence, HtmlRenderer...) | KHÃ”NG MAP nÃ o / ráº£i rÃ¡c | ChÆ°a cÃ³ pháº§n FAQ riÃªng |
| 16 | **CÃ¡c Article services** (CtaPlaceholder, EditorReadiness, EditorHistory, EditorMediaAi, ProductGalleryDistribute, PostImages, MediaLocal, MarkdownToHtml, ContentSeoBonus, TextTranslateTool) | KHÃ”NG MAP nÃ o | ChÆ°a cÃ³ |
| 17 | **CÃ¡c Media/AI utilities** (TagPersistence, ImageGenerationChain, GeminiMediaGeneration, AiImageProcessing, AiModelsReadiness, PromptPostProcessingApply, PromptMediaStorage) | KHÃ”NG MAP nÃ o | ChÆ°a cÃ³ |
| 18 | **CÃ¡c utilities** (SeoMigrationReconciler, SeoSqlStreamParser, CommentReview*, Utf8Sanitizer, SeoImageResizeMath, CreateArticleWorkflowNotification) | KHÃ”NG MAP nÃ o | ChÆ°a cÃ³ |
| 19 | **PromptResultLinkService**, **ArticlePromptRunHistoryService**, **SeoNotificationService** | MAP_SEO_PROJECTS Â§4.2 | ÄÃ£ nháº¯c |
| 20 | **Shortcode** `[omi_faq]`, **Placeholder** `[phone]`/`[website]`/`[zalo]` | KHÃ”NG MAP nÃ o | ChÆ°a cÃ³ |

---

## File lá»›n nháº¥t cáº§n chÃº Ã½

| File | DÃ²ng | LÃ½ do |
|------|------|-------|
| `Services/WorkflowParserService.php` | 2614 | Parser workflow output (lá»›n nháº¥t) |
| `Services/TaskWorkflowTestRunner.php` | 2082 | Engine cháº¡y workflow |
| `Services/SyncDomainContentService.php` | 1273 | Äá»“ng bá»™ domain tá»« WP |
| `Services/SeoAnalyzerService.php` | 1160 | PhÃ¢n tÃ­ch SEO tá»•ng thá»ƒ |
| `Services/PromptRunnerService.php` | 1156 | Engine AI trung tÃ¢m |

---

## LÆ°u Ã½ kiáº¿n trÃºc

1. **KhÃ´ng cÃ³ Event/Listener**: `app/Events/` vÃ  `app/Listeners/` Ä‘á»u rá»—ng â€” khÃ´ng dÃ¹ng Laravel events
2. **KhÃ´ng cÃ³ Console Kernel**: Laravel 11+ dÃ¹ng `bootstrap/app.php` + `routes/console.php`
3. **KhÃ´ng cÃ³ Schedule**: KhÃ´ng cÃ³ cron jobs trong codebase
4. **KhÃ´ng cÃ³ Broadcasting/Channels**: KhÃ´ng cÃ³ `routes/channels.php`
5. **Intervention Image**: Singleton ImageManager vá»›i GD/Imagick fallback (log ghi khi fallback)
6. **Cross-DB pattern**: Core models dÃ¹ng `UsesCoreDatabaseConnection` trait; SEO models dÃ¹ng `BelongsToOnDefaultConnection` trait
7. **Misplaced table cleanup**: `php artisan database:cleanup-misplaced-tables` â€” xem `docs/DATABASE_CLEANUP_MISPLACED_TABLES.md`
8. **Automation DB (core)**: tables `automation_*` + `business_events` trÃªn `config('database.core_connection')` qua `config/automation.php` (`AUTOMATION_DB_CONNECTION`); migrate data `php artisan automation:migrate-to-core`; base `App\Support\Automation\AutomationModel` / `AutomationConnection`
8. **Sanctum**: SPA authentication + token-based API
9. **Dynamic Addon Registration**: KhÃ´ng Ä‘Äƒng kÃ½ static trong `config/app.php` â€” Ä‘á»c tá»« `services` table runtime
