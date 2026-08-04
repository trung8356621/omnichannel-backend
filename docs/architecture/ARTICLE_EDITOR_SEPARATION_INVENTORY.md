# Article Editor Separation Inventory

> Status: Inventory / planning (not an implementation ADR)  
> Task ID: `article-editor-separation-inventory`  
> Scope: documentation only — no runtime refactor in this task  
> Canonical module SoT: [`docs/modules/ARTICLE_EDITOR.md`](../modules/ARTICLE_EDITOR.md)  
> Historical only: `docs/archive/maps/MAP_SEO_EDITOR.md`  
> Surveyed date: 2026-08-02  
> Codebase: `app/Addons/SeoContentAi/`

---

## Current architecture summary

- **Mount:** Filament SEO panel page `EditArticle` at  
  `GET /seo/{connection_hash}/articles/{record}/edit`  
  (`filament.seo.resources.articles.edit`).
- **React root:** `#seo-article-editor-root` (`wire:ignore`) plus portal roots for SEO / Images / Reviews / Links / FAQ / AI chat / AI launcher.
- **Livewire/Blade/Alpine:** Livewire shell (`EditArticle`), Blade chrome (publish sidebar, media picker modal, page actions), Alpine for publish box / categories / media modal / heavy-action overlay. React owns TipTap DOM inside ignored roots.
- **TipTap:** **Multiple** TipTap instances — one per text block via `useEditor`, tracked in `blockEditorsRef: Map<blockId, Editor>`; plus FAQ answer TipTap. Image blocks often sibling React (`ImageBlockEditor`), not a single monolithic editor.
- **Bootstrap:** JSON script `#seo-article-core-bootstrap` from `EditArticle::getEditorCoreBootstrap()` + `window.__SEO_*` globals; read by `readArticleEditorBootstrap()` in `article-editor.jsx`.
- **Save:** Explicit session document API (`PUT .../editor-sessions/{session}/document`) preferred; legacy `POST /api/seo/articles/{id}/save` still exists but cannot bypass active session. Conflict via `expected_document_version` (+ `expected_updated_at` / `expected_content_hash`).  
  **Autosave:** localStorage draft **and** debounced server session document save.  
  **Lock:** `article_editor_sessions` (TTL/heartbeat); not Cache mutex alone.
- **Livewire morph:** React roots use `wire:ignore`. Surrounding Blade/Alpine (publish, actions, media modal) still morph. `livewire:navigated` remounts React via `mountArticleEditorPage()`.
- **State sources (concurrent):** React document/blocks; localStorage drafts/featured/gallery; Livewire page props + Alpine publish snapshot; client SEO analyzer; lazy JSON endpoints; CustomEvent bus; `window.__seo*` globals.

## Main separation blockers

| Severity | Blocker | Evidence |
|----------|---------|----------|
| **critical** | Event + `window.__seo*` glue between React TipTap and Livewire/Alpine (media, FAQ, publish, heavy actions) | `article-editor.jsx` `registerArticleEditorLivewireBridge`; Blade `x-on:*.window` → `$wire.*` |
| **critical** | Multi TipTap registry + selection/insertion context (easy to lose caret on remount) | `SeoArticleEditor.jsx` `blockEditorsRef`, `editorInsertionContext.js` |
| **mitigated (P1/1.1)** | Edit session lock + `document_version` — remaining: Featured/Gallery LS SoT, some system writers | See [`ARTICLE_EDITOR_SESSION_LOCK.md`](ARTICLE_EDITOR_SESSION_LOCK.md) |
| **mitigated (P2A)** | Featured/Gallery Laravel snapshot SoT — React presentation only | [`ARTICLE_EDITOR_MEDIA_SNAPSHOT.md`](ARTICLE_EDITOR_MEDIA_SNAPSHOT.md) |
| **high** | Categories still multi-owner | publish Blade / category storage |
| **high** | Autosave ≠ server save (operators may assume draft is durable) | `SeoArticleEditor` scheduleAutosave → `saveDraft` only |
| **high** | Content Project Sync WP / Save&Close still mixed into Blade actions + API guard | `article-editor-page-actions.blade.php`, `WordPressManualSyncService` |
| **mitigated (P2B)** | React immediate analysis + Laravel policy/external facts | [`ARTICLE_EDITOR_ANALYSIS_OWNERSHIP.md`](ARTICLE_EDITOR_ANALYSIS_OWNERSHIP.md) |
| **mitigated (P2C)** | FAQ/CTA widget ownership + insertion context | [`ARTICLE_EDITOR_WIDGETS_OWNERSHIP.md`](ARTICLE_EDITOR_WIDGETS_OWNERSHIP.md) |
| **mitigated (P3)** | TipTap JSON DocumentModel for analysis traversal; HTML = compat/export | [`ARTICLE_EDITOR_DOCUMENT_MODEL.md`](ARTICLE_EDITOR_DOCUMENT_MODEL.md) |
| **mitigated (P4)** | Editor Command Layer — single mutation boundary + document-changed signal | [`ARTICLE_EDITOR_COMMAND_LAYER.md`](ARTICLE_EDITOR_COMMAND_LAYER.md) |
| **mitigated (P5A)** | Server persists TipTap `article_document` envelope; `body` derived HTML | [`ARTICLE_EDITOR_JSON_PERSISTENCE.md`](ARTICLE_EDITOR_JSON_PERSISTENCE.md) |
| **mitigated (P6A)** | Internal editor runtime registries (built-in modules); not public SDK | [`ARTICLE_EDITOR_RUNTIME.md`](ARTICLE_EDITOR_RUNTIME.md) |
| **mitigated (P6B)** | Panel/toolbar/health/nav cutover to runtime slots; shell bridge | [`ARTICLE_EDITOR_RUNTIME.md`](ARTICLE_EDITOR_RUNTIME.md) |
| **medium** | Server analyzer still persists DB score; not UI SoT | `SeoAnalyzerService` on save/rescore |
| **medium** | `livewire:navigated` remount can rebind listeners / destroy TipTap if not careful | `article-editor.jsx` mount hooks |
| **medium** | Image health / SEO ratio / WP rename protection split across React utils + PHP services | `assistantWidgetHealth.js`, `WordPressMediaRenameService`, Fix Slug All paths |
| **low** | Editor already has dedicated Vite entry; shared CSS/utils still couple to monorepo build | `vite.config.js` `article-editor.jsx` |
| **low** | Many localStorage keys without schema versioning | `articleEditorStorage.js` and related |

---

## 3. Route → page → view → React mount

```
GET /seo/{connection_hash}/articles/{record}/edit
  → Filament panel SeoPanelProvider (id=seo, path=seo/{connection_hash})
  → ArticleResource::getPages()['edit'] = EditArticle::route('/{record}/edit')
  → Livewire EditArticle (view: seo-content-ai::.../edit-article)
  → Blade edit-article.blade.php
       ├─ #seo-article-core-bootstrap (JSON)
       ├─ window.__SEO_* globals
       ├─ #seo-article-editor-root [wire:ignore]
       ├─ portal roots [wire:ignore] (seo/images/reviews/links/faq/ai)
       └─ @vite article-editor.jsx
            → createRoot → SeoArticleEditor + EditorSidebarPortalHost + portals
                 → TipTap per text block + assistant widgets
```

| Layer | Concrete |
|-------|----------|
| Panel | `Providers/SeoPanelProvider.php` — `panel()` path `seo/{connection_hash}` |
| Resource | `Filament/Resources/ArticleResource.php` — `$slug = 'articles'` |
| Page | `Filament/Resources/ArticleResource/Pages/EditArticle.php` |
| Base | `Filament/Resources/Pages/SeoEditRecord.php` → Filament `EditRecord` |
| View | `resources/views/filament/resources/article-resource/pages/edit-article.blade.php` |
| Actions partial | `.../partials/article-editor-page-actions.blade.php` |
| React entry | `resources/js/article-editor.jsx` (Vite) |
| Main component | `resources/js/components/SeoArticleEditor.jsx` |
| Middleware | Filament SEO panel auth + `web`; API save uses `$seoWebApiMiddleware` (auth web JSON) |

### Alias / related routes

| Route | Role |
|-------|------|
| `seo.articles.wp-edit-redirect` (+ `.legacy`) | Redirect into Filament edit |
| `seo.articles.preview` / `seo-preview` / `media-picker` | Preview / picker HTML pages |
| Legacy path patterns in BODY_END hook for `seo/articles/\d+/edit` | Detection only; live mount is hashed panel URL |

---

## 4. Frontend component inventory

| Area | Component/file | Framework | Responsibility | State owned | External deps |
|------|----------------|-----------|----------------|-------------|---------------|
| Editor entry | `article-editor.jsx` | React | Mount/remount, Livewire bridge, heavy save helpers | roots, globals | Livewire, Vite |
| Editor root | `SeoArticleEditor.jsx` | React | Blocks, TipTap map, draft autosave, analyze schedule | blocks, dirty, analysis | TipTap, storage, API |
| Module host | ~~`ArticleEditorModuleHost`~~ → `EditorSidebarPortalHost` | React | Runtime sidebar portals | openPanel | shell bridge |
| Outline | `ArticleOutlineTab.jsx` | React | Outline rail | outline UI | outline API |
| TipTap extensions | `utils/editorExtensions.js` | React/TipTap | StarterKit + custom nodes/marks | schema | ProseMirror |
| Image node | `utils/articleImageExtension.js`, `extensions/imageMarkerExtension.js` | TipTap | Image markers | attrs | — |
| Image block UI | `ImageBlockEditor.jsx` | React | Non-TipTap image block | block props | media events |
| Toolbar | `BlockFormatToolbar` / insert menus (in SeoArticleEditor tree) | React | Format commands | selection | TipTap cmds |
| Link bubble | `LinkEditBubble` + `editorLinkCommands.js` | React | Link edit/unlink | mark attrs | TipTap |
| CTA | Links module + `seo-editor-insert-cta-link` | React + events | CTA insert | — | contacts |
| Assistant shell | `ArticleAssistantWidget.jsx` | React | Panel switch | active module | events |
| SEO widget | `SeoModule` (lazy) | React | SEO panel | meta/analysis UI | lazy APIs |
| Images widget | `ImagesModule` / `ArticleImagesTab.jsx` | React | Image list, Fix Slug, WP rename open | image rows | health utils |
| Featured | featured storage + health | React + LS + Alpine | Featured URL | LS key | events |
| Gallery | product album storage + Blade | React + LS + Alpine | Album | LS key | events |
| Media picker | Blade Alpine modal + cache JS | Alpine + Livewire + LS | Pick media | picker pages | Livewire methods |
| FAQ | `ArticleFaqEditor` + FAQ snapshot API | React draft + Laravel `seo_faqs` | FAQ CRUD/AI preview | recovery LS only | REST snapshot — see [`ARTICLE_EDITOR_WIDGETS_OWNERSHIP.md`](ARTICLE_EDITOR_WIDGETS_OWNERSHIP.md) |
| Reviews | `ReviewsModule` | React | Virtual reviews | — | APIs/events |
| Links | `ArticleLinksSidebar` | React | Suggestions | excluded LS | insert events |
| AI chat | `AiChatModule` | React | Chat | chat LS | — |
| AI launcher | `ArticleAiFloatingLauncher.jsx` | React | Floating AI entry | — | events |
| WP rename modal | `WordPressMediaRenameModal.jsx` | React | Explicit WP rename | modal | rename API |
| Save / Save&Close | page-actions Blade → CustomEvent | Blade + React | Trigger save | — | API |
| Preview | route + button | Blade | Open preview | — | preview ctrl |
| Autosave | `scheduleAutosave` in SeoArticleEditor | React | Draft LS | draft | storage |
| Toast | `seo-article-editor-notify` | mixed | Notifications | — | listeners |
| Publish chrome | `publish-sidebar.blade.php` etc. | Alpine + Livewire | Status/visibility | wire props | `$wire.applyPublishBoxFromClient` |
| Help | help events / sticky header | Blade + React | Help drawer | — | — |

**Marks:** React = TipTap/document; Alpine = publish/media modal; Livewire = shell + WP/FAQ/media mutations; Blade-only = layout chrome; mixed = most bridges.

---

## 5. State ownership matrix

| State | Current owner(s) | SoT today | Persistence | Mutation paths | Conflict risk | Desired future owner |
|-------|------------------|-----------|-------------|----------------|---------------|----------------------|
| Article document (body HTML/blocks) | React | DB `articles` on explicit save | DB + LS draft | TipTap → save API; draft LS | **High** (multi-tab, no lock) | React edit + Laravel persist/version |
| Section/block content | React TipTap per block | In-memory Map | Draft LS | block editors | High | React |
| Caret/selection | TipTap + `editorInsertionContext` | Ephemeral | none | focus/selection events | High on remount | React |
| Active editor / block | React `globalEditor`, `activeBlockId` | Ephemeral | session block height only | focus | Medium | React |
| Expanded/collapsed UI | React / Alpine mix | Ephemeral | some sessionStorage | UI toggles | Low | React |
| Undo/redo | TipTap history per editor | Ephemeral | none | TipTap | Medium (multi-editor) | React |
| Dirty | React | Ephemeral | implied by draft | edits | Medium | React |
| Save status | React + events `article-editor:save-status` | Ephemeral | none | save API | Low | React |
| Autosave status | React + `articleAutosaveLock` | Ephemeral | none | lock events | Medium | React |
| LocalStorage draft | React storage util | Recovery cache | LS | debounce saveDraft | **High** overwrite | React recovery only |
| Document version | **Absent** (client `documentVersion` scheduler only) | N/A | N/A | utility scheduler | — | Laravel `document_version` |
| Conflict tokens | Bootstrap + `window.__SEO_EDITOR_CONFLICT__` | `updated_at` + content hash | DB timestamps/hash | save response | Medium | Laravel version |
| Media count / image health | React `assistantWidgetHealth.js` | Client compute | none | mutations + refresh event | Medium vs SEO ratio | React immediate + Laravel save validate |
| Featured health | React + LS featured | LS + DB on save paths | LS + article meta | events | **High** multi-owner | Laravel snapshot + React UI |
| Gallery state | LS album + Alpine + React | Multi | LS | events both ways | **High** | Laravel + React |
| SEO score/reasons | Client analyzer (+ optional Livewire analyze) | `article_meta.seo_rule_violations` on save | DB meta | analyze + save | **High** dual path | Shared policy; React immediate; Laravel canonical |
| Focus keyword | React SEO + bootstrap | article fields | DB | save / seo-meta | Medium | Laravel + React |
| Links count | React Links module | Client + lazy API | excluded LS | scan/insert | Medium | React + Laravel catalog |
| CTA templates | `ctaQuickTemplates.js` LS | LS | LS | UI | Low | React + optional server |
| Workflow / CP membership | Blade `$inContentProject` + membership service | `SeoProjectTask.article_id` | DB | CP ops outside editor | Medium | Laravel bootstrap |
| Manual WP sync allowed | Blade hide + API fail-closed | membership | DB | Sync button / sync-wp | Low if consistent | Laravel |
| Publish state | Alpine publish box + Livewire | article publish fields | DB | applyPublishBoxFromClient | Medium | Laravel |
| Article lock (collab) | React `EditorSessionClient` + `article_editor_sessions` | DB + event `article-editor-session-state-changed` | DB | acquire/heartbeat | Medium | React owns; shell consume |
| Locale | `window.__SEO_I18N_LOCALE__` + `i18n.js` | Client dict | none | page load | Low | Bootstrap locale |
| Permissions | Blade gates + API abort | SeoAccessControl | session | — | Low | Bootstrap permissions |

**Duplicate-owner hotspots:** featured, gallery, categories, SEO analysis, publish snapshot, FAQ (React + Livewire).

---

## 6. Event and bridge inventory

Representative table (not every toast). Producers/consumers from `article-editor.jsx`, `SeoArticleEditor.jsx`, Blade edit-article, module hosts.

| Event name | Producer | Consumer(s) | Payload | Sync/async | Remount risk | Future |
|------------|----------|-------------|---------|------------|--------------|--------|
| `article-editor-shortcut` | page-actions Blade | SeoArticleEditor / heavy helpers | `{action}` | sync | low | React toolbar API |
| `article-editor-save-started/finished/patched` | API finish helpers | publish Blade, UI | save result | async | low | save promise |
| `article-autosave-lock` | Livewire | `articleAutosaveLock.js` | lock flag | sync | low | React lock store |
| `seo-article-autosave-lock-changed` | lock util | editor | — | sync | low | same |
| `seo-article-save-conflict` | conflict handler | UI | conflict | sync | low | lock/version UI |
| `seo-editor-active-module` / `seo-assistant-switch-panel` | modules | widgets | module id | sync | low | React store |
| `seo-assistant-widget-health` / `-refresh` | health util / editor | navigator | health DTO | sync | low | React store |
| `seo-featured-image-updated/cleared` | LS / Blade | health + UI | url | sync | medium | API snapshot |
| `seo-product-gallery-updated` | LS ↔ Livewire | both | album | sync | **high** overwrite | API |
| `seo-open-*-media-picker` | React | Alpine modal | mode | sync | low | React picker |
| `seo-wordpress-media-rename-open` | Images/Media Lib | RenameModal | detail | sync | low | keep or API |
| `seo-editor-insert-suggested-link` / CTA | Links | TipTap | link DTO | sync | caret loss if bad | TipTap cmd bus |
| `seo-editor-seo-analysis-updated` | client analyze | sidebars | analysis | async | low | shared store |
| `seo-analyze-result` → `seo-editor-analyze-result` | Livewire bridge | React | analyze | async | **dual path** | remove Livewire analyze |
| `collect-editor-html` / `editor-html-collected` | Livewire ↔ React | FAQ/heavy | HTML | async | medium | API bundle |
| `save-article-faqs` / FAQ AI events | React ↔ Livewire | FAQ | faqs | async | medium | FAQ API |
| `livewire:navigated` | Livewire | remount React | — | async | **high** TipTap destroy | SPA island stable |
| `project-item-updated` | API | CP dirty session | — | sync | low | keep |

**Findings:**
- Large CustomEvent surface **without formal schema**.
- Dual SEO analyze events are overwrite risks.
- Gallery/featured bidirectional events can fight React state.
- Selection/insert events sensitive to order (`freeze-insertion-context`).

---

## 7. TipTap / ProseMirror inventory

### Lifecycle
1. `SeoArticleEditor` parses bootstrap `content` into **blocks**.
2. Each text block mounts `useEditor({ extensions: articleEditorExtensions, content, onUpdate… })`.
3. `registerBlockEditor(blockId, editor)` into `blockEditorsRef`.
4. Focus sets `globalEditor`; insertion uses `resolveEditorForInsertion`.
5. Unmount deletes map entry; page remount destroys all.

### Custom extensions (primary)

| Extension | File | Type | Custom behavior | Stored data | Separation concern |
|-----------|------|------|-----------------|-------------|-------------------|
| Preserved paragraph/heading | `editorExtensions.js` | node | HTML preserve | HTML | serialize parity |
| Link (custom) | `editorExtensions.js` + `editorLinkCommands.js` | mark | boundary-safe unlink | href attrs | must not remount mid-edit |
| Image / ImageMarker | `articleImageExtension.js`, `imageMarkerExtension.js` | node | marker attrs | src/alt/id | dual with ImageBlockEditor |
| Table / color / align | `editorExtensions.js` | mixed | standard+ | attrs | schema freeze |
| Blockquote | StarterKit / custom cmds | node | quote UX | — | selection restore |

### Risky patterns (searchable in editor JS)
- `window.getSelection` / DOM `querySelector` for legacy helpers  
- `focus('end')` / `doc.content.size`  
- `setTimeout` selection restore  
- Global `window.__seoCollectEditorHeavyBundle` / conflict globals  

Undo/redo = per-TipTap history (not cross-block unified).

---

## 8. Save and autosave flow

### Save article

```
UI Save (Blade data-seo-page-action=save)
  → CustomEvent article-editor-shortcut {action:'save'}
  → React / __seoRunArticleEditorApiAction / saveCurrentArticleFromEditor
  → collect HTML + publish snapshot + conflict tokens
  → POST /api/seo/articles/{id}/save  (ArticleEditorSyncController::save)
  → BusinessActionDispatcher article.content.update
  → ArticleContentConflictGuard::assertCompatible (expected_updated_at / expected_content_hash)
  → ActionSupport::withArticleLock (Cache lock automation-article-{id})
  → UpdateArticleContentAction → ArticleEditorPersistService / DB writes
  → JSON response (tokens, handoff, patches)
  → finishArticleSaveFromApi: clear draft, patch globals, dispatch save-finished
```

### Save & Close
- Same save API when `action: 'save-close'`.
- Then `closeEditorAfterProjectLocalSave(projectUrl)` (`articleEditorApi.js`) using `data-seo-content-project-url` — **no lifecycle Published bump**.
- Independent articles use Sync WP instead of Save&Close (Blade branch).

### Autosave
- Trigger: TipTap updates → debounced `scheduleAutosave`.
- Interval: `settings.autosave_interval_seconds` (default **2s**, clamp 0–30) — `ArticleEditorHistoryLocalDraftIntervalTest`.
- Payload: HTML/blocks to `saveDraft` localStorage.
- **No** server POST; **no** conflict check; blocked by `isArticleAutosaveLocked()`.
- Offline: survives in LS until clear/quota eviction.

### Unload / recovery
- Draft keys via `articleEditorStorage.js`; restore on mount if newer than server tokens (draft choice events).
- `beforeunload` / beacon: verify open questions if not confirmed in code survey — treat as **open** unless found.
- Clear: successful save `finishArticleSaveFromApi`; `clearArticleLocalState`.

---

## 9. LocalStorage inventory

| Key/pattern | Writer | Reader | Schema | TTL | User-scoped? | Article-scoped? | Clear | Risk |
|-------------|--------|--------|--------|-----|--------------|-----------------|-------|------|
| `seo-editor:draft:{hash}:{siteId}:{articleId}` | saveDraft | loadDraft | HTML/meta blob | none | **no** (browser profile) | yes (+site+hash) | save/clear | multi-user same browser collision |
| legacy draft keys | migrate | load | older | none | no | partial | eviction | stale overwrite |
| `seo_article_history_{id}` | history hook | history | history | none | no | yes | clear | growth |
| `seo_article_outline_{id}` | outline | outline | outline | none | no | yes | clear | — |
| `seo_article_chat_{id}` | chat | chat | chat | none | no | yes | clear | — |
| `seo_article_faq_{id}` | FAQ | FAQ | faqs | none | no | yes | clear | vs Livewire |
| `seo_featured_image_{id}` | featured util | health/UI | url/meta | none | no | yes | clear | **multi-owner** |
| `seo_product_album_list_{id}` | album util | gallery | list | none | no | yes | clear | **multi-owner** |
| `seo_wp_category_ids_{id}` | categories | Alpine | ids | none | no | yes | — | vs wire |
| `seo_article_excluded_link_suggestions_{site}_{id}` | Links | Links | ids | none | no | yes | — | — |
| `seo-cta-quick-templates:v1:*` | CTA | CTA | templates | none | no | no | — | shared |
| `seo-article-media-picker:*` | picker cache | picker | pages | none | no | yes | clear | stale media |
| session `seo-block-editor-h:{blockId}` | resize | resize | height | session | no | block | clearArticleLocalState | — |

**Conclusion:** localStorage is **primarily recovery cache + transient UI**, but featured/gallery/categories behave like **shadow SoT** until save — **mixed roles**. Not tenant/user-scoped beyond browser profile → two users on one OS profile can collide on same article id keys (mitigated somewhat by connectionHash/siteId on draft key only).

---

## 10. Backend editor API inventory

Auth: `$seoWebApiMiddleware` (authenticated SEO web/API). Prefix `api/seo/articles`.

| Method | Route | Controller | Purpose | Notes |
|--------|-------|------------|---------|-------|
| POST | `/{article}/save` | `ArticleEditorSyncController@save` | Persist content | conflict tokens; CP handoff stamp possible |
| POST | `/{article}/seo-meta` | `saveSeoMeta` | Meta only | |
| POST | `/{article}/sync-wp` | `syncWp` | Manual WP | **fail-closed** if CP membership |
| GET | `/{article}/editor/*` | `ArticleEditorLazyPayloadController` | seo-summary, images, faqs, meta, links, settings, media-picker-config | JSON lazy |
| GET/POST | outline* | `ArticleOutlineController` | Outline CRUD/gen | |
| POST | `/{article}/fix-media-slugs` | `ArticleEditorOperationController` | Local Fix Slug All | skips WP media |
| GET | `/{article}/operation-status` | OperationController | Job status | |
| GET/POST | product-reviews* | review controllers | Reviews | WP side effects |
| GET | revisions* | revision controllers | History | |
| POST | `/api/seo/media/wordpress/rename*` | `WordPressMediaRenameController` | Explicit WP rename | strong confirm |
| GET | `/seo/articles/{article}/media-picker` | HTML picker | Blade/Livewire adjacent | |
| GET | preview / seo-preview | preview controllers | Preview | |

**Flags:** Save JSON + idempotent-ish with conflict guard (not true idempotency key). **No** document_version / edit-session lock yet. Cache write lock only. WP side effects on sync-wp / rename / media Livewire methods.

---

## 11. Domain service dependencies

| Dependency | Class examples | Class |
|------------|----------------|-------|
| Article persistence | `UpdateArticleContentAction`, persist services | **B** Shared domain |
| Conflict | `ArticleContentConflictGuard` | **B** |
| Write mutex | `ActionSupport::withArticleLock` | **B** |
| Content Project | `ContentProjectArticleMembership`, handoff service | **B** |
| Manual WP sync | `WordPressManualSyncService` | **B** |
| Publishing | publish Blade + sync queue | **B** |
| Media / gallery | `SeoMedia*`, Livewire EditArticle media methods | **B** + **C** UI |
| WP rename | `WordPressMediaRenameService` | **B** |
| SEO scoring registry | `SeoScoringRulesRegistry` + client analyzer | **B** + **A** client |
| Prompts/AI | Prompt hooks execute API, FAQ AI Livewire | **B** / **D** Livewire |
| Permissions | `SeoAccessControl` | **B** |
| Localization | client `i18n.js` vs PHP `__()` | **C** / split |
| Site connection | panel `connection_hash` | **B** |

A = editor-only UI; B = shared domain; C = shared UI; D = legacy/compat bridge.

---

## 12. Content Project and archive contract

**Code-backed:**
- Membership: `ContentProjectArticleMembership::belongsToContentProject` via `SeoProjectTask` by `article_id` (**includes archived projects** — no `whereNull('archived_at')` on assigned task).
- Active membership: separate `belongsToActiveContentProject` / `activeTaskForArticle`.
- Editor UI: `ArticleResource::articleIsInContentProject` → hide Sync WP; show Save & Close + `data-seo-content-project-url`.
- API: `WordPressManualSyncService` → `content_project_manual_sync_forbidden` (fail-closed even if archived).
- Archive project: workspace destroy in transaction; restore clears `archived_at`, **does not** rebuild workspace (`workspace_reused: false`).
- Save/Save&Close do **not** mark article Published; only successful WP publish lifecycle does (module SoT).

**Canonical rules (aligned with CONTENT_PROJECTS + tests):**
- Active CP owns publishing workflow.
- Articles in CP (incl. archived membership) cannot manual Sync WP.
- Archive = destroy/reset workflow; not “independent article”.
- Restore ≠ reuse old queue/runtime.

**Discrepancy to watch:** UI “in project” helpers may also match rewrite/improve `source_content` paths (`ArticleResource`) — confirm vs membership service for edge cases (open question if dual definitions diverge).

---

## 13. Media and image health inventory

| Concern | Where | Notes |
|---------|-------|-------|
| Content image count | React blocks + Images module | live |
| Valid / integrity | `assistantWidgetHealth.analyzeImageRowsHealth` | empty/blob/incomplete = error |
| ALT missing | same | warning |
| Local slug placeholder | same | warning; Fix Slug All local only |
| WP filename ≠ keyword | **not** warning | protected bulk rename |
| Image ratio | SEO metrics → `image_ratio_*` as **info** | not integrity red |
| Featured / gallery | separate health builders | LS + props |
| Fix Slug All | React + `fix-media-slugs` + Livewire rename reject WP | BE fail-closed |
| Single WP rename | `WordPressMediaRenameModal` + rename API | strong confirm |
| Stale after F5 | health recomputed from DOM/LS; featured LS may diverge from DB until hydrate | |

**Duplicate evaluators:** client widget health vs SEO analyzer image rules vs PHP slug fix services — keep both until shared policy package exists.

---

## 14. SEO analysis inventory

Client `RULE_KEYS` / `computeSeoAnalysis` (`seoAnalyzer.js`); present via `presentSeoReason` (`seoReasonMetrics.js`); PHP `SeoScoringRulesRegistry` mirrors keys.

| Code | Emitter | Formatter | Severity (typical) | UI |
|------|---------|-----------|--------------------|----|
| `missing_focus_keyword` | client/PHP | presentSeoReason | error/warn | SEO widget |
| `h2_missing` | client | … | warn | SEO |
| `content_length_low` | client | SAFE_FALLBACKS | warn | SEO |
| `image_ratio_missing/poor/low/suboptimal` | client metrics | … | info/recommend | SEO + Images info |
| `image_alt_missing` | client / health | … | warn | Images/SEO |
| `wiki_trust_missing` | needs site/trust data | … | warn | SEO |
| `faq_missing` | client | … | warn | SEO/FAQ |
| `keyword_missing_in_*` | client | … | warn | SEO |
| `featured_snippet_*` | client | … | warn | SEO |

**Client-capable:** word count, headings, links, images, keyword occurrence in title/meta/slug/intro.  
**Needs backend/external:** wiki trust, WP attachment existence, cross-site usage, workflow/permissions, provider scores.

Thresholds: settings/lazy seo-summary + registry — not SSR bootstrap (per ARTICLE_EDITOR.md).

---

## 15. Livewire morph / remount risk map

| Region | wire:ignore? | Risk |
|--------|--------------|------|
| `#seo-article-editor-root` | **yes** | TipTap safe from morph **if** ignore honored |
| Portal roots (seo/images/…) | **yes** | same |
| Page actions | `wire:ignore.self` | partial |
| Publish sidebar / categories | **no** | morph OK (Alpine) |
| Media picker modal | ignore on wrapper | mixed |
| Full page Livewire navigated | remount React intentionally | **TipTap destroy** |

Actions that touch Livewire (may morph chrome, not ignored roots): FAQ save/extract, attachment rename/meta, media picker confirm, publish apply, heavy AI actions, sync lock props.

Props into React after mount: mostly events + lazy fetch — **not** Livewire public full HTML snapshot (SoT).

---

## 16. Build and asset inventory

| Item | Path |
|------|------|
| Editor entry | `vite.config.js` → `article-editor.jsx` |
| CSS | imported `article-editor.css`; page also `article-edit-page.css` |
| Media picker bootstrap | `article-media-picker-cache-bootstrap.js` |
| Lazy chunks | Seo/Images/Reviews/Links/Faq/AiChat modules |
| Manifest | shared app Vite manifest |

**Readiness:** editor **already** dedicated entry; still imports shared utils/i18n/media. Independent build **not** ready until event bus and shared CSS graph cleaned (Phase 6). Risk of loading editor on unrelated pages: primarily edit view `@vite` push — verify no global layout accidental include (open if layout pushes entry).

---

## 17. Test inventory

| Behavior | Existing test | Quality | Missing |
|----------|---------------|---------|---------|
| Core bootstrap lazy | `ArticleEditorCoreBootstrapContractTest`, BladeLazy, BootstrapSizer | good contract | runtime E2E |
| Conflict tokens | `ArticleEditorSyncConflictInputTest` | good | multi-tab E2E |
| Local draft interval | `ArticleEditorHistoryLocalDraftIntervalTest` | unit | recovery E2E |
| CP save / Sync guard | `ContentProjectEditorLocalSaveTest`, MediaHealthSyncContract | good | — |
| Archive membership Sync | workspace destroy + membership tests | good | — |
| Mount no remote WP | `ArticleEditorMountNoRemoteWpTest` | good | — |
| Rich text / health tick | `ArticleEditorRichText3eContractTest` | contract | browser caret |
| WP rename / health | `WordPressMediaSafeRenameContractTest` | contract | — |
| wire:ignore | **none** | — | **needed** |
| Collaborative lock | **none** | — | Phase 1 |
| Autosave server | N/A (by design local) | — | if server autosave added |
| Browser/E2E | not found as standard | — | Playwright/Cypress? open |

---

## 18. Edit lock readiness analysis

**Today (Phase 1 + 1.1):** `article_editor_sessions` + `articles.document_version`; APIs acquire/heartbeat/document/close/takeover; React owns session state + TipTap `setEditable`; shell consumes `article-editor-session-state-changed`; Livewire body persist session-aware; external AI/revision/media rewrite blocked when locked. See [`ARTICLE_EDITOR_SESSION_LOCK.md`](ARTICLE_EDITOR_SESSION_LOCK.md).

Remaining separation debt: Categories LS/Alpine; full event-bus schema; SEO/image-ratio Phase 2B; caret E2E.

Cache write lock (`ActionSupport::withArticleLock`) still serializes short races only.

### Contract

| Endpoint | Maps onto |
|----------|-----------|
| `POST .../editor-sessions` acquire | `ArticleEditorSessionService::acquire` |
| `PUT .../heartbeat` | touch lock TTL |
| `PUT .../document` autosave/explicit | session + version + persist |
| `POST .../close` atomic save+release | save then release in TX |
| `POST .../takeover` | manager+; audit via `RuntimeLogger` |

**Reuse:** `ArticleContentConflictGuard` (+ `expected_document_version`); `ActionSupport::withArticleLock` for race serialize; `UpdateArticleContentAction`; CP archive revokes sessions.

---

## 19. Proposed ownership boundary

### React owns
Document editing, caret/selection, active editor/section, collapse UI, undo/redo, sidebar/modals, local immediate analysis, dirty, recovery cache, lock heartbeat client, read-only/conflict UI.

### Laravel owns
Auth/permission, persistence, document version, edit lock authority, CP ownership, archive/restore, WP sync, media persist/rename, publishing, audit, canonical validation at save/publish.

### Shared contract
Bootstrap schema, analysis policy + reason codes + locale keys, media snapshot schema, document schema, error codes, version/lock protocol.

---

## 20. Proposed bootstrap contract

Evolve `getEditorCoreBootstrap()` toward a single JSON document (field names may match existing keys):

```json
{
  "article": {
    "id": 9629,
    "document_version": null,
    "content_revision": "<existing sha>",
    "updated_at": "...",
    "expected_updated_at": "...",
    "expected_content_hash": "...",
    "document": { "html_or_blocks": "..." },
    "title": "...",
    "slug": "...",
    "focus_keyword": "...",
    "meta_description": "..."
  },
  "editor_session": {
    "session_id": null,
    "lock_status": "unlocked"
  },
  "workflow": {
    "belongs_to_content_project": true,
    "content_project_id": 14,
    "content_project_status": "active|archived",
    "manual_wp_sync_allowed": false,
    "return_url": "https://.../seo/.../content-projects/14"
  },
  "permissions": {},
  "analysis_policy": {},
  "media_snapshot": {},
  "contacts": {},
  "endpoints": {},
  "settings": { "autosave_interval_seconds": 2 },
  "locale": "vi"
}
```

Goal: React must not scrape Livewire public properties for SoT.

---

## 21. Migration plan (proposal only)

### Phase 1 — Lock/version contract
- DB: `document_version` (+ `editor_sessions` table).
- APIs acquire/heartbeat/document/close/takeover.
- React read-only when locked by other; keep UI shell.
- Risk: dual conflict systems during rollout. Rollback: feature flag off. Gate: conflict + lock unit tests.

### Phase 2 — React root ownership
- Strengthen `wire:ignore`; freeze remount policy; bootstrap-only hydrate.
- Risk: broken Livewire media modal. Rollback: restore bridge events. Gate: mount + morph contracts.

### Phase 3 — API save/media/actions
- Move FAQ/media/featured/gallery mutations off Livewire events to JSON APIs.
- Risk: large surface. Gate: per-action contract tests.

### Phase 4 — Client immediate analysis
- Shared reason schema package; kill Livewire analyze bridge.
- Gate: SeoAnalyzer parity tests.

### Phase 5 — Remove duplicate state
- Delete dead presenters/events/LS shadow SoT.
- Gate: grep zero consumers.

### Phase 5A — JSON persistence (canonical document)
- Persist TipTap `article_document` envelope on `articles.editor_document`; derive `body` HTML.
- Dual-write + lazy backfill + stale invalidation for legacy body writers.
- Docs: [`ARTICLE_EDITOR_JSON_PERSISTENCE.md`](ARTICLE_EDITOR_JSON_PERSISTENCE.md).
- Gate: `ArticleEditorJsonPersistencePhase5aTest` + remote migrate/build.

### Phase 6A — Internal editor runtime
- Built-in module registries (sidebar/toolbar/extensions/health/commands meta).
- TipTap extensions via runtime cache; Phase 4 commands unchanged.
- Publishing stays Laravel shell.
- Docs: [`ARTICLE_EDITOR_RUNTIME.md`](ARTICLE_EDITOR_RUNTIME.md).
- Gate: `ArticleEditorRuntimePhase6aTest` + remote `npm run build`.

### Phase 6B — Deeper module extraction / SDK boundary
- Move panel JSX into slots; deprecate leftover CustomEvents.
- Public editor SDK only with separate ADR (not Extension SDK v1.0 merge).

### Phase 6C.1 — React navigation ownership (done)
- Dock chips/search/health: `EditorSidebarNavigation` + runtime registry / health store.
- Alpine `seoAssistantNavigator`: read-only panel visibility mirror only.
- Blade: mount roots; no chip list SoT.
- Publishing/Article: shell boundary items, not runtime registry.
- Docs: [`ARTICLE_EDITOR_SHELL_BOUNDARY.md`](ARTICLE_EDITOR_SHELL_BOUNDARY.md).
- Gate: `ArticleEditorRuntimeNavigationPhase6c1Test`.

### Phase 6C.2 — Links/FAQ/CTA runtime modules (done)
- Links/FAQ `host: editor` panels; CTA chip aliases Links; insert via command host actions.
- FAQ extract REST `faq-snapshot/extract`; toolbar `runFaqExtractFromToolbar`.
- ModuleHost removed; AI Chat via runtime module.
- Gate: `ArticleEditorRuntimeModulesPhase6c2Test`.

### Phase 6C.3 — Featured/Gallery + Shared Media Picker (done)
- React owns Featured/Gallery panels; one Shared Media Picker portal; Alpine drafts/modal removed; snapshot versioning + WP rename protection preserved.

### Phase 6C.4 — AI cutover + host contracts (done)
- AI Chat runtime module; `ArticleEditorModuleHost` removed; scoped host hooks + contract v1; shell bridge finalized; Alpine dead picker state removed. See [`ARTICLE_EDITOR_RUNTIME_COMPLETION.md`](ARTICLE_EDITOR_RUNTIME_COMPLETION.md).

### Post-6C
- Broader CustomEvent cleanup; public SDK not ready.

### Phase 6 — Optional independent Vite build
- Only after import graph clean.
- Gate: isolated build CI + route loads only edit.

---

## 22. Do not remove yet

- Compatibility redirect routes (`wp-edit-redirect`).
- Backend conflict validation + Cache write lock.
- CP Manual Sync fail-closed + archive workspace destroy.
- WordPress sync / rename audit services.
- Server-side SEO violation persistence.
- localStorage draft recovery until server autosave+lock stable.
- Publish lifecycle separation (Save ≠ Published).

---

## 23. Open questions

1. Can one article belong to **multiple** `SeoProjectTask` rows simultaneously in production data, and which wins for return URL?
2. Which **workers/jobs** mutate `articles.body` outside the editor (automation pipeline list complete)?
3. Is `beforeunload` / `sendBeacon` implemented for dirty guard, or only draft LS?
4. Does WordPress bridge plugin expose any **lock hint** for media/content (none found in editor path)?
5. Is there an official **browser E2E** harness for SeoContentAi (Playwright/Dusk), or only PHPUnit contracts?
6. Do `ArticleResource::articleIsInContentProject` and `ContentProjectArticleMembership` ever **disagree** on rewrite/improve source_content matching?
7. Should Save&Close ever flush featured/gallery LS explicitly if user never opened those panels?

---

## Related documents

- `docs/modules/ARTICLE_EDITOR.md`
- `docs/modules/CONTENT_PROJECTS.md`
- `docs/modules/MEDIA_AND_GALLERY.md`
- `docs/modules/PUBLISHING.md`
- `docs/modules/WORDPRESS_BRIDGE.md`
- `docs/modules/PROMPTS_AND_AI.md`
- Archive only: `docs/archive/maps/MAP_SEO_EDITOR.md`

## Legacy cleanup

See [ARTICLE_EDITOR_LEGACY_CLEANUP.md](ARTICLE_EDITOR_LEGACY_CLEANUP.md) for deleted/kept/deprecated paths after runtime cutover.
