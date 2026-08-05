# Media and Gallery

> Status: Canonical  
> Owner: SeoContentAi  
> Last verified: 2026-08-01  
> Supersedes: `docs/archive/maps/MAP_SEO_MEDIA.md` (architecture — discard prompt playbook dumps)

## 1. Purpose

SEO media library, upload/optimize/watermark pipeline, image editors, and WordPress attachment sync touchpoints for SeoContentAi.

- Storage + rows on `omi_seo_ai` (`seo_media`, meta, watermark/optimization settings, processing history, WP backup/pending tables).
- Site ACL via core `sites` (cross-DB).
- Editor gallery / featured / product album consume these APIs — rename/slug contracts owned with Article Editor.
- **Article Editor Phase 2A:** Featured/Gallery canonical SoT = Laravel `media_snapshot` ([`ARTICLE_EDITOR_MEDIA_SNAPSHOT.md`](../architecture/ARTICLE_EDITOR_MEDIA_SNAPSHOT.md)). Mutations persist immediately; no localStorage shadow SoT.
- **Article Editor Phase 2B:** Image ratio / widget health consume `media_snapshot.content_images` + analysis policy (`words_per_image`); ratio is **info**, not Images hard error. See [`ARTICLE_EDITOR_ANALYSIS_OWNERSHIP.md`](../architecture/ARTICLE_EDITOR_ANALYSIS_OWNERSHIP.md).
- **Article Editor Phase 6C.3:** React owns Featured/Gallery sidebar UI + one Shared Media Picker (`mode`: `content_image` | `featured` | `gallery`). Alpine modal/drafts removed. WP Fix Slug All still skips WP attachments; picker selection never renames. Dead Alpine picker partial / featured CustomEvents cleaned — see [`ARTICLE_EDITOR_LEGACY_CLEANUP.md`](../architecture/ARTICLE_EDITOR_LEGACY_CLEANUP.md).
- **Images unified inventory:** editor Images panel/health consume `unifiedArticleImagesInventory.js` (content+Featured+Gallery). Local Laravel Featured must not be flagged WP-protected via stale `wp_featured_attachment_id` (SeoMedia PK) — `mediaSourceClassification.js` + snapshot `enrichMediaItem` clear false WP ids when URL is `/storage/…`.

## 2. Canonical routes

### REST (`SeoPanelProvider` — `/api/seo/media/*`)

| Method | Path | Role |
|--------|------|------|
| POST | `upload` | Local upload |
| POST | `import-url` | Remote import (`random_filename` → `import-{hex}`) |
| POST | `rename-by-url` / `{media}/rename` | Rename (+ optional `article_id` rewrite) |
| POST | `update-meta` | Alt/title meta |
| POST | `save-split` / GET `splitter-source` | Image splitter |
| POST | `prepare-editor` | Prep for AI / eraser |
| POST | `apply-watermark` | Watermark one media |
| GET | `{media}/status` | Processing status |
| GET | `article/{article}/ai-jobs` | AI job list (incl. recent completed) |
| POST | `{media}/retry-generation` | Retry AI |
| DELETE | `{media}/ai-job` | Delete AI job |
| POST | `{media}/save-edited` | Save edited binary |
| GET | `workspace-picker` | `WorkspaceMediaPickerController` (not SeoMediaController) |

Also: `POST /api/seo/articles/{id}/fix-media-slugs`; watermark group `/api/seo/watermark/*`.

### Filament pages

| Path | Page |
|------|------|
| `media-image-editor?media=&tab=` | `MediaImageEditor` (eraser / splitter) — no nav |
| `image-processing` | `ImageProcessingPage` |
| `settings/image-optimization` | `ImageOptimizationSettings` |
| Watermark Filament pages | Settings + batch UI |

Middleware: Authenticate + role checks + `SetDynamicSeoDatabase` / connection context.

## 3. Main components

| Concern | Class |
|---------|--------|
| HTTP | `Http/Controllers/SeoMediaController` |
| Storage | `SeoMediaStorageService` |
| Optimize | `SeoImageOptimizationService` |
| Pipeline | `Support/SeoImagePipeline` + `SeoImageResizeMath` |
| Resize | `SeoMediaResizeService` |
| Driver | `App\Support\ImageDriverResolver` (imagick/gd) |
| Validate convert | `SeoConvertedImageValidator` + `ImageContentSignature` |
| Watermark | `SeoWatermarkService` + `SeoWatermarkController` |
| Split | `SeoImageSplitterService` |
| URL import | `SeoMediaUrlImportResolverService` |
| Paths | `SeoMediaPathAllocator` |
| History | `SeoMediaProcessingHistoryService` |
| Model | `SeoMedia` + `SeoMediaBuilder` (meta-field routing) |
| Library actions | `SeoMediaLibraryImageActionService` |
| Media Library page | `Filament/Pages/MediaLibrary` + `SeoMediaLibraryService::fetch` default **50**/page (5×10 desktop); tab/filter/search reset page; `#[Url]` keeps `activeTab` deep-link |
| Article media picker | `ArticleMediaPickerController` — perPage **28** (shared with workspace picker) |
| Article slug fix | `SeoMediaArticleSlugFixService` |
| Media source classify (editor) | `resources/js/utils/mediaSourceClassification.js` + snapshot `isLocalLaravelMediaUrl` |
| Unified images inventory | `resources/js/utils/unifiedArticleImagesInventory.js` |
| URL rewrite | `SeoMediaUrlReplacementService` |
| WP sync media | `WordPressLocalMediaSyncService` |
| WP media browse capability | `WordPressMediaCapabilityResolver` (site-level; used by article media picker) |
| Article media picker | `ArticleMediaPickerController` + `ArticleEditorLazyPayloadController::mediaPickerConfig` |
| AI generate job | `Jobs/GenerateMediaJob` |
| Image routing | `ImageRoutingStrategy` / `ImageCapabilityResolver` / `GeminiModelVersionPolicy` |
| Frontend | `seoMediaApi.js`, `watermarkApi.js`, `media-image-editor-page.jsx`, `watermark-editor-page.jsx`, Media Library Alpine |

## 4. Data ownership

| Concern | Owner | Notes |
|---------|-------|-------|
| Binary + `seo_media` row | Laravel SEO DB | Local working files |
| Watermark settings | `seo_watermark_settings` | Per-site apply rules |
| Optimization settings | `seo_image_optimization_settings` | WebP / size targets |
| WP attachment id / URL | Media meta after sync | Cleared if WP attachment missing → re-import |
| WP originals on disk | **Never overwritten** by WebP sibling | Sibling `.webp` / `-wp-upload.*` |
| Local after sync | Keep until Reviewed cleanup | Do not auto-trash on sync |
| Product gallery blocks | Article editor state + media ids | Split keeps original when `canDeleteOriginal=false` |

Clipboard upload forces `paste-{hex}` slug (`source=clipboard`) — never OS `image.png`.

## 5. Read path

```text
UI / editor → seoMediaApi / workspace-picker
  → SeoMediaController (ACL site/article)
  → SeoMedia (+ meta via builder) + status/history
```

Library and picker list local media for site/article scope. Broken image UI: static placeholder, no retry loop (`brokenImageGuard`).

### Article Media Picker — WordPress tab (site-level)

- Route: `GET /api/seo/articles/{article}/editor/media-picker-config` + `GET .../media-picker?tab=original`.
- Capability: `WordPressMediaCapabilityResolver` → `wordpress_media_available` / `wordpress_media_unavailable_reason`.
- **Site-level**: require domain + `seo_read_token` + WP permalink base. **Not** gated on `article.wp_post_id`, sync status, or publish.
- BC field `wordPressLinked` mirrors `wordpress_media_available` (no longer means “article linked to WP post”).
- Browse/select WP media does **not** enqueue article sync or publish.
- UI tab “Gốc (WP)” disabled reason must come from capability reason (connection/credential), never “bài chưa đồng bộ” when library is still reachable.

## 6. Write path

### Upload pipeline

```text
upload/import
  → storeUpload / storeFromRemoteUrl
  → SeoImageOptimizationService.processUpload/processBinary
  → SeoImagePipeline (Imagick Lanczos preferred → Intervention fallback)
  → SeoConvertedImageValidator (choose WebP vs fallback; blank WebP rejected)
  → SeoWatermarkService.applyToMediaIfEnabled
  → SeoMedia::create + meta
```

WebP validation failure does **not** block WP sync if original still decodable.

### WP media sync (`WordPressLocalMediaSyncService`)

Called from article sync prepare path:

1. Prefer `data-seo-media-id` on `<img>`; sync each `seo_media.id` once per pass.
2. `prepareWordPressUploadFile`: prefer valid WebP (even >100KB with log); else original / compressed fallback; **null only** if source missing/undecodeable.
3. Plugin import/replace-binary (≥1.0.50 can switch extension to `.webp`).
4. WebP backfill only when usable local WebP and **no** persistent `-wp-upload.jpg` fallback (avoids duplicate WP attachments).
5. Dead `wp_attachment_id` → clear → fresh import.

### WordPress media safe rename (explicit single)

- **Bulk Fix Slug All never renames WordPress media** (frontend filter + backend fail-closed `wordpress_media_requires_explicit_rename`).
- **Except** UI/state removed — protection is by media source classification (`wordpress` vs local/generated/uploaded).
- Explicit rename: `WordPressMediaRenameService` + `POST /api/seo/media/wordpress/rename/preview|rename` (shared by Article Editor + Media Library modal).
- Requires manager permission, usage scan, checkbox + typed `RENAME`, strict collision, audit log, partial-failure status (no silent success).
- Updates known references only (WP post content/featured via plugin ≥1.0.69 usage/rename; Laravel `articles.body` + media metas). Does **not** claim theme options / page builders / external sites.
- **Editor lock policy (Phase 1.1):** `SeoMediaUrlReplacementService::rewriteArticleReferences` calls `ArticleEditorSessionService::assertBodyRewriteAllowed` — **block** rewrite when any active `article_editor_sessions` row exists (no silent overwrite of open editor). Successful rewrite still bumps `document_version` via observer.
- Media Library preview actions: Edit image · **Đổi tên ảnh** · Apply watermark.

### Watermark

Settings + batch via `/api/seo/watermark/*`. Apply-on-upload when enabled; explicit apply endpoint for existing media.

### Save edited / split / AI

`save-edited`, `save-split`, `prepare-editor` → processing history; AI via `GenerateMediaJob`.

## 7. Public capabilities

REST under authenticated SEO session / connection hash — not Agent MCP media writes by default.

Editor-facing public contracts: upload, rename, fix-media-slugs response shape (`renamed[]` exact map).

## 8. Internal-only capabilities

- Imagick pixel/color compatibility shims.
- Dashboard warning when Imagick/GD missing.
- WP backup / edited-pending tables for repair flows.
- Typography candidate routing (do not spam library).
- `diagnoseLocalMedia` for legacy repair — not required before every sync.

## 9. Authorization and confirmation

- `canAccessSite` / `canAccessArticle` on controller.
- Delete media: `SeoAccessControl::canDeleteSeoMedia()` (Planner+).
- Connection bootstrap required before queries hit `omi_seo_ai`.

## 10. Queue and scheduler ownership

| Job | Queue | Role |
|-----|-------|------|
| `GenerateMediaJob` | `media_generation` | AI image/video generate + post-process |

Resize from library/workflow may run inline via `SeoMediaResizeService`. Flatten paths console: `seo:media-flatten-paths` (ops).

## 11. Transactions and side effects

- Upload creates media row after successful process; undecodeable paste → no row.
- Watermark mutates binary when enabled.
- Rename/fix-slugs rewrites article body/meta URLs.
- Sync patches HTML `src` from cache within same pass — no re-import loop.
- Reviewed cleanup may delete local media for article (editor/review module).

## 12. Retry and recovery

- AI: retry-generation; delete failed/completed jobs.
- WP missing attachment → clear ids → import.
- WebP fail → fallback original/JPEG path; log `SEO_MEDIA_*` codes.
- Over-size WebP still syncable with `SEO_MEDIA_FALLBACK_OVER_TARGET_SIZE`.

## 13. Compatibility paths

- Intervention fallback when native Imagick path unavailable.
- Workspace picker separate controller.
- Product gallery split moved into AI generate modal (thumbnail sidebar quick-split removed).
- Legacy trash status on media restored to `completed` on re-sync when applicable.

## 14. Forbidden paths

1. Overwrite original PNG/JPG on Laravel disk with WebP in place.
2. Block WP sync solely because WebP encode failed.
3. WebP backfill when `-wp-upload.jpg` fallback exists.
4. Second article image rename pipeline outside slug-fix + URL replacement services.
5. DOM-only URL patch after rename without editor document apply + save (see ARTICLE_EDITOR).
6. Media queries without SEO connection bootstrap.
7. Use `workspace-picker` handler inside `SeoMediaController` (wrong owner).

## 15. Tests and invariants

Prefer unit tests around pipeline validation, Imagick pixel compat, WP prepare upload fallback, and article slug fix map contract.

Also: `ArticleEditorContextPreservationContractTest` asserts WP media tab is site-level (no `wp_post_id` gate).

Manual verification:

```text
$PHP_BIN vendor/bin/phpunit --filter=SeoMedia
$PHP_BIN vendor/bin/phpunit --filter=SeoImage
$PHP_BIN vendor/bin/phpunit --filter=ArticleEditorContextPreservationContractTest
```

## 16. Related documents

- [ARTICLE_EDITOR.md](ARTICLE_EDITOR.md) — picker, gallery, fix slug all
- [WORDPRESS_BRIDGE.md](WORDPRESS_BRIDGE.md) — attachment import REST
- [SITE_SYNC.md](SITE_SYNC.md) — catalog sync ≠ media upload
- [DATA_AND_RUNTIME_BOUNDARIES.md](../architecture/DATA_AND_RUNTIME_BOUNDARIES.md)
- Archive: `docs/archive/maps/MAP_SEO_MEDIA.md`

### Frontend API map

| Module | Endpoints |
|--------|-----------|
| `seoMediaApi.js` | upload, import-url, prepare-editor, watermark apply, rename*, meta, split, save-edited, AI jobs, workspace-picker, fix-media-slugs |
| `watermarkApi.js` | `/api/seo/watermark/*` |
