# Article Editor Performance Audit

**Date:** 2026-07-22  
**Scope:** `/seo/{connection_hash}/articles/{id}/edit` (`EditArticle` + React `SeoArticleEditor`)  
**Status:** Phase 1 **implemented** (PHP + JS) — production numeric baseline still pending ops measurement  
**Source of truth:** runtime code

### Phase 1 checklist

- [x] **A** Remove WP HTTP from `EditArticle::mount()` / `pollEditorReadiness()` / `hydrateArticleState` heal
- [x] **B** `forEditorBootstrap()` + on-demand `GET .../editor-seo-payload` (`forArticle`)
- [x] **C** `bootstrapEditorHtml` protected — not Livewire public snapshot
- [x] **D** Typing marks SEO stale; no 150ms full analyze
- [x] **E** Local draft schema v2 + scoped key + explicit restore modal
- [x] **F** Single-flight save queue
- [x] **G** Conflict tokens + HTTP 409 handling (keep draft)
- [x] **H** Product reviews fetch only when Reviews panel active
- [x] **I** Outline API deferred to outline open/interact
- [x] **J** Links/AI chat deferred mount; Images/Reviews heavy body gated
- [x] **Settings/lang** local draft interval semantics
- [x] **Instrumentation** `ARTICLE_EDITOR_PERF_DEBUG`
- [x] **Tests** unit/static contracts (not executed in this env — remote-first)
- [ ] **Ops** Fill production before/after numbers (TTFB, snapshot bytes, query count)

---

## 0. Docs reviewed

| Doc | Relevance | Notes vs code |
|-----|-----------|---------------|
| `docs/SUPER_MAP_INDEX.md` | Index → MAP_SEO_EDITOR | OK |
| `docs/MAP_SEO_EDITOR.md` | Primary editor map | Mostly accurate; says local draft không hit server — **correct for typing**. Understates initial WP fetch + SEO payload cost. |
| `docs/MAP_SEO_SETTINGS.md` | Editor settings | Label «auto-save» vẫn mô tả **DB save** — **sai so với runtime** (chỉ localStorage). |
| `docs/MAP_SEO_WP.md` | Sync / lease / poll | Sync path OK; initial mount WP fetch không nhấn mạnh đủ. |
| `docs/MAP_SEO_PROJECTS.md` | Approve / project | Peripheral |
| `docs/MAP_SEO_MEDIA.md` | Media picker / AI image | Flow đúng |
| `docs/MAP_SEO_FRONTEND.md` | Bundle / roots | Mentions 5–6 React roots — matches `article-editor.jsx` |
| `docs/MAP_SEO_AUDIT.md` | Review UI | Peripheral |
| `docs/automation/AUTOMATION_SERVICE_INVENTORY.md` | Persist / sync services | OK |
| `docs/automation/AUTOMATION_BOUNDARIES.md` | Content vs WP boundary | OK |
| `docs/automation/AUTOMATION_ACTION_CATALOG.md` | `article.content.update` conflict | Catalog có expected_updated_at/hash; editor save UI chưa surface 409 restore flow |
| `docs/prompt-hooks/article-title-suggestion.md` | Title ngoài React hub | OK |

**Docs ≠ code (must fix after refactor):**

1. Settings label: «Lưu vào database mỗi X giây» — code chỉ localStorage; `autosave_interval_seconds` **không được đọc** trong `SeoArticleEditor` debounce (hardcode 2000ms).
2. Draft restore: docs/người dùng kỳ vọng modal; code **auto-apply** draft khi cùng `contentRevision`, **im lặng bỏ** draft khi revision khác.
3. `content_revision` trong meta payload = **sha256(project_run ∥ bodyHash)**, không phải integer optimistic lock column (`ArticleContentConcurrencyLimitations` xác nhận không có revision column).

---

## 1. Current architecture

```text
Filament route EditArticle
  → Livewire mount()  [WP fetch + hydrate + FAQ import + reviews sync]
  → Blade edit-article.blade.php
       ├── public Livewire props (gồm editorHtml full body)
       ├── JSON bootstrap scripts (html, seo, images, settings, meta, faqs)
       ├── #seo-article-editor-root (wire:ignore) → SeoArticleEditor
       ├── #seo-article-faq-root → ArticleFaqEditor (eager)
       ├── #seo-article-links-root → ArticleLinksSidebar (eager)
       ├── #seo-article-ai-chat-root → ArticleAiChatPanel (eager)
       ├── #seo-article-ai-launcher-root → ArticleAiFloatingLauncher
       └── Assistant slots (x-show only) → React portals SEO / Images / Reviews (eager mount)
  → Manual Save/Sync → REST `/api/seo/articles/{id}/save|sync-wp` (không sync content theo keystroke)
```

### Ownership (as-is)

| Concern | Owner today | Problem |
|---------|-------------|---------|
| Content while typing | React `blocks` + TipTap | OK |
| Crash recovery | `localStorage` `seo_article_draft_{articleId}` | Key thiếu `connection_id`; payload nặng (`blocks` + `html`) |
| Permanent persist | Explicit Save → REST API | OK hướng; payload vẫn bundle lớn (faqs, publish, featured, seo_analysis) |
| WordPress persist | Explicit Sync WP | OK; nhưng **initial mount** vẫn fetch WP |
| SEO analysis | Client `runLocalSeoAnalysis` mỗi thay đổi blocks (debounce 150ms) | Lag typing; không phải Livewire |
| Sidebar data | Eager SSR payload + eager React mount | Trái kiến trúc đích on-demand |

---

## 2. Runtime paths traced

### 2.1 Entry / mount (PHP)

**File:** `Filament/Resources/ArticleResource/Pages/EditArticle.php`

| Step | Method | External / DB | Cost class |
|------|--------|---------------|------------|
| 1 | `parent::mount` + `getRecordRouteBindingEloquentQuery` | `user`, `site`, **full** `articleMetas` | Medium |
| 2 | `$record->load('articleMetas')` again | Duplicate meta load possible | Medium |
| 3 | `ArticleEditorReadinessService::evaluate` | DB readiness | Low–Medium |
| 4 | `syncTitleFromWordPressWhenAllowed` | **HTTP WordPress** via `fetchWordPressPostPayload` | **Critical** |
| 5 | `hydrateArticleState` | `healTaxonomyMetaFromWordPress` (HTTP nếu term), schedule reconcile, resolve HTML/slug/featured/gallery, SEO meta | **High** |
| 6 | `syncWordPressCategoriesOnLoad` | Reuses cached WP payload (same request) | High (shares #4) |
| 7 | `importFaqsFromWordPressOnLoad` | May use WP payload + FAQ import service | High |
| 8 | `syncReviewedStatusFromExistingReviews` | `getVirtualReviewsPayload` + possible `UPDATE` | Medium |
| 9 | `ArticleWpSyncQueueService::activeOperation` | DB queue/lease | Low |

**Comment in code (line ~499):** «Luôn fetch danh mục từ WP khi mở trang» — confirms intentional external call on every open when `wp_post_id` set.

### 2.2 Blade SSR bootstrap (every render)

Scripts in `edit-article.blade.php`:

| Element | Builder | Heavy work |
|---------|---------|------------|
| `#seo-article-initial-html` | `$editorHtml` public prop | Full article HTML in **Livewire snapshot + HTML** |
| `#seo-article-initial-seo` | `getEditorSeoPayload()` → `ArticleEditorSeoPayloadService::forArticle` | See §2.3 |
| `#seo-article-initial-images` | `getEditorImagesPayload()` | Image catalog |
| `#seo-article-editor-settings` | `getEditorSettingsPayload()` | Scoring rules + permissions + prompt hooks |
| `#seo-article-meta` | `getEditorMetaPayload()` | virtual_reviews, product options, content_revision hash, supplemental images |
| `#seo-article-initial-faqs` | `getEditorFaqsPayload()` | FAQ rows |
| `window.__SEO_ARTICLE_MEDIA_PICKER__` | `getArticleMediaPickerPayload()` | Picker config |

### 2.3 SEO payload (critical server work on open)

**File:** `Services/ArticleEditorSeoPayloadService.php`

On open:

1. `loadMissing(['articleMetas', 'site', 'linkMaps', 'faqs'])`
2. Resolve violations/score
3. `resolveExtractedLinks()` on body
4. **`ArticleInternalLinkSuggestionService` called 4×** (`suggest`, `suggestCatalog`, `suggestExternal`, `suggestExternalCatalog`) — each calls `collectCandidates()` → full `Keyword::forSite()->get()` + phrase scan over body
5. Domain link list + catalog + CTA list
6. Google SERP preview build
7. Duplicate scoring rules/messages also embedded again in settings payload

### 2.4 React mount (`article-editor.jsx`)

Eager roots (always if DOM present):

1. `SeoArticleEditor` (hub ~8.5k LOC / ~370KB source)
2. `ArticleLinksSidebar`
3. `ArticleAiFloatingLauncher`
4. `ArticleAiChatPanel` (even when chat closed)
5. `ArticleFaqEditor`

Inside `SeoArticleEditor`, portals mount **SEO + Images + Reviews** whenever `#seo-article-*-assistant-root` exists. Blade uses `x-show` only → **hidden ≠ unmounted**.

Product posts: `useEffect` calls `fetchWordPressProductReviews(articleId)` once on editor mount (client WP/API) even if Reviews panel not focused.

AI media: `setInterval(reconcile, 8000)` while processing placeholders.

Outline: `GET /api/seo/articles/{id}/outline` on load path.

### 2.5 Typing / local draft (as-is)

```text
blocks change
  → useEffect → scheduleAutosave()
       → setSaveStatus('pending')
       → debouncedLocalSave (2000ms) → saveDraft() → localStorage only
       → debouncedAnalyze (150ms) → runLocalSeoAnalysis()  [CPU heavy, main-thread]
```

**Confirmed:** local draft path **does not** call `$wire` / Livewire for content.

**However:**

- Title: `wire:model.blur="articleTitle"` → Livewire round-trip on blur (+ `updatedArticleTitle` → SERP dispatch).
- Focus keyword: `wire:model.live.debounce.300ms` in SEO fields partial → Livewire while editing SEO fields.
- `updatedSeoTitle` / `updatedSeoMetaDescription` similarly.
- Any Livewire action still dehydrates **`public string $editorHtml`** (full body) in snapshot even if content unchanged by typing.

### 2.6 Manual save (as-is)

```text
__seoExecuteHeavyArticleAction('save')
  → __seoCollectEditorHeavyBundle()  [HTML + seoAnalysis + faqs]
  → buildArticleEditorApiPayload()   [meta, publish, featured, album, categories]
  → POST /api/seo/articles/{id}/save
```

Not character-sync. Still large DTO. No client `saveQueued` / single-flight guard found in editor JS. No integer revision column; concurrency limitations documented separately.

### 2.7 Livewire bridge still used for

| Call site | Method | When |
|-----------|--------|------|
| `SeoArticleEditor` | `refreshVirtualReviewsForEditor` | Reviews refresh |
| | `generateQuickPostReviews` | Quick reviews |
| | `renameAttachmentSlugsOnWordPress` | Fix slug all |
| | `persistFeaturedImageFromClient` | Featured persist |
| | `generateArticleImageFromEditor` | AI image |
| Title hook | `wire.set('articleTitle')` | AI title suggestion |
| Alpine/media modal | various `$wire` picker methods | Media modal open |

---

## 3. Initial page load sequence (ordered)

```text
1. HTTP GET edit page
2. Livewire/Filament resolve Article (+ user, site, articleMetas)
3. Access + readiness checks
4. WordPress HTTP fetch (if wp_post_id) — title/categories/FAQ path
5. hydrateArticleState (DB + optional taxonomy heal HTTP)
6. Reviews payload + possible is_reviewed write
7. Render Blade (~2.4k lines view) + embed JSON bootstraps
8. Livewire dehydrate snapshot including editorHtml + many public props
9. Browser download article-editor bundle + vendors
10. mountArticleEditorPage → 5 React roots
11. SeoArticleEditor hydrate blocks (local draft auto-apply or server HTML)
12. TipTap per active blocks; portals SEO/Images/Reviews
13. Client SEO analyze; outline fetch; product reviews WP fetch (if product)
14. Idle: operation tracker may poll if active sync; AI media interval if pending
```

---

## 4. Livewire payload / properties of concern

**Public props on `EditArticle` (non-exhaustive):**

- `editorHtml` — **full article HTML** (largest risk)
- `articleTitle`, `articleSlug`, SEO fields, publish datetime parts
- `productGallery`, `featuredImageUrl`
- `mediaPickerImages`, `mediaPickerArticleCatalog` (grow when picker used)
- `wpSyncContext`, `wpSyncPrepared`, `wpSyncDecoded`
- `articleCategoryIds`, `reviewsCountForEditor`

Typing does not update `editorHtml`, but **any** Livewire request still ships current snapshot including stale full HTML.

---

## 5. Database / query hotspots (code-derived)

| Hotspot | Evidence |
|---------|----------|
| Full articleMetas on edit binding | `getRecordRouteBindingEloquentQuery` |
| Meta re-query patterns | Multiple `articleMetas()->where('meta_key',…)->value()` in hydrate/meta |
| Keyword table full scan ×4 | `ArticleInternalLinkSuggestionService::collectCandidates` |
| Domain link/CTA lists | `DomainLinkListEditorService`, `DomainCtaEditorService` on SEO payload |
| Publish category options | Can query all category/product_category articles for site |
| Virtual reviews list | Included in meta bootstrap |
| FAQ import on load | Extra reads/writes |

**N+1 risk:** keyword phrase loop + per-keyword target resolve inside suggestion service (inspect `KeywordLinkTargetResolver` during Phase 2).

---

## 6. Browser / client hotspots (code-derived)

| Issue | Evidence |
|-------|----------|
| Hub JS size | `SeoArticleEditor.jsx` ≈ **8556 lines / 370KB** source |
| SEO re-analyze 150ms | `debouncedAnalyze` on every blocks change |
| Draft write stores blocks+html | `saveDraft` payload duplication |
| Undo history in localStorage | `seo_article_history_{id}` — quota pressure |
| Eager portals | SEO/Images/Reviews always mounted |
| Eager FAQ/Links/AI chat | Always mounted |
| Product reviews fetch on mount | WP/API without panel open |
| `livewire:navigated` remount | `mountArticleEditorPage()` again — risk duplicate listeners if not guarded |
| Media picker Alpine | Huge `x-data` on page wrapper always |

---

## 7. Baseline measurements

### 7.1 Measured in this audit (static)

| Metric | Value |
|--------|-------|
| `SeoArticleEditor.jsx` size | 377,846 bytes; 8,556 lines |
| `EditArticle.php` size | 197,147 bytes; 4,543 lines |
| `edit-article.blade.php` size | 134,547 bytes; 2,423 lines |
| Local draft debounce | **2000 ms** hardcode (settings unused) |
| SEO analyze debounce | **150 ms** |
| AI media reconcile interval | **8000 ms** (when pending) |
| Readiness poll | `wire:poll.3s` only while `editorPreparing` |
| Operation status poll | 2.5s when active op (docs + tracker) |

### 7.2 Not measured yet (requires production / DevTools)

| Metric | Status |
|--------|--------|
| TTFB | **Not measured** |
| Time to editor visible / usable | **Not measured** |
| Initial HTML document bytes | **Not measured** |
| Initial Livewire snapshot bytes | **Not measured** |
| Initial DB query count / duplicates | **Not measured** (need Debugbar / telescope / log) |
| Peak PHP memory | **Not measured** |
| XHR after idle | **Not measured** |
| Typing 30s Livewire request count | **Code predicts 0 from draft path**; title/SEO field edits may still fire |
| DOM node count / long tasks / JS heap | **Not measured** |

> Rule: không bịa số. Phase 5 phải điền bảng trước/sau từ đo thật trên staging/prod.

### 7.3 Manual baseline checklist (ops)

```text
1. Chrome DevTools → Network: open editor, filter Livewire/XHR/Fetch
2. Note: document size, first Livewire update size, WP host calls
3. Performance: long tasks while typing 30s
4. Application → Local Storage key seo_article_draft_{id} size
5. Server: enable query log / Debugbar for one edit request
6. Record PHP memory peak if available (FPM status / telescope)
```

---

## 8. Bottlenecks (ranked)

### Critical

1. **WordPress HTTP on `EditArticle::mount`** (`fetchWordPressPostPayload` for title/categories/FAQ) — shared hosting worker blocked; timeout → slow page / 503 risk.
2. **`ArticleEditorSeoPayloadService::forArticle` on every editor open** — keyword suggestion catalog ×4 + domain lists + full body parse — CPU/RAM/query spike before HTML returns.
3. **`public $editorHtml` full body in Livewire snapshot** — inflates every Livewire round-trip (title blur, SEO live fields, media actions).

### High

4. **Eager mount of all assistant React modules** (SEO/Images/Reviews portals + Links + FAQ + AI chat) despite `x-show`.
5. **Client SEO analyze debounce 150ms on typing** — main-thread jank on long articles (not Livewire, still lag).
6. **Settings/docs claim DB autosave**; runtime local-only — operational confusion; interval setting unused.
7. **Product reviews WP fetch on editor mount** (client).

### Medium

8. Duplicate / fragmented `articleMetas` queries during hydrate.
9. Local draft schema: no `connection_id`, stores full `blocks`+`html`, silent restore rules, no conflict UI.
10. No client single-flight save queue (`saveQueued`).
11. No integer `content_revision` column; hash revision only for draft matching. Domain **does** have `ArticleContentConflictGuard` (`expected_updated_at` / `expected_content_hash`) via `UpdateArticleContentAction`. Editor `ArticleEditorSyncController::buildContentUpdateInput` currently **does not pass** those guards → conflict check skipped (backward compatible). UI 409 restore flow missing.
12. Huge monolith files (hard to isolate modules / tree-shake).

### Low

13. Outline API on load.
14. AI media 8s polling when placeholders exist (acceptable if gated).
15. Media picker Alpine always on page (cost when unused).

---

## 9. Local draft architecture — BEFORE

```text
Key: seo_article_draft_{articleId}
Payload: { blocks[], html, parserVersion, contentRevision(hash), updatedAt }
Debounce: 2000ms hardcode
Path: React → localStorage only (no Livewire for draft)
Restore: auto-apply if same contentRevision; else discard silently
Clear: on some WP rename/reload paths
Setting autosave_interval_seconds: stored but unused by editor JS
```

---

## 10. Local draft architecture — TARGET (Phase 1)

```text
Key: seo-editor:draft:{connection_id}:{article_id}
Payload: schema_version, article_id, base_updated_at, base_revision, saved_at,
         title, slug, content, content_hash, dirty_fields
Debounce: 800–1500ms (may map from setting, client-only)
Path: React memory → debounce → localStorage ONLY
No $wire / Livewire / server on draft flush
Restore: modal/banner when newer/different; never silent overwrite
After successful server save matching hash: clear draft; keep if newer local exists
Indicators: client-only (Saving local draft / Draft saved locally / …)
```

---

## 11. Target architecture (reminder)

```text
Core Editor Shell
  + On-demand Feature Modules (single heavy module active)
  + Client-only Local Draft
  + Explicit Server Save (minimal PATCH/DTO, single-flight, conflict UI)
```

Ownership split:

| Responsibility | Store |
|----------------|-------|
| Typing content | React memory |
| Crash recovery | localStorage |
| Laravel persistence | Explicit Save |
| WordPress | Explicit Sync WP |
| SEO scoring | Explicit Analyze / cache by content_hash |
| Sidebar | On-demand module host (`article_id` scalar only) |

---

## 12. Phase plan (implementation gate)

| Phase | Scope | Gate |
|-------|-------|------|
| **1** | Audit (this doc) + isolate local draft + stop Livewire content sync paths that remain + restore UI + save single-flight + tests | **Start here after audit sign-off** |
| **2** | Core query cut: remove WP from mount; slim SEO bootstrap; drop `editorHtml` from Livewire snapshot if possible | After Phase 1 |
| **3** | On-demand modules one-by-one (SEO → AI → Images → Links → Reviews → FAQ → CTA → Publishing) | Per-module commits |
| **4** | Client utilities (outline/wordcount/find/preview already partly client — finish isolation) | |
| **5** | Benchmark fill-in + dead bridge removal + docs | Must use real numbers |

**Do not implement Phase 2–5 until Phase 1 draft isolation + typing Livewire=0 verified.**

---

## 13. Tests required (Phase 1 checklist)

- Typing updates localStorage; **0** Livewire from draft path
- Debounce; refresh survival; article ID isolation
- Successful save clears matching draft; failed save keeps; newer local not cleared by stale response
- Restore modal paths; discard key correctness
- Save single-flight + queue one final; 409 preserves local
- Regression: Save / Sync WP / Preview / Approve / image gen / permissions

---

## 14. Open questions for implementers

1. Map `connection_id` for draft key: use `seo_connection_hash` already in meta payload, or numeric connection id?
2. Keep hash-based `content_revision` vs add integer column (catalog prefers eventual integer lock)?
3. Should product reviews fetch move behind Reviews panel open only? (**Yes** per target architecture.)
4. Can `suggest*` catalog computation move to Links module open only? (**Yes** — Phase 2/3.)

---

## 15. Sign-off

- [x] Docs reviewed
- [x] Runtime paths traced against code (not name-only)
- [x] Architecture diagram recorded
- [x] Bottlenecks classified (Critical/High/Medium/Low)
- [ ] Production numeric baseline (ops) — pending manual measurement
- [x] Phase 1 PHP implementation started (2026-07-22)

**Audit complete. Phase 1 PHP in progress; JS/React next.**
