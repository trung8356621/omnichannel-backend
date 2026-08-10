# WordPress Bridge

> Status: Canonical  
> Owner: SeoContentAi  
> Last verified: 2026-08-01  
> Supersedes: `docs/MAP_SEO_WP.md`, `docs/WP_PLUGIN_SITE_SYNC_V2.md` (plugin/general sections), Site Sync–adjacent WP notes formerly rooted in MAP_SEO_WP

## 1. Purpose

Durable contract between Laravel SeoContentAi and the WordPress plugin **`omi-seo-ai-bridge`** (`wp-seo-ai`).

Principles:

- Laravel article = **working copy** (edit, schedule, trash/delete **on Laravel**).
- WordPress = **live public** post/SEO/media for published content.
- Outbound Laravel → WP **does not** trash/delete WP posts.
- Outbound sync status is **`publish`** only (+ `post_date` clamp ≤ now) — never WP `draft` / `future` for SEO schedule.
- Schedule lives on Laravel (`articles.status=scheduled`); system cron publishes when due.
- Catalog/SEO delta ownership for V2 sites: **Site Sync** (`SITE_SYNC.md`), not legacy push enrich.

## 2. Canonical routes

### Laravel inbound (plugin → Laravel)

| Method | Path | Role |
|--------|------|------|
| GET | `/api/seo-wp-bridge/ping` | Token + domain check |
| POST | `/api/seo-wp-bridge/push-content` | Compat article import; V2 writer skips links/keywords/scores enrich |
| POST | `/api/seo-wp-bridge/snapshot-callback` | Site Sync snapshot/delta compat |
| POST | `/api/seo-wp-bridge/delta-event` | Site Sync outbox fast-ack |

Registered in `app/Addons/SeoContentAi/routes/api.php` → `SeoWpBridgeController`. Middleware: `api`. Auth: Bearer `sites.seo_read_token`.

### WordPress REST (`omi-seo-ai/v1`)

Durable groups (plugin `Rest_Controller`):

| Group | Endpoints (representative) |
|-------|----------------------------|
| Site Sync V2 | `/capabilities`, `/sync/v2/profile`, `/sync/v2/delta`, `/sync/v2/batches`, `/sync/v2/manifest` |
| Legacy sync | `/sync`, `/sync/manifest`, `/sync/items`, `/site-info` |
| Posts | `/posts`, `/posts/{id}`, `/posts/find-by-article`, `/posts/{id}/editor-sync` |
| Media | `/attachments/import`, `…/replace-binary`, `…/delete`, `…/rename`, `…/update-meta` |
| Taxonomy | `/taxonomies/{taxonomy}/terms`, term editor-sync |
| Reviews / FAQ | `/posts/{id}/comment-reviews`, `/virtual-comments`, `/seo-faq` |

Min Site Sync contract bridge: **`1.0.64`** (`SiteSyncSchema::MIN_BRIDGE_VERSION`). Current plugin line may be newer (features additive).

## 3. Main components

| Side | Component | Role |
|------|-----------|------|
| Laravel | `SeoWpBridgeController` | Inbound ping/push/delta |
| Laravel | `SiteSyncInboundGateway` / delta ingest | Site Sync callbacks |
| Laravel | `WordPressArticleSyncService` | Outbound hub (`syncForArticle`, `publishForArticle`) |
| Laravel | `WordPressFieldConflictService` | Field-level WP/Laravel sync baseline + same-field conflict detection |
| Laravel | `WordPressArticleContentService` | `editor-sync` HTTP |
| Laravel | `WordPressLocalMediaSyncService` / `ArticleMediaLocalService` | Local media → WP |
| Laravel | `WordPressArticleMediaService` / `WordPressArticleAttachmentService` | Featured/gallery/rename/meta |
| Laravel | `WordPressManualSyncService` + `ManualWordPressSyncJob` | Manual editor/list sync (queue `seo`) |
| Laravel | `ArticleWpSyncQueueService` + lease/watchdog | Sync job lease |
| Laravel | `ScheduledArticlePublishRunner` | Due scheduled → publish |
| Laravel | `ExternalPluginRegistry` | Manifest version / download for `omi-seo-ai-bridge` |
| WP | `omi-seo-ai-bridge.php` | Plugin bootstrap |
| WP | `Capability_Manifest` | Provider capability SoT for Site Sync |
| WP | `Score_Exporter` | Provider scores into sync batches |
| WP | `Site_Sync_Outbox` / `Site_Sync_V2_Provider` | Auto delta producer |
| WP | Provider adapters | Rank Math / Yoast / AIOSEO / None |
| WP | `Laravel_Push_Sync` | Outbound push + suppress loop |
| WP | WP-Cron disabler / missed-schedule fixer | Laravel owns schedule; cleanup legacy `future` |

## 4. Data ownership

| Data | SoT | Notes |
|------|-----|-------|
| Live post content/SEO on WP after publish | WordPress | Outbound may update via editor-sync |
| Laravel draft/scheduled working copy | Laravel | Until publish cron/manual sync |
| `wp_post_id` / sync keys | Laravel article + WP meta `_teamvia_article_id` / `_teamvia_sync_key` | Idempotent create |
| Media binary after upload | WordPress attachment + Laravel managed source when present | Laravel `seo_media` / local media markers / pending versions allow explicit Sync WP to update managed attachment meta, slug, assignment, and pending binary |
| FAQs meta | WP `_omi_seo_faqs` | Empty `faqs:[]` must not wipe existing unless `clear_faqs` |
| Product virtual reviews | WP meta + optional local pending | Reviewed article: WP SoT; local pending cleared |
| Catalog links/keywords/scores (V2) | Site Sync / WP provider | Not dual-written by push-content |
| Manual Domain overrides | Laravel Manual | See SITE_SYNC ownership |

## 5. Read path

```
Plugin admin / Laravel Domain widgets
  → ping, capabilities, comment-reviews refresh, plugin release widget

Site Sync pull
  → WordPressSiteSyncClient → WP sync/v2/*

Find existing post
  → GET …/posts/find-by-article
```

## 6. Write path

### Publish / push (outbound)

```
Manual: WordPressManualSyncService
  → local persist (article.content.update)
  → ArticleWpSyncLeaseService::enqueue
  → ManualWordPressSyncJob (queue seo)
  → ArticleWordPressBusinessSequence
  → WordPressArticleSyncService::publishForArticle|syncForArticle
       → media sync → editor-sync REST

Automatic: Automation rule wordpress.article.sync (enabled+published)
  → automation-external queue
  → same sync pipeline

Scheduled due: ScheduledArticlePublishRunner (linked wp_post_id)
  → publish path (not article.completed alone)
```

Invariants:

- Content Project completion / `PromptTestPublishService.publishArticle` = **Laravel only** (no direct WP job).
- `createForArticle` includes `post_content` (+ FAQ/SEO/categories) to avoid empty stub posts; `publishForArticle` lock per `article_id`.
- Outbound status payload: publish only; future `post_date` clamped.
- Existing/imported/rewrite posts are mutable by explicit Sync WP. `wp_post_id`, imported origin, or rewrite mode must not protect the whole record.
- Conflict protection is per field: block only when Laravel and WordPress both changed the same field since `wp_last_synced_field_snapshot`; different-field changes can merge. Without reliable snapshot, `wp_post_id` alone is not a conflict.
- Linked article slug is sent as `slug` / WP `post_name`; returned canonical `slug`/`permalink` from WordPress are stored back on Laravel.
- Product reviews share `SyncArticleToWordPressPipeline` (no separate orphan publish rule).
- Editor canonical document: TipTap JSON on Laravel (`articles.editor_document`); WP still receives derived HTML. Import/body rewrites should invalidate or re-ingest JSON — [`ARTICLE_EDITOR_JSON_PERSISTENCE.md`](../architecture/ARTICLE_EDITOR_JSON_PERSISTENCE.md).

### Media sync

Inside editor-sync prepare:

1. Extract local `seo_media` refs → upload/import or replace-binary
2. Patch HTML src to WP URLs
3. Featured/gallery push; dirty/WebP backfill rules (no junk JPEG→WebP when upload is JPEG)

Rename/meta: dedicated REST; stale attachment id resolved by URL on plugin ≥ 1.0.54.

WordPress media rename (plugin ≥ **1.0.69**):

- `GET|POST /attachments/usage` — usage scan (post_content + featured) before rename.
- `POST /attachments/rename` with `mode=explicit_single` — requires `acknowledge_url_change` + `confirmation_phrase=RENAME`, `strict_collision` (no silent `-2`).
- Bulk rename from Laravel editor Fix Slug All is **blocked**; Laravel uses `WordPressMediaRenameService` only for explicit single rename.
- Laravel-managed media (`seo_media_id`, local source, local media marker, pending binary/version/revision) is not "WP protected"; Sync WP may update alt/title/caption/description, attachment slug, featured/gallery assignment, and pending binary replacement. WP-only unmanaged media remains protected from automatic binary replacement and deleted/missing attachments remain blocked.
- No redirect mapping promised unless plugin reports `supports_redirect`.

### Inbound (plugin → Laravel)

Site Sync outbox/callback — see [SITE_SYNC.md](SITE_SYNC.md). Compat push still imports articles but must not dual-apply catalog layers on V2 writers.

## 7. Public capabilities

- Manual Sync from editor/list (no Automation rule required).
- Automation action `wordpress.article.sync` when rule enabled+published.
- Bridge REST for connected sites with valid `seo_read_token`.
- Site Sync public caps (`site.sync`, …) — owned by Site Sync module.
- Plugin packaging: `.\compress_plugin.ps1` in `wp-seo-ai` after version bump.

## 8. Internal-only capabilities

| Surface | Rule |
|---------|------|
| `ArticleScheduleReconcileService` | Laravel status only — **no** WP API |
| Lease claim/heartbeat internals | Queue workers |
| `Laravel_Push_Sync::suppress` | Loop prevention around WP-local publishes |
| Automation disabled rule | Blocks **new** auto executions; mid-flight may get cancel request |
| Core `ExternalPluginRegistry` config | Version/download metadata, not business writes |

## 9. Authorization and confirmation

- Inbound: Bearer `seo_read_token`; Site Sync callbacks add HMAC/nonce when required.
- Outbound: site WP credentials from established site/domain settings — never hard-coded secrets.
- Manual sync is explicit user action; automatic sync requires enabled Automation rule.
- Do not log tokens, signatures, or full sensitive payloads (`RuntimeLogger` / web channel on HTTP).

## 10. Queue and scheduler ownership

| Owner | What |
|-------|------|
| Queue `seo` | `ManualWordPressSyncJob`, Site Sync step/inbound jobs |
| Queue `automation-external` | Hook action WordPress sync |
| Queue `automation-critical` | Rule bootstrap / critical automation |
| Scheduler every minute | `seo:publish-scheduled-articles` → `ScheduledArticlePublishRunner` |
| Scheduler | `seo:wordpress-sync-lease-watchdog` (stale lease recovery) |
| WP | Outbox flush cron + optional missed-schedule fixer; WP-Cron may be disabled in favor of Laravel |

**Timezone**: WordPress **không** sở hữu schedule timezone của SaaS. SaaS quyết định UTC schedule; WP nhận lệnh publish do runner/dispatch. System timezone chỉ dùng input/display trên Laravel (`SystemDateTime`).

## 11. Transactions and side effects

- Manual sync: short local content TX then enqueue — editor closes/navigates to Sync Queue after enqueue (no Elapsed poll on Edit Article).
- Publish create+sync sequential under cache lock — avoids duplicate empty WP posts.
- Sync does **not** trash local `seo_media` rows; disk delete on Reviewed flows elsewhere.
- FAQ empty-array guard on plugin (≥ 1.0.61).
- Site Sync cancel ≠ undo catalog; outbound fail ≠ Laravel trash.

## 12. Retry and recovery

- Sync lease: stale auto-retry capped (`MAX_STALE_AUTO_RETRIES`); force unlock / `--force` disables.
- Watchdog reclaims expired leases.
- Idempotent create via find-by-article + teamvia meta.
- Site Sync dead letters: Ops requeue (see SITE_SYNC).
- Missed WP `future` legacy posts: plugin admin fixer with push suppressed.

## 13. Compatibility paths

- Legacy `push-content` + `/sync` REST remain for older bridges; V2 catalog owned by Site Sync.
- Plugin version gates for features (create-with-content, FAQ guard, taxonomy export, attachment rename resolve, WebP replace, virtual reviews UI).
- Business Hook seed: `wordpress.article.sync` default **disabled** — explicit enable required for auto.
- Audit coupling: `php artisan automation:audit-wordpress-coupling [--strict]`.

## 14. Forbidden paths

- Outbound trash/delete WP from Laravel sync.
- Send `draft` / WP `future` as sync status for SEO schedule.
- Content Project / test-publish dispatching WP jobs directly.
- Dual-apply Site Sync catalog via push-content on V2 writers.
- Empty `faqs:[]` clearing existing FAQ meta without `clear_faqs`.
- Hard-code WP secrets in code.
- Treat MAP_SEO_WP archive/history notes as live SoT after this doc.

## 15. Tests and invariants

| Invariant | Test / evidence |
|-----------|-----------------|
| V2 push enrich skip | `SiteSyncCompatPushOwnershipContractTest` |
| Site Sync schema/min bridge | `SiteSyncV2ArchitectureFreezeTest` |
| Publish scheduled runner ownership | `PublishScheduledArticlesCanonicalRunnerContractTest` |
| Automation / dispatcher ownership | `AutomationDispatcherOwnershipContractTest` |
| Architecture locks (unique jobs, etc.) | `ArchitectureHardeningLockContractTest` |

```text
$PHP_BIN vendor/bin/phpunit --filter=SiteSyncCompatPushOwnershipContractTest
$PHP_BIN vendor/bin/phpunit --filter=PublishScheduledArticlesCanonicalRunnerContractTest
```

## 16. Related documents

- [SITE_SYNC.md](SITE_SYNC.md) — catalog sync, outbox, ownership, Ops tab
- [CONTENT_PROJECTS.md](CONTENT_PROJECTS.md) — project publish/approve (Laravel-side)
- [ARTICLE_EDITOR.md](ARTICLE_EDITOR.md) — editor sync UX (when present)
- [MEDIA_AND_GALLERY.md](MEDIA_AND_GALLERY.md) — media encode/upload detail (when present)
- [../operations/SCHEDULER_AND_WORKERS.md](../operations/SCHEDULER_AND_WORKERS.md)
- [../operations/TESTING.md](../operations/TESTING.md)
- Historical: `docs/archive/site-sync/`, archived MAP_SEO_WP (parent archive)
)
