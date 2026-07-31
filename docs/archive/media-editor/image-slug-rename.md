> Status: Historical
> Not canonical
> Archived on: 2026-08-01
> Superseded by: ../../modules/ARTICLE_EDITOR.md
> Purpose: implementation history only
# Fix slug all â€” image rename (Article Editor)

> **KhÃ´ng chá»‰ rename file PHP.** Má»i láº§n sá»­a â€œFix slug allâ€ pháº£i Ä‘á»c note nÃ y trÆ°á»›c. Entry point: `ArticleEditorOperationController::fixMediaSlugs` â†’ `SeoMediaArticleSlugFixService` (+ WP: `EditArticle::renameAttachmentSlugsOnWordPress`). KhÃ´ng táº¡o pipeline rename thá»© hai.

## Root cause (regression nÃ y)

1. Backend rename file + rewrite `article.body`/meta thÃ nh cÃ´ng vÃ  tráº£ `replacements`.
2. Frontend chá»‰ patch `block.type === 'image'` theo ID; **khÃ´ng** rewrite HTML text/classic blocks; **khÃ´ng** sync TipTap document.
3. Láº§n Save / `blockFlush` / local draft sau Ä‘Ã³ ghi **URL cÅ©** tá»« editor document ngÆ°á»£c láº¡i DB.
4. Gallery / Images / media-picker cache cÃ²n item URL cÅ© â†’ 404 / â€œImage unavailableâ€.

## Contract API

`POST /api/seo/articles/{id}/fix-media-slugs`

Response báº¯t buá»™c cÃ³ exact rename map:

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

Frontend **pháº£i** dÃ¹ng map nÃ y (`buildExactRenameUrlMap` / `applyRenameResultsToBlocks`). KhÃ´ng Ä‘oÃ¡n URL báº±ng `replaceUrlSlug` láº§n hai trá»« recovery khi file Ä‘Ã£ rename sáºµn.

## Flow chuáº©n (8 bÆ°á»›c)

1. **Save article/editor** hiá»‡n táº¡i (`saveCurrentArticleFromEditor`, reason `before_fix_slug_all`). Fail â†’ dá»«ng, khÃ´ng rename.
2. Backend rename file + update DB media + rewrite article refs (`SeoMediaArticleSlugFixService` / WP rename + `SeoMediaUrlReplacementService`).
3. Backend tráº£ exact rename map (`renamed` + `failed`).
4. Frontend apply map vÃ o **editor document/state** (`finalizeBlocksAfterWpRename` â†’ má»i block HTML + TipTap `setContent`, khÃ´ng chá»‰ `img.src` DOM).
5. Invalidate caches: `clearArticleMediaPickerCache`, product album, featured storage, `seo-editor-images-catalog`.
6. Refetch/replace Gallery + Images assistant (`resetSupplementalImagesAfterSlugRename`, `setImagesReloadKey`, publish catalog).
7. **Save láº§n cuá»‘i** (reason `after_fix_slug_all`) náº¿u state vá»«a Ä‘á»•i â€” persist URL má»›i; clear draft / write synced snapshot.
8. Toast success/failed theo `renamed` / `failed` / `skipped`.

Trong lÃºc cháº¡y: lock autosave `quick-fix-slug-all`, overlay, disable double-submit.

## Cáº¥m

- Sá»­a DOM `querySelectorAll('img')` mÃ  bá» qua TipTap/ProseMirror document.
- Save song song / autosave ghi Ä‘Ã¨ URL má»›i báº±ng content cÅ©.
- Local draft trÆ°á»›c rename restore URL cÅ© sau F5 (pháº£i `clearDraft` + synced snapshot sau rename).
- Pipeline rename trÃ¹ng (controller tá»± rename, Livewire tá»± rename local, JS tá»± build slug URL).
- Cache-bust `?seo_reload=` Ä‘á»ƒ che editor cÃ²n URL cÅ©; canonical URL khÃ´ng tÃ­ch lÅ©y query.

## State / cache cáº§n invalidate

| Store | Key / event |
|-------|-------------|
| Media picker | `seo-article-media-picker:v2:*` via `clearArticleMediaPickerCache(siteId)` |
| Local draft | `seo-editor:draft:{connection}:{site}:{article}` â€” clear + synced snapshot |
| Product album | `syncProductAlbumUrlsFromBlockImages` + exact URL map |
| Featured | `applyRenameMapToFeaturedImageStorage` / `seo_featured_image_{id}` |
| Images panel | `supplementalImages` replace, `seo-editor-images-catalog`, `imagesReloadKey` |
| Gallery Livewire | `seo-product-gallery-updated` / `article-media-removed` (match normalizeSrcKey + id) |

## Classes / files

| Layer | File |
|-------|------|
| Route | `SeoPanelProvider` â†’ `POST .../fix-media-slugs` |
| Controller | `ArticleEditorOperationController::fixMediaSlugs` |
| Service | `SeoMediaArticleSlugFixService`, `SeoMediaUrlReplacementService`, `SeoMediaStorageService` |
| WP | `EditArticle::renameAttachmentSlugsOnWordPress` |
| Editor | `SeoArticleEditor.jsx` â†’ `quickFixSlugAllImages`, `applySlugRenameFinished` |
| Utils | `articleImagesUtils.js` (`applyRenameResultsToBlocks`, `buildExactRenameUrlMap`, â€¦) |
| Save | `articleEditorSaveQueue.js` â†’ `saveCurrentArticleFromEditor` |

## Regression tests báº¯t buá»™c

Backend (`SeoMediaArticleSlugFixServiceContractTest` + URL replacement tests):

- HTML + JSON cÃ¹ng Ä‘á»•i URL
- Nhiá»u occurrence cÃ¹ng old URL
- Empty map khÃ´ng Ä‘á»¥ng content
- Variant absolute/relative

Frontend / tay:

- Editor getter / export HTML chá»‰ cÃ²n URL má»›i sau Fix slug all
- Save + F5: áº£nh cÃ²n; Network khÃ´ng request filename cÅ©
- Gallery + Images: khÃ´ng item cÅ© / khÃ´ng duplicate
- Clear gallery: item biáº¿n máº¥t khá»i Images (match id + normalizeSrcKey)
- Dirty editor: save trÆ°á»›c fail â†’ khÃ´ng rename

## Manual verification

```text
Manual verification:

1. Má»Ÿ article cÃ³ áº£nh local, sá»­a nháº¹ ná»™i dung (dirty).
2. Báº¥m Fix slug all â€” overlay: save â†’ rename â†’ save URL má»›i.
3. Network: POST fix-media-slugs tráº£ renamed[]; khÃ´ng cÃ²n request filename cÅ©.
4. Inspect editor HTML / Images panel: chá»‰ URL má»›i.
5. Save thá»§ cÃ´ng + F5: áº£nh cÃ²n.
6. Clear 1 áº£nh Gallery: biáº¿n máº¥t khá»i Images; khÃ´ng 404 orphan.
7. php artisan test --filter=SeoMediaArticleSlugFix
```
