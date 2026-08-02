# Article Editor

> Status: Canonical  
> Owner: SeoContentAi  
> Last verified: 2026-08-01  
> Supersedes: `docs/archive/maps/MAP_SEO_EDITOR.md`, `MAP_SEO_EDITOR_SCORING.md`, `MAP_SEO_FRONTEND.md` (editor cluster), `docs/archive/media-editor/image-slug-rename.md`

## 1. Purpose

EditArticle = Filament Livewire shell + React TipTap editor for `SeoArticle` on `omi_seo_ai`.

- Local save / conflict / SEO score / modules (Links, FAQ, Images, Reviews, AI).
- Sync WP and Publish are **separate** concerns (bridge / Content Projects) — editor may trigger Sync WP; CP Publish is not the editor save path.
- Laravel article = working copy. Outbound must not trash/delete WP posts.

## 2. Canonical routes

Panel prefix: `/seo/{connection_hash}/`

| Path | Page / API |
|------|------------|
| `articles` | `ListArticles` (tabs: posts / categories / queue / reviewed / skipped) |
| `articles/{record}/edit` | `EditArticle` |
| `articles/queue` | `ListArticleSyncQueue` |
| `GET/POST .../editor/*` | Lazy editor payloads (`ArticleEditorLazyPayloadController`) |
| `POST /api/seo/articles/{id}/seo-meta` | SEO meta save (no Livewire) |
| `POST /api/seo/articles/{id}/fix-media-slugs` | Batch image slug fix |
| `POST /api/seo/prompt-hooks/{hookKey}/execute` | Title / meta prompt hooks |

Route binding: edit/view **does not** 404 when global domain ≠ `article.site_id` (`includeGlobalSiteScope: false`). List still scopes.

## 3. Main components

| Concern | Class / file |
|---------|----------------|
| Livewire page | `Filament/Resources/ArticleResource/Pages/EditArticle` |
| Blade host | `edit-article.blade.php` — `#seo-article-editor-root` + `#seo-article-core-bootstrap` |
| Vite entry | `resources/js/article-editor.jsx` |
| React core | `SeoArticleEditor.jsx` + `ArticleEditorModuleHost.jsx` |
| Persist | `ArticleEditorPersistService` |
| Content update | `UpdateArticleContentAction` + `ArticleContentConflictGuard` |
| SEO meta API | `ArticleEditorSeoMetaService` |
| SEO payload | `ArticleEditorSeoPayloadService` |
| Links payload | `ArticleEditorLinksPayloadService` |
| Scoring registry | `Support/SeoScoringRulesRegistry` |
| Scoring engine | `Services/SeoScoringEngine` + `SeoScoringCalculator` |
| Client analyzer | `seoAnalyzer.js` + `seoScoreCalculator.js` + `SeoScorePanel.jsx` |
| Score job | `AnalyzeArticleSeoJob` via `SeoArticleScoringQueueService` |
| Violations | `SeoRuleViolationsResolver` / `SeoAnalyzerService` |
| FAQ matcher | `Support/FaqHeadingMatcher` |
| Last change stamps | `ArticleLastSavedTimestampService` / `ArticleLastContentChangeResolver` |
| HTTP logging | `App\Support\RuntimeLogger` |
| Slug fix | `SeoMediaArticleSlugFixService` + `SeoMediaUrlReplacementService` |
| Sticky header bridge | `articleEditorStickyHeader.js` |
| AI History (manual recovery) | `ViewArticlePrompts` + `Services/ArticleAiHistory/*` — preview/apply/delete typed artifacts into editor draft |
| Insertion context (transient) | `resources/js/utils/editorInsertionContext.js` — `activeSectionId` / `activeBlockId` / selection bookmark; used by CTA, link, media assistants |
| CTA / Contact sidebar | `CtaContactInsertList.jsx` + `DomainCtaEditorService` — usable contacts only; insert value / quick CTA sentence (deterministic templates, no AI) |
| Quick CTA templates | `Support/CtaQuickTemplates` + `SeoDomainCtaGlobalSettingsService::cta_quick_templates` (+ localStorage editor override) |
| Assistant widget health | `resources/js/utils/assistantWidgetHealth.js` + `Support/AssistantWidgetHealthRules` — dock status `error\|warning\|success\|neutral`, reasons, click-to-fix |
| SEO reason metrics | `resources/js/utils/seoReasonMetrics.js` + `Support/SeoReasonPresentation` — `image_ratio_*` / `content_length_low` with current/recommended/missing; locale `lang/{vi,en}/seo_rules.php` |
| CTA block insert | `insertCtaBlockInEditor` → `<p class="article-cta">` + label/value; `unsetAllMarks` / lift blockquote |
| CTA freeze bookmark | `freezeEditorInsertionContext` on CTA `pointerdown` + `seo-assistant-freeze-insertion-context`; insert uses frozen caret |
| Content image census | `contentImageCounter.js` — body image-blocks + inline `<img>`; featured/gallery excluded |
| Orphan quote fix | `orphanQuoteNormalizer.js` — move quote chars outside `</p>` back into editable paragraph |
| Link unlink / boundary | `editorLinkCommands.js` (`removeLinkKeepText`, `exitLinkAtBoundary`); Link mark `inclusive: false` |

### Editor UX invariants (context preservation)

- Section React keys = stable `section-${headingBlockId}` / `section-intro`; block keys = `block.id`.
- Expanded/collapsed state is keyed by stable section id; **not** reset when article content / image blocks mutate.
- Opening/closing Media Picker or mutating an image block must **not** collapse other sections or jump viewport to FAQ/end.
- `focusImageBlock` expands the target section only (no `collapseSectionsExcept`); outline/link jumps may still isolate a section intentionally.
- Sidebar CTA/link insert uses saved `EditorInsertionContext` bookmark **before** sidebar steals focus — never silent fallback to first section / first TipTap instance when active context exists.
- Insertion priority: saved bookmark → end of active block → end of active section → empty-editor fallback. Never append end of article while active context exists.
- Assistant dock chips show health status (label color + issue badge + tooltip reasons); click error opens panel and focuses fix target without collapsing unrelated sections.
- Widget health refreshes after keyword/link/image/featured/gallery mutations (no full page reload).
- SEO score reasons never render raw snake_case keys; `image_ratio_low` / `content_length_low` expose concrete missing counts from checker metrics.
- CTA / Contact UI shows only **usable** contacts (no unresolved `[email_1]` placeholders); header count matches usable rows.
- Quick CTA = template resolve only (no AI run / prompt / usage log).

## 4. Data ownership

| State | Source of truth | Not SoT |
|-------|-----------------|---------|
| Body / title / slug | `articles` row (+ meta for WP body when needed) | Livewire public snapshot of full HTML |
| Review | `articles.review_status` (+ `reviewed_at`) | Dropped `is_reviewed` |
| Skip list/audit | `article_meta.skip_seo_audit` | Soft-delete alone |
| SEO violations | `article_meta.seo_rule_violations` | Client score without server persist |
| Display score | Recomputed from violations + current registry deductions | Stale `seo_score` alone for UI truth |
| Conflict tokens | `updated_at` + content hash | Force overwrite without `canForceArticleContentOverwrite` |
| Manual save stamp | `last_manual_saved_at` | Touching `updated_at` for CP row semantics |
| Featured / gallery | Meta + editor events | SSR-only product gallery gates |
| FAQ catch keywords | `SeoOverviewSettingsService::KEY_FAQ_CATCH_KEYWORDS` | Hardcoded VI/EN only when setting empty |

List tabs: posts/categories/queue exclude skip meta; reviewed uses `review_status` approved path; skipped = skip meta only.

## 5. Read path

1. `mount()` → `hydrateArticleState()` — **no** remote WP HTTP; body/featured from local meta; `wordpressMetadataStale` if `wp_post_id`.
2. SSR embeds **only** `getEditorCoreBootstrap()` (identity, content, conflict tokens, endpoints map, minimal settings). **No** scoring rules/messages in shell.
3. React reads bootstrap → mounts `SeoArticleEditor` (+ optional AI FAB root).
4. Lazy HTTP after idle / panel open: `/editor/seo-summary`, `/editor/settings`, `/editor/links`, `/editor/images`, `/editor/faqs`, media-picker-config.
5. Existing links = **client document scan** (not DB body alone).

Policy: max **one** heavy sidebar module mounted; switch unmounts (no CSS-hide tree keep).

## 6. Write path

### Local persist

```text
JS collect HTML → editor-html-collected → Livewire persistArticleLocal
  → ArticleEditorPersistService::writeArticleRow (short TX)
  → runAfterPersistSideEffects (images / revision / keyword after commit)
    · `syncContentProjectScheduledPublish` **skipped** while task `writing|pending|processing`
      (AI persist must not assert Schedule while lifecycle=generating)
  → dispatch AnalyzeArticleSeoJob (force) when content scoring inputs change
```

SEO modal: `POST .../seo-meta` → `ArticleEditorSeoMetaService` (queues score; not Livewire).

Save payload SEO analysis: **violations (+ extracted_links) only** — never send fixed score/breakdown.

Conflict: hash match allows pass despite `updated_at` skew. Force overwrite: `SeoAccessControl::canForceArticleContentOverwrite()` (actualRole rank > content_manager).

### Sync WP (editor)

`syncArticleToWordPress` / bridge outbound — see `WORDPRESS_BRIDGE.md`. Toast `wp_sync_blocked` often means persist failed before enqueue.

### Fix slug all

1. Save editor (`before_fix_slug_all`).
2. `SeoMediaArticleSlugFixService` (+ optional WP rename).
3. Apply exact `renamed[]` map to TipTap/blocks (not DOM-only).
4. Invalidate picker / gallery / featured caches.
5. Save again (`after_fix_slug_all`).

### Review actions

Approve / unreview via `ArticleReviewService` / resource helpers — SoT `review_status`.

## 7. Public capabilities

Editor itself is Filament/user UI — not MCP write surface.

Related public:

- Prompt hooks execute API (authenticated SEO session).
- Media upload/rename APIs used by editor (`MEDIA_AND_GALLERY.md`).
- CP assign from editor keyword anchor → pending link services.

## 8. Internal-only capabilities

- `getBootstrapEditorHtml()` — not Livewire public snapshot.
- `ArticleEditorPerfDebug` / bootstrap sizer (`ARTICLE_EDITOR_PERF_DEBUG`).
- Heavy AI generate Livewire methods (`executeHeavyArticleAction`, image/video/FAQ generate).
- Markdown debug import parsers.
- Polylang quick-translate helpers.

## 9. Authorization and confirmation

- Panel mutate: `SeoAccessControl::canMutateInSeoPanel()`.
- Sync WP: `canSyncArticlesToWordPress()` (Planner+).
- Force content overwrite: rank above content_manager.
- Site access: `canAccessSite` / accessible article query — global site header is **not** edit authorization.
- Admin foreign connection: read-only panel.

## 10. Queue and scheduler ownership

| Trigger | Job / effect |
|---------|----------------|
| Persist / seo-meta | `AnalyzeArticleSeoJob` |
| AI media generate | `GenerateMediaJob` (`media_generation` queue) |
| Quick post reviews | `GenerateArticleReviewsJob` |
| CP full rewrite from editor menu | `ArticleWritingExecutionService` path (not Publish graph) |

No second scheduler for editor autosave — client debounce + Livewire/REST.

## 11. Transactions and side effects

- Short row lock on `articles` write; retry InnoDB 1205/deadlock ×3.
- Side effects **after** commit (images, revision, keyword).
- Score: persist violations → denormalized `seo_score`; UI still recomputes from registry.
- Manual save stamp via `touchManualSaved`; sync → `touchSynced`; AI body hash change → `touchAiContent`. FAQ/meta/image-only does not stamp AI content.
- Reviewed path may delete local media (see media module) — not on every sync.
- Paste clipboard image: random `paste-{hex}` slug; clear stale WP attachment ids on block.

## 12. Retry and recovery

- Persist lock timeout → user-friendly message; do not enqueue WP sync.
- Score job unique per article; domain queue missing/retry failed.
- AI media: retry-generation / delete-ai-job endpoints.
- Slug fix: never invent second rename pipeline; recovery only if file already renamed.
- **Editor does not own full workflow rerun.** Menu «Chạy lại quy trình» (from-outline / from-article modal) removed. Retry/resume stays on Content Project.
- After editor save, emit `project-item-updated` + `sessionStorage` dirty flag so Content Project ops page can **lazy-refresh** summary (no websocket/poll). Opening a generated article from project **Needs Review** (presentation filter) marks that generation viewed — not a lifecycle change.
- **Content Manager canonical Save** (`POST .../save`, origin `article_editor`): after successful `article.content.update`, `ContentProjectContentManagerHandoffService` stamps reporting In Review (`content_manager_reviewed_at` / `content_manager_reviewed_by`) **once** — **no** lifecycle / `SubmitReview` / task `reviewing`. Autosave/local draft does not. Planner/Manager Save does not stamp. Response may include `content_project_handoff`.
- Content Manager ops UI is edit-only (Draft / Needs Review / In Review + Total badge); no Generate/Queue/Retry/Approve/Schedule/Publish. Planner **Send to Publishing Queue** handoff — Sync/Save ≠ Publish.
- **Save/Sync ≠ Published:** editor `articles.status=published` must not drive Content Project lifecycle Published. Only real WordPress publish success (`publish_published_at` / queue published) bumps Published.
- **Lịch sử AI** (`/{article}/prompts`): manual preview / apply typed artifacts (`article_outline` | `article_content`) into editor draft/session. Apply does **not** auto-save, publish, sync WP, or change generation/run status. Outline and content are independent targets. Pending draft in `article_meta.seo_ai_history_pending_draft`; provenance committed on article save via `ArticleAiHistoryApplyService::commitPendingOnSave`.

## 13. Compatibility paths

- Legacy `GET .../editor-seo-payload` — links must not use as primary.
- Violation resolver legacy Rank Math / scoring_details shapes.
- FAQ activate events: `article-editor:module-open` + compat `seo-faq-panel-activate`.
- List tab Reviewed / skip share flags with ArticlesOptimal.
- Core `SeoEngineService` wrapper for older callers.

## 14. Forbidden paths

1. Embed full scoring rules/messages in SSR core bootstrap.
2. Analyze SEO by parsing HTML inside Audit-style request from editor list (use job).
3. `Log::` / `report()` on HTTP editor paths — use `RuntimeLogger` → `web_app`.
4. Change `LOG_CHANNEL` to `web_app` in `.env` (breaks cron).
5. DOM-only slug rewrite without TipTap document + post-rename save.
6. Second rename pipeline outside `SeoMediaArticleSlugFixService` / WP rename + URL replacement.
7. Treat editor Sync WP as Content Project Publish / schedule stamp.
8. Treat editor Save / Sync as lifecycle Published (Save≠Publish).
9. Reintroduce `is_reviewed` column as SoT.
10. Livewire round-trip solely to open media/help/modals (Alpine first).
11. Mount multiple heavy React modules concurrently.
12. Reintroduce Editor «Chạy lại quy trình» full-pipeline modal; use Content Project retry + AI History apply instead.
13. Apply outline artifact into article body, or content artifact into outline editor.

## 15. Tests and invariants

| Test / area | Invariant |
|-------------|-----------|
| `RuntimeLoggerWebAppChannelTest` | HTTP → `web_app`; no laravel.log fallback |
| `ArticleReviewServiceTest` / cutover | `review_status` SoT |
| Scoring unit / audit integration | Deduction registry; audit reads cache |
| Editor performance audits | Bootstrap size budgets (docs/audits) |
| `ArticleEditorContextPreservationContractTest` | Media/image UX không reset expanded sections; CTA dùng insertion bookmark; WP media site-level |
| `CtaContactUsabilityAndQuickTemplatesTest` | Filter placeholder; resolve/validate quick CTA templates |
| `SeoReasonPresentationAndAssistantHealthTest` | image/content metrics; locale keys; links min 5; focus keyword health |
| `ArticleEditorRichText3eContractTest` | CTA paragraph; unlink keep text; quote CSS; images badge; featured snapshot; stable recommendation |

Manual verification (remote):

```text
$PHP_BIN vendor/bin/phpunit --filter=RuntimeLoggerWebAppChannelTest
$PHP_BIN vendor/bin/phpunit --filter=ArticleReviewServiceTest
$PHP_BIN vendor/bin/phpunit --filter=ArticleEditorContextPreservationContractTest
$PHP_BIN vendor/bin/phpunit --filter=CtaContactUsabilityAndQuickTemplatesTest
$PHP_BIN vendor/bin/phpunit --filter=SeoReasonPresentationAndAssistantHealthTest
$PHP_BIN vendor/bin/phpunit --filter=ArticleEditorRichText3eContractTest
$PHP_BIN vendor/bin/phpunit --filter=ArticleEditorCtaMediaQuoteFixContractTest

npm run build
```

## 16. Related documents

- [MEDIA_AND_GALLERY.md](MEDIA_AND_GALLERY.md) — upload, watermark, WP media sync
- [SEO_AUDIT_AND_KEYWORDS.md](SEO_AUDIT_AND_KEYWORDS.md) — score cache consumers
- [CONTENT_PROJECTS.md](CONTENT_PROJECTS.md) / [PUBLISHING.md](PUBLISHING.md)
- [WORDPRESS_BRIDGE.md](WORDPRESS_BRIDGE.md)
- [DATA_AND_RUNTIME_BOUNDARIES.md](../architecture/DATA_AND_RUNTIME_BOUNDARIES.md) — RuntimeLogger
- Archive: `docs/archive/maps/MAP_SEO_EDITOR*.md`, `MAP_SEO_FRONTEND.md`

### Scoring model (durable)

`score = max(0, 100 - sum(deductions))`. Display always from current violations + registry. Key deductions include `missing_focus_keyword` (100), `h2_missing` (20), `content_length_low` (15), image/FAQ/snippet/keyword-in-* rules.

### Vite editor roots

1 main `#seo-article-editor-root` + optional `#seo-article-ai-launcher-root`. Sticky header hides Filament topbar via `body.article-editor-page` only on Edit Article.
