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
| `POST /api/seo/articles/{id}/fix-media-slugs` | Batch **local/safe** image slug fix (WP media skipped) |
| `POST /api/seo/media/wordpress/rename[/preview]` | Explicit WordPress media rename (strong confirm) |
| `POST /api/seo/prompt-hooks/{hookKey}/execute` | Title / meta prompt hooks |

Route binding: edit/view **does not** 404 when global domain ≠ `article.site_id` (`includeGlobalSiteScope: false`). List still scopes.

## 3. Main components

| Concern | Class / file |
|---------|----------------|
| Livewire page | `Filament/Resources/ArticleResource/Pages/EditArticle` |
| Blade host | `edit-article.blade.php` — `#seo-article-editor-root` + `#seo-article-core-bootstrap` |
| Vite entry | `resources/js/article-editor.jsx` |
| React core | `SeoArticleEditor.jsx` + `EditorSidebarPortalHost` / runtime modules |
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
| CTA / Contact sidebar | `CtaContactInsertList.jsx` + `DomainCtaEditorService` — usable contacts only; insert value (`--contact`) / sentence (`--sentence`) icon pair, fixed equal height |
| Quick CTA templates | `Support/CtaQuickTemplates` + `SeoDomainCtaGlobalSettingsService::cta_quick_templates` via `PUT /api/seo/domain-cta/quick-templates` (React form draft only) |
| Assistant widget health | `resources/js/utils/assistantWidgetHealth.js` — Images from **unified inventory**: `error` (`image_slug_unresolved`) / `warning` (`image_alt_missing`) / `info` (`image_ratio_low`, body content count only); Featured clean of ALT/slug/ratio; WP filename≠keyword is **not** an issue |
| Unified images inventory | `resources/js/utils/unifiedArticleImagesInventory.js` — content + Featured + Gallery dedupe; Images panel `useUnifiedInventory: true` |
| Media source classify | `resources/js/utils/mediaSourceClassification.js` — local `/storage/…/seo_media`, `seo_media_id`, local media markers, or pending version/revision evidence = **Laravel managed** and wins over stale `wp_attachment_id`; **WP only** = unmanaged `/wp-content/uploads/` / attachment with no Laravel ownership |
| Shared media picker | `resources/js/editor/host/SharedMediaPicker.jsx` + `ArticleMediaPickerController` / `WorkspaceMediaPickerController` — remote tabs paginate **28**/page (7×4); search/tab change resets to page 1 |
| Inline whitespace safety | `resources/js/utils/inlineWhitespaceGuard.js` + `InlineMarkBoundaryWhitespace` (PHP) — TipTap `preserveWhitespace: 'full'`; glued mark-boundary repair on bootstrap; save guard `inline_whitespace_corruption_detected` |
| Paragraph style dropdown | `ParagraphStyleDropdown.jsx` — menu portals to `document.body` (`position: fixed`); format toolbar row `overflow: visible` (no clip “Heading N” tab) |
| Fix Slug All | Local/safe media only (`mediaSourceClassification.js` — `https` alone ≠ WP); owning session via `editor_session_id` + `assertOwningActiveSessionForMediaMutation`. Response returns `document_version`/`content_hash`; client `syncVersionAfterSlugFix` before `after_fix_slug_all`. WP → explicit `WordPressMediaRenameModal`. |
| Exclusive lock gate | `ExclusiveLockScreen` in `article-editor.jsx` — mounts **instead of** TipTap when locked / not_editable **or** session fault (`article_editor_session_unavailable` / 5xx heartbeat / `unknown_error`). Mid-archive revoke may still surface `content_project_archived` until reload; **archived Content Project must not permanently block** Article Editor — post-archive articles are standalone (`ArticleEditorSessionService::assertArticleEditable` does not deny on project `archived_at`). Mid-session version conflict keeps editor writable (sync version; toast only). |
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
| Featured / gallery | Laravel `media_snapshot` + REST mutations | See [`ARTICLE_EDITOR_MEDIA_SNAPSHOT.md`](../architecture/ARTICLE_EDITOR_MEDIA_SNAPSHOT.md) |
| Immediate SEO analysis (editor) | React `composeImmediateArticleAnalysis` + Laravel `analysisPolicy` / `externalFacts` | Livewire `seo-analyze-result` (removed as UI SoT) — see [`ARTICLE_EDITOR_ANALYSIS_OWNERSHIP.md`](../architecture/ARTICLE_EDITOR_ANALYSIS_OWNERSHIP.md) |
| Editor document traversal | TipTap JSON `DocumentModel` + selectors | Raw HTML regex for analysis — see [`ARTICLE_EDITOR_DOCUMENT_MODEL.md`](../architecture/ARTICLE_EDITOR_DOCUMENT_MODEL.md) |
| Document mutations | `executeEditorCommand` command layer | Direct `editor.chain()` in widgets — see [`ARTICLE_EDITOR_COMMAND_LAYER.md`](../architecture/ARTICLE_EDITOR_COMMAND_LAYER.md) |
| Canonical editable document | `articles.editor_document` TipTap envelope; `body` derived HTML | HTML-only save as SoT — see [`ARTICLE_EDITOR_JSON_PERSISTENCE.md`](../architecture/ARTICLE_EDITOR_JSON_PERSISTENCE.md) |
| Editor module wiring | Runtime slots + `EditorSidebarPortalHost` / toolbar registry / nav API | Hard-coded panel switch in `SeoArticleEditor` — see [`ARTICLE_EDITOR_RUNTIME.md`](../architecture/ARTICLE_EDITOR_RUNTIME.md) |
| Editor dock navigation (6C.1) | React `EditorSidebarNavigation` + runtime `openPanel` / health store | Alpine chips/`activePanel`/health SoT — Blade mount roots only; Publishing shell boundary — [`ARTICLE_EDITOR_SHELL_BOUNDARY.md`](../architecture/ARTICLE_EDITOR_SHELL_BOUNDARY.md) |
| Links/FAQ/CTA modules (6C.2) | Runtime sidebar panels + command host actions; FAQ extract REST | Old `ArticleEditorModuleHost` Links/FAQ branches + insert CustomEvent — see [`ARTICLE_EDITOR_RUNTIME.md`](../architecture/ARTICLE_EDITOR_RUNTIME.md) + [`ARTICLE_EDITOR_LEGACY_CLEANUP.md`](../architecture/ARTICLE_EDITOR_LEGACY_CLEANUP.md) |
| Featured/Gallery + Shared Media Picker (6C.3) | React panels + `openMediaPicker` modes + media snapshot APIs | Alpine Featured/Gallery draft + Alpine media modal — see [`ARTICLE_EDITOR_MEDIA_SNAPSHOT.md`](../architecture/ARTICLE_EDITOR_MEDIA_SNAPSHOT.md) |
| AI Chat runtime (6C.4) | `article-editor.ai` + host generate actions; ModuleHost removed | Legacy ModuleHost — see [`ARTICLE_EDITOR_RUNTIME_COMPLETION.md`](../architecture/ARTICLE_EDITOR_RUNTIME_COMPLETION.md) |
| FAQ domain | Laravel `faq_snapshot` API (`seo_faqs`); React draft/preview | Livewire FAQ shadow / LS SoT — see [`ARTICLE_EDITOR_WIDGETS_OWNERSHIP.md`](../architecture/ARTICLE_EDITOR_WIDGETS_OWNERSHIP.md) |
| CTA quick templates | Laravel domain CTA settings API | localStorage CTA templates SoT |
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

### Edit session lock (Phase 1 / 1.1)

See [`ARTICLE_EDITOR_SESSION_LOCK.md`](../architecture/ARTICLE_EDITOR_SESSION_LOCK.md).

- Acquire writable session before edit; other tabs/users → **ExclusiveLockScreen** (no TipTap / no hard-readonly under lock).
- Archived Content Project is not an editor deny reason after archive completes; article is standalone while archive report keeps historical links.
- Heartbeat `PUT .../heartbeat`: `expireStaleSessionsForArticleId` is best-effort (retry then skip InnoDB 1205/1213) so deadlock must not 500 the owning session.
- Heartbeat/server 5xx → FE code `article_editor_session_unavailable` → ExclusiveLockScreen + notify + **Tải lại trang** (`editorSessionClient.js` / `article-editor.jsx`).
- Preparing gate: `ArticleEditorReadinessService` (processing AI media + body hash). `evaluate()` calls `reconcileStaleAiMediaJobs`; stuck jobs → `forceOpenEditorWhilePreparing` / `abandonPreparingGate` (EditArticle + blade CTA).
- Canonical guard: `expected_document_version` (`articles.document_version`).
- Compat: `expected_updated_at` + `expected_content_hash`.
- Explicit Save → session document endpoint; Save & Close → atomic `close`.
- Legacy `POST .../save` cannot bypass active session without owning session id.
- Livewire `EditArticle` persist requires owning `editorSessionId` and delegates `ArticleEditorPersistService` (no direct body update).
- Shell Save/Save&Close reactive-disable via `article-editor-session-state-changed`.
- Media body rewrite (Fix Slug) allowed for **owning** active session (`editor_session_id`); blocked for other sessions.
- Server autosave (debounced) + localStorage draft schema v3 (user-scoped).
- Featured/Gallery: immediate API persist + `media_snapshot` (no localStorage SoT).
- Immediate analysis (Phase 2B): React owns live checks; Laravel owns `analysisPolicy` / `externalFacts` + save/publish validation. See [`ARTICLE_EDITOR_ANALYSIS_OWNERSHIP.md`](../architecture/ARTICLE_EDITOR_ANALYSIS_OWNERSHIP.md).
- Article write mutex: `ActionSupport::withArticleLock` key `article-write:{id}` (process-local reentrancy; code `article_write_busy`).

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

Conflict: `document_version` primary; hash match allows pass despite `updated_at` skew. Mid-session version/hash conflict keeps editor writable (client syncs actual version; no ExclusiveLockScreen). Force overwrite: `SeoAccessControl::canForceArticleContentOverwrite()` (actualRole rank > content_manager).

### Sync WP (editor)

Two contracts:

| Action | When | Behavior |
|--------|------|----------|
| **Lưu bài** (`POST …/save`) | Always | Laravel only — `article.content.update`. Never WordPress. |
| **Đồng bộ WordPress** (`POST …/sync-wp`) | Non-CP: manual queue. CP **Published** (queue + `publish_published_at`): `SyncPublishedArticleToWordPressCommand` | Save local first, then **UPDATE** existing `wp_post_id`. Never `create_post`. |
| CP not yet Published | Sync button hidden / blocked | Initial WordPress create owned by Publishing Queue only. |

Existing/imported/rewrite posts are not record-level immutable. Explicit Sync WP is authorization to push Laravel title/body/SEO/slug/media fields unless `WordPressFieldConflictService` detects same-field concurrent changes against `wp_last_synced_field_snapshot` / `wp_latest_field_snapshot`. `wp_post_id` alone is not a conflict. Laravel slug remains editable and is sent as WP `post_name`; if WordPress canonicalizes it (for example duplicate slug suffix), Laravel stores the returned slug/permalink and surfaces a warning in the sync result.

Images panel labels: **Laravel managed** for rows with Laravel media record/local source/pending version markers; **WP only** for unmanaged WordPress attachments; **Conflict** only for verified same-field conflict. Laravel-managed media may sync alt/title/caption/description/attachment slug/featured-gallery assignment and pending binary replacement; WP-only media remains protected from automatic binary replacement.

Post-publish success toast: «Đã đồng bộ bài viết lên WordPress.» + «Mở bài WordPress». Failure keeps `publish_queue_status=published` (separate `post_publish_wp_sync_error` meta).

See `PUBLISHING.md` § post-publish sync. Toast `wp_sync_blocked` may mean persist failed or eligibility blocked.

### Fix slug all

1. Save editor (`before_fix_slug_all`).
2. `SeoMediaArticleSlugFixService` (+ optional WP rename) with owning `editor_session_id`.
3. Apply exact `renamed[]` map to TipTap/blocks (not DOM-only).
4. Sync `document_version` / `content_hash` from Fix Slug response (`syncVersionAfterSlugFix`) — body rewrite bumps version; avoid false Version conflict on next save.
5. Invalidate picker / gallery / featured caches.
6. Save again (`after_fix_slug_all`; one silent retry on version/hash conflict).

Conflict: `document_version` primary; hash match allows pass despite `updated_at` skew. Mid-session conflict does **not** unmount into ExclusiveLockScreen. Force overwrite: `SeoAccessControl::canForceArticleContentOverwrite()` (actualRole rank > content_manager).

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
| AI media generate | `GenerateMediaJob` (`media_generation` queue); dispatch failure marks placeholder `failed`; stale `processing` reconciled via `ArticleEditorMediaAiService::reconcileStaleAiMediaJobs` / `failAllProcessingAiMediaJobs` |
| Quick post reviews | `GenerateArticleReviewsJob` |
| CP full rewrite from editor menu | `ArticleWritingExecutionService` path (not Publish graph) |

No second scheduler for editor autosave — client debounce (local draft + server session document) + REST session APIs.

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
- AI media: retry-generation / delete-ai-job endpoints; editor preparing lock → reconcile stale + **Mở editor ngay** (`abandonPreparingGate`).
- Session heartbeat expire sweep: skip after deadlock retries (`sessions_expire_skipped_lock`); FE surfaces reload on 5xx.
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
| `ArticleEditorContextPreservationContractTest` | Media/image UX không reset expanded sections; CTA insert `--contact`/`--sentence`; WP media site-level |
| `ArticleEditorExclusiveLockRegressionTest` | ExclusiveLockScreen gate; sessionStorage client id; no takeover; conflict ≠ exclusive screen |
| `ArticleEditorArchivedContentProjectStandaloneTest` | Archived CP ≠ block editor; membership active-only; archive preview keeps historical article_id |
| `ArticleEditorPreparingLockContractTest` | Readiness reconcile + `abandonPreparingGate` / force-open CTA |
| `ArticleEditorSessionHeartbeatUxContractTest` | Expire deadlock skip; 5xx → `SESSION_UNAVAILABLE` + reload CTA |
| `ArticleEditorImagesHealthAndSlugSessionTest` | Owning Fix Slug session; slug error / ALT warning / `image_ratio_low` |
| `ArticleEditorUnifiedImagesInventoryHealthTest` | Unified inventory panel/health; Featured/Gallery roles; ratio ownership |
| `ArticleEditorInlineWhitespaceRoundTripRegressionTest` | Mark-boundary spaces HTML↔JSON; glue repair; hydrate not dirty |
| `ArticleEditorLocalFeaturedNotWpProtectedTest` | Local Featured `/storage/uploads/seo_media` ≠ WP protected |
| `ArticleEditorHeadingStyleDropdownOverflowTest` | Style dropdown portal; format toolbar no overflow-x clip |
| `CtaContactUsabilityAndQuickTemplatesTest` | Filter placeholder; resolve/validate quick CTA templates |
| `SeoReasonPresentationAndAssistantHealthTest` | image/content metrics; locale keys; links min 5; focus keyword health |
| `ArticleEditorRichText3eContractTest` | CTA paragraph; unlink keep text; quote CSS; images badge; featured snapshot; stable recommendation |

Manual verification (remote):

```text
$PHP_BIN vendor/bin/phpunit --filter=RuntimeLoggerWebAppChannelTest
$PHP_BIN vendor/bin/phpunit --filter=ArticleReviewServiceTest
$PHP_BIN vendor/bin/phpunit --filter=ArticleEditorContextPreservationContractTest
$PHP_BIN vendor/bin/phpunit --filter=ArticleEditorExclusiveLockRegressionTest
$PHP_BIN vendor/bin/phpunit --filter=ArticleEditorImagesHealthAndSlugSessionTest
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
