# Article Editor Runtime Completion (Phase 6C)

> Status: Internal stability candidate (Phase 6C.4)  
> Task IDs: `article-editor-runtime-completion-phase-6c` … `6c-4`  
> Related: [`ARTICLE_EDITOR_RUNTIME.md`](ARTICLE_EDITOR_RUNTIME.md), [`ARTICLE_EDITOR_SHELL_BOUNDARY.md`](ARTICLE_EDITOR_SHELL_BOUNDARY.md)

## Final ownership

| Concern | Sole writable owner | Persistence | Runtime consumers | Shell consumers |
|---------|---------------------|-------------|-------------------|-----------------|
| Document / TipTap | React command layer + DocumentModel | JSON envelope / HTML compat | modules via commands | Save/Preview collect |
| Selection / insertion | React insertion context | session-only | AI / Links / CTA / FAQ | — |
| Editor session | Laravel session API | DB | `useEditorSession` | takeover UI |
| Active panel | React `editorRuntimeNavigation` | session-only | PortalHost / chips | Alpine `runtimeActivePanel` mirror + AI rail layout |
| Widget health | React health store | session-only | dock badges | shell summary event |
| Media snapshot | Laravel API | article meta | Featured/Gallery/Images | one-way snapshot events |
| FAQ snapshot | Laravel API | `seo_faqs` | FAQ module | non-editor Livewire |
| Contacts / CTA templates | Laravel domain services | site/domain meta | CTA/Links | Filament settings |
| AI preview (editor) | React AI module | none until Apply | AI panel | — |
| AI history / prompts | Laravel / Filament | DB | — | history pages / audit |
| Publishing / Sync WP | Livewire/Alpine shell | queue / WP | dirty/save/session only | Publishing panel |
| Content Project lifecycle | Laravel | DB | read-only flags | archive / membership |

## AI / editor boundary

**Editor-owned (`article-editor.ai`):** selection context, generate image/video into document, panel UI, Apply via host actions → existing media/document pipeline (`insert_image` / Livewire video).

**Shell-owned:** AI history, prompt configuration, billing/audit, Content Project generation jobs, Filament admin pages.

## Shell event boundary

Only `installEditorShellCompatibilityBridge` (+ media picker bridge) may listen for browser CustomEvents that cross shell↔runtime.

Bridge: translate schema → `openPanel` / host.actions. No TipTap transactions, no health compose, no snapshot mutation.

Deprecated registry: `SHELL_COMPAT_DEPRECATED_EVENTS` (event, replacement, consumer, phase).

## Scoped host API

`EDITOR_RUNTIME_HOST_CONTRACT_VERSION = 1`  
Modules use `editor/host/hooks/useEditor*` — not giant unrestricted bags.  
`EditorHostApiContext` remains a narrow presentation bag (seo/images/reviews/ai debug flags).

## Portal ownership

| Portal | Root id |
|--------|---------|
| Navigation | `#article-editor-sidebar-navigation-root` |
| Panels | module `portalRootKey` (seo/image/links/faq/featured/aiChat/…) |
| Media picker | `#article-editor-media-picker-root` (`media.picker`) |
| Link bubble | runtime inspector slot `bubble.link` via `EditorInspectorBubbleHost` |

## Remaining compatibility paths

- Alpine `aiChatOpen` layout toggled by bridge one-way events
- `seo-open-*-media-picker` → Shared Media Picker
- `generate-article-video` → Livewire shell
- `seo-article-editor-notify` → Filament toast adapter
- Legacy `generate-article-image` listener still on host for non-AI producers

## Manual regression checklist

1. Open AI FAB → chat rail; close → dock chips return  
2. Generate image from AI with selection → placeholder → complete  
3. Featured/Gallery Shared Picker set/clear/reorder  
4. Links/CTA insert without CustomEvent loop  
5. FAQ extract from toolbar  
6. Publishing Sync WP still shell-only  
7. Archived article: AI generate disabled  
8. Session lock lost: AI apply/generate blocked  

## Public SDK

See ADR: **Ready for internal stability testing** — not public SDK.
