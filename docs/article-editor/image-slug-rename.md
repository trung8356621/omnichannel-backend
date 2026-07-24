# Fix slug all — image rename (Article Editor)

> **Không chỉ rename file PHP.** Mọi lần sửa “Fix slug all” phải đọc note này trước. Entry point: `ArticleEditorOperationController::fixMediaSlugs` → `SeoMediaArticleSlugFixService` (+ WP: `EditArticle::renameAttachmentSlugsOnWordPress`). Không tạo pipeline rename thứ hai.

## Root cause (regression này)

1. Backend rename file + rewrite `article.body`/meta thành công và trả `replacements`.
2. Frontend chỉ patch `block.type === 'image'` theo ID; **không** rewrite HTML text/classic blocks; **không** sync TipTap document.
3. Lần Save / `blockFlush` / local draft sau đó ghi **URL cũ** từ editor document ngược lại DB.
4. Gallery / Images / media-picker cache còn item URL cũ → 404 / “Image unavailable”.

## Contract API

`POST /api/seo/articles/{id}/fix-media-slugs`

Response bắt buộc có exact rename map:

```json
{
  "success": true,
  "renamed": [
    {
      "image_id": 123,
      "media_id": 123,
      "old_filename": "old-name.png",
      "new_filename": "new-name.png",
      "old_url": "/storage/.../old-name.png",
      "new_url": "/storage/.../new-name.png",
      "old_path": "...",
      "new_path": "...",
      "old_slug": "old-name",
      "new_slug": "new-name"
    }
  ],
  "failed": [],
  "replacements": []
}
```

Frontend **phải** dùng map này (`buildExactRenameUrlMap` / `applyRenameResultsToBlocks`). Không đoán URL bằng `replaceUrlSlug` lần hai trừ recovery khi file đã rename sẵn.

## Flow chuẩn (8 bước)

1. **Save article/editor** hiện tại (`saveCurrentArticleFromEditor`, reason `before_fix_slug_all`). Fail → dừng, không rename.
2. Backend rename file + update DB media + rewrite article refs (`SeoMediaArticleSlugFixService` / WP rename + `SeoMediaUrlReplacementService`).
3. Backend trả exact rename map (`renamed` + `failed`).
4. Frontend apply map vào **editor document/state** (`finalizeBlocksAfterWpRename` → mọi block HTML + TipTap `setContent`, không chỉ `img.src` DOM).
5. Invalidate caches: `clearArticleMediaPickerCache`, product album, featured storage, `seo-editor-images-catalog`.
6. Refetch/replace Gallery + Images assistant (`resetSupplementalImagesAfterSlugRename`, `setImagesReloadKey`, publish catalog).
7. **Save lần cuối** (reason `after_fix_slug_all`) nếu state vừa đổi — persist URL mới; clear draft / write synced snapshot.
8. Toast success/failed theo `renamed` / `failed` / `skipped`.

Trong lúc chạy: lock autosave `quick-fix-slug-all`, overlay, disable double-submit.

## Cấm

- Sửa DOM `querySelectorAll('img')` mà bỏ qua TipTap/ProseMirror document.
- Save song song / autosave ghi đè URL mới bằng content cũ.
- Local draft trước rename restore URL cũ sau F5 (phải `clearDraft` + synced snapshot sau rename).
- Pipeline rename trùng (controller tự rename, Livewire tự rename local, JS tự build slug URL).
- Cache-bust `?seo_reload=` để che editor còn URL cũ; canonical URL không tích lũy query.

## State / cache cần invalidate

| Store | Key / event |
|-------|-------------|
| Media picker | `seo-article-media-picker:v2:*` via `clearArticleMediaPickerCache(siteId)` |
| Local draft | `seo-editor:draft:{connection}:{site}:{article}` — clear + synced snapshot |
| Product album | `syncProductAlbumUrlsFromBlockImages` + exact URL map |
| Featured | `applyRenameMapToFeaturedImageStorage` / `seo_featured_image_{id}` |
| Images panel | `supplementalImages` replace, `seo-editor-images-catalog`, `imagesReloadKey` |
| Gallery Livewire | `seo-product-gallery-updated` / `article-media-removed` (match normalizeSrcKey + id) |

## Classes / files

| Layer | File |
|-------|------|
| Route | `SeoPanelProvider` → `POST .../fix-media-slugs` |
| Controller | `ArticleEditorOperationController::fixMediaSlugs` |
| Service | `SeoMediaArticleSlugFixService`, `SeoMediaUrlReplacementService`, `SeoMediaStorageService` |
| WP | `EditArticle::renameAttachmentSlugsOnWordPress` |
| Editor | `SeoArticleEditor.jsx` → `quickFixSlugAllImages`, `applySlugRenameFinished` |
| Utils | `articleImagesUtils.js` (`applyRenameResultsToBlocks`, `buildExactRenameUrlMap`, …) |
| Save | `articleEditorSaveQueue.js` → `saveCurrentArticleFromEditor` |

## Regression tests bắt buộc

Backend (`SeoMediaArticleSlugFixServiceContractTest` + URL replacement tests):

- HTML + JSON cùng đổi URL
- Nhiều occurrence cùng old URL
- Empty map không đụng content
- Variant absolute/relative

Frontend / tay:

- Editor getter / export HTML chỉ còn URL mới sau Fix slug all
- Save + F5: ảnh còn; Network không request filename cũ
- Gallery + Images: không item cũ / không duplicate
- Clear gallery: item biến mất khỏi Images (match id + normalizeSrcKey)
- Dirty editor: save trước fail → không rename

## Manual verification

```text
Manual verification:

1. Mở article có ảnh local, sửa nhẹ nội dung (dirty).
2. Bấm Fix slug all — overlay: save → rename → save URL mới.
3. Network: POST fix-media-slugs trả renamed[]; không còn request filename cũ.
4. Inspect editor HTML / Images panel: chỉ URL mới.
5. Save thủ công + F5: ảnh còn.
6. Clear 1 ảnh Gallery: biến mất khỏi Images; không 404 orphan.
7. php artisan test --filter=SeoMediaArticleSlugFix
```
