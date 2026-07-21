# Automation Cutover Audit

**Updated:** 2026-07-21 (Action Runtime local-mutation cutover)

## Scope

Full SeoContentAi automatic/manual ownership cutover for WordPress sync, Product Review post-sync, article completion, scheduled publish, SEO analysis rules, notification rules, **local article/keyword mutation via BusinessActionDispatcher**, seeder/UI/audit.

## Source-of-truth rules

```text
Action = execution boundary duy nhất của nghiệp vụ
Rule   = orchestration dựa trên Business Event

Production mutation caller
→ BusinessActionDispatcher / ActionRunner
→ canonical Action → domain service
→ commit → emit Business Event (Action owns)
→ enabled+published Rules handle side effects

Automatic WP: BusinessEvent → rule → wordpress.article.sync → pipeline
Manual WP: UI → WordPressManualSyncService → ManualSyncContext → ManualWordPressSyncJob → shared pipeline
  → wordpress.synced (origin=manual, sync_operation_id=requestId) → pending-review rule
No legacy fallback. No dual-run.
```

## Local mutation caller matrix (post-cutover)

| Capability | Canonical Action | Domain Service | Production Callers | Direct write | Legacy fallback | Migrated |
|---|---|---|---|---|---|---|
| Editor content save | `article.content.update` | `ArticleEditorPersistService` | `ArticleEditorSyncController::save` | **0** | **0** | **yes** |
| Editor SEO meta | `article.seo_meta.update` | `ArticleEditorSeoMetaService::persist` | `ArticleEditorSyncController::saveSeoMeta` | **0** | **0** | **yes** |
| Manual WP pre-persist | `article.content.update` | Persist via Action | `WordPressManualSyncService::enqueueFromEditorBundle` | **0** | **0** | **yes** |
| Project article create | `article.create` | CreateArticleAction | `CreateArticlesFromTaskService` → bridge | **0** | emergency only | **yes** |
| Project content/meta | `article.content.update` / `article.seo_meta.update` | Actions | `PromptTestPublishService` → bridges | **0** | emergency only | **yes** |
| Assignments / attach / complete | catalog actions | bridges | Article/Keyword Resource, WorkflowRun | **0** | emergency only | **yes** |
| Approval | `article.approve` | `SeoProjectApprovalService` | `ArticleResource::submitStaffEditingComplete` | **0** | **0** | **yes** |
| Keyword vocab | `keyword.vocabulary.save` | `WorkflowKeywordResearchService` | `TaskWorkflowTestRunner` | **0** | **0** | **yes** |
| Domain link list | `keyword.domain_link_list.sync` | `DomainLinkListKeywordSyncService` | Rule on `keyword.saved` (observer emit only) | **0** | **0** | **yes** |
| Manual WP sync | pipeline (no rule gate) | `ArticleWordPressBusinessSequence` | Editor / ListArticles | n/a | **0** | **yes** |
| Auto WP sync | `wordpress.article.sync` | Pipeline | Business Hook rule | n/a | **0** | **yes** |

`MigrationMode` default = **Action**. Emergency rollback: `AUTOMATION_MIGRATION_EMERGENCY_LEGACY=true` only.

## Capability inventory (WP / review)

| Capability | Automatic owner | Manual owner | Event | Rule | Action | Enabled | Published | Legacy callers |
|---|---|---|---|---|---|---|---|---|
| Article WP sync | `sync-article-to-wordpress` | — | `article.completed` | same | `wordpress.article.sync` | yes | yes | 0 |
| Manual WP sync | — | `WordPressManualSyncService` | outcome `wordpress.synced` | pending-review may match | domain sync | n/a | n/a | 0 |
| Scheduled publish | `dispatch-publish-request` | — | `article.publish_requested` | same | `wordpress.article.sync` mode=publish | yes | yes | 0 |
| Product review after WP sync | linear on sync rule | manual sequence | (linear) | same | create+sync-wp | yes | yes | 0 |
| SEO analysis auto | `seo-analysis-on-content-updated` | ops scoring | `article.content_updated` | same | `article.run_seo_analysis` | yes | yes | ops direct OK |
| Domain link list | `sync-keyword-domain-link-list-on-saved` | — | `keyword.saved` | same | `keyword.domain_link_list.sync` | yes | yes | 0 |
| Task fail notify | `notify-workflow-failure` | Filament toast | `content_project.task.failed` | same | `notification.send` | yes | yes | UI toast OK |

## Event owners (one producer per outcome)

| Event | Owner |
|---|---|
| `article.content_updated` | `UpdateArticleContentAction` / `UpdateArticleSeoMetaAction` (via ActionRunner bridge) |
| `article.seo_meta_updated` | `UpdateArticleSeoMetaAction` |
| `article.approved` | `ApproveArticleAction` |
| `keyword.saved` | `KeywordLinkListSyncObserver` (emit only) |
| `wordpress.synced` | ManualJob `wordpressSyncedOnce(requestId)` / HookAction `eventUuid=idempotencyKey` |

`ArticleEditorPersistService` / `ArticleEditorSeoMetaService` **do not** emit.

## Remaining risks

- `ArticleEditorSyncOrchestrator` vẫn tồn tại; production entry chỉ deprecated fail-closed job (`SyncArticleToWordPressFromQueueJob`)
- Prompt-hook migration flags vẫn default `legacy` (ngoài phạm vi Action cutover)
- Hosting phải `automation:seed-rules` để publish rule keyword domain link list

## Tests

- `AutomationActionCutoverArchitectureTest`
- ProductReview* / ManualAutomationCutover / WordpressCutoverCoupling / BusinessHook*

## Commands

```text
php artisan automation:seed-rules
php artisan automation:audit-coupling --strict
php artisan automation:audit-wordpress-coupling --strict
php artisan test --filter=AutomationActionCutoverArchitectureTest
```
