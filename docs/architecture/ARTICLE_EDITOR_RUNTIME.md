# Article Editor Runtime (Phase 6A + 6B + 6C.1)

> Status: Implemented (internal foundation + UI cutover + navigation + **Links/FAQ/CTA modules**)  
> Task IDs: `article-editor-runtime-phase-6a`, `article-editor-runtime-ui-cutover-phase-6b`, `article-editor-runtime-completion-phase-6c-1`, `article-editor-runtime-completion-phase-6c-2`  
> **Not a public SDK.** Built-in modules only (build-time import).  
> Public SDK readiness: [`decisions/ARTICLE_EDITOR_RUNTIME_PUBLIC_SDK_READINESS.md`](decisions/ARTICLE_EDITOR_RUNTIME_PUBLIC_SDK_READINESS.md) → **Not ready**  
> Shell boundary: [`ARTICLE_EDITOR_SHELL_BOUNDARY.md`](ARTICLE_EDITOR_SHELL_BOUNDARY.md)

## Purpose

Internal registry + navigation + slot host so `SeoArticleEditor` orchestrates lifecycle, not each panel’s JSX.

```text
React EditorSidebarNavigation (registry chips + shell Publishing/Article)
        ↓ openPanel / runtime navigation (sole activePanel owner)
EditorSidebarPortalHost + module components / ModuleHost / Alpine panels
        ↓
executeEditorCommand (Phase 4) + TipTap (cached extensions)
```

## Phase 6C.1 — React runtime owns editor navigation

| Surface | Canonical path |
|---------|----------------|
| Dock chips / search / health badges | `EditorSidebarNavigation` ← `getSidebarEntries()` + health store |
| Active panel | `editorRuntimeNavigation` only |
| Blade | Mount roots only (`article-editor-sidebar-navigation-root`) |
| Alpine `seoAssistantNavigator` | Read-only `runtimeActivePanel` for `x-show` — no chips/health/activePanel SoT |
| Health publish | `setRuntimeWidgetHealth` → React chips; shell summary event only |
| Publishing / Article chips | `SHELL_BOUNDARY_NAV_ITEMS` (not runtime registry) |

**React runtime owns editor navigation.** Blade only supplies mount root.

## Phase 6B cutover (still true)

| Surface | Canonical path |
|---------|----------------|
| Editor-hosted panels (seo/images/reviews) | Runtime sidebar `component` + `EditorSidebarPortalHost` |
| Toolbar history/inline/lists/align/insert | `RuntimeToolbarCommandButtons` ← toolbar registry |
| Widget health compose | `publishRuntimeWidgetHealth` ← healthProviders |
| Links / FAQ panels | Runtime `host: editor` + `LinksSidebarPanel` / `FaqSidebarPanel` |
| CTA chip | Aliases Links panel (`aliasPanelId: links`); insert via command host actions |
| AI Chat | React `article-editor.ai` → `AiChatSidebarPanel` (ModuleHost removed) |
| Featured / Gallery UI | React `FeaturedSidebarPanel` (+ embedded `GallerySidebarPanel` for product) |
| Shared Media Picker | One portal `media.picker` (`#article-editor-media-picker-root`) |

### Phase 6C.2 — Links / FAQ / CTA modules

- Scoped hooks: `editor/host/hooks/useEditor*` (commands, insertion, links, contacts, faq, session, notifications).
- Insert path: module → `getEditorCommandHost().actions` / `executeEditorCommand` (no internal CustomEvent bus).
- FAQ extract: `POST /editor/faq-snapshot/extract` + `runFaqExtractFromToolbar` (Livewire extract remains for non-editor).
- Link bubble registered on `bubble.link` inspector; unlink/create/update use command layer.
- Compatibility bridge forwards deprecated insert/extract events only.

### Phase 6C.3 — Featured / Gallery + Shared Media Picker

- Featured chip owns Featured UI; product posts embed Gallery in same panel (`gallery` `navChip: false`).
- Canonical data: Laravel media snapshot APIs; React `useEditorMedia` / `articleEditorMediaSnapshot.js`.
- Shared picker: `openMediaPicker({ mode: content_image|featured|gallery })` via `useEditorMediaPicker` / `editorMediaPickerStore`.
- Modes: `content_image` → `insert_image` / block apply; `featured` → PUT Featured; `gallery` → gallery API. No body insert for featured/gallery.
- WP tab not disabled for article sync state; picker never renames; Fix Slug All still skips WP attachments.
- Alpine: mount roots + thin open forwarders (`openArticleMediaModal` → `__seoOpenSharedMediaPicker`). No `featuredImageDraft` / `mediaModalOpen` SoT.
- Snapshot apply ignores stale `snapshot_version`; mutations send `expected_snapshot_version`.

## Paths

```
resources/js/editor/runtime/   # create, nav, health store, bridge, media picker store
resources/js/editor/host/      # Navigation, PortalHost, SharedMediaPicker, hooks
resources/js/editor/modules/   # built-in modules + *SidebarPanel.jsx
```

## Module UI contract

Sidebar items may include `component`, `portalRootKey`, `host` (`editor`|`external`|`alpine`), `navChip`, `label`, `keywords`.

Panels use scoped hooks (`useEditorMedia`, `useEditorMediaPicker`, …) — not window/Alpine/Livewire SoT.

## Shell compatibility

`installEditorShellCompatibilityBridge()` + `installMediaPickerCompatibilityBridge()`:

- `seo-assistant-switch-panel` / `article-editor:module-open` → `openPanel`
- `seo-open-*-media-picker` / `open-article-media-modal` → Shared Media Picker (adapter only)

Flow: shell event → bridge → runtime service (never Alpine writable media draft).

Deprecated as internal React bus — listed in `SHELL_COMPAT_DEPRECATED_EVENTS`.

### Phase 6C.4 — AI cutover + host contracts

- `ArticleEditorModuleHost` deleted; AI mounts via `EditorSidebarPortalHost` + `#seo-article-ai-chat-root`.
- Scoped hooks + `EDITOR_RUNTIME_HOST_CONTRACT_VERSION = 1`.
- Shell bridge owns AI open/close layout sync; AI panel uses host generate actions (no internal open/close/generate events).
- Link bubble via `EditorInspectorBubbleHost` (`bubble.link`).
- Alpine dead media-picker fields/methods removed.
- Completion doc: [`ARTICLE_EDITOR_RUNTIME_COMPLETION.md`](ARTICLE_EDITOR_RUNTIME_COMPLETION.md).

## Remaining (post-6C)

- Broader CustomEvent cleanup outside approved bridges
- Public SDK still **Not ready** (internal stability testing only)

## Explicitly out of scope

Public npm package, dynamic third-party JS, PHP editor registry, Vite split, schema migration, UI redesign, publishing ownership in runtime.

## Related

- Shell boundary: [`ARTICLE_EDITOR_SHELL_BOUNDARY.md`](ARTICLE_EDITOR_SHELL_BOUNDARY.md)
- Commands: [`ARTICLE_EDITOR_COMMAND_LAYER.md`](ARTICLE_EDITOR_COMMAND_LAYER.md)
- Widgets: [`ARTICLE_EDITOR_WIDGETS_OWNERSHIP.md`](ARTICLE_EDITOR_WIDGETS_OWNERSHIP.md)
- Extension SDK (different boundary): [`../modules/EXTENSION_SDK.md`](../modules/EXTENSION_SDK.md)
