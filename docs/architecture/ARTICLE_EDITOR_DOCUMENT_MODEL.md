# Article Editor Document Model (Phase 3)

> Status: Implemented (Phase 3 foundation)  
> Task ID: `article-editor-document-model-phase-3`  
> Related: [`ARTICLE_EDITOR_ANALYSIS_OWNERSHIP.md`](ARTICLE_EDITOR_ANALYSIS_OWNERSHIP.md), [`ARTICLE_EDITOR_WIDGETS_OWNERSHIP.md`](ARTICLE_EDITOR_WIDGETS_OWNERSHIP.md)

## Canonical chain

```text
TipTap JSON (per-block getJSON / merged doc)
        ↓
DocumentModel (walk + memoized index)
        ↓
Selectors (selectHeadings / selectLinks / …)
        ↓
Analyzer (computeSeoAnalysis / composeImmediateArticleAnalysis)
        ↓
Widgets (consume analysis metrics — do not re-parse HTML)
        ↓
HTML Renderer (export / WP / clipboard only)
```

## Ownership

| Layer | Role |
|-------|------|
| **TipTap JSON** | Editor document SoT for analysis traversal |
| **blocks[]** | Persist shell; `content` HTML is **compat** until full JSON persist |
| **htmlDocumentCompat** | Single DOMParser ingest: HTML → PM JSON |
| **editorDocumentBridge** | Prefer live `editor.getJSON()`; else blocks HTML compat |
| **HTML export** | Serialize / preview / WordPress only — **not** analysis input |

## Modules

| File | API |
|------|-----|
| `resources/js/utils/documentModel.js` | `createDocumentModel`, `walk`, `wordCount`, `plainTextEligible`, `findNode` |
| `resources/js/utils/documentSelectors.js` | `selectH2`, `selectLinks`, `selectImages`, `selectFaqPlaceholders`, `selectCtaParagraphs`, … |
| `resources/js/utils/htmlDocumentCompat.js` | `htmlToDocumentJson`, `blocksToDocumentJson` |
| `resources/js/utils/editorDocumentBridge.js` | `documentJsonFromEditorsOrBlocks` |

## Analysis migration

`resolveAnalysisDocumentModel({ document, documentModel, blocks, html })`

Priority: live model → PM JSON → blocks→JSON → HTML compat.

Word count / H2 / links / intro keyword slice use DocumentModel.

Image **counts** still prefer Phase 2A media snapshot; ratio word count uses DocumentModel.

## Inventory (Phase 3 cut)

| Consumer | HTML | TipTap JSON | Migrate |
|----------|------|-------------|---------|
| SEO analysis word/H2/links/intro | compat only | DocumentModel | done |
| FAQ presence (scoring) | fallback parse | `selectFaqPlaceholders` + FAQ snapshot | done |
| Featured snippet presence | score HTML | `selectTables` presence | partial |
| Image ratio counts | export HTML alts | media snapshot (2A) + DM words | done |
| CTA/FAQ/image insert | — | TipTap `insertContent` nodes | already PM |
| Save / WP / clipboard | serialize SoT | not yet persist JSON | later |
| Outline / link scroll / block split | still HTML | — | remaining |
| FAQ answer editors | getHTML domain UI | — | out of scope |

## Remaining HTML compatibility

- `exportBlocksToHtml` / save payload / WP sync
- FAQ answer TipTap (`getHTML`) — separate domain (Phase 2C)
- Outline / link scroll / block split that still read `block.content` HTML (migrate incrementally)
- Featured snippet **score** still scans export HTML tables (`seoContentBonus`)

## Not in this phase

Media ownership, session lock, publishing, FAQ/CTA ownership redesign, Vite split.

## Phase 4 follow-up

Document mutations go through [`ARTICLE_EDITOR_COMMAND_LAYER.md`](ARTICLE_EDITOR_COMMAND_LAYER.md). Analysis still consumes DocumentModel; commands must not re-parse HTML for targets.

## Phase 5A persistence

Server stores multi-block envelope in `articles.editor_document`. Bootstrap prefers JSON; HTML ingest only when absent/stale. See [`ARTICLE_EDITOR_JSON_PERSISTENCE.md`](ARTICLE_EDITOR_JSON_PERSISTENCE.md).

## Phase 6A runtime

TipTap extension list assembled via internal runtime document-extension registry (cached; same factories as `editorExtensions.js`). Schema unchanged. See [`ARTICLE_EDITOR_RUNTIME.md`](ARTICLE_EDITOR_RUNTIME.md).
