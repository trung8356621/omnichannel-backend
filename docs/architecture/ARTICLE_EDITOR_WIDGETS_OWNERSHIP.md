# Article Editor Widgets Ownership (Phase 2C)

> Status: Implemented (Phase 2C)  
> Task ID: `article-editor-widgets-ownership-phase-2c`  
> Related: [`ARTICLE_EDITOR_ANALYSIS_OWNERSHIP.md`](ARTICLE_EDITOR_ANALYSIS_OWNERSHIP.md), [`ARTICLE_EDITOR_MEDIA_SNAPSHOT.md`](ARTICLE_EDITOR_MEDIA_SNAPSHOT.md), [`ARTICLE_EDITOR_SESSION_LOCK.md`](ARTICLE_EDITOR_SESSION_LOCK.md)

## Ownership

| Concern | Owner |
|---------|--------|
| FAQ rows (`seo_faqs`) | **Laravel** snapshot API |
| FAQ panel draft / AI preview | **React** |
| Body `[omi_faq]` placeholder inject | Laravel apply + owning editor session |
| Contact values | Laravel `DomainCtaEditorService` / site meta |
| Quick CTA templates | Laravel `SeoDomainCtaGlobalSettingsService` (not localStorage SoT) |
| Insertion bookmark | React `editorInsertionContext` + `editorInsertionCommands` |
| TipTap insert | React commands (`insertContactCtaAtBookmark` / value) |

## FAQ boundary

- **Domain SoT:** table `seo_faqs` (+ `wp_faqs` meta snapshot).
- **Body role:** placeholder only — not FAQ text SoT.
- Snapshot: `GET/PUT …/editor/faq-snapshot`, `POST …/generate-preview`, `POST …/apply`.
- AI: `generatePreview` returns rows without persist; user **Apply** → `apply` writes faqs + session-aware body inject.
- Legacy Livewire `saveArticleFaqs` / `generateArticleFaqs` remain for non-editor callers; React editor path uses REST.

## CTA / contact

- Usable contacts only (PHP filter + JS defense).
- Sidebar UX (chốt): insert actions use CTA **sentence** mode (`value` remaps to sentence). Raw value command remains for legacy/programmatic callers.
- Template settings: `PUT /api/seo/domain-cta/quick-templates`; React form draft only while modal open.

## Insertion context

- Single store: `EditorInsertionContext` (`editorInsertionContext.js` — live + frozen).
- Freeze on sidebar `pointerdown` via `seo-assistant-freeze-insertion-context`.
- Commands: Phase 4 `executeEditorCommand` + bookmark restore (legacy `editorInsertionCommands` helpers remain under command modules).
- Multi-TipTap: `resolveEditorForInsertion` / `resolveTargetEditor` prefers active block — never first map entry.

## Event bridge cleanup

| Removed from React FAQ editor | Replacement |
|------------------------------|-------------|
| `save-article-faqs` → Alpine → Livewire | `replaceFaqSnapshot` API |
| `generate-article-faqs` → Alpine → Livewire | `generateFaqPreview` + Apply API |

CTA insert stays React→React CustomEvent (no Alpine loop).

## localStorage

- `seo_article_faq_{id}`: recovery only; cleared after canonical save / when server items hydrate.
- `seo-cta-quick-templates:v1:{siteId}`: no longer SoT; discarded when server templates load.

## Remaining (parser / command layer)

- TipTap JSON persist server-side
- Remaining hydrate/`setContent` paths → `replace_article_document`
- Extract/import/renew FAQ still partly Livewire-bridged
- Browser E2E caret proof for CTA in blockquote/list

Command boundary: [`ARTICLE_EDITOR_COMMAND_LAYER.md`](ARTICLE_EDITOR_COMMAND_LAYER.md) — CTA/contact insert via `executeEditorCommand`.

Runtime boundary (Phase 6A): FAQ/CTA/media/seo declare sidebar/health/command **metadata** on built-in modules — ownership of snapshots/APIs unchanged. See [`ARTICLE_EDITOR_RUNTIME.md`](ARTICLE_EDITOR_RUNTIME.md).

Phase 6C.3: Featured/Gallery **UI** owned by React runtime modules + Shared Media Picker; Laravel remains snapshot/API SoT. Alpine no longer holds Featured/Gallery writable drafts.

Phase 6C.4: AI Chat **UI** owned by `article-editor.ai`; generate/apply via host actions + command/media pipeline. AI history/prompts remain shell. `ArticleEditorModuleHost` removed.

## Legacy cleanup

CTA insert aliases + Phase2C editorInsertionCommands facade removed. See [ARTICLE_EDITOR_LEGACY_CLEANUP.md](ARTICLE_EDITOR_LEGACY_CLEANUP.md).
