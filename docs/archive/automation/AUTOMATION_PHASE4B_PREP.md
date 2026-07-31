> Status: Historical
> Not canonical
> Archived on: 2026-08-01
> Superseded by: ../../modules/AUTOMATION.md
> Purpose: implementation history only
# Automation Phase 4B Preparation â€” No DB

**Status:** bridges prepared + **wired** (default `legacy`) â€” chÆ°a deploy / chÆ°a shadow validated / chÆ°a promoted  
**Updated:** 2026-07-18  
**Constraint:** khÃ´ng Laragon/MySQL/SEO DB â€” khÃ´ng Ä‘á»•i env production; hosting: `AUTOMATION_PHASE4B_HOSTING_VALIDATION.md`.

## Goal

Khi cÃ³ hosting: deploy legacy â†’ shadow tá»«ng caller â†’ review parity â†’ promote action.  
Code Ä‘Ã£ wire; runtime production **chÆ°a** Ä‘á»•i mode (default `legacy`).

---

## Caller inventory (Group 2)

### `article.create`

| Caller | Legacy path | Business Action | Expected parity | Wire 4B? |
|---|---|---|---|---|
| `SeoProjectWorkflowRunService` â†’ `CreateArticlesFromTaskService::runPublishWorkflowForContext` | `createDraftArticle` via `ProjectArticleCreateCallerBridge` | `article.create` + origin `seo_project_task` | dedup náº¿u task.article_id; else would_create | **wired** (default legacy) |
| `CreateArticlesFromTaskService::runFromKeywords*` / `runFromSingleKeyword` | same bridge | `article.create` | site+keyword create | **wired** (origin optional) |
| `InteractsWithAiKeywordDiscovery::createDraftArticles` / AiKeywordDiscovery / KeywordWorkspace | CreateArticlesFromTaskService | `article.create` | draft from keywords | **wired** (via service) |
| `ListArticles` Filament action | CreateArticlesFromTaskService | `article.create` | UI draft | **wired** (via service) |
| Automation Action (registry) | CreateArticleAction | self | â€” | already |

**Hidden:** khÃ´ng Job/Observer táº¡o article trá»±c tiáº¿p ngoÃ i cÃ¡c path trÃªn (Observer SEO article khÃ¡c concern).

### `article.content.update`

| Caller | Legacy path | Business Action | Expected parity | Wire 4B? |
|---|---|---|---|---|
| `ArticleEditorSyncController::save` | `ArticleEditorPersistService::persistLocal` | `article.content.update` | hash/title/status; conflict guards | **out of scope** (Editor) |
| `PromptTestPublishService::publishArticle` | Eloquent `update` via `ProjectArticleContentCallerBridge` | `article.content.update` | content hash / changed_fields | **wired** (default legacy) |
| `ArticleEditorSyncOrchestrator` / `ArticleWpSyncQueueService` | persistLocalSilent | **khÃ´ng** map 4B local-only (WP hub) | â€” | blocked / later WP |
| Automation Action | UpdateArticleContentAction | self | â€” | already |

### `article.seo_meta.update`

| Caller | Legacy path | Business Action | Expected parity | Wire 4B? |
|---|---|---|---|---|
| `ArticleEditorSyncController::saveSeoMeta` | ArticleEditorSeoMetaService path | `article.seo_meta.update` | slug/focus/meta fields | Editor â€” tÃ¡ch |
| `PromptTestPublishService::persistMetaDescription` | meta updateOrCreate via `ProjectArticleSeoMetaCallerBridge` | `article.seo_meta.update` (`dispatch_scoring=false` khi project publish) | meta fields; scoring deferred to analyze/content | **wired** (default legacy) |
| Automation Action | UpdateArticleSeoMetaAction | self | scoring pending flag | already |

---

## Bridges (wired â€” default legacy)

| Class | Flag | Action key | Production caller |
|---|---|---|---|
| `ProjectArticleCreateCallerBridge` | `project_article_create` | `article.create` | `CreateArticlesFromTaskService` |
| `ProjectArticleContentCallerBridge` | `project_article_content_update` | `article.content.update` | `PromptTestPublishService::publishArticle` |
| `ProjectArticleSeoMetaCallerBridge` | `project_article_seo_meta_update` | `article.seo_meta.update` | `PromptTestPublishService::persistMetaDescription` |

Shared: `AutomationCallerMigrator`, `ParitySnapshotNormalizer`, `AutomationParitySampleRecorder`, `AutomationActionPromotionGate`, `ArticleActionOutputNormalizer`.

Origin stamp: `ProjectTaskOriginVariables` (`_seo_project_task_id`) tá»« `TaskTestInputResolver::resolveForProjectTask`.

## Planners (shadow only â€” no DB write)

| Class | Notes |
|---|---|
| `ArticleCreateParityPlanner` | Input + optional existing origin snapshot |
| `ArticleContentUpdateParityPlanner` | Input + article state snapshot; conflict/noop |
| `ArticleSeoMetaUpdateParityPlanner` | Input + meta state; scoring/sync flags **planned only** |

Planner **khÃ´ng**: query DB, dispatch queue, emit event.

## Side effect review (document â€” khÃ´ng sá»­a runtime)

| Action | Side effects |
|---|---|
| `article.create` | Eloquent insert; KeywordFocusAttach; meta `seo_focus_keyword`, `wp_post_type`, origin metas; optional attach task; event `article.created` (skip náº¿u dedup). **KhÃ´ng** WP HTTP. |
| `article.content.update` | `ArticleEditorPersistService` â†’ cÃ³ thá»ƒ queue SEO analyze (defer); sync-pending flags tÃ¹y persist. **KhÃ´ng** Orchestrator/WP. Conflict: expected_updated_at / expected_content_hash. Optional `slug` input. |
| `article.seo_meta.update` | Meta rows; KeywordFocusAttach; slug update + sync flag; scoring queue trá»« khi `dispatch_scoring=false`. **KhÃ´ng** WP HTTP trong action. |

## Concurrency limitations

Xem `ArticleContentConcurrencyLimitations::catalog()`:

- no revision column
- hash = trim only (chÆ°a CRLF/HTML normalize)
- updated_at second precision
- timezone parse caller-dependent
- guards optional náº¿u thiáº¿u cáº£ hai expected_*
- race window háº¹p

## Feature flags (Ä‘Ã£ cÃ³, default legacy)

```text
AUTOMATION_MIGRATION_PROJECT_ARTICLE_CREATE=legacy
AUTOMATION_MIGRATION_PROJECT_ARTICLE_CONTENT_UPDATE=legacy
AUTOMATION_MIGRATION_PROJECT_ARTICLE_SEO_META_UPDATE=legacy
```

## Unit tests

```text
php artisan test app/Addons/SeoContentAi/tests --filter=AutomationPhase4B
php artisan test app/Addons/SeoContentAi/tests --filter=AutomationActionBoundary
```

## Hosting next steps

Xem [`AUTOMATION_PHASE4B_HOSTING_VALIDATION.md`](AUTOMATION_PHASE4B_HOSTING_VALIDATION.md):

1. Deploy legacy
2. Shadow tá»«ng caller
3. Review parity
4. Promote action
5. Rollback legacy

## Out of scope (chÆ°a wire)

- Editor save / saveSeoMeta
- WP sync / scheduled publish / comment review
- Group 1 mode changes
