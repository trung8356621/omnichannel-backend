> Status: Historical
> Not canonical
> Archived on: 2026-08-01
> Superseded by: ../../modules/ARTICLE_EDITOR.md
> Purpose: implementation history only
# Article Editor Performance Audit

**Date:** 2026-07-22  
**Scope:** `/seo/{connection_hash}/articles/{id}/edit` (`EditArticle` + React `SeoArticleEditor`)  
**Status:** Phase 1â€“4 code path + **post-Phase-4 stabilization** (regression fixes). Not Phase 5.

**Docs split:**

- **Baseline architecture before Phase 1** â€” historical audit below (sections that describe eager SSR / multi-root / mount WP). Treat as archive, not runtime.
- **Current architecture after Phase 4 stabilization** â€” see Â§Current (top) + `docs/MAP_SEO_EDITOR.md` Â§2.5.2 + `docs/audits/ARTICLE_EDITOR_POST_PHASE4_REGRESSIONS.md`.

---

## Current architecture after Phase 4 stabilization

```text
EditArticle mount (no WP HTTP)
  â†’ Blade: #seo-article-core-bootstrap only (identity + content + light SERP fields + endpoints)
  â†’ Main React root: SeoArticleEditor + ArticleEditorModuleHost (+ optional AI FAB root)
  â†’ Google Preview: core + local title/slug/meta (no seo-summary wait)
  â†’ One SEO widget: SeoModule in SEO Assistant portal (default activeHeavyModule=seo)
  â†’ Lazy modules: Links/CTA, FAQ, AI Chat, Images, Reviews
  â†’ Lazy endpoints: /editor/seo-summary|faqs|links|links/suggestions|images|meta|settings
  â†’ Adapters: articleEditorPayloadAdapters.js
  â†’ Reviews status: GET product-review-status only when Reviews mounted; non-product â†’ 200 applicable:false
  â†’ Web logs: RuntimeLogger â†’ storage/logs/web-app-YYYY-MM-DD.log (not laravel.log)
```

**Logging split:** HTTP/editor â†’ `web_app` (`web-app-*.log`, PHP-FPM/www). Cron/queue/watchdog â†’ existing root-owned `laravel.log` / `queue-cron.log` / `watchdog.log` â€” unchanged. Chi tiáº¿t: [MAP_SEO_EDITOR.md](../MAP_SEO_EDITOR.md) Â§2.5.3b (`RuntimeLogger`, env `WEB_APP_LOG_*`, exception handler).

| Contract | Behavior |
|----------|----------|
| SEO widget | Single owner â€” `SeoModule` portal; loading ends success/error/empty |
| FAQ | `{cached,items,count}` (+ legacy `faqs`); normalize never reads null.cached |
| Links / CTA | `/editor/links` on open; Generate â†’ `/editor/links/suggestions`; CTA = Links section |
| Preview | coreBootstrap light fields |
| Review status | applicable / status / count; missing import fixed |

### Phase 4 checklist (client utilities / outline / cleanup)

- [x] **A** Audit utility paths (outline API, word count DOMParser, find/replace, SERP, hash on draft boundary)
- [x] **B** Canonical session document = editor **blocks** (HTML strings per block); export HTML only at draft/save/preview/sync boundaries
- [x] **C** `articleEditorClientOutline.js` â€” H2â€“H4 tree from blocks; fingerprint skip rebuild
- [x] **D** `ArticleOutlineTab` `preferClientSource` â€” skip GET `/outline` on mount; refresh = client rebuild
- [x] **E** Remove SeoArticleEditor GET `/outline` on rail open; section-add no longer POST outline
- [x] **F** `articleEditorUtilityScheduler.js` â€” versioned debounce/idle + cancelAll on destroy
- [x] **G** `articleEditorMetrics.js` â€” light word count (strip tags, no DOMParser loop)
- [x] **H** Find already client + 350ms debounce; SERP preview remains local state
- [x] **I** `hashContent` only via draft/save paths (not per keystroke)
- [x] **J** Outline API **kept** for AI generate / check-duplicates / compare-other-article (explicit actions)
- [x] **Tests** `ArticleEditorPhase4ClientUtilitiesTest`
- [x] **Docs** MAP Â§2.5.2 + audit Phase 4 checklist
- [x] **Stabilization** Post-Phase-4 regressions â€” `ArticleEditorPostPhase4RegressionTest` + `ARTICLE_EDITOR_POST_PHASE4_REGRESSIONS.md`
- [ ] **Ops** Client benchmark (outline build ms, getHTML count, long tasks)

### Phase 3 checklist (module host / unmount / dynamic import)

- [x] **A** Audit: multiple roots â†’ consolidate to main root + light FAB root
- [x] **B** `ArticleEditorModuleHost` â€” Links/FAQ/AI one-at-a-time; portals; AbortController; error boundary
- [x] **C** `SeoArticleEditor.activeHeavyModule` replaces forever-`activatedPanels` Set (true unmount)
- [x] **D** Dynamic import chunks: `modules/{Seo,Images,Reviews,Links,Faq,AiChat}Module.jsx`
- [x] **E** FAQ / AI / Links not mounted on initial page open
- [x] **F** Images/Reviews fetch abort on leave; reviews list cleared when inactive
- [x] **G** Duplicate mount guards: navigated / Livewire bridge / `__seoMountedLivewireId` / pageCleanups
- [x] **H** Tests `ArticleEditorPhase3ModuleHostTest` (static source contracts)
- [x] **Docs** `MAP_SEO_EDITOR.md` Â§2.5.2 Phase 3 architecture
- [ ] **Ops** Client benchmark (roots, heap, chunks, long tasks) on staging

### Phase 2 checklist (bootstrap slimming + lazy endpoints)

- [x] **A** `Support/ArticleEditorBootstrapSizer` (+ `ArticleEditorPerfDebug::recordBootstrapSize()` / `logBootstrapSizes()` / `logLivewireSnapshotEstimate()`)
- [x] **B** `EditArticle::getEditorCoreBootstrap()` â€” single `#seo-article-core-bootstrap`
- [x] **C** Blade: **removed** eager `initial-seo` / `settings` / `meta` / `images` / `faqs` embeds; FAQ placeholder only
- [x] **D** `forEditorSeoSummary()` â€” meta score/keyword/title/desc only (no catalogs / SERP rebuild / suggest)
- [x] **E** `ArticleEditorLinksPayloadService` â€” Links open = `base()`; Generate = `withSuggestions()`
- [x] **F** `suggestBundle()` + request-scoped keyword/candidates cache
- [x] **G** `ArticleMetaMap` â€” one metas load / reuse
- [x] **H** `ArticleEditorLazyPayloadController` routes under `api/seo/articles/{article}/editor/*`
- [x] **I** FAQ mount only on `seo-faq-panel-activate` (no IntersectionObserver); Images+meta fetch only when Images panel activates
- [x] **J** Links never calls `forArticle()` / never suggestions on panel open
- [x] **K** Reviews: mount uses `exists()` / pending flag only
- [x] **L** Hydrate: no `resolveEditorHtml` / `resolveFeaturedImageUrl` / `resolveProductAlbum` (local meta/body only; `productGallery=[]`)
- [x] **Tests** BootstrapSizer, MetaMap, LinksPayload, Keyword cache, CoreBootstrap, BladeLazy, MountNoRemoteWp (hydrate local-only asserts)
- [x] **Docs** `ARTICLE_EDITOR_PHASE2_BOOTSTRAP_SIZES.md`, `MAP_SEO_EDITOR.md` Â§2.5.3
- [ ] **Ops** Fill production before/after (Blade HTML KB, snapshot KB, query count, mount ms, peak memory)

### Phase 1 checklist

- [x] **A** Remove WP HTTP from `EditArticle::mount()` / `pollEditorReadiness()` / `hydrateArticleState` heal
- [x] **B** `forEditorBootstrap()` + on-demand `GET .../editor-seo-payload` (`forArticle`)
- [x] **C** `bootstrapEditorHtml` protected â€” not Livewire public snapshot
- [x] **D** Typing marks SEO stale; no 150ms full analyze
- [x] **E** Local draft schema v2 + scoped key + explicit restore modal
- [x] **F** Single-flight save queue
- [x] **G** Conflict tokens + HTTP 409 handling (keep draft)
- [x] **H** Product reviews fetch only when Reviews panel active
- [x] **I** Outline API deferred to outline open/interact
- [x] **J** Links/AI chat deferred mount; Images/Reviews heavy body gated
- [x] **Settings/lang** local draft interval semantics
- [x] **Instrumentation** `ARTICLE_EDITOR_PERF_DEBUG`
- [x] **Tests** unit/static contracts (not executed in this env â€” remote-first)
- [ ] **Ops** Fill production before/after numbers (TTFB, snapshot bytes, query count)

---

## Baseline architecture before Phase 1

> Archive â€” describes pre-refactor eager SSR / multi-root / mount WP. **Not** current runtime.

### 0. Docs reviewed

| Doc | Relevance | Notes vs code |
|-----|-----------|---------------|
| `docs/SUPER_MAP_INDEX.md` | Index â†’ MAP_SEO_EDITOR | OK |
| `docs/MAP_SEO_EDITOR.md` | Primary editor map | Updated for Phase 3/4 + stabilization |
| `docs/MAP_SEO_SETTINGS.md` | Editor settings | Label Â«auto-saveÂ» váº«n mÃ´ táº£ **DB save** â€” **sai so vá»›i runtime** (chá»‰ localStorage). |
| `docs/MAP_SEO_WP.md` | Sync / lease / poll | Sync path OK |
| `docs/MAP_SEO_FRONTEND.md` | Bundle / roots | Historical multi-root notes superseded by Phase 3 |

**Docs â‰  code (must fix after refactor):**

1. Settings label: Â«LÆ°u vÃ o database má»—i X giÃ¢yÂ» â€” code chá»‰ localStorage; `autosave_interval_seconds` **khÃ´ng Ä‘Æ°á»£c Ä‘á»c** trong `SeoArticleEditor` debounce (hardcode 2000ms).
2. Draft restore: docs/ngÆ°á»i dÃ¹ng ká»³ vá»ng modal; code **auto-apply** draft khi cÃ¹ng `contentRevision`, **im láº·ng bá»** draft khi revision khÃ¡c.
3. `content_revision` trong meta payload = **sha256(project_run âˆ¥ bodyHash)**, khÃ´ng pháº£i integer optimistic lock column (`ArticleContentConcurrencyLimitations` xÃ¡c nháº­n khÃ´ng cÃ³ revision column).

---

## 1. Baseline architecture (before Phase 1)

```text
Filament route EditArticle
  â†’ Livewire mount()  [WP fetch + hydrate + FAQ import + reviews sync]
  â†’ Blade edit-article.blade.php
       â”œâ”€â”€ public Livewire props (gá»“m editorHtml full body)
       â”œâ”€â”€ JSON bootstrap scripts (html, seo, images, settings, meta, faqs)
       â”œâ”€â”€ #seo-article-editor-root (wire:ignore) â†’ SeoArticleEditor
       â”œâ”€â”€ #seo-article-faq-root â†’ ArticleFaqEditor (eager)
       â”œâ”€â”€ #seo-article-links-root â†’ ArticleLinksSidebar (eager)
       â”œâ”€â”€ #seo-article-ai-chat-root â†’ ArticleAiChatPanel (eager)
       â”œâ”€â”€ #seo-article-ai-launcher-root â†’ ArticleAiFloatingLauncher
       â””â”€â”€ Assistant slots (x-show only) â†’ React portals SEO / Images / Reviews (eager mount)
  â†’ Manual Save/Sync â†’ REST `/api/seo/articles/{id}/save|sync-wp` (khÃ´ng sync content theo keystroke)
```

### Ownership (as-is baseline)

| Concern | Owner today | Problem |
|---------|-------------|---------|
| Content while typing | React `blocks` + TipTap | OK |
| Crash recovery | `localStorage` `seo_article_draft_{articleId}` | Key thiáº¿u `connection_id`; payload náº·ng (`blocks` + `html`) |
| Permanent persist | Explicit Save â†’ REST API | OK hÆ°á»›ng; payload váº«n bundle lá»›n (faqs, publish, featured, seo_analysis) |
| WordPress persist | Explicit Sync WP | OK; nhÆ°ng **initial mount** váº«n fetch WP |
| SEO analysis | Client `runLocalSeoAnalysis` má»—i thay Ä‘á»•i blocks (debounce 150ms) | Lag typing; khÃ´ng pháº£i Livewire |
| Sidebar data | Eager SSR payload + eager React mount | TrÃ¡i kiáº¿n trÃºc Ä‘Ã­ch on-demand |

---

## 2. Runtime paths traced (baseline)

### 2.1 Entry / mount (PHP)

**File:** `Filament/Resources/ArticleResource/Pages/EditArticle.php`

| Step | Method | External / DB | Cost class |
|------|--------|---------------|------------|
| 1 | `parent::mount` + `getRecordRouteBindingEloquentQuery` | `user`, `site`, **full** `articleMetas` | Medium |
| 2 | `$record->load('articleMetas')` again | Duplicate meta load possible | Medium |
| 3 | `ArticleEditorReadinessService::evaluate` | DB readiness | Lowâ€“Medium |
| 4 | `syncTitleFromWordPressWhenAllowed` | **HTTP WordPress** via `fetchWordPressPostPayload` | **Critical** |
| 5 | `hydrateArticleState` | `healTaxonomyMetaFromWordPress` (HTTP náº¿u term), schedule reconcile, resolve HTML/slug/featured/gallery, SEO meta | **High** |
| 6 | `syncWordPressCategoriesOnLoad` | Reuses cached WP payload (same request) | High (shares #4) |
| 7 | `importFaqsFromWordPressOnLoad` | May use WP payload + FAQ import service | High |
| 8 | `syncReviewedStatusFromExistingReviews` | `getVirtualReviewsPayload` + possible `UPDATE` | Medium |
| 9 | `ArticleWpSyncQueueService::activeOperation` | DB queue/lease | Low |

**Comment in code (line ~499):** Â«LuÃ´n fetch danh má»¥c tá»« WP khi má»Ÿ trangÂ» â€” confirms intentional external call on every open when `wp_post_id` set.

### 2.2 Blade SSR bootstrap (every render)

Scripts in `edit-article.blade.php`:

| Element | Builder | Heavy work |
|---------|---------|------------|
| `#seo-article-initial-html` | `$editorHtml` public prop | Full article HTML in **Livewire snapshot + HTML** |
| `#seo-article-initial-seo` | `getEditorSeoPayload()` â†’ `ArticleEditorSeoPayloadService::forArticle` | See Â§2.3 |
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
4. **`ArticleInternalLinkSuggestionService` called 4Ã—** (`suggest`, `suggestCatalog`, `suggestExternal`, `suggestExternalCatalog`) â€” each calls `collectCandidates()` â†’ full `Keyword::forSite()->get()` + phrase scan over body
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

Inside `SeoArticleEditor`, portals mount **SEO + Images + Reviews** whenever `#seo-article-*-assistant-root` exists. Blade uses `x-show` only â†’ **hidden â‰  unmounted**.

Product posts: `useEffect` calls `fetchWordPressProductReviews(articleId)` once on editor mount (client WP/API) even if Reviews panel not focused.

AI media: `setInterval(reconcile, 8000)` while processing placeholders.

Outline: `GET /api/seo/articles/{id}/outline` on load path.

### 2.5 Typing / local draft (as-is)

```text
blocks change
  â†’ useEffect â†’ scheduleAutosave()
       â†’ setSaveStatus('pending')
       â†’ debouncedLocalSave (2000ms) â†’ saveDraft() â†’ localStorage only
       â†’ debouncedAnalyze (150ms) â†’ runLocalSeoAnalysis()  [CPU heavy, main-thread]
```

**Confirmed:** local draft path **does not** call `$wire` / Livewire for content.

**However:**

- Title: `wire:model.blur="articleTitle"` â†’ Livewire round-trip on blur (+ `updatedArticleTitle` â†’ SERP dispatch).
- Focus keyword: `wire:model.live.debounce.300ms` in SEO fields partial â†’ Livewire while editing SEO fields.
- `updatedSeoTitle` / `updatedSeoMetaDescription` similarly.
- Any Livewire action still dehydrates **`public string $editorHtml`** (full body) in snapshot even if content unchanged by typing.

### 2.6 Manual save (as-is)

```text
__seoExecuteHeavyArticleAction('save')
  â†’ __seoCollectEditorHeavyBundle()  [HTML + seoAnalysis + faqs]
  â†’ buildArticleEditorApiPayload()   [meta, publish, featured, album, categories]
  â†’ POST /api/seo/articles/{id}/save
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
4. WordPress HTTP fetch (if wp_post_id) â€” title/categories/FAQ path
5. hydrateArticleState (DB + optional taxonomy heal HTTP)
6. Reviews payload + possible is_reviewed write
7. Render Blade (~2.4k lines view) + embed JSON bootstraps
8. Livewire dehydrate snapshot including editorHtml + many public props
9. Browser download article-editor bundle + vendors
10. mountArticleEditorPage â†’ 5 React roots
11. SeoArticleEditor hydrate blocks (local draft auto-apply or server HTML)
12. TipTap per active blocks; portals SEO/Images/Reviews
13. Client SEO analyze; outline fetch; product reviews WP fetch (if product)
14. Idle: operation tracker may poll if active sync; AI media interval if pending
```

---

## 4. Livewire payload / properties of concern

**Public props on `EditArticle` (non-exhaustive):**

- `editorHtml` â€” **full article HTML** (largest risk)
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
| Meta re-query patterns | Multiple `articleMetas()->where('meta_key',â€¦)->value()` in hydrate/meta |
| Keyword table full scan Ã—4 | `ArticleInternalLinkSuggestionService::collectCandidates` |
| Domain link/CTA lists | `DomainLinkListEditorService`, `DomainCtaEditorService` on SEO payload |
| Publish category options | Can query all category/product_category articles for site |
| Virtual reviews list | Included in meta bootstrap |
| FAQ import on load | Extra reads/writes |

**N+1 risk:** keyword phrase loop + per-keyword target resolve inside suggestion service (inspect `KeywordLinkTargetResolver` during Phase 2).

---

## 6. Browser / client hotspots (code-derived)

| Issue | Evidence |
|-------|----------|
| Hub JS size | `SeoArticleEditor.jsx` â‰ˆ **8556 lines / 370KB** source |
| SEO re-analyze 150ms | `debouncedAnalyze` on every blocks change |
| Draft write stores blocks+html | `saveDraft` payload duplication |
| Undo history in localStorage | `seo_article_history_{id}` â€” quota pressure |
| Eager portals | SEO/Images/Reviews always mounted |
| Eager FAQ/Links/AI chat | Always mounted |
| Product reviews fetch on mount | WP/API without panel open |
| `livewire:navigated` remount | `mountArticleEditorPage()` again â€” risk duplicate listeners if not guarded |
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

> Rule: khÃ´ng bá»‹a sá»‘. Phase 5 pháº£i Ä‘iá»n báº£ng trÆ°á»›c/sau tá»« Ä‘o tháº­t trÃªn staging/prod.

### 7.3 Manual baseline checklist (ops)

```text
1. Chrome DevTools â†’ Network: open editor, filter Livewire/XHR/Fetch
2. Note: document size, first Livewire update size, WP host calls
3. Performance: long tasks while typing 30s
4. Application â†’ Local Storage key seo_article_draft_{id} size
5. Server: enable query log / Debugbar for one edit request
6. Record PHP memory peak if available (FPM status / telescope)
```

---

## 8. Bottlenecks (ranked)

### Critical

1. **WordPress HTTP on `EditArticle::mount`** (`fetchWordPressPostPayload` for title/categories/FAQ) â€” shared hosting worker blocked; timeout â†’ slow page / 503 risk.
2. **`ArticleEditorSeoPayloadService::forArticle` on every editor open** â€” keyword suggestion catalog Ã—4 + domain lists + full body parse â€” CPU/RAM/query spike before HTML returns.
3. **`public $editorHtml` full body in Livewire snapshot** â€” inflates every Livewire round-trip (title blur, SEO live fields, media actions).

### High

4. **Eager mount of all assistant React modules** (SEO/Images/Reviews portals + Links + FAQ + AI chat) despite `x-show`.
5. **Client SEO analyze debounce 150ms on typing** â€” main-thread jank on long articles (not Livewire, still lag).
6. **Settings/docs claim DB autosave**; runtime local-only â€” operational confusion; interval setting unused.
7. **Product reviews WP fetch on editor mount** (client).

### Medium

8. Duplicate / fragmented `articleMetas` queries during hydrate.
9. Local draft schema: no `connection_id`, stores full `blocks`+`html`, silent restore rules, no conflict UI.
10. No client single-flight save queue (`saveQueued`).
11. No integer `content_revision` column; hash revision only for draft matching. Domain **does** have `ArticleContentConflictGuard` (`expected_updated_at` / `expected_content_hash`) via `UpdateArticleContentAction`. Editor `ArticleEditorSyncController::buildContentUpdateInput` currently **does not pass** those guards â†’ conflict check skipped (backward compatible). UI 409 restore flow missing.
12. Huge monolith files (hard to isolate modules / tree-shake).

### Low

13. Outline API on load.
14. AI media 8s polling when placeholders exist (acceptable if gated).
15. Media picker Alpine always on page (cost when unused).

---

## 9. Local draft architecture â€” BEFORE

```text
Key: seo_article_draft_{articleId}
Payload: { blocks[], html, parserVersion, contentRevision(hash), updatedAt }
Debounce: 2000ms hardcode
Path: React â†’ localStorage only (no Livewire for draft)
Restore: auto-apply if same contentRevision; else discard silently
Clear: on some WP rename/reload paths
Setting autosave_interval_seconds: stored but unused by editor JS
```

---

## 10. Local draft architecture â€” TARGET (Phase 1)

```text
Key: seo-editor:draft:{connection_id}:{article_id}
Payload: schema_version, article_id, base_updated_at, base_revision, saved_at,
         title, slug, content, content_hash, dirty_fields
Debounce: 800â€“1500ms (may map from setting, client-only)
Path: React memory â†’ debounce â†’ localStorage ONLY
No $wire / Livewire / server on draft flush
Restore: modal/banner when newer/different; never silent overwrite
After successful server save matching hash: clear draft; keep if newer local exists
Indicators: client-only (Saving local draft / Draft saved locally / â€¦)
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
| **1** | Audit (this doc) + isolate local draft + stop Livewire content sync paths that remain + restore UI + save single-flight + tests | **Done** |
| **2** | Core query cut: remove WP from mount; slim SEO bootstrap; drop `editorHtml` from Livewire snapshot if possible â†’ implemented as `getEditorCoreBootstrap()` + lazy `/editor/*` endpoints (see Phase 2 checklist above) | **Done** |
| **3** | On-demand modules one-by-one (SEO â†’ AI â†’ Images â†’ Links â†’ Reviews â†’ FAQ â†’ CTA â†’ Publishing) | Per-module commits |
| **4** | Client utilities (outline/wordcount/find/preview already partly client â€” finish isolation) | |
| **5** | Benchmark fill-in + dead bridge removal + docs | Must use real numbers |

**Do not implement Phase 2â€“5 until Phase 1 draft isolation + typing Livewire=0 verified.**

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
4. Can `suggest*` catalog computation move to Links module open only? (**Yes** â€” Phase 2/3.)

---

## 15. Sign-off

- [x] Docs reviewed
- [x] Runtime paths traced against code (not name-only)
- [x] Architecture diagram recorded
- [x] Bottlenecks classified (Critical/High/Medium/Low)
- [ ] Production numeric baseline (ops) â€” pending manual measurement
- [x] Phase 1 PHP implementation started (2026-07-22)
- [x] Phase 2 core bootstrap + lazy `/editor/*` endpoints implemented (2026-07-22) â€” fixture-measured 94% reduction of non-content bootstrap bytes (25.2 KB â†’ 1.5 KB); see `ARTICLE_EDITOR_PHASE2_BOOTSTRAP_SIZES.md`

**Audit complete. Phase 1 + Phase 2 implemented; production ops baseline still pending manual measurement.**
