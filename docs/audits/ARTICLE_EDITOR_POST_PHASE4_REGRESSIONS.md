# Article Editor — Post Phase 4 Regression Matrix

**Date:** 2026-07-22  
**Goal:** Stabilization (fix contracts). Not Phase 5 perf.

Runtime = source of truth when docs/audit “before” conflict.

## Contract (canonical)

```text
activation event
  → lazy import
  → endpoint request
  → normalize adapter
  → module state
  → UI render
  → cleanup (abort / unmount)
```

One activation path per module. One SEO widget owner.

## Official events (kept)

| Event | Role |
|-------|------|
| `seo-assistant-switch-panel` | Navigator chip → module open (`MODULE_EVENT_SWITCH`) |
| `seo-editor-active-module` | Broadcast active heavy module (`MODULE_EVENT_ACTIVE`) |
| `article-editor:module-open` | Canonical open (`MODULE_EVENT_OPEN`) — FAQ shortcode / Help goto / widgets |
| `seo-faq-panel-activate` | **Compat only** → maps to FAQ module open in ModuleHost |
| `seo-assistant-link-section` | Links / CTA section filter inside Links panel (**FAQ section removed**) |
| `seo-editor-links-rescan-request` | Links mount / siteDomain hydrate → republish client existing-link scan |
| `seo-editor-seo-summary-loaded` | SEO summary hydrated |
| `seo-editor-settings-loaded` | Scoring rules / messages |
| `seo-editor-links-updated` | Client document existing links (+ catalogs via other payloads) |
| `seo-article-ai-chat-open` / `close` | AI chat |
| `google-serp-preview-updated` | Local SERP preview patch |
| `article-editor:help-open` | Sticky Help → global Help modal |

## Fixes applied (stabilization)

| Issue | Fix |
|-------|-----|
| Help button missing | Blade `seo-article-editor-help-btn` + labeled `Help` + inline CSS fallback; not inside More |
| Help modal not mounting | `ArticleEditorHelpModal` in `article-editor.jsx`; host marker `data-article-editor-help-modal` when closed |
| FAQ assistant tag | Removed from `seoAssistantNavigator` (CTA kept) |
| FAQ accordion in Links | Removed from `ArticleLinksSidebar`; no FAQ count in badges/payload |
| FAQ shortcode crash `null.cached` | API always `{cached,items,...}`; `normalizeFaqPayload` accepts null/array; ModuleHost `EMPTY_FAQ_PAYLOAD` + try/catch; **cần Vite build** `FaqModule`/`article-editor` |
| FAQ open path | Single `article-editor:module-open`; shortcode/Edit FAQ dispatch it |
| Existing links = 0 | Restore `scanExistingLinksCompat` + rescan-on-Links-mount + siteDomainRef; not DB body |
| Save 409 `updated_at mismatch` | Soft pass khi content hash khớp; force overwrite nếu `actualRole` > content_manager; Owner/Admin = manager rank; save content trước bundleApply; conflict tokens UTC |
| Keyword missing in meta (case) | Lowercase keyword trước so meta (`seoAnalyzer` / `SeoScoringEngine`) |
| Article widget thiếu author | `publish-sidebar` + bootstrap `authorName` / badge «Bạn» |

## Stabilization contracts (FAQ / SEO / Links)

- FAQ entry = shortcode block only (no assistant tag, no Links FAQ accordion).
- FAQ shortcode lazy: count từ core `faqCount` / `/editor/faqs/count`; full rows chỉ khi mở module.
- SEO idle: debounce 3–5s (4000), cancel on typing, no 150ms loop; summary updates even if SEO panel closed.
- Existing links: TipTap/blocks scan client-side (pre-refactor `extractLinksFromBlocks` behavior via `existingLinkScanner`); suggestions & domain catalog separate.
- Violation action map only for mapped keys (FAQ schema / featured snippet missing).

## Runtime verification (ops)

Fill on staging (post + product):

| Feature | Opened? | Request | HTTP | Render | Error |
|---------|---------|---------|------|--------|-------|
| Help button | sticky header | none | — | visible `Help` | |
| Help modal | click Help | none | — | accordion + Esc | |
| Google Preview | editor open | none for paint | — | title/url/desc | |
| SEO | default/click | seo-summary | 200 | score | |
| FAQ | shortcode / Edit FAQ | /editor/faqs | 200 | list/empty | |
| FAQ chip | — | — | — | **absent** | |
| Links FAQ accordion | — | — | — | **absent** | |
| Links existing | Links chip | none for scan | — | Internal(N)/External(N) from editor | |
| Links suggestions | Generate | /editor/links/suggestions | 200 | suggestions/empty | |
| CTA | CTA chip | /editor/links | 200 | CTA section | |
| Reviews status | Reviews (product) | product-review-status | 200 | status | |

Do **not** mark done from static tests alone.

## Git compare notes (Help / FAQ / Links restore)

| Area | Old | Current restore path |
|------|-----|----------------------|
| Existing links | `extractLinksFromBlocks` / `extractLinksFromHtml` in `articleLinkScroll.js` (eager Links) | Same parsers via `existingLinkScanner` + event republish |
| FAQ open | panel activate + Links accordion | shortcode → `MODULE_EVENT_OPEN` → ModuleHost lazy FAQ |
| Help | (new sticky) | Blade sticky + React modal; no SSR payload change |
