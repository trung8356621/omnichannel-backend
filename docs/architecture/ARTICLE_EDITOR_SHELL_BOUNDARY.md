# Article Editor — Shell Boundary (Phase 6C.1)

> Status: Active  
> Task ID: `article-editor-runtime-completion-phase-6c-1`

## Ownership

| Surface | Owner |
|---------|--------|
| Editor dock chips / search / health badges | **React runtime** (`EditorSidebarNavigation`) |
| Active editor panel id | **Runtime navigation** (`openPanel` / `getActivePanel`) |
| Widget health for chips | **Runtime health store** (`editorRuntimeHealthStore`) |
| Panel slot visibility (Alpine `x-show`) | Alpine **read-only** mirror of runtime (`runtimeActivePanel`) |
| Publishing / Article info panels | **Laravel/Alpine shell** (not runtime registry) |
| Shell → open panel | `editorShellCompatibilityBridge` → `openPanel` |
| TipTap / document / commands | React editor + Phase 4/5a |

## Mount roots (Blade only)

```html
<div id="article-editor-sidebar-navigation-root"></div>
<div id="article-editor-sidebar-panel-root"></div>
```

Blade must not render `x-for="chip in chips"` or hard-code dock chip IDs.

## Publishing boundary

Publishing stays outside runtime sidebar registry.

Dock shows Publishing / Article as **shell boundary items** (`SHELL_BOUNDARY_NAV_ITEMS`) rendered beside registry chips. Selecting them calls `openPanel('publishing'|'article')` for exclusive accordion only — lifecycle/Sync WP/queue remain Livewire/Alpine.

## Compatibility events (deprecated bus)

The compatibility bridge is the sole shell adapter: `installEditorShellCompatibilityBridge()`.

Allowed:

- shell requests open panel / focus reason
- shell receives `seo-editor-shell-health-summary` (overall only)
- Livewire navigation lifecycle

Not allowed for shell:

- insert CTA/link/image
- mutate FAQ / Featured / Gallery SoT
- own primary health badge rendering

## Media shell (6C.3)

- Blade mounts `#seo-article-featured-root` + `#article-editor-media-picker-root` only.
- Featured/Gallery drafts / Alpine media modal **not** shell SoT.
- Legacy picker open events → `installMediaPickerCompatibilityBridge` → Shared Media Picker (compatibility bridge adapter).

## AI shell layout (6C.4)

- Alpine `aiChatOpen` is **layout only** (show/hide chat rail).
- SoT active panel = React `openPanel('ai-chat')`.
- Bridge mirrors navigation → `seo-article-ai-chat-open/close` with `fromRuntime` guard.
- AI history / prompts remain Filament shell — not runtime.

## Remaining (post-6C)

- Remove deprecated shell events after zero external consumers
- Broader host API split / toolbar cleanup
- Full internal CustomEvent cleanup outside Links/FAQ/CTA

## Legacy cleanup

Internal orphan CustomEvents removed; shell bridge events retained. See [ARTICLE_EDITOR_LEGACY_CLEANUP.md](ARTICLE_EDITOR_LEGACY_CLEANUP.md).
