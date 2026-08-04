# Article Editor — Legacy Dead-Code Cleanup

**Status:** Wave A1–A3 complete (source + command duplicate + docs).  
**Deploy session:** `article-editor-legacy-dead-code-cleanup`  
**Date:** 2026-08-03

## Goal

Remove proven zero-consumer legacy after editor runtime cutover. **No UX/behavior change.** No performance optimization, Vite split, public SDK, or JSON publish rollout.

## Canonical owners (post-cleanup)

| Concern | Owner |
|---|---|
| Document + TipTap | `SeoArticleEditor` + command layer + JSON persistence |
| Sidebar panels | `EditorSidebarPortalHost` + `editor/modules/*` |
| Navigation / health dock | `EditorSidebarNavigation` + `editorRuntimeHealthStore` |
| Media picker | `SharedMediaPicker` + media snapshot APIs |
| Shell boundary events | `editorShellCompatibilityBridge` (+ `mediaPickerCompatibilityBridge`) |
| Exclusive lock | `ExclusiveLockScreen` + session acquire/heartbeat/release |
| FAQ insert command | `faqCommands.insertFaqPlaceholderCommand` (single registry handler) |

`ArticleEditorModuleHost` remains **deleted** (not reintroduced).

## Deleted (A)

### Files

| File | Replacement | Evidence |
|---|---|---|
| `components/ArticleEditorModuleErrorBoundary.jsx` | `editor/runtime/EditorModuleErrorBoundary.jsx` | zero import |
| `modules/LinksModule.jsx` | runtime Links panel | shim only |
| `modules/FaqModule.jsx` | runtime FAQ panel | shim only |
| `modules/AiChatModule.jsx` | runtime AI panel | shim only |
| `utils/editorInsertionCommands.js` | `editorCommands/insertionCommands.js` | zero import |
| `partials/media-picker-item.blade.php` | SharedMediaPicker | zero `@include` |

### Events (orphan producers / listeners)

Removed: `seo-assistant-widget-health`, `seo-editor-cta-link-inserted`, `seo-featured-image-updated/cleared`, `seo-editor-seo-analysis-updated`, `seo-article-score-patched`, `article-editor-flags-patched`, `seo-editor-settings-loaded`, `seo-editor-meta-loaded`, listeners for `seo-editor-distribute-product-gallery`, `seo-editor-seo-payload-updated`, `article-editor-save-started`, `seo-faq-panel-activate`.

### Other A removals

- Alpine `featuredImageDraft` / `mediaModalOpen` stubs
- Except stubs `shouldAutoExcludeQuickFixFromWpPicker` / `withWpPickerExcludeQuickFix`
- CTA insert aliases (`insertCtaInline*`, `insertCtaBlock*`, `insertLinkReplacingEditorSelection`, `insertCtaInEditor`)
- `loadChat` / `saveChat` (clear still drops leftover `seo_article_chat_*` keys)
- `EditArticle::analyzeSeoDraft()` empty stub
- CSS `.seo-editor-hard-lock-bar*`
- i18n `editor_locked_takeover`
- alias `EditorSessionLockBanner`
- JS presentation wiring for `canTakeoverEditorSession`
- duplicate `insertFaqPlaceholderCommand` in `insertionCommands.js` + void alias import
- deprecated `rowHasUnresolvedMediaSlug` alias

## Kept shell compatibility (B)

- `editorShellCompatibilityBridge` / media open bridge / `seo-article-editor-notify`
- Publish open events, Livewire→window forward bus
- FAQ extract/save/flush/generate Alpine↔Livewire paths still used
- `seo-request-editor-images-catalog` ↔ `seo-editor-images-catalog`
- `seo-assistant-widget-health-refresh`
- SharedMediaPicker + `ArticleMediaPickerController`
- workspace media picker + `seo-article-media-modal.css` (non-editor)
- JSON persistence flags (all)
- HTML body / ingest / render / WP / Preview
- Heartbeat / release / close / acquire / document
- Draft / history / outline LS; session `client_instance_id`
- CTA templates LS; media picker cache
- Legacy `POST /save` until session-only cutover

## `seo-wordpress-media-renamed` decision (B)

**Option B.** Event kept **deprecated** (zero listener). Canonical refresh already via `seo-assistant-widget-health-refresh` (host listens). No parallel business path.

## Takeover backend (C / D)

- Route + `ArticleEditorSessionService::takeover` + `EditorSessionClient.takeover` **retained**, marked `@deprecated`.
- Bootstrap still may expose `canTakeoverEditorSession` / endpoints.takeover for payload compat; **editor UI ignores**.
- **Removal blocker:** product/ops approval.

## Command registry notes

- Zero-external-caller registered commands (`insert_html_compat`, `split_block`, `duplicate_block`, …) **kept as C** (registry/API surface + Phase 4 tests).
- Duplicate FAQ placeholder handler removed; canonical = `faqCommands`.
- Duplicate registration still fails in development (`registerUnique` / fail-fast).

## Storage key matrix (editor-relevant)

| Key | Reader | Writer | Owner | Keep/remove |
|---|---|---|---|---|
| `seo-editor:draft:*` (+ legacy draft) | SeoArticleEditor | SeoArticleEditor | recovery | **keep** |
| `seo_article_history_*` | history hook | history | undo | **keep** |
| `seo_article_outline_*` | Outline panel | Outline | draft | **keep** |
| `seo_article_faq_*` | FAQ editor | FAQ editor | transient | **keep (C: not SoT)** |
| `seo_article_chat_*` | none | clear only | leftover wipe | **writer removed** |
| `seo-editor:client-instance:{id}` (session) | session client | session client | lock | **keep** |
| `seo_featured_image_*` / album LS | discard/clear | none (SoT) | snapshot | **no SoT write** |
| `seo-article-media-picker:v2:*` | SharedMediaPicker | picker | cache | **keep** |
| `seo-cta-quick-templates:v1:` | CTA list | CTA list | templates | **keep** |

## NPM dependencies

No editor-only unused top-level package removed. Candidate list (`mitt`, `sortablejs`, `dompurify`, …) not present as direct deps or still in use via TipTap stack. **No lockfile change.**

## Remaining C / D

- Takeover API removal (needs ACK)
- CustomEvent insert/nav paths still on shell bridge mid-rollout
- `dispatchActiveModule` / `seo-editor-active-module` producer without ModuleHost listener
- Giant `editorHostApi` bag (seo/images/reviews panels)
- Zero-caller structure/compat commands
- FAQ LS transient writers
- Dual `/save` vs session document
- JSON `publish_from_json` still default false

## Performance audit readiness

**Yes** for measuring baseline after this cleanup. **No** deep optimization in this task.

Suggested next audit boundary: chunk sizes, listener count, SeoArticleEditor LOC, portal roots — without changing behavior.

## Tests updated

Contract tests retargeted to canonical owners (ExclusiveLockScreen, SharedMediaPicker absence of Alpine drafts, `insertionCommands`, `EditorModuleErrorBoundary`, media snapshot event, FAQ command owner).
