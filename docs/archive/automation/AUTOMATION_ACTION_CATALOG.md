> Status: Historical
> Not canonical
> Archived on: 2026-08-01
> Superseded by: ../../modules/AUTOMATION.md
> Purpose: implementation history only
# Automation Action Catalog â€” SeoContentAi

**Status:** Action Runtime cutover 2026-07-21 â€” production local mutations via `BusinessActionDispatcher` (Migrated=yes)  
**Updated:** 2026-07-18

## Classification legend

| Value | NghÄ©a |
|---|---|
| `IMPLEMENT` | CÃ³ handler + test |
| `CATALOG_ONLY` | CÃ³ definition (hoáº·c Ä‘á» xuáº¥t key); chÆ°a handler an toÃ n |
| `INTERNAL_SERVICE_ONLY` | DÃ¹ng trong resolver/orchestration ná»™i bá»™; khÃ´ng node workflow |
| `BLOCKED` | KhÃ´ng implement cho Ä‘áº¿n khi cÃ³ semantic/service Ä‘Ãºng |
| `NOT_NEEDED` | KhÃ´ng phá»¥c vá»¥ automation |

## Selectability

| Value | Ã nghÄ©a |
|---|---|
| `selectable` | Workflow/rule Ä‘Æ°á»£c tham chiáº¿u |
| `internal_only` | Chá»‰ code/adapter ná»™i bá»™ |
| `legacy_not_selectable` | Catalog migrate; cáº¥m UI/workflow |

## Naming (LOCKED)

```text
<module>.<resourceâ€¦>.<verb>
```

Canonical IDs: `team_id?`, `site_id`, `connection_id`, `article_id`, `wp_post_id`. Cáº¥m `website_id` / `domain_id`.

---

## Capability matrix (Phase 3 Full)

### Article

| Action key | classification | selectability | handler | test status | production migrated | remaining risk / note |
|---|---|---|---|---|---|---|
| `article.create` | IMPLEMENT | selectable | `CreateArticleAction` | Automation PASS | **yes** (`CreateArticlesFromTaskService` â†’ bridge, mode=action) | Idempotent `origin_type`+`origin_id`. |
| `article.content.update` | IMPLEMENT | selectable | `UpdateArticleContentAction` â†’ `ArticleEditorPersistService` | Automation PASS | **yes** â€” Editor + PromptTest + Manual WP pre-persist | Conflict: expected_updated_at/hash. Event owner = Action. |
| `article.seo_meta.update` | IMPLEMENT | selectable | `UpdateArticleSeoMetaAction` â†’ `ArticleEditorSeoMetaService::persist` | Automation PASS | **yes** â€” Editor + PromptTest | Emits `article.seo_meta_updated` + `article.content_updated`. |
| `article.media.attach` | CATALOG_ONLY | â€” | â€” | n/a | no | Cáº§n path media usage rÃµ + lock article. |
| `article.media.detach` | CATALOG_ONLY | â€” | â€” | n/a | no | |
| `article.faq.update` | CATALOG_ONLY | â€” | â€” | n/a | no | Local FAQ; verify khÃ´ng gá»i FaqSync WP. |
| `article.focus_keyword.attach` | CATALOG_ONLY | â€” | â€” | n/a | no | Má»™t pháº§n Ä‘Ã£ nhÃºng trong create/seo_meta. |
| `article.readiness.recalculate` | INTERNAL_SERVICE_ONLY | â€” | â€” | n/a | no | `ArticleEditorReadinessService` â€” gá»i sau task run, khÃ´ng cáº§n node riÃªng Phase 3. |
| `article.revision.create` | NOT_NEEDED | â€” | â€” | n/a | no | Schema/revision domain chÆ°a cÃ³. |
| `article.skip_seo_audit.set` | CATALOG_ONLY | â€” | â€” | n/a | no | Meta flag local. |
| `article.schedule.set` | CATALOG_ONLY | â€” | â€” | n/a | no | Local schedule; publish cron = WP path riÃªng. |
| `article.review.request` | BLOCKED | internal_only | **none** | Automation PASS (handler_missing) | no | Xem blockers. |
| `article.approve` | IMPLEMENT | selectable | `ApproveArticleAction` â†’ `SeoProjectApprovalService` | architecture | **yes** (`ArticleResource::submitStaffEditingComplete`) | Emits `article.approved`. Idempotent náº¿u Ä‘Ã£ approved. |

### Content Project

| Action key | classification | selectability | handler | test status | production migrated | remaining risk / note |
|---|---|---|---|---|---|---|
| `project.create` | CATALOG_ONLY | â€” | â€” | n/a | no | UI-heavy; chÆ°a use case node. |
| `project.task.create` | IMPLEMENT | selectable | `CreateProjectTaskAction` | Automation PASS | no | Dedup theo identity task trong service path. |
| `project.task.attach_article` | IMPLEMENT | selectable | `AttachArticleToProjectTaskAction` | Automation PASS | no | Idempotent attach. |
| `project.task.assign_owner` | CATALOG_ONLY | â€” | â€” | n/a | no | Owner sync service sáºµn. |
| `project.task.mark_running` | CATALOG_ONLY | â€” | â€” | n/a | no | Status transition; verify race. |
| `project.task.mark_failed` | CATALOG_ONLY | â€” | â€” | n/a | no | |
| `project.task.mark_completed` | IMPLEMENT | selectable | `MarkProjectTaskCompletedAction` | Automation PASS | no | + `SeoProjectArticleOwnerSyncService`. |
| `project.task.prepare_retry` | CATALOG_ONLY | â€” | â€” | n/a | no | |
| `project.prompt_result.attach` | SUPERSEDED | â€” | use `prompt_result.attach` | n/a | â€” | Canonical key moved out of project module. |
| `prompt_result.attach` | IMPLEMENT | selectable | `AttachPromptResultAction` â†’ `PromptResultAttachService` | Phase5C unit | no | Idempotent; allowlist article\|project_task\|project; no WP. |
| `project.pending_internal_link.create` | CATALOG_ONLY | â€” | â€” | n/a | no | overlap keyword pending link. |
| `project.run_everything` / `process` / `handle` | NOT_NEEDED | â€” | â€” | n/a | â€” | Orchestration â€” cáº¥m. |
| Workflow run orchestration | INTERNAL_SERVICE_ONLY | â€” | â€” | n/a | no | `SeoProjectWorkflowRunService` â€” composition sau. |

### SEO Audit

| Action key | classification | selectability | handler | test status | production migrated | remaining risk / note |
|---|---|---|---|---|---|---|
| `seo.audit.run` | IMPLEMENT | selectable | `RunSeoAuditAction` â†’ `SeoAuditScanService` | Automation PASS | no | Read-heavy; khÃ´ng sá»­a body. |
| `seo.audit.skip.set` | CATALOG_ONLY | â€” | â€” | n/a | no | |
| `seo.audit.result.read` | INTERNAL_SERVICE_ONLY | â€” | â€” | n/a | no | Query/cache Ä‘á»c â€” khÃ´ng cáº§n action node. |
| `seo.project_task.create_from_issue` | IMPLEMENT | selectable | `CreateProjectTaskFromSeoIssueAction` â†’ **`SeoIssueProjectTaskAssignmentService`** | Automation PASS | no | KhÃ´ng cÃ²n Filament Resource. Dedup â†’ `deduplicated`. |
| `seo.issue.classify` | CATALOG_ONLY | â€” | â€” | n/a | no | Pure classify náº¿u cáº§n. |
| Auto-fix article / publish WP tá»« audit | NOT_NEEDED / BLOCKED | â€” | â€” | â€” | â€” | Cáº¥m Phase 3. |

### Keyword

| Action key | classification | selectability | handler | test status | production migrated | remaining risk / note |
|---|---|---|---|---|---|---|
| `keyword.create` | CATALOG_ONLY | â€” | â€” | n/a | no | `KeywordPersistenceService`; observer link list. |
| `keyword.update` | CATALOG_ONLY | â€” | â€” | n/a | no | |
| `keyword.assign_to_project` | IMPLEMENT | selectable | `AssignKeywordToProjectAction` â†’ **`KeywordProjectAssignmentService`** | Automation PASS | no | KhÃ´ng Filament Resource. CTA blacklist / primary keyword giá»¯ logic service. |
| `keyword.vocabulary.save` | IMPLEMENT | selectable | `SaveKeywordVocabularyAction` | Automation PASS | **yes** (`TaskWorkflowTestRunner`) | `WorkflowKeywordResearchService`. |
| `keyword.topic_cluster.sync` | IMPLEMENT | selectable | `SyncKeywordTopicClusterAction` | Automation PASS | yes (via vocabulary path) | |
| `keyword.pending_internal_link.create` | CATALOG_ONLY | â€” | â€” | n/a | no | `ArticlePendingInternalLinkService`. |
| `keyword.domain_link_list.sync` | IMPLEMENT | selectable | `SyncKeywordDomainLinkListAction` + HookAction | architecture | **yes** â€” rule on `keyword.saved` | Observer emit only; khÃ´ng gá»i sync service. |
| `keyword.review.set` | CATALOG_ONLY | â€” | â€” | n/a | no | |

### Site / context

| Capability | classification | note |
|---|---|---|
| `site.context.resolve` | INTERNAL_SERVICE_ONLY | `AutomationSiteContextResolver` |
| `site.settings.read` | INTERNAL_SERVICE_ONLY | Chuáº©n bá»‹ context |
| `site.wordpress_capability.read` | INTERNAL_SERVICE_ONLY | Guard/capability check |
| Site CRUD | NOT_NEEDED | |

### WordPress

| Action key | classification | selectability | handler | test status | production migrated | remaining risk |
|---|---|---|---|---|---|---|
| `wordpress.article.sync_outbound` | CATALOG_ONLY (legacy) | legacy_not_selectable | none Phase 3 | Automation PASS (blocked workflow) | no | `implies_publish_status=true` |
| `wordpress.article.publish` | CATALOG_ONLY | internal_only | none | Automation PASS (PublishIntent) | no | critical; cáº§n guard/idempotency trÆ°á»›c handler |
| `wordpress.article.sync` | IMPLEMENT | selectable | `SyncArticleToWordPressHookAction` | ProductReviewArticleSyncIsolationTest | yes | article/product+media only; **no** review orchestration |
| `product-review.create` | IMPLEMENT | selectable | `CreateProductReviewsHookAction` | ProductReviewSyncPipelineTest | yes | Idempotent: `ProductReviewCreationPolicy` + `ProductReviewAutomationSettingsResolver` (rule `target_count`); Manual Sync/editor dÃ¹ng cÃ¹ng settings |
| `product-review.sync-wp` | IMPLEMENT | selectable | `SyncProductReviewsToWordPressHookAction` | ProductReviewSyncPipelineTest | yes | idempotent WP create â†’ `reviewed`; SideEffectGuard allows |
| `wordpress.comment_review.publish` | DEPRECATED | hidden | `PublishWordPressCommentReviewHookAction` (no-op) | â€” | no | replaced by `product-review.sync-wp` |

---

## Implemented handlers (detail)

### `article.create`

| Field | Value |
|---|---|
| path | Eloquent + `KeywordFocusAttach` |
| side_effect | internal_write |
| risk | medium |
| idempotency | **limited** â€” xem matrix |
| lock | article create txn |
| events | `article.created` (chá»‰ khi táº¡o má»›i) |

### `article.content.update`

| Field | Value |
|---|---|
| path | `ArticleEditorPersistService` only |
| side_effect | internal_write |
| risk | medium |
| supports_dry_run | true (catalog + handler) |
| revision | **khÃ´ng há»— trá»£** `expected_revision` |
| events | `article.content_updated` |

### `article.seo_meta.update`

| Field | Value |
|---|---|
| path | local meta (trÃ¡nh Orchestrator) |
| events | `article.seo_meta_updated` |

### `seo.project_task.create_from_issue` / `keyword.assign_to_project`

| Field | Value |
|---|---|
| domain services | `SeoIssueProjectTaskAssignmentService`, `KeywordProjectAssignmentService` |
| Filament | Resource chá»‰ notify UI; Action khÃ´ng import Resource |
| output | counts + `deduplicated` |

### Project / SEO / Keyword cÃ²n láº¡i

Xem matrix + `ActionHandlerRegistrar`.

---

## Foundation

| Key | classification | selectability | handler |
|---|---|---|---|
| `automation.ping` | IMPLEMENT | selectable (dev) | `PingAction` |
