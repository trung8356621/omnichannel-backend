# SEO Content AI

Addon Omnichannel Backend để quản lý nội dung SEO, prompt/workflow AI, thư viện media, watermark và đồng bộ hai chiều với WordPress qua plugin **omi-seo-ai-bridge**.

| Thuộc tính | Giá trị |
|------------|---------|
| Slug addon | `seo-content-ai` |
| Panel Filament | `/seo` (id: `seo`) |
| Database connection | `omi_seo_ai` (tên DB trong `addon.json`: `omi_seo_ai`) |
| Provider | `App\Addons\SeoContentAi\SeoContentAiServiceProvider` |
| Panel provider | `App\Addons\SeoContentAi\Providers\SeoPanelProvider` |

---

## Tính năng chính

- **Bài viết / sản phẩm / danh mục** — Editor block (TipTap/React), FAQ, SEO meta, preview Google SERP, điểm SEO, review ảo.
- **Đồng bộ WordPress** — Lưu local, đồng bộ full (`WordPressArticleSyncService`), sửa slug trực tiếp lên WP + refresh permalink, lấy nội dung từ WP, redirect sửa bài từ frontend WP.
- **Prompt, Task & Content Projects** — Quản lý prompt, workflow builder, chạy thử, project theo tháng, chạy workflow và theo dõi run/task.
- **Trợ lý AI toàn cục** — Sidebar chat Gemini/Claude trên các trang SEO, hỗ trợ ảnh và dùng model active từ AI settings; không render trong `/admin` hoặc trang sửa bài viết vì editor có panel AI riêng.
- **Phân quyền SEO workspace** — Tách quyền `manager` / `planner` / `content_manager`, hỗ trợ scope domain toàn cục và role simulation qua `GlobalSeoBar`.
- **Domain** — Cấu hình site, từ khóa (cụm/cluster), internal link list, CTA, tone, prompt theo domain.
- **Keywords** — Quản lý từ khóa global (`keywords`) + liên kết đích theo domain (`seo_links` / `keyword_link`), phân loại `focus` / `internal` / `suggest` / `free`, cụm parent–child, lọc nâng cao, cào lại từ bài viết, phát hiện lệch domain khi xem từ khóa con.
- **Thư viện media** — Upload local, sync WP, watermark, split ảnh, tối ưu ảnh, job AI generate.
- **Editor nâng cao** — Lưu nháp local cho featured image + product album, bridge event Livewire↔React, autosave lock, FAQ extract debug.
- **Cài đặt** — Workflows, prompt hệ thống, tối ưu ảnh, watermark theo domain; widget tải plugin WP trên dashboard.

---

## Yêu cầu

- PHP 8.2+, Laravel 12, Filament 3
- MySQL (connection `omi_seo_ai` kế thừa cấu hình `mysql`, database name từ `addon.json`)
- Node.js — build frontend (`npm run build` / `npm run dev`)
- WordPress site có cài plugin **omi-seo-ai-bridge** (repo nội bộ: `wp-seo-ai`) với token đọc/ghi trên domain

---

## Kiến trúc

```text
┌─────────────────────┐     REST (read/write token)      ┌──────────────────────┐
│  Filament /seo      │ ◄──────────────────────────────► │  WordPress + plugin  │
│  Livewire + React   │   editor-sync, fetch, webhook   │  omi-seo-ai-bridge   │
└─────────┬───────────┘                                  └──────────────────────┘
          │
          ▼
   MySQL (omi_seo_ai)
   articles, prompts, seo_media, keywords, seo_links, keyword_link, …
```

- **Laravel** — Business logic trong `Services/`, model `Models/`, Filament UI `Filament/`.
- **React (Vite)** — Editor bài viết, media library, watermark, task builder; entry trong `vite.config.js`.
- **WP plugin** — REST namespace `omi-seo-ai/v1` (posts/terms editor-sync, media, FAQ shortcode, virtual comments, v.v.).

---

## Cấu trúc thư mục

```text
SeoContentAi/
├── addon.json                 # Metadata addon (slug, database, provider)
├── SeoContentAiServiceProvider.php
├── Providers/
│   └── SeoPanelProvider.php   # Filament panel /seo, routes, Livewire hooks
├── Filament/                  # Resources, Pages, Widgets
├── Models/                    # Eloquent (connection omi_seo_ai): Keyword, SeoLink, SeoArticle, …
├── Services/                  # Logic nghiệp vụ (sync WP, SEO, media, FAQ, keywords, …)
├── Support/                   # SeoAccessControl, filters (CTA blacklist, internal anchor), …
├── Http/Controllers/          # Preview, media API, WP redirect, plugin download
├── Livewire/                  # GlobalSeoBar (domain + role simulator)
├── database/migrations/       # Migration addon DB
├── lang/                      # en, vi (seo-content-ai::filament.*)
├── resources/
│   ├── js/                    # React components + entry (article-editor.jsx, …)
│   ├── css/                   # Tailwind / editor / media styles
│   └── views/                 # Blade (seo-content-ai::)
├── routes/
│   ├── api.php                # WP bridge webhook, plugin update API
│   └── web.php                # Route web được mount qua SeoPanelProvider
└── tests/Unit/                # PHPUnit unit tests addon
```

---

## Cài đặt & vận hành

### 1. Database

Connection runtime: `omi_seo_ai` (khai báo trong `addon.json` → `database.connection`).

| Nguồn | Vai trò |
|--------|---------|
| `addon.json` → `"database"` | Host, port, username, tên DB mặc định |
| `database.local.php` | Override credential hosting (password, host, user…) — **gitignore** |
| Connection `mysql` core | Fallback password/user khi chưa có `database.local.php` (local dev) |

**Local (cùng user/pass với core):** tạo DB `omi_seo_ai`, giữ `addon.json` mặc định — không cần `database.local.php`.

**Hosting (user/pass khác core):**

```bash
cp app/Addons/SeoContentAi/database.local.php.example app/Addons/SeoContentAi/database.local.php
```

Sửa `database.local.php`:

```php
return [
    'host' => 'mysql.hosting.vn',
    'name' => 'u123_omi_seo_ai',
    'username' => 'u123_seo',
    'password' => 'mat_khau_seo',
];
```

```bash
php artisan migrate
```

### 2. Frontend

Các entry Vite nằm trong `vite.config.js` (project root):

- `article-editor.jsx`, `article-edit-page.css`, `article-seo-preview.jsx`
- `task-builder.jsx`, `media-library-actions.js`, `media-image-editor-page.jsx`
- `watermark-editor-page.jsx`, CSS liên quan

```bash
npm install
npm run dev    # phát triển
npm run build  # production — bắt buộc sau khi sửa JS/CSS addon
```

### 3. Queue (job AI ảnh)

```bash
php artisan queue:work --queue=media_generation,default --timeout=360
```

### 4. WordPress

Trên từng **Domain** (Filament):

- `seo_read_token` — đọc nội dung WP
- `seo_migration_token` — ghi / editor-sync

Cài plugin từ dashboard SEO (widget **WordPress Plugin**) hoặc upload bản mới tại **Settings → WP Plugin release** (`/seo/settings/wp-plugin-release`).

Metadata update (`info.json`) lưu trong bảng `wp_options` → `wp_plugin_bridge_info`. API tương thích:

- `GET /api/seo/plugin/update-check` — WordPress auto-update (kèm `download_url` signed)
- `GET /api/seo/plugin/info.json` hoặc `/storage/plugins/omi-seo-ai-bridge/info.json` — JSON metadata từ DB

File ZIP lưu tại `storage/app/public/plugins/omi-seo-ai-bridge/omi-seo-ai-bridge-{version}.zip`.

Repo plugin nguồn: `wp-seo-ai` → package `omi-seo-ai-bridge`. REST `editor-sync` hỗ trợ `category_ids` khi đồng bộ bài viết.

Cấu hình URL Laravel trên WP (để nút **Sửa bài viết** trên frontend trỏ về editor):

- API URL Omnichannel + route redirect: `GET /seo/articles/wp-edit-redirect?wp_id=…&type=…&site_url=…`

---

## Routes & API (tóm tắt)

| Nhóm | Prefix | Mô tả |
|------|--------|--------|
| Panel | `/seo` | Filament (articles, content-projects, domains, prompts, tasks, keywords, media, settings) |
| Media API | `/api/seo/media` | Upload, watermark, rename, AI jobs |
| Watermark API | `/api/seo/watermark` | Cấu hình & batch watermark |
| Article | `/seo/articles/{id}/preview`, `seo-preview`, `wp-edit-redirect` | Preview & redirect WP |
| Global AI chat | `GET /api/ai/chat/models`, `POST /api/ai/chat` | Danh sách model được phép và chat text/image có session auth |
| WP bridge | `/api/seo-wp-bridge/*` | Webhook push content từ WP |
| Plugin | `/api/seo/plugin/*` | Update check / download bridge |
| Plugin metadata | `/api/seo/plugin/info.json`, `/storage/plugins/omi-seo-ai-bridge/info.json` | Metadata JSON từ `wp_options` |
| Plugin (panel) | `/seo/wp-plugin/download/{version}`, `/seo/settings/wp-plugin-release` | Download & upload release |

---

## Global site scope

Thanh **GlobalSeoBar** (Livewire) trên panel `/seo` chọn domain làm scope toàn cục:

- Lưu trong session `seo_global_site_id` + cookie (đồng bộ qua `SeoAccessControl`).
- Hầu hết resource (articles, keywords, tags, content projects, media, …) lọc theo domain đang chọn.
- Đổi domain → reload trang hiện tại để query áp dụng scope mới.

**Lệch domain (domain mismatch):**

| Trang | Hành vi |
|-------|---------|
| `/seo/articles/{id}/edit` | Nếu bài thuộc domain khác global site → redirect `ArticleDomainMismatch`, nút chuyển domain rồi mở editor |
| `/seo/keywords?parent_id={id}` | Nếu từ khóa con chỉ có liên kết trên domain khác → empty state cảnh báo + nút **Chuyển sang {domain}** |

Helper: `SeoAccessControl::globalSiteId()`, `setGlobalSiteId()`, `hasGlobalSiteScope()`.

---

## Keywords & cụm từ khóa

Route: **`/seo/keywords`** (`KeywordResource`).

Danh sách keyword là **global dictionary** — domain trên `GlobalSeoBar` **không lọc** query bảng `keywords`. Domain chỉ dùng khi gắn/xem `seo_links` (cột **Link domain**, modal **Gắn link website**).

### Mô hình dữ liệu

Một **phrase** là một bản ghi `keywords` (global, unique `phrase`). Ngữ cảnh theo domain nằm ở `seo_links` + pivot `keyword_link`:

| Bảng | Vai trò |
|------|---------|
| `keywords` | `phrase`, `type`, `parent_id` (cụm con → focus cha) — global, không `site_id` |
| `seo_links` | `site_id`, `url`, `type` (`internal`/`external`), `article_id`, `source_article_id`, `is_nofollow` |
| `keyword_link` | `keyword_id`, `link_id`, `search_volume`, `difficulty`, `metrics` (JSON) |

Scope domain: `Keyword::scopeForSite()` / `scopeForSites()` qua `whereHas('links', …)` hoặc `whereHas('articles', …)`.

### Loại từ khóa (`type`)

| Type | Mô tả |
|------|--------|
| `focus` | Từ khóa chính / pillar; có thể là parent của cụm |
| `internal` | Anchor internal link bóc tách từ bài |
| `suggest` | Gợi ý từ workflow / related topics (`metrics.rescrape_keep`) |
| `free` | Thêm thủ công; mặc định giữ khi cào lại domain |

### Danh sách & lọc

- **Root list** — chỉ keyword `parent_id IS NULL` (mặc định).
- **Child list** — `?parent_id={focus_id}` hoặc action **Chi tiết cụm** trên dòng focus có con.
- **Filter:** domain (khi chưa chọn global site), type, *Có từ khóa con*, *Có bài viết chính*, *Có bài viết liên kết*, include/exclude tags.
- **Bulk:** gắn tag, chỉ định parent, đổi type, xóa (chỉ keyword chưa dùng).
- **Add keyword** — textarea nhiều dòng → `type=free` trên domain đang chọn.

Lọc tối thiểu: phrase ≥ 2 từ, loại anchor giống link (`InternalAnchorKeywordFilter`).

### Cào lại keywords theo domain

Action **Cào lại keywords** (`KeywordDomainResyncService::resetAndResync`):

1. Xóa keyword khớp CTA blacklist trên domain.
2. Xóa keyword có liên kết (bài/link/cụm) trên domain; **giữ** `free` / `suggest` có `metrics.rescrape_keep`.
3. Quét lại toàn bộ bài viết domain → bóc tách keyword qua `SeoAnalyzerService`.

### Lệch domain khi xem từ khóa con

Parent focus có thể có liên kết trên nhiều domain, nhưng **từ khóa con** thường chỉ có `seo_links` trên domain đã cào cụm. Khi global site ≠ domain có con:

- Bảng trống, hiển thị empty state (icon cảnh báo, mô tả domain hiện tại vs domain có dữ liệu).
- Nút chuyển domain gọi `ListKeywords::switchToClusterChildrenSite()` → `SeoAccessControl::setGlobalSiteId()` + reload `?parent_id=…`.

Logic phát hiện: `KeywordResource::resolveClusterChildrenSiteMismatch()`.

### Services liên quan

| Service | Vai trò |
|---------|---------|
| `KeywordPersistenceService` | Upsert phrase global + `seo_links` / `keyword_link` theo site |
| `KeywordDomainResyncService` | Cào lại keyword theo domain |
| `KeywordPhraseUpdateService` | Đổi phrase, đồng bộ liên kết |
| `KeywordLinkTargetResolver` | Resolve URL đích anchor |
| `DomainLinkListKeywordSyncService` | Đồng bộ internal link list ↔ keywords |

---

## Luồng chỉnh sửa bài viết

1. Mở **Articles → Edit** (`EditArticle` + `edit-article.blade.php`).
2. Nếu global site ≠ `article.site_id` → trang **Domain mismatch** (`ArticleDomainMismatch`) trước khi vào editor.
3. React mount tại `#seo-article-editor-root` (`article-editor.jsx` → `SeoArticleEditor.jsx`).
4. **Lưu** — `persistArticleLocal()` (chỉ Laravel).
5. **Đồng bộ** — `syncArticleToWordPress()` → `WordPressArticleSyncService` → `POST …/editor-sync`.
6. **Slug** — `confirmArticleSlug()` → `syncSlugForArticle()` → fetch lại permalink từ WP (`refreshSlugAndPermalinkFromWordPress`).
7. **Lấy từ WordPress** — `restoreArticleFromWordPress()` / `ArticleFaqWordPressRestoreService`.
8. **Ảnh đại diện + album sản phẩm** — nháp lưu localStorage và đồng bộ về server qua bridge client (`articleFeaturedImageStorage`, `articleProductAlbumStorage`).
9. **SEO scoring realtime** — preview gọi `analyzeSeoDraft()` kèm `seoTitle` / `seoMetaDescription` khi user sửa meta; panel hiển thị điểm `+/-` từng rule (`SeoScorePanel.jsx`).
10. **Danh mục WP** — mở trang edit luôn fetch/sync categories từ WordPress (`syncWordPressCategoriesOnLoad`).

HTML gửi WP được làm sạch qua `ArticleEditorHtmlSanitizeService` (gỡ class Tailwind/utility từ output AI cũ).

---

## Services quan trọng

| Service | Vai trò |
|---------|---------|
| `WordPressArticleSyncService` | Đồng bộ full + slug-only lên WP (kèm `category_ids`) |
| `WordPressArticleContentService` | Fetch WP, permalink, slug, featured image, categories |
| `SeoAnalyzerService` | Chấm SEO (rules có điểm +/-), preview SERP, bóc tách link/keyword |
| `KeywordPersistenceService` | Upsert keyword + meta theo domain |
| `KeywordDomainResyncService` | Cào lại keyword domain (giữ free/suggest thủ công) |
| `ArticleFaqGeneratorService` | Tạo FAQ bằng AI (prompt workflow) |
| `SeoMediaLibraryService` / `WordPressMediaLibraryService` | Thư viện ảnh |
| `SeoWatermarkService` | Watermark theo domain |
| `WorkflowParserService` | Parse workflow / FAQ từ nội dung |
| `SeoProjectWorkflowRunService` | Chạy workflow cho content project và quản lý trạng thái run/task |
| `SeoProjectTaskSyncService` | Đồng bộ task theo project, kiểm soát limit và dữ liệu đầu vào |
| `SeoProjectApprovalService` | Duyệt bài và cập nhật trạng thái content project liên kết |
| `DomainOverviewService` | Tổng hợp trạng thái kết nối/token WordPress theo domain |
| `WordPressPluginReleaseService` | Quản lý metadata + package plugin để update/download |
| `VirtualCommentService` | Review ảo ↔ meta `_omi_seo_virtual_comments` |
| `ArticleWpEditRedirectController` | Redirect từ WP frontend sang editor Laravel |

---

## i18n

- File: `lang/en/filament.php`, `lang/vi/filament.php`
- Namespace: `seo-content-ai::filament.*`
- JS: `resources/js/utils/i18n.js` (locale từ `window.__SEO_I18N_LOCALE__`)

---

## Kiểm thử

```bash
php artisan test --filter=SeoContentAi
# hoặc
php artisan test app/Addons/SeoContentAi/tests
```

Một số test có sẵn: `WorkflowParserServiceTest`, `WorkflowKeywordResearchServiceTest`, `KeywordPhraseMatcherTest`, `KeywordPersistenceServiceTest`, `KeywordPhraseDecodeTest`, `CtaKeywordBlacklistFilterTest`, `DomainLinkListKeywordSyncServiceTest`, `WordPressArticleContentServiceCategoryTest`, `ArticleEditorHtmlSanitizeServiceTest`, `SeoWatermarkSettingTest`, `SeoProjectMergeServiceTest`, `SeoProjectRunPreflightServiceTest`, `SeoMediaStorageServiceTest`, `GlobalAiChatServiceTest`.

---

## Phát triển

- Filament resource mới → `php artisan make:filament-resource …` trong namespace addon hoặc thêm tay dưới `Filament/Resources/`.
- CSS/JS addon → sửa file trong `resources/`, thêm entry vào `vite.config.js` nếu cần, chạy `npm run build`.
- Blade view → namespace `seo-content-ai::` (đăng ký trong `SeoPanelProvider::boot`).
- Model mới → `protected $connection = 'omi_seo_ai';` và migration trong `database/migrations/`.
- Keyword theo domain → luôn ghi qua `KeywordPersistenceService::upsert()` / `upsertMeta()`, không gán `site_id` trực tiếp lên `keywords`.
- Cross-database (Site ↔ keyword/link) → scalar `site_id` trên `seo_links`, không FK sang DB core.

**Lưu ý layout trang Edit Article:** CSS header/sidebar tách entry `article-edit-page.css` (Vite), không `@vite` trực tiếp `article-editor.css` (chỉ bundle qua JS).

---

## Liên quan

| Thành phần | Vị trí |
|------------|--------|
| Omnichannel Backend | Repo hiện tại, addon này |
| WP Bridge Plugin | `wp-seo-ai` → `omi-seo-ai-bridge` |
| Site model | `App\Models\Site` (domain, metas token) |

---

## Phiên bản

Theo `addon.json` (hiện tại `1.0.0`). Plugin WP có version riêng trong `storage/app/public/plugins/omi-seo-ai-bridge/` và widget dashboard SEO.
