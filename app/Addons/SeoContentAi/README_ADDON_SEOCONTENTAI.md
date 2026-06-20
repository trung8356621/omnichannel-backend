# SEO Content AI

Addon Omnichannel Backend để quản lý nội dung SEO, prompt/workflow AI, thư viện media, watermark và đồng bộ hai chiều với WordPress qua plugin **omi-seo-ai-bridge**.

| Thuộc tính | Giá trị |
|------------|---------|
| Slug addon | `seo-content-ai` |
| Panel Filament | `/seo/{connection_hash}` (id: `seo`); entry redirect `GET /seo` |
| Database | Mỗi **SEO Database Connection** có schema MySQL riêng; runtime connection động (bootstrap qua `SeoDatabaseConnectionService`) |
| Provider | `App\Addons\SeoContentAi\SeoContentAiServiceProvider` |
| Panel provider | `App\Addons\SeoContentAi\Providers\SeoPanelProvider` |
| Quản lý connection (admin) | `/admin` → **Site Management → SEO Database Connections** |

---

## Tính năng chính

- **Bài viết / sản phẩm / danh mục** — Editor block (TipTap/React), FAQ, SEO meta, preview Google SERP, điểm SEO, review ảo.
- **Đồng bộ WordPress** — Lưu local, đồng bộ full (`WordPressArticleSyncService`), sửa slug trực tiếp lên WP + refresh permalink, lấy nội dung từ WP, redirect sửa bài từ frontend WP.
- **Prompt, Task & Content Projects** — Quản lý prompt, workflow builder, **test prompt** (3 cột: raw / đã ghép / lịch sử), project theo tháng, chạy workflow và theo dõi run/task.
- **Trợ lý AI toàn cục** — Sidebar chat Gemini/Claude trên các trang SEO, hỗ trợ ảnh và dùng model active từ AI settings; không render trong `/admin` hoặc trang sửa bài viết vì editor có panel AI riêng.
- **Phân quyền SEO workspace** — Staff có `users.seo_role`: `manager` / `planner` / `content_manager`; owner/admin vào panel không cần `seo_role`. **GlobalSeoBar**: chọn domain, content project (planner), mô phỏng role.
- **Dashboard 2 chế độ** — **Một domain** (global site đã chọn): thống kê SEO, biểu đồ điểm, trạng thái sync WP. **Tất cả domain**: tiến độ content project, hiệu suất biên tập viên (chỉ `content_manager`), sức khỏe domain, **Plugin WordPress** theo từng domain (owner/admin).
- **Domain** — Cấu hình site, từ khóa (cụm/cluster), internal link list, CTA, tone, prompt theo domain.
- **Keywords & Tags** — Từ khóa global + liên kết theo domain; **Tags** (submenu Keywords) gắn phrase.
- **Thư viện media** — Upload local, sync WP, watermark, split ảnh, tối ưu ảnh, job AI generate.
- **Team** — Trang quản lý thành viên (`SeoTeam`), chat team (`/api/seo/team/messages`), profile / đổi mật khẩu trên panel SEO.
- **Editor nâng cao** — Lưu nháp local cho featured image + product album, bridge event Livewire↔React, autosave lock, FAQ extract debug.
- **Cài đặt** — Workflows, prompt hệ thống, tối ưu ảnh, watermark theo domain; **Phát hành WP Plugin** (`/seo/settings/wp-plugin-release`) trên server Laravel.

---

## Yêu cầu

- PHP 8.2+, Laravel 12, Filament 3
- MySQL — schema SEO **theo từng connection** (bảng `seo_database_connections` trên DB core); credential trong admin hoặc `database.local.php` (legacy single-DB dev)
- Node.js — build frontend (`npm run build` / `npm run dev`)
- WordPress site có plugin **omi-seo-ai-bridge** (repo: `wp-seo-ai`) với token đọc/ghi trên domain

---

## Kiến trúc

```text
┌─────────────────────┐     REST (read/write token)      ┌──────────────────────┐
│  Filament           │ ◄──────────────────────────────► │  WordPress + plugin  │
│  /seo/{hash}        │   editor-sync, fetch, webhook   │  omi-seo-ai-bridge   │
│  Livewire + React   │                                  └──────────────────────┘
└─────────┬───────────┘
          │  SeoDatabaseConnectionService (bootstrap theo hash_id)
          ▼
   MySQL workspace (per connection)
   articles, prompts, prompt_results, seo_media, keywords, seo_links, …
          ▲
          │  site_id, user_id (scalar — không FK sang DB core)
┌─────────┴───────────┐
│  DB core (mysql)    │  sites, users, seo_database_connections, site_services
└─────────────────────┘
```

- **Multi-tenant SEO DB** — Mỗi workspace = một bản ghi `seo_database_connections` + pivot `seo_connection_users`. URL panel luôn có `{connection_hash}`. Middleware `SetDynamicSeoDatabaseByHash` / `SetDynamicSeoDatabase` đặt connection runtime trước query Eloquent addon.
- **Site service binding** — `site_services.bound_type`: `site` (theo domain) hoặc `user` (SEO gắn trực tiếp owner). Owner cần service SEO active mới được admin gán connection hoặc tự tạo connection (tối đa 1).
- **Laravel** — Business logic trong `Services/`, model `Models/`, Filament UI `Filament/`.
- **React (Vite)** — Editor bài viết, media library, watermark, task builder; entry trong `vite.config.js`.
- **WP plugin** — REST namespace `omi-seo-ai/v1` (posts/terms editor-sync, media, FAQ shortcode, virtual comments, site-info, …).

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
├── Support/                   # SeoAccessControl, SeoConnectionContext, filters, …
├── Http/Controllers/          # Preview, media API, WP redirect, plugin download, team chat
├── Http/Middleware/             # SetDynamicSeoDatabase, CheckMainRole
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

### 1. Database & workspace

**Production (khuyến nghị):**

1. Bật addon `seo-content-ai` trên `/admin` → **Quản lý Service**.
2. **Site Management → SEO Database Connections** — tạo connection (`manual` hoặc `auto`), chạy migrate, gán **Owner** (admin) hoặc owner tự tạo nếu đã có Activated Service SEO (`bound_type=user`).
3. Mở workspace: **`/seo/{hash_id}`** hoặc **`/seo`** (redirect).

**Local dev (legacy single DB):** vẫn có thể dùng connection tên `omi_seo_ai` qua `database.local.php` / config mặc định nếu chưa tách connection — xem `SeoDatabaseConnectionService` và migration reconciler (`SeoMigrationReconciler`) khi hosting đã có bảng nhưng thiếu dòng `migrations`.

| Nguồn | Vai trò |
|--------|---------|
| Bảng core `seo_database_connections` | Host, port, database, user, password (encrypted), `hash_id` |
| `database.local.php` (gitignore) | Override credential cho dev / fallback connection tĩnh |
| `SeoDatabaseConnectionService` | Bootstrap runtime connection, migrate, backup |

```bash
php artisan migrate   # core + migrations addon đã load
```

Sau khi tạo connection mới trên admin, dùng action **Run migrations** trên form edit connection.

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

**Plugin bridge:**

- Phát hành ZIP trên Laravel: **Settings → WP Plugin release** (`/seo/{hash}/settings/wp-plugin-release`).
- Dashboard **Tất cả domain** (owner/admin): bảng phiên bản plugin từng site (site-info `bridge_version`); nút mở `{domain}/wp-admin/admin.php?page=omi-seo-ai&view=settings` để kiểm tra/cập nhật thủ công trên WP.
- Auto-update WP: `GET /api/seo/plugin/update-check` (kèm `download_url` signed); metadata `GET /api/seo/plugin/info.json` hoặc `/storage/plugins/omi-seo-ai-bridge/info.json` từ `wp_options`.

File ZIP: `storage/app/public/plugins/omi-seo-ai-bridge/omi-seo-ai-bridge-{version}.zip`.

Repo plugin nguồn: `wp-seo-ai` → package `omi-seo-ai-bridge`. REST `editor-sync` hỗ trợ `category_ids` khi đồng bộ bài viết.

Cấu hình URL Laravel trên WP (để nút **Sửa bài viết** trên frontend trỏ về editor):

- API URL Omnichannel + route redirect: `GET /seo/articles/wp-edit-redirect?wp_id=…&type=…&site_url=…`

---

## Routes & API (tóm tắt)

| Nhóm | Prefix | Mô tả |
|------|--------|--------|
| Panel | `/seo/{connection_hash}` | Filament: articles, domains, prompts, tasks, keywords, tags, media, settings, profile |
| Redirect | `GET /seo` | Vào workspace hoặc login / no-workspace |
| Media API | `/api/seo/media` | Upload, watermark, rename, AI jobs |
| Watermark API | `/api/seo/watermark` | Cấu hình & batch watermark |
| Team API | `/api/seo/team` | Tin nhắn team (`messages`, `config`) |
| Article | `/seo/articles/{id}/preview`, `seo-preview`, `wp-edit-redirect` | Preview & redirect WP |
| Global AI chat | `GET /api/ai/chat/models`, `POST /api/ai/chat` | Model được phép + chat (session auth) |
| WP bridge | `/api/seo-wp-bridge/*` | Webhook push content từ WP |
| Plugin | `/api/seo/plugin/*` | Update check / download bridge |
| Plugin (panel) | `/seo/wp-plugin/download/{version}`, settings wp-plugin-release | Download & upload release |

---

## Global site scope

Thanh **GlobalSeoBar** (Livewire) trên panel SEO:

| Giá trị domain | Dashboard & scope |
|----------------|-------------------|
| **Tất cả domain** (`globalSiteId = null`) | Dashboard workspace: content projects, team, sức khỏe domain, plugin WP (owner/admin). Một số query không lọc theo site. |
| **Một domain cụ thể** | Dashboard domain: stats, biểu đồ SEO, sync WP. Articles, keywords links, media, content projects, … lọc theo site. |

- Lưu trong session `seo_global_site_id` + cookie (`SeoAccessControl`).
- Staff `content_manager` không chọn domain — scope theo quyền bài viết.
- Đổi domain → event `seoGlobalSiteChanged` + reload trang.

**Phân quyền (tóm tắt):**

| Vai trò | Panel | Ghi chú |
|---------|-------|---------|
| `admin`, `owner` | Có | Owner qua connection pivot; admin mọi connection |
| Staff + `seo_role` | Có | `manager` > `planner` > `content_manager` |
| GlobalSeoBar «Góc nhìn» | manager/planner | Mô phỏng role thấp hơn để test UX |

Helper: `SeoAccessControl::globalSiteId()`, `setGlobalSiteId()`, `hasGlobalSiteScope()`, `applyAccessibleSiteScope()` (tránh subquery cross-DB trên hosting).

**Lệch domain (domain mismatch):**

| Trang | Hành vi |
|-------|---------|
| `/seo/articles/{id}/edit` | Nếu bài thuộc domain khác global site → redirect `ArticleDomainMismatch`, nút chuyển domain rồi mở editor |
| `/seo/keywords?parent_id={id}` | Nếu từ khóa con chỉ có liên kết trên domain khác → empty state cảnh báo + nút **Chuyển sang {domain}** |

---

## Keywords & cụm từ khóa

Route: **`/seo/keywords`** (`KeywordResource`).

Danh sách keyword là **global dictionary** — domain trên `GlobalSeoBar` **không lọc** query bảng `keywords`. Domain chỉ dùng khi lọc theo ngữ cảnh link (`seo_link_maps` → `articles.site_id`) hoặc gắn link thủ công.

### Mô hình dữ liệu

Một **phrase** là một bản ghi `keywords` (global, unique `phrase`). Ngữ cảnh anchor/link theo bài viết nằm ở **`seo_link_maps`** (thay cho pivot legacy `keyword_link` — đã drop từ migration `2026_06_18_100000_reform_keywords_to_seo_link_maps`).

| Bảng | Vai trò |
|------|---------|
| `keywords` | `phrase`, `type`, `parent_id` (cụm con → pillar cha) — global, không `site_id` |
| `seo_link_maps` | Một dòng = một anchor trong ngữ cảnh bài: `keyword_id`, `source_article_id`, `target_article_id` / `target_external_url`, `anchor_text`, `context_before`, `context_after`, `link_type`, `status`, `last_http_status`, `last_audited_at` |
| `article_keyword` | Pivot bài ↔ keyword (`is_main`, `weight`) — bài viết chính / liên kết semantic |
| `seo_links` | *(Legacy)* Danh sách link domain; vẫn có thể tồn tại cho flow cũ nhưng **Filament Keywords / audit / rescrape mới dùng `seo_link_maps`** |

**`seo_link_maps.link_type`:** `internal`, `external`, `wiki_trust` (`SeoLinkMapType`).

**`seo_link_maps.status`:** `active`, `needs_audit`, `ignored`, `broken` (`SeoLinkMapStatus`). Job `AuditLinkStatusJob` cập nhật HTTP audit vào `last_http_status` / `last_audited_at`.

**Quan hệ Eloquent chính:**

| Model | Relationship | Ghi chú |
|-------|--------------|---------|
| `Keyword` | `linkMaps()` | HasMany `SeoLinkMap` — **dùng cho UI dictionary, triage, drawer** |
| `Keyword` | `mainArticles()` | BelongsToMany qua `article_keyword` where `is_main` |
| `Keyword` | `links()` | BelongsToMany qua `keyword_link` — **legacy**; chỉ gọi khi `Schema::hasTable('keyword_link')` |

Scope domain: `Keyword::scopeForSite()` / `scopeForSites()` qua `whereHas('linkMaps', …)` (theo `sourceArticle.site_id`) hoặc `whereHas('articles', …)`.

> **Không** dùng `whereHas('links')` hay join `keyword_link` trên DB đã migrate — bảng không còn tồn tại.

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

Parent focus có thể có liên kết trên nhiều domain, nhưng **từ khóa con** thường chỉ có `seo_link_maps` (ngữ cảnh bài) trên domain đã cào cụm. Khi global site ≠ domain có con:

- Bảng trống, hiển thị empty state (icon cảnh báo, mô tả domain hiện tại vs domain có dữ liệu).
- Nút chuyển domain gọi `ListKeywords::switchToClusterChildrenSite()` → `SeoAccessControl::setGlobalSiteId()` + reload `?parent_id=…`.

Logic phát hiện: `KeywordResource::resolveClusterChildrenSiteMismatch()`.

### Services liên quan

| Service | Vai trò |
|---------|---------|
| `KeywordPersistenceService` | Upsert phrase global; đồng bộ mapping qua `seo_link_maps` / `article_keyword` (fallback `keyword_link` chỉ khi bảng legacy còn tồn tại) |
| `ArticleLinkContextMapService` | Bóc tách / cập nhật `seo_link_maps` theo ngữ cảnh HTML bài viết |
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

## Test prompt (`/prompts/{id}/test`)

Layout 3 cột:

| Cột | Nội dung |
|-----|----------|
| **Prompt** | Raw từ parts — giữ placeholder `{{biến}}`, sửa tay trước khi chạy |
| **Prompt đã ghép** | Biến site mặc định đã thay; chọn lịch sử chỉ cập nhật cột này |
| **Lịch sử** | Các lần chạy thử gần đây |

Chạy thử gửi nội dung cột Prompt (đã sửa) qua `PromptRunnerService::runWithCompiledPrompt()`. Compile: `compileRawPrompt()` vs `compilePrompt()`.

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
| `AllDomainsDashboardService` | Dashboard tất cả domain: projects, team, sức khỏe domain |
| `WordPressPluginDomainsOverviewService` | Bảng plugin WP theo domain trên dashboard |
| `WordPressPluginReleaseService` | Quản lý metadata + package plugin trên Laravel |
| `WordPressSiteInfoService` | Fetch/lưu `site-info` (gồm `bridge_version`) từ WP |
| `SeoDatabaseConnectionService` | Bootstrap connection theo hash/site, migrate workspace |
| `SeoMigrationReconciler` | Reconcile migration khi DB đã có bảng (hosting) |
| `PromptRunnerService` | Compile/chạy prompt, lưu `prompt_results` |
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
php artisan test app/Addons/SeoContentAi/tests
# hoặc
php artisan test --filter=SeoAccessControl
```

Nhóm test gồm: access control & site scope, keyword persistence/resync, workflow parser, SEO analyzer/sanitize, media storage, project run, migration reconciler, database bootstrap, …

---

## Phát triển

- Filament resource mới → `php artisan make:filament-resource …` trong namespace addon hoặc thêm tay dưới `Filament/Resources/`.
- CSS/JS addon → sửa file trong `resources/`, thêm entry vào `vite.config.js` nếu cần, chạy `npm run build`.
- Blade view → namespace `seo-content-ai::` (đăng ký trong `SeoPanelProvider::boot`).
- Model mới → connection qua base model addon / `$connection` runtime sau bootstrap; migration trong `database/migrations/` (chạy trên workspace connection).
- Keyword theo domain → luôn ghi qua `KeywordPersistenceService::upsert()` / `upsertMeta()`, không gán `site_id` trực tiếp lên `keywords`.
- Cross-database (Site ↔ SEO workspace) → scalar `site_id` / `user_id`, **không FK** sang DB core; query core dùng `whereIn` qua `SeoAccessControl::accessibleSiteIds()` khi cần.
- Bảng **`entities` / `entity_results`** đã gỡ (legacy); lịch sử prompt nằm ở `prompt_results` + `input_snapshot`.

**Lưu ý layout trang Edit Article:** CSS header/sidebar tách entry `article-edit-page.css` (Vite), không `@vite` trực tiếp `article-editor.css` (chỉ bundle qua JS).

---

## Liên quan

| Thành phần | Vị trí |
|------------|--------|
| Omnichannel Backend | Repo hiện tại, README gốc `README.md` |
| WP Bridge Plugin | `wp-seo-ai` → `omi-seo-ai-bridge` |
| Site model | `App\Models\Site` (domain, metas token) |
| SEO connection (core) | `App\Models\SeoDatabaseConnection` |

---

## Phiên bản

Theo `addon.json` (hiện tại `1.0.0`). Plugin WP có version riêng trên từng site (`bridge_version` trong site-info) và bản phát hành trên Laravel (`storage/app/public/plugins/omi-seo-ai-bridge/`).
