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
- **Prompt & Task** — Quản lý prompt, workflow builder, chạy thử, tích hợp AI (Gemini và kết nối AI).
- **Domain** — Cấu hình site, từ khóa, internal link list, CTA, tone, prompt theo domain.
- **Thư viện media** — Upload local, sync WP, watermark, split ảnh, tối ưu ảnh, job AI generate.
- **Cài đặt** — Workflows, prompt hệ thống, tối ưu ảnh, watermark theo domain; widget tải plugin WP trên dashboard.

---

## Yêu cầu

- PHP 8.3+, Laravel 12, Filament 3
- MySQL (connection `omi_seo_ai` kế thừ cấu hình `mysql`, database name từ `addon.json`)
- Node.js — build frontend (`npm run build` / `npm run dev`)
- WordPress site có cài **[omi-seo-ai-bridge](https://github.com/)** (repo: `wp-seo-ai`) với token đọc/ghi trên domain

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
   articles, prompts, seo_media, keywords, …
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
├── Models/                    # Eloquent (connection omi_seo_ai)
├── Services/                  # Logic nghiệp vụ (sync WP, SEO, media, FAQ, …)
├── Http/Controllers/          # Preview, media API, WP redirect, plugin download
├── Livewire/                  # GlobalSeoBar (domain + role simulator)
├── database/migrations/       # Migration addon DB
├── lang/                      # en, vi (seo-content-ai::filament.*)
├── resources/
│   ├── js/                    # React components + entry (article-editor.jsx, …)
│   ├── css/                   # Tailwind / editor / media styles
│   └── views/                 # Blade (seo-content-ai::)
├── routes/
│   └── api.php                # WP bridge webhook, plugin update API
└── tests/Unit/                # PHPUnit unit tests addon
```

---

## Cài đặt & vận hành

### 1. Database

Addon tự đăng ký connection `omi_seo_ai` từ `addon.json` (clone config `mysql`, đổi tên database).

```bash
# Tạo database MySQL tên omi_seo_ai (hoặc tên trong addon.json), rồi:
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

Cài plugin từ dashboard SEO (widget **WordPress Plugin**) hoặc build zip:

```powershell
# Ví dụ script repo (xem .cmd ở root project)
powershell -ExecutionPolicy Bypass -File C:\work\omnichannel-backend\compress_plugin.ps1
```

Repo plugin: `wp-seo-ai` → package `omi-seo-ai-bridge`.

Cấu hình URL Laravel trên WP (để nút **Sửa bài viết** trên frontend trỏ về editor):

- API URL Omnichannel + route redirect: `GET /seo/articles/wp-edit-redirect?wp_id=…&type=…&site_url=…`

---

## Routes & API (tóm tắt)

| Nhóm | Prefix | Mô tả |
|------|--------|--------|
| Panel | `/seo` | Filament (articles, domains, prompts, tasks, media, settings) |
| Media API | `/api/seo/media` | Upload, watermark, rename, AI jobs |
| Watermark API | `/api/seo/watermark` | Cấu hình & batch watermark |
| Article | `/seo/articles/{id}/preview`, `seo-preview`, `wp-edit-redirect` | Preview & redirect WP |
| WP bridge | `/api/seo-wp-bridge/*` | Webhook push content từ WP |
| Plugin | `/api/seo/plugin/*` | Update check / download bridge |

---

## Luồng chỉnh sửa bài viết

1. Mở **Articles → Edit** (`EditArticle` + `edit-article.blade.php`).
2. React mount tại `#seo-article-editor-root` (`article-editor.jsx` → `SeoArticleEditor.jsx`).
3. **Lưu** — `persistArticleLocal()` (chỉ Laravel).
4. **Đồng bộ** — `syncArticleToWordPress()` → `WordPressArticleSyncService` → `POST …/editor-sync`.
5. **Slug** — `confirmArticleSlug()` → `syncSlugForArticle()` → fetch lại permalink từ WP (`refreshSlugAndPermalinkFromWordPress`).
6. **Lấy từ WordPress** — `restoreArticleFromWordPress()` / `ArticleFaqWordPressRestoreService`.

HTML gửi WP được làm sạch qua `ArticleEditorHtmlSanitizeService` (gỡ class Tailwind/utility từ output AI cũ).

---

## Services quan trọng

| Service | Vai trò |
|---------|---------|
| `WordPressArticleSyncService` | Đồng bộ full + slug-only lên WP |
| `WordPressArticleContentService` | Fetch WP, permalink, slug, featured image |
| `SeoAnalyzerService` | Chấm SEO, preview SERP |
| `ArticleFaqGeneratorService` | Tạo FAQ bằng AI (prompt workflow) |
| `SeoMediaLibraryService` / `WordPressMediaLibraryService` | Thư viện ảnh |
| `SeoWatermarkService` | Watermark theo domain |
| `WorkflowParserService` | Parse workflow / FAQ từ nội dung |
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

Một số test có sẵn: `WorkflowParserServiceTest`, `KeywordPhraseMatcherTest`, `ArticleEditorHtmlSanitizeServiceTest`, `SeoWatermarkSettingTest`.

---

## Phát triển

- Filament resource mới → `php artisan make:filament-resource …` trong namespace addon hoặc thêm tay dưới `Filament/Resources/`.
- CSS/JS addon → sửa file trong `resources/`, thêm entry vào `vite.config.js` nếu cần, chạy `npm run build`.
- Blade view → namespace `seo-content-ai::` (đăng ký trong `SeoPanelProvider::boot`).
- Model mới → `protected $connection = 'omi_seo_ai';` và migration trong `database/migrations/`.

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
