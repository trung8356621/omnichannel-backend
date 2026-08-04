# Article Editor Command Layer (Phase 4)

> Status: Implemented (foundation + primary UI cutover)  
> Task ID: `article-editor-command-layer-phase-4`  
> Related: [`ARTICLE_EDITOR_DOCUMENT_MODEL.md`](ARTICLE_EDITOR_DOCUMENT_MODEL.md), [`ARTICLE_EDITOR_WIDGETS_OWNERSHIP.md`](ARTICLE_EDITOR_WIDGETS_OWNERSHIP.md), [`ARTICLE_EDITOR_SESSION_LOCK.md`](ARTICLE_EDITOR_SESSION_LOCK.md), [`ARTICLE_EDITOR_JSON_PERSISTENCE.md`](ARTICLE_EDITOR_JSON_PERSISTENCE.md), [`ARTICLE_EDITOR_RUNTIME.md`](ARTICLE_EDITOR_RUNTIME.md)

## Boundary

```text
UI / shortcut / compat event adapter
        ↓
executeEditorCommand(name, payload)
        ↓
assertWritableCommandContext + resolveTargetEditor
        ↓
runEditorTransaction / host structure mutation
        ↓
article-editor-document-changed (single signal)
        ↓
commitActiveBlock + requestAnalyze + scheduleAutosave
```

Components **must not** build TipTap transactions for user mutations. Low-level helpers (`editorSelectionUtils`, `editorLinkCommands`) remain as implementation used **only** by command modules.

## Modules

| File | Role |
|------|------|
| `utils/editorCommands/index.js` | `executeEditorCommand`, host bind exports |
| `editorCommandContext.js` | Injected host context; writable guard |
| `editorCommandResult.js` | Result + error codes |
| `editorCommandRegistry.js` | Metadata + handlers |
| `resolveTargetEditor.js` | Explicit editor → insertion ctx → single fallback → ambiguous fail |
| `runEditorTransaction.js` | Dispatch + one document-changed signal |
| `insertionCommands.js` | CTA / contact / text / link / FAQ placeholder / HTML compat |
| `linkCommands.js` | create/update/remove/exit link |
| `formattingCommands.js` | marks, lists, align, table, undo/redo |
| `mediaCommands.js` | document image insert/update/delete only |
| `faqCommands.js` | FAQ placeholder/fragment in body |
| `structureCommands.js` | block delete/dup/move via host |
| `documentReplaceCommands.js` | AI/revision replace |

## Context

Injected via `bindEditorCommandHost` from `SeoArticleEditor` (no window/DOM/Livewire reads inside command handlers except host getters the editor bound).

## Result contract

`{ ok, code, command, editor_id, transaction_applied, document_changed, selection_changed, new_selection, history_step, error }`

Toast success only when `ok && transaction_applied` (caller opt-in). Failures notify via `notifyOnFailure` (default true).

## History

User mutations: `historyPolicy: 'add'` (one TipTap undo step per command). Selection/exit-link: `skip`.

Large `replace_article_document`: may exceed per-editor undo; server revision remains recovery SoT (`meta.history_note`).

## Events

| Event | Status |
|-------|--------|
| `seo-editor-insert-cta-link` | Compat adapter → `executeEditorCommand` |
| `seo-editor-insert-suggested-link` | Still adapter (migrate incrementally) |
| `article-editor-document-changed` | Canonical dirty/analyze/autosave signal |
| CustomEvent as React command bus | **Deprecated** for same-tree mutations |

## Remaining

- Persist TipTap JSON server-side (later)
- Full HTML save/output removal
- Vite split
- Remaining `setContent` hydrate paths in SeoArticleEditor (import/AI/revision) should call `replace_article_document`
- Outline/link-scroll HTML helpers (Phase 3 remaining)
- Image block (non-TipTap) structure still React state

## Not in this phase

Server TipTap JSON persist, publishing refactor, collaborative editing, browser E2E.

## Phase 6C.4 note

AI Chat generate/insert uses host actions + existing insert_image / media pipeline; video generate remains Livewire shell endpoint. No new document schema.

## Legacy cleanup

Duplicate insert_faq_placeholder insertion-module handler removed; canonical aqCommands. Zero-caller structure/compat commands retained as deprecated registry surface. See [ARTICLE_EDITOR_LEGACY_CLEANUP.md](ARTICLE_EDITOR_LEGACY_CLEANUP.md).
