# Automation Migration Status — SeoContentAi

**Cập nhật:** 2026-07-21

## Phase tracker

| Phase | Nội dung | Status |
|---|---|---|
| 1 | Docs + inventory + vocabulary | **done** |
| 1b | Khóa naming, IDs, publish intent, selectability | **done** |
| 2 | Contracts, Registry, Runner, Context, execution log, foundation tests | **done** |
| 3 (minimum) | Action adapters tối thiểu | **done** |
| 3 Full | Extract Filament deps, classify catalog, skill, chạy test | **done** |
| 4A | Migrate local-only callers (flag + shadow) | **in progress** — Group 1 wired, default `legacy` |
| 4B | Group 2 wire (bridges + production callers) | **wired** (default `legacy`) — chưa deployed/shadow/promoted; see `AUTOMATION_PHASE4B_PREP.md` + `AUTOMATION_PHASE4B_HOSTING_VALIDATION.md` |
| 5A | Audit Prompt Workflows + Hook Spec v0.1 | **done** — docs `automation/prompt/*`; fixtures; Spec helpers; không đổi production prompt behavior |
| 5B | Prompt Hook Runtime Core (single-hook) | **done** — loader/registry/engine/bridge; default **legacy**; experimental@0.1.0; outline/FAQ/keywords wired |
| 5C | Production adapter + PromptResult attach + rollout gates | **code ready** — `PromptRunnerProviderAdapter`; `prompt_result.attach`; promotion/live-shadow gates; default **legacy** |
| 5D1 | Hosting rollout + single-hook stabilization support | **code ready** — parity aggregator; per-hook thresholds; mode/rollback policy; status/parity commands; runbook + fill-in report; defaults still **legacy**; title/meta experimental |
| Outline vertical slice | Editor binding → ExplicitBinding → RuntimeEngine | **code ready** — `article.outline.generate@0.1.0` selectable; hosting tested = no; stable = no |
| 4 full | Migrate high-risk / WP | not started |
| 5 | Regression + static guards + docs finalize | not started |

## Decisions locked (1b)

1. `wordpress.article.update` **rejected**; dùng `wordpress.article.sync_outbound` + `legacy_not_selectable` + `implies_publish_status=true`.
2. Naming: action `<module>.<resource…>.<verb>`; event `<module>.<past_tense_phrase>`.
3. Canonical IDs: `team_id?`, `site_id`, `connection_id`, `article_id`, `wp_post_id`.
4. `PublishIntent`: `manual_publish` | `scheduled_publish` | `republish` | `remote_update` (reserved).
5. `article.publish_requested` không authorize publish.

## Phase 3 Full — domain extract

| Service | Callers |
|---|---|
| `SeoIssueProjectTaskAssignmentService` | `CreateProjectTaskFromSeoIssueAction`, `ArticleResource` (notify UI), `ArticlePendingInternalLinkService` |
| `KeywordProjectAssignmentService` | `AssignKeywordToProjectAction`, `KeywordResource` (notify UI), `ArticlePendingInternalLinkService` |

Action **không** phụ thuộc Filament Resource/Page.

## Phase 3 adapters

| Action | Handler | Services / path | WP outbound | Handler? |
|---|---|---|---|---|
| `article.create` | `CreateArticleAction` | Eloquent + focus attach | no | yes |
| `article.content.update` | `UpdateArticleContentAction` | `ArticleEditorPersistService` | no | yes |
| `article.seo_meta.update` | `UpdateArticleSeoMetaAction` | meta local | no | yes |
| `article.review.request` | — | BLOCKER | — | no |
| `project.task.create` | `CreateProjectTaskAction` | Eloquent | no | yes |
| `project.task.attach_article` | `AttachArticleToProjectTaskAction` | Eloquent | no | yes |
| `project.task.mark_completed` | `MarkProjectTaskCompletedAction` | Eloquent + owner sync | no | yes |
| `seo.audit.run` | `RunSeoAuditAction` | `SeoAuditScanService` | no | yes |
| `seo.project_task.create_from_issue` | `CreateProjectTaskFromSeoIssueAction` | `SeoIssueProjectTaskAssignmentService` | no | yes |
| `keyword.assign_to_project` | `AssignKeywordToProjectAction` | `KeywordProjectAssignmentService` | no | yes |
| `keyword.vocabulary.save` | `SaveKeywordVocabularyAction` | `WorkflowKeywordResearchService` | no | yes |
| `keyword.topic_cluster.sync` | `SyncKeywordTopicClusterAction` | `WorkflowKeywordResearchService` | no | yes |

WordPress keys: definition only — no Phase 3 handlers. Production callers: **not migrated**.

## Phase 4A — local-only migration

Chi tiết: `AUTOMATION_PHASE4_ROLLOUT.md`

### Bước 0 (done)

- `article.create`: idempotent theo `origin_type`/`origin_id` (Content Project = `seo_project_task`); task đã attach → `deduplicated`.
- `article.content.update`: conflict qua `expected_updated_at` / `expected_content_hash`.

### Group 1 wired (default mode = legacy)

| Caller | Flag | Bridge | Migrated to action? |
|---|---|---|---|
| SEO/Article assign UI | `seo_issue_assignment` | `AssignmentCallerBridge` | no (flag legacy) |
| Keyword assign UI | `keyword_project_assignment` | `AssignmentCallerBridge` | no |
| Workflow attach/relink | `project_article_attach` | `ProjectTaskCallerBridge` | no |
| Workflow mark completed | `project_task_complete` | `ProjectTaskCallerBridge` | no |

Group 2 **wired** (default `legacy`) — chưa deployed/shadow validated/promoted. WP paths untouched. Hosting: `AUTOMATION_PHASE4B_HOSTING_VALIDATION.md`.

### Group 2 wired (default mode = legacy)

| Caller | Flag | Bridge | Promoted to action? |
|---|---|---|---|
| `CreateArticlesFromTaskService::createDraftArticle` | `project_article_create` | `ProjectArticleCreateCallerBridge` | no |
| `PromptTestPublishService::publishArticle` | `project_article_content_update` | `ProjectArticleContentCallerBridge` | no |
| `PromptTestPublishService::persistMetaDescription` | `project_article_seo_meta_update` | `ProjectArticleSeoMetaCallerBridge` | no |

**Not wired:** Article Editor save, WP sync, scheduled publish, comment review.

### Phase 4A tests (chạy thật)

```text
php artisan test app/Addons/SeoContentAi/tests --filter=AutomationPhase4
→ 26 passed (81 assertions) EXIT 0
```

Gồm migration foundation + **staging scenarios** (new/existing/retry/partial dup/wrong context/already attached|completed, parity log, promotion gate, rollback).

**Staging shadow:** repo default vẫn `legacy`. Ops bật `shadow` theo `AUTOMATION_PHASE4_ROLLOUT.md`. **Chưa** promote `action`.

## Test report Phase 3 (chạy thật — 2026-07-18)

### Automation (PASS)

```text
php artisan test app/Addons/SeoContentAi/tests --filter=Automation
```

Kết quả: **25 passed (236 assertions)** — exit 0.

Suites:

- `AutomationFoundationTest` — catalog, duplicate key, schema, canonical IDs, WP non-selectable, PublishIntent, ping, blocker handler_missing, redactor
- `AutomationActionAdapterTest` — phase3 keys, dry_run short-circuit, registrar
- `AutomationActionBoundaryTest` — no WP outbound / no Filament Resource in Article|Project|Seo|Keyword actions
- `AutomationDomainAssignmentServiceTest` — domain services không Filament; Action typehint service

Lưu ý: `php artisan test --filter=Automation` **không** tìm thấy test nếu không chỉ path addon (`phpunit.xml` chỉ `tests/Unit|Feature`). Luôn dùng path `app/Addons/SeoContentAi/tests`.

### Regression nhóm (môi trường local)

```text
php artisan test app/Addons/SeoContentAi/tests --filter=ArticleEditor
php artisan test app/Addons/SeoContentAi/tests --filter=SeoProject
php artisan test app/Addons/SeoContentAi/tests --filter=SeoAudit
php artisan test app/Addons/SeoContentAi/tests --filter=Keyword
php artisan test app/Addons/SeoContentAi/tests --filter=ArticlePendingInternalLink
```

| Nhóm | Kết quả | Ghi chú |
|---|---|---|
| ArticleEditor | 4 pass / 1 fail | Fail: `ArticleEditorSavePatchServiceTest` — `SQLSTATE[HY000] [2002]` omi_seo_ai refused |
| SeoProject | 16 pass / 3 fail | Fail: `SeoProjectRunConsolidationServiceTest` — PDO connection refused |
| SeoAudit | 14 pass / 6 skip / 1 fail | Skip: DB; Fail: `SeoScoringEngine::analyzeHtml()` undefined (pre-existing API drift) |
| Keyword (+ liên quan) | nhiều pass / nhiều fail | Fail chủ yếu PDO `omi_seo_ai` refused |
| ArticlePendingInternalLink | 0 pass / 2 fail | PDO connection refused — **không** phải lỗi constructor domain service |

**Kết luận regression:** Fail quan sát được gắn **thiếu MySQL `omi_seo_ai` local** (và 1 test API cũ), không phải fail assertion do extract AssignmentService. Automation unit (không cần SEO DB) **PASS**.

Phase 3 **không** đánh dấu complete trước khi Automation test chạy — đã chạy và PASS.

## Migration table (callers)

| Legacy caller | Legacy service | New action | Migrated | Remaining risk |
|---|---|---|---|---|
| `ArticleEditorSyncController::save` | `ArticleEditorPersistService` | `article.content.update` | no | Low |
| `ArticleEditorSyncController::saveSeoMeta` | SeoMeta path | `article.seo_meta.update` | no | Low |
| `ArticleEditorSyncController::syncWp` | Queue + Orchestrator | `wordpress.article.publish` | no | **High** |
| `EditArticle` sync phases | `WordPressArticleSyncService` | `wordpress.article.publish` | no | **High** |
| Queue / cron publish | Jobs + Scheduled | `wordpress.article.publish` + intent | no | Retry |
| Project workflow article write | CreateArticles + PromptTestPublish | `article.*` + `project.task.*` | no | Naming |
| `post_comment_review` | `ArticleProductReviewStoreService` | `wordpress.comment_review.publish` | yes (handler+rules seeded disabled) | **Medium** — enable rules + migrate legacy meta |
| Audit/List assign | domain assignment service (via Resource) | `seo.project_task.create_from_issue` | no | Dup tasks mitigated in service |
| Keyword vocab/cluster | `WorkflowKeywordResearchService` | `keyword.*` | no | Medium |
| Approval | `SeoProjectApprovalService` | `article.approve` | no | Low |

## Rủi ro trước Phase 4

1. `article.create` chưa có idempotency key nghiệp vụ.
2. `article.content.update` chưa conflict revision.
3. WP comment_review: handler + default rules seeded (disabled). Enable `publish-generated-product-reviews-to-wordpress` + `publish-pending-product-reviews-after-article-sync`.
4. `keyword.domain_link_list.sync` observer side effect chưa thành action riêng.
5. Migrate caller phải dual-run / parity — chưa bắt đầu.
6. Regression DB-dependent cần chạy trên môi trường có `omi_seo_ai`.
