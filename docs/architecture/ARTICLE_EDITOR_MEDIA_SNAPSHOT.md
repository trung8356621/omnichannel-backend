# Article Editor Media Snapshot (Phase 2A)

> Status: Implemented (Phase 2A ownership cutover)  
> Task ID: `article-editor-media-ownership-phase-2a`  
> Related: [`ARTICLE_EDITOR_SEPARATION_INVENTORY.md`](ARTICLE_EDITOR_SEPARATION_INVENTORY.md), [`../modules/MEDIA_AND_GALLERY.md`](../modules/MEDIA_AND_GALLERY.md)

## Ownership

| Concern | Owner |
|---------|--------|
| Featured identity/URL/attachment | Laravel `article_meta` (`wp_featured_image_url`, `wp_featured_attachment_id`) |
| Gallery order/items | Laravel `article_meta` (`wp_product_gallery`, `wp_product_gallery_attachment_ids`) |
| Snapshot version | `editor_media_snapshot_version` meta |
| Presentation / picker / pending UI | React in-memory `mediaSnapshot` + Shared Media Picker (Phase 6C.3) |
| Featured / Gallery sidebar panels | React runtime modules (`FeaturedSidebarPanel` / `GallerySidebarPanel`) |
| Shell mount roots | Blade portals only — no Alpine Featured/Gallery draft SoT |

**Policy:** Featured/Gallery mutations **persist immediately** via API (not batched into Save Article). Save/autosave body **does not** re-send featured/gallery drafts.

## Schema

`ArticleEditorMediaSnapshotService::build()` → `media_snapshot` with:

- `version` (schema), `snapshot_version`, `article_id`, `document_version`, `generated_at`
- `featured` | null
- `gallery.required` + `gallery.items[]` (stable `id`)
- `content_images` occurrence/valid/invalid summary (Images widget; ratio → Phase 2B)
- `capabilities.*`

Integrity reasons: missing/broken/upload incomplete/alt warning. **Not** WP filename≠keyword hard error.

## Endpoints

| Method | Path |
|--------|------|
| GET | `/api/seo/articles/{article}/editor/media-snapshot` |
| PUT | `/api/seo/articles/{article}/editor/media/featured` |
| DELETE | `/api/seo/articles/{article}/editor/media/featured` |
| PUT | `/api/seo/articles/{article}/editor/media/gallery` |
| POST | `/api/seo/articles/{article}/editor/media/gallery/reorder` |

Mutations require owning editor session when an active session exists; soft-deleted Article → not editable. Archived Content Project does **not** permanently mark media not editable (standalone Article after archive). Mid-archive revoke may briefly surface `content_project_archived`. Optional `expected_snapshot_version` rejects stale ACK.

Response: `{ success, media_snapshot }`. React applies only if `snapshot_version >= current`.

## Client

- `resources/js/utils/articleEditorMediaSnapshot.js`
- Storage helpers no longer read/write Featured/Gallery localStorage; mount discards legacy keys.
- Bootstrap: `mediaSnapshot` on core JSON + `endpoints.mediaSnapshot`.

## Event

One-way: `article-editor-media-snapshot-changed`  
Compat: `seo-product-gallery-updated` may still fire from snapshot apply. Featured `seo-featured-image-updated/cleared` **removed** (zero listeners); consumers use `article-editor-media-snapshot-changed`. See [`ARTICLE_EDITOR_LEGACY_CLEANUP.md`](ARTICLE_EDITOR_LEGACY_CLEANUP.md).

## Phase 6C.3 client

- `useEditorMedia` / `useEditorMediaPicker` / `SharedMediaPicker` / `editorMediaPickerStore`
- Portal: `#article-editor-media-picker-root` (`data-editor-portal="media.picker"`)
- Compat bridge: `mediaPickerCompatibilityBridge.js` (legacy open events → `openMediaPicker`)
- Health: Featured/Gallery providers read snapshot; valid URL clears `featured_missing` immediately

## Remaining

- AI Chat / broader event cleanup (6C.4)
- Browser E2E caret/media picker
- Remove leftover Alpine picker helper methods (dead code after modal removal)
