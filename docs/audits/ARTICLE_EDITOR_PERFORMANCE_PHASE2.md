# Article Editor Performance — Phase 2 (autosave + bootstrap)

**Task ID:** `article-editor-performance-phase-2-autosave-bootstrap`  
**Date:** 2026-08-03  
**Scope:** unchanged-skip (client) + server no-op short-circuit + bootstrap query dedupe.  
**Non-goals:** UX/API/lock/interval changes, HostApi React profiling, Vite split, publish redesign.

## Measurement environment

| Item | Value |
|---|---|
| Mode | Code-path inventory + contract tests (agent cannot run production query listener) |
| Runtime query listener | **Pending remote** — Debugbar / `DB::listen` on host |
| Fixture | Article `9631` (browser smoke) or equivalent |
| Note | Tables below mix **static evidence** (before) with **expected after** from short-circuit design. Final numbers require remote capture. |

## Autosave query baseline (static evidence — before)

Canonical path: `PUT …/editor-sessions/{session}/document` → `SessionService::saveDocument` → `article.content.update` → DocumentWriter + revision.

| Path | Queries (est.) | Unique | Duplicate | Writes | Notes |
|---|---:|---:|---:|---:|---|
| Autosave document change | 25–80+ | — | high | article + meta + revision | Full persist |
| Autosave identical payload | 25–80+ | — | high | same | **No skip before Phase 2** |
| Explicit Save after ACK | 25–80+ | — | high | often rewrite | Same path |

Hotspots: session+article `lockForUpdate`, conflict checks, HTML render, meta apply, FAQ/categories, revision when `wp_post_id` empty.

## Bootstrap query baseline (static evidence — before)

| Caller | Issue |
|---|---|
| `getEditorCoreBootstrap` + `getEditorCoreSettingsPayload` | `analysisPolicy` / `externalFacts` built **twice** |
| `ArticleEditorMediaSnapshotService::build` | `refresh` + meta reload; **N+1** `findSeoMedia` per featured/gallery item |
| `WpOption::get` | Same option key re-queried in one request (fixed shared request cache) |
| Core bootstrap | Still includes `content` HTML + media snapshot (contract kept) |

## Client unchanged-skip

File: `SeoArticleEditor.jsx` `scheduleServerAutosave`

- Compare `hashContent(html)` to last ACK `expected_content_hash`.
- Skip PUT when match + `!serverAutosaveNeedsRetryRef` (+ idle bootstrap hash seed).
- Failed autosave sets `needsRetry` — still retries even if hashes match.
- Does **not** clear editor dirty UI; only clears autosave dirty flag for network.
- Sends diagnostic `client_document_hash` (body hash). Server remains authority via `canonicalHash`.

Helper: `articleEditorDocument.js` → `stableSerialize` / `hashEditorDocumentEnvelope`.

## Server no-op short-circuit

File: `ArticleEditorSessionService::tryDocumentNoopAck` (after ownership + locks, **before** `$persist`)

- Auth/session/editable already enforced by `saveDocument`.
- Hash via `ArticleEditorDocumentWriter::canonicalHash` (**no** `renderHtml`).
- Match `editor_document_hash` → noop ACK; invalid document falls through to persist reject.
- Stale expected version **lower** than server + same hash → lost-ACK idempotent noop.
- Expected version **higher** than server → no silent noop (conflict path).
- Controller: noop skips `bundleApply` + heavy `savePatch`.

Response shape includes: `noop: true`, current `document_version`, `editor_document_hash`, `content_hash`/`body_hash`, `saved_at` unchanged.

## Idempotency / retry

- Same-session duplicate / lost ACK: same content hash + `expected < current` → noop.
- No new idempotency table (session ownership + hash + version policy).
- Foreign/content mismatch: existing conflict codes unchanged.

## Revision impact

- True autosave: revision policy **unchanged** (still may capture when `wp_post_id` empty).
- No-op: **0** revisions (persist never called).
- Remote measure: revisions/10 min before vs after idle editor.

## Write queue behavior

Unchanged priority: Save & Close > Explicit Save > Autosave (`shouldSuppressServerAutosave` + single-flight). No-op must not race: explicit save still cancels pending debounce / awaits in-flight.

## Bootstrap query dedupe

| Change | Effect |
|---|---|
| Single `forArticle` / `externalFacts` reused into `settings` | −2 duplicate policy builds |
| Media snapshot `build(..., refresh: false)` on bootstrap | Skip refresh+meta reload on mount |
| `primeSeoMediaLookup` + `whereIn` | Collapse N+1 SeoMedia |
| `WpOption` class-level `$requestCache` | Same-key get dedupe; `set` invalidates |

## Lazy / deferred payload decisions

| Payload | Decision |
|---|---|
| FAQ / contacts / history | **Defer** further lazy cutover — endpoints already exist; panel loading not fully audited for one-shot cache |
| `body` HTML in core bootstrap | **Keep** — frontend still consumes `content` |
| Duplicate analysis in settings | **Reuse same arrays** (payload still has both keys for contract) |

## Database indexes

None added. No EXPLAIN from production yet. Candidates for Phase 3 if remote proves: `article_meta(article_id, meta_key)`, sessions `(article_id, status)`.

## SaaS metrics before/after

| Metric | Before (evidence) | After (design / pending remote) |
|---|---|---|
| Idle PUT/min | Could fire on local draft timer | Client skip → ~0 document PUT when unchanged |
| No-op server queries | = full save | Session lock + hash compare + heartbeat touch only |
| True autosave queries | 25–80+ | Unchanged path (no coalesce this phase) |
| Bootstrap duplicate policy | 2× | 1× |
| SeoMedia N+1 | N items | ≤2 batch queries |

## Files changed (primary)

- `ArticleEditorActionRequest.php` (envelope fields)
- `ArticleEditorSessionService.php` (noop)
- `ArticleEditorSessionController.php` (skip apply)
- `ArticleEditorDocumentWriter.php` (`canonicalHash`)
- `WpOption.php` (request cache)
- `ArticleEditorMediaSnapshotService.php` (batch + soft build)
- `EditArticle.php` (policy dedupe + soft snapshot)
- `SeoArticleEditor.jsx` / `articleEditorDocument.js`
- Tests: `ArticleEditorAutosaveNoopPerformanceTest`, `ArticleEditorBootstrapPerformanceTest`

## Migrations

None.

## Tests added

- `ArticleEditorAutosaveNoopPerformanceTest`
- `ArticleEditorBootstrapPerformanceTest`

## Tests run

Agent **did not** execute (remote-first rule). Manual:

```text
$PHP_BIN vendor/bin/phpunit --filter=ArticleEditorAutosaveNoopPerformanceTest
$PHP_BIN vendor/bin/phpunit --filter=ArticleEditorBootstrapPerformanceTest
$PHP_BIN vendor/bin/phpunit --filter=ArticleEditorSession
$PHP_BIN vendor/bin/phpunit --filter=ArticleEditorJsonPersistence
$PHP_BIN vendor/bin/phpunit --filter=ArticleEditorExclusiveLock
$PHP_BIN vendor/bin/phpunit --filter=ArticleEditorHeartbeat
$PHP_BIN vendor/bin/phpunit --filter=ArticleEditorRuntime
$PHP_BIN vendor/bin/phpunit --filter=ArticleEditorCoreBootstrapContractTest

npm run check:editor-cycles
npm run build
php artisan optimize:clear
```

## Browser smoke (pending remote)

Article 9631: idle no PUT; one edit → one autosave; stop typing → no duplicate payload; Save after ACK → noop/minimal; Save & Close OK; heartbeat unchanged.

## Remaining bottlenecks

- True autosave still heavy (revision + meta apply + full action dispatch).
- Bootstrap still ships HTML body + media snapshot eagerly.
- React HostApi / SEO debounce → Phase 3 profiling.

## Ready for Phase 3 React profiling

**no** until remote phpunit + `npm run build` + browser smoke recorded.
