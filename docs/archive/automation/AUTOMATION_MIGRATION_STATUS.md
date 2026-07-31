> Status: Historical
> Not canonical
> Archived on: 2026-08-01
> Superseded by: ../../modules/AUTOMATION.md
> Purpose: implementation history only
# Automation Migration Status â€” SeoContentAi

**Cáº­p nháº­t:** 2026-07-21

## Phase tracker

| Phase | Ná»™i dung | Status |
|---|---|---|
| 1 | Docs + inventory + vocabulary | **done** |
| 1b | KhÃ³a naming, IDs, publish intent, selectability | **done** |
| 2 | Contracts, Registry, Runner, Context, execution log, foundation tests | **done** |
| 3 (minimum) | Action adapters tá»‘i thiá»ƒu | **done** |
| 3 Full | Extract Filament deps, classify catalog, skill, cháº¡y test | **done** |
| 4A | Migrate local-only callers (flag + shadow) | **done** â€” default `action`; emergency legacy only (`AUTOMATION_MIGRATION_EMERGENCY_LEGACY`) |
| 4B | Group 2 + Editor/Approval/Keyword cutover | **done** â€” production callers via `BusinessActionDispatcher`; see `AUTOMATION_CUTOVER_AUDIT.md` |
| 5A | Audit Prompt Workflows + Hook Spec v0.1 | **done** â€” docs `automation/prompt/*`; fixtures; Spec helpers; khÃ´ng Ä‘á»•i production prompt behavior |
| 5B | Prompt Hook Runtime Core (single-hook) | **done** â€” loader/registry/engine/bridge; default **legacy**; experimental@0.1.0; outline/FAQ/keywords wired |
| 5C | Production adapter + PromptResult attach + rollout gates | **code ready** â€” `PromptRunnerProviderAdapter`; `prompt_result.attach`; promotion/live-shadow gates; default **legacy** |
| 5D1 | Hosting rollout + single-hook stabilization support | **code ready** â€” parity aggregator; per-hook thresholds; mode/rollback policy; status/parity commands; runbook + fill-in report; defaults still **legacy**; title/meta experimental |
| Outline vertical slice | Editor binding â†’ ExplicitBinding â†’ RuntimeEngine | **code ready** â€” `article.outline.generate@0.1.0` selectable; hosting tested = no; stable = no |
| 4 full | Migrate high-risk / WP + local Action Runtime | **done (2026-07-21)** â€” Editor/Approval/Keyword/WP; Migrated=yes, direct write=0, legacy fallback=0 |
| 5 | Regression + static guards + docs finalize | **done (code)** â€” `AutomationActionCutoverArchitectureTest` + strict audit-coupling; hosting `automation:seed-rules` still required |

## Decisions locked (1b)

1. `wordpress.article.update` **rejected**; dÃ¹ng `wordpress.article.sync_outbound` + `legacy_not_selectable` + `implies_publish_status=true`.
2. Naming: action `<module>.<resourceâ€¦>.<verb>`; event `<module>.<past_tense_phrase>`.
3. Canonical IDs: `team_id?`, `site_id`, `connection_id`, `article_id`, `wp_post_id`.
4. `PublishIntent`: `manual_publish` | `scheduled_publish` | `republish` | `remote_update` (reserved).
5. `article.publish_requested` khÃ´ng authorize publish.

## Phase 3 Full â€” domain extract

| Service | Callers |
|---|---|
| `SeoIssueProjectTaskAssignmentService` | `CreateProjectTaskFromSeoIssueAction`, `ArticleResource` (notify UI), `ArticlePendingInternalLinkService` |
| `KeywordProjectAssignmentService` | `AssignKeywordToProjectAction`, `KeywordResource` (notify UI), `ArticlePendingInternalLinkService` |

Action **khÃ´ng** phá»¥ thuá»™c Filament Resource/Page.

## Phase 3 adapters

| Action | Handler | Services / path | WP outbound | Handler? |
|---|---|---|---|---|
| `article.create` | `CreateArticleAction` | Eloquent + focus attach | no | yes |
| `article.content.update` | `UpdateArticleContentAction` | `ArticleEditorPersistService` | no | yes |
| `article.seo_meta.update` | `UpdateArticleSeoMetaAction` | meta local | no | yes |
| `article.review.request` | â€” | BLOCKER | â€” | no |
| `project.task.create` | `CreateProjectTaskAction` | Eloquent | no | yes |
| `project.task.attach_article` | `AttachArticleToProjectTaskAction` | Eloquent | no | yes |
| `project.task.mark_completed` | `MarkProjectTaskCompletedAction` | Eloquent + owner sync | no | yes |
| `seo.audit.run` | `RunSeoAuditAction` | `SeoAuditScanService` | no | yes |
| `seo.project_task.create_from_issue` | `CreateProjectTaskFromSeoIssueAction` | `SeoIssueProjectTaskAssignmentService` | no | yes |
| `keyword.assign_to_project` | `AssignKeywordToProjectAction` | `KeywordProjectAssignmentService` | no | yes |
| `keyword.vocabulary.save` | `SaveKeywordVocabularyAction` | `WorkflowKeywordResearchService` | no | yes |
| `keyword.topic_cluster.sync` | `SyncKeywordTopicClusterAction` | `WorkflowKeywordResearchService` | no | yes |

WordPress keys: definition only â€” no Phase 3 handlers. Production callers: **not migrated**.

## Phase 4A â€” local-only migration

Chi tiáº¿t: `AUTOMATION_PHASE4_ROLLOUT.md`

### BÆ°á»›c 0 (done)

- `article.create`: idempotent theo `origin_type`/`origin_id` (Content Project = `seo_project_task`); task Ä‘Ã£ attach â†’ `deduplicated`.
- `article.content.update`: conflict qua `expected_updated_at` / `expected_content_hash`.

### Group 1 wired (default mode = legacy)

| Caller | Flag | Bridge | Migrated to action? |
|---|---|---|---|
| SEO/Article assign UI | `seo_issue_assignment` | `AssignmentCallerBridge` | no (flag legacy) |
| Keyword assign UI | `keyword_project_assignment` | `AssignmentCallerBridge` | no |
| Workflow attach/relink | `project_article_attach` | `ProjectTaskCallerBridge` | no |
| Workflow mark completed | `project_task_complete` | `ProjectTaskCallerBridge` | no |

Group 2 **wired** (default `legacy`) â€” chÆ°a deployed/shadow validated/promoted. WP paths untouched. Hosting: `AUTOMATION_PHASE4B_HOSTING_VALIDATION.md`.

### Group 2 wired (default mode = legacy)

| Caller | Flag | Bridge | Promoted to action? |
|---|---|---|---|
| `CreateArticlesFromTaskService::createDraftArticle` | `project_article_create` | `ProjectArticleCreateCallerBridge` | no |
| `PromptTestPublishService::publishArticle` | `project_article_content_update` | `ProjectArticleContentCallerBridge` | no |
| `PromptTestPublishService::persistMetaDescription` | `project_article_seo_meta_update` | `ProjectArticleSeoMetaCallerBridge` | no |

**Not wired:** Article Editor save, WP sync, scheduled publish, comment review.

### Phase 4A tests (cháº¡y tháº­t)

```text
php artisan test app/Addons/SeoContentAi/tests --filter=AutomationPhase4
â†’ 26 passed (81 assertions) EXIT 0
```

Gá»“m migration foundation + **staging scenarios** (new/existing/retry/partial dup/wrong context/already attached|completed, parity log, promotion gate, rollback).

**Staging shadow:** repo default váº«n `legacy`. Ops báº­t `shadow` theo `AUTOMATION_PHASE4_ROLLOUT.md`. **ChÆ°a** promote `action`.

## Test report Phase 3 (cháº¡y tháº­t â€” 2026-07-18)

### Automation (PASS)

```text
php artisan test app/Addons/SeoContentAi/tests --filter=Automation
```

Káº¿t quáº£: **25 passed (236 assertions)** â€” exit 0.

Suites:

- `AutomationFoundationTest` â€” catalog, duplicate key, schema, canonical IDs, WP non-selectable, PublishIntent, ping, blocker handler_missing, redactor
- `AutomationActionAdapterTest` â€” phase3 keys, dry_run short-circuit, registrar
- `AutomationActionBoundaryTest` â€” no WP outbound / no Filament Resource in Article|Project|Seo|Keyword actions
- `AutomationDomainAssignmentServiceTest` â€” domain services khÃ´ng Filament; Action typehint service

LÆ°u Ã½: suite SeoContentAi Ä‘Ã£ náº±m trong `phpunit.xml` â€” `php artisan test --filter=Automation` / class name hoáº¡t Ä‘á»™ng. Váº«n nÃªn Æ°u tiÃªn path file khi debug. Xem `docs/TESTING.md` + `php artisan test:doctor`.

### Regression nhÃ³m (mÃ´i trÆ°á»ng local)

```text
php artisan test app/Addons/SeoContentAi/tests --filter=ArticleEditor
php artisan test app/Addons/SeoContentAi/tests --filter=SeoProject
php artisan test app/Addons/SeoContentAi/tests --filter=SeoAudit
php artisan test app/Addons/SeoContentAi/tests --filter=Keyword
php artisan test app/Addons/SeoContentAi/tests --filter=ArticlePendingInternalLink
```

| NhÃ³m | Káº¿t quáº£ | Ghi chÃº |
|---|---|---|
| ArticleEditor | 4 pass / 1 fail | Fail: `ArticleEditorSavePatchServiceTest` â€” `SQLSTATE[HY000] [2002]` omi_seo_ai refused |
| SeoProject | 16 pass / 3 fail | Fail: `SeoProjectRunConsolidationServiceTest` â€” PDO connection refused |
| SeoAudit | 14 pass / 6 skip / 1 fail | Skip: DB; Fail: `SeoScoringEngine::analyzeHtml()` undefined (pre-existing API drift) |
| Keyword (+ liÃªn quan) | nhiá»u pass / nhiá»u fail | Fail chá»§ yáº¿u PDO `omi_seo_ai` refused |
| ArticlePendingInternalLink | 0 pass / 2 fail | PDO connection refused â€” **khÃ´ng** pháº£i lá»—i constructor domain service |

**Káº¿t luáº­n regression:** Fail quan sÃ¡t Ä‘Æ°á»£c gáº¯n **thiáº¿u MySQL `omi_seo_ai` local** (vÃ  1 test API cÅ©), khÃ´ng pháº£i fail assertion do extract AssignmentService. Automation unit (khÃ´ng cáº§n SEO DB) **PASS**.

Phase 3 **khÃ´ng** Ä‘Ã¡nh dáº¥u complete trÆ°á»›c khi Automation test cháº¡y â€” Ä‘Ã£ cháº¡y vÃ  PASS.

## Migration table (callers)

| Legacy caller | Legacy service | New action | Migrated | Remaining risk |
|---|---|---|---|---|
| `ArticleEditorSyncController::save` | `ArticleEditorPersistService` | `article.content.update` | no | Low |
| `ArticleEditorSyncController::saveSeoMeta` | SeoMeta path | `article.seo_meta.update` | no | Low |
| `ArticleEditorSyncController::syncWp` | Queue + Orchestrator | `wordpress.article.publish` | no | **High** |
| `EditArticle` sync phases | `WordPressArticleSyncService` | `wordpress.article.publish` | no | **High** |
| Queue / cron publish | Jobs + Scheduled | `wordpress.article.publish` + intent | no | Retry |
| Project workflow article write | CreateArticles + PromptTestPublish | `article.*` + `project.task.*` | no | Naming |
| `post_comment_review` | `ArticleProductReviewStoreService` | `wordpress.comment_review.publish` | yes (handler+rules seeded disabled) | **Medium** â€” enable rules + migrate legacy meta |
| Audit/List assign | domain assignment service (via Resource) | `seo.project_task.create_from_issue` | no | Dup tasks mitigated in service |
| Keyword vocab/cluster | `WorkflowKeywordResearchService` | `keyword.*` | no | Medium |
| Approval | `SeoProjectApprovalService` | `article.approve` | no | Low |

## Rá»§i ro trÆ°á»›c Phase 4

1. `article.create` chÆ°a cÃ³ idempotency key nghiá»‡p vá»¥.
2. `article.content.update` chÆ°a conflict revision.
3. WP product reviews: linear actions `product-review.create` + `product-review.sync-wp` on `article > wordpress`. Legacy review rules deprecated+hidden.
4. `keyword.domain_link_list.sync` observer side effect chÆ°a thÃ nh action riÃªng.
5. Migrate caller pháº£i dual-run / parity â€” chÆ°a báº¯t Ä‘áº§u.
6. Regression DB-dependent cáº§n cháº¡y trÃªn mÃ´i trÆ°á»ng cÃ³ `omi_seo_ai`.
