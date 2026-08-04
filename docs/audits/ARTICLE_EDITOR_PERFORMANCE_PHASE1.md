# Article Editor — Performance Phase 1

**Status:** Baseline (static + build) + 2 measured-safe optimizations  
**Date:** 2026-08-03  
**Deploy session:** `article-editor-performance-phase-1`

## Policy

- No UX / lock / save / publish / analysis-rule changes.
- Implement only high-impact, low-regression items with before/after evidence.
- Runtime React Profiler / memory / idle-10min **not executed in this agent environment** — marked **needs remote measure**.

## Baseline (production build artifacts, local `public/build` 2026-08-03)

| Asset | Bytes |
|---|---:|
| `article-editor-*.js` (entry) | 639 788 |
| `vendor-*.js` | 690 520 |
| `tiptap-vendor-*.js` | 403 451 |
| `react-vendor-*.js` | 145 386 |
| `article-editor-*.css` | 326 137 |

Gzip from prior `npm run build` log: editor ~179 KB, vendor ~195 KB, tiptap ~122 KB.

### Config defaults

| Key | Default |
|---|---|
| Heartbeat | 30s (~2 req/min) |
| Server autosave debounce | 4000ms |
| Local draft | `autosave_interval_seconds` default 2s |
| SEO analyze debounce | **250ms** (comment elsewhere says 3–5s — mismatch) |
| Links rescan | 750ms |
| Outline | 400ms idle |
| Lock TTL | 120s |

## React / typing profile (static)

Per keystroke:

1. TipTap `onUpdate` → `getHTML()` → `setBlocks`
2. Full `SeoArticleEditor` re-render (canvas + memos)
3. Effects: outline@400, links@750, SEO@250, local save, server autosave@4s
4. **Before Phase-1:** widget health rebuilt **seo+images+links+featured+gallery** on every `blocks` change

DocumentModel: **not** rebuilt every keystroke; rebuilt inside SEO analyze after debounce.

`EditorHostApi` `useMemo` includes `blocks` → sidebar host value new identity each typing (TipTap itself is outside provider).

## DocumentModel profile (static)

| Event | DocumentModel |
|---|---|
| Keystroke | no |
| SEO analyze (250ms after pause) | yes (`resolveAnalysisDocumentModel`) |
| Server autosave | JSON envelope via `getJSON()` per text block |

## Analysis profile (static)

| Widget | Typing recompute (before) | Typing recompute (after Phase-1) |
|---|---|---|
| SEO / images / links health | yes | yes (needed) |
| Featured health | **yes (waste)** | **no** |
| Gallery health | **yes (waste)** | **no** |

## Autosave profile (static)

- Local LS: always writes (hash only for dirty_fields).
- Server: **no skip-if-unchanged**; nested lock/TX; JSON validate + HTML render; revisions + link reconcile + bundle meta.
- Single-flight coalesce; explicit save suppresses autosave 15s.

## Heartbeat profile (static)

~2 req/min idle or editing. Visibility keeps interval; +1 on visible. No duplicate timer (stop before start).

## Database profile (static estimates)

| Path | Est. queries | Hotspot |
|---|---:|---|
| Page bootstrap + core JSON | 25–60+ | metas reload, mediaSnapshot N+1, WpOption fan-out, Schema::hasColumn |
| Heartbeat | 4–6 | no |
| Document autosave | 25–80+ | yes |
| Media snapshot GET | 5+2N | yes |
| FAQ snapshot GET | 2+F | yes |

## Network / SaaS KPI (code-derived)

| Mode | Requests/min (order) |
|---|---|
| Idle | heartbeat ~2 + none |
| Steady typing | heartbeat ~2 + autosave ≤15 theoretical (coalesced lower) + analyze is client-only |

## Memory (static)

~46 window listeners on SeoArticleEditor (mostly cleaned). Session visibility listener cleaned. SharedMediaPicker in-memory TTL 45s + in-flight dedupe.

## Bottlenecks (ranked)

1. Server autosave cost per dirty pause (no noop skip) — SaaS writes
2. Bootstrap payload (body + JSON + mediaSnapshot) + WpOption / Schema chatter
3. Typing → full editor re-render + hostApi identity
4. ~~Typing → featured/gallery health~~ **fixed Phase-1**
5. SEO debounce 250ms vs intended 3–5s comment (product decision — not changed)

## Optimizations implemented

### 1. Split widget health publish (frontend)

**Before:** one `useEffect` deps include `blocks` + featured/gallery → rebuild all 5 widgets every keystroke.  
**After:** `publishPartialRuntimeWidgetHealth` — content widgets on `blocks`; featured/gallery only on media snapshot / keyword / product flags.

Evidence: `SeoArticleEditor.jsx`, `composeRuntimeWidgetHealth.js`, `patchRuntimeWidgetHealth`.

### 2. Cache `DocumentWriter::columnsReady` (backend)

**Before:** `Schema::hasColumn` every call (many times per autosave/bootstrap).  
**After:** static `$cache` per connection.table.column (same pattern as `ArticleDocumentVersionService`).

Evidence: `ArticleEditorDocumentWriter.php`.

## Before / After

| Metric | Before | After | Measure type |
|---|---|---|---|
| Featured/gallery health builders per keystroke | 1 | 0 | static call graph |
| Schema::hasColumn per request (document path) | N calls | 1 | static |
| Bundle sizes | unchanged intent | rebuild remote | need `npm run build` |
| React render count / typing | not measured | needs Profiler | remote |
| Autosave DB writes/min | not reduced yet | — | Phase-2 candidate |

## Regression verification

Contract: `ArticleEditorMediaHealthSyncContractTest` asserts partial publish split.

```text
Manual verification:

$PHP_BIN vendor/bin/phpunit --filter=ArticleEditorMediaHealthSync
$PHP_BIN vendor/bin/phpunit --filter=ArticleEditorRuntime
$PHP_BIN vendor/bin/phpunit --filter=ArticleEditorSession
$PHP_BIN vendor/bin/phpunit --filter=ArticleEditorJsonPersistence
$PHP_BIN vendor/bin/phpunit --filter=ArticleEditorExclusiveLock

npm run check:editor-cycles
npm run build
php artisan optimize:clear
```

Browser smoke: typing, Featured/Gallery chips after media change, Save/autosave, lock, Links/CTA, FAQ, Fix Slug, Publishing.

## Remaining bottlenecks → next phase

1. **Autosave skip-if-unchanged** (client hash + server short-circuit) — biggest SaaS write win; must preserve ACK/version.
2. Bootstrap: dedupe analysisPolicy/externalFacts; request-cache `WpOption`; batch SeoMedia in snapshot.
3. Host API: stop putting `blocks` in memoized bag (refs) to cut sidebar re-renders.
4. Product decision: SEO idle debounce 250ms vs 3–5s.
5. Runtime Profiler + idle memory 10min on production host.

## Ready for Phase 2: yes (after remote build + smoke)
