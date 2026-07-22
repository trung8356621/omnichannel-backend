# Automation Action Catalog — SeoContentAi

**Status:** Action Runtime cutover 2026-07-21 — production local mutations via `BusinessActionDispatcher` (Migrated=yes)  
**Updated:** 2026-07-18

## Classification legend

| Value | Nghĩa |
|---|---|
| `IMPLEMENT` | Có handler + test |
| `CATALOG_ONLY` | Có definition (hoặc đề xuất key); chưa handler an toàn |
| `INTERNAL_SERVICE_ONLY` | Dùng trong resolver/orchestration nội bộ; không node workflow |
| `BLOCKED` | Không implement cho đến khi có semantic/service đúng |
| `NOT_NEEDED` | Không phục vụ automation |

## Selectability

| Value | Ý nghĩa |
|---|---|
| `selectable` | Workflow/rule được tham chiếu |
| `internal_only` | Chỉ code/adapter nội bộ |
| `legacy_not_selectable` | Catalog migrate; cấm UI/workflow |

## Naming (LOCKED)

```text
<module>.<resource…>.<verb>
```

Canonical IDs: `team_id?`, `site_id`, `connection_id`, `article_id`, `wp_post_id`. Cấm `website_id` / `domain_id`.

---

## Capability matrix (Phase 3 Full)

### Article

| Action key | classification | selectability | handler | test status | production migrated | remaining risk / note |
|---|---|---|---|---|---|---|
| `article.create` | IMPLEMENT | selectable | `CreateArticleAction` | Automation PASS | **yes** (`CreateArticlesFromTaskService` → bridge, mode=action) | Idempotent `origin_type`+`origin_id`. |
| `article.content.update` | IMPLEMENT | selectable | `UpdateArticleContentAction` → `ArticleEditorPersistService` | Automation PASS | **yes** — Editor + PromptTest + Manual WP pre-persist | Conflict: expected_updated_at/hash. Event owner = Action. |
| `article.seo_meta.update` | IMPLEMENT | selectable | `UpdateArticleSeoMetaAction` → `ArticleEditorSeoMetaService::persist` | Automation PASS | **yes** — Editor + PromptTest | Emits `article.seo_meta_updated` + `article.content_updated`. |
| `article.media.attach` | CATALOG_ONLY | — | — | n/a | no | Cần path media usage rõ + lock article. |
| `article.media.detach` | CATALOG_ONLY | — | — | n/a | no | |
| `article.faq.update` | CATALOG_ONLY | — | — | n/a | no | Local FAQ; verify không gọi FaqSync WP. |
| `article.focus_keyword.attach` | CATALOG_ONLY | — | — | n/a | no | Một phần đã nhúng trong create/seo_meta. |
| `article.readiness.recalculate` | INTERNAL_SERVICE_ONLY | — | — | n/a | no | `ArticleEditorReadinessService` — gọi sau task run, không cần node riêng Phase 3. |
| `article.revision.create` | NOT_NEEDED | — | — | n/a | no | Schema/revision domain chưa có. |
| `article.skip_seo_audit.set` | CATALOG_ONLY | — | — | n/a | no | Meta flag local. |
| `article.schedule.set` | CATALOG_ONLY | — | — | n/a | no | Local schedule; publish cron = WP path riêng. |
| `article.review.request` | BLOCKED | internal_only | **none** | Automation PASS (handler_missing) | no | Xem blockers. |
| `article.approve` | IMPLEMENT | selectable | `ApproveArticleAction` → `SeoProjectApprovalService` | architecture | **yes** (`ArticleResource::submitStaffEditingComplete`) | Emits `article.approved`. Idempotent nếu đã approved. |

### Content Project

| Action key | classification | selectability | handler | test status | production migrated | remaining risk / note |
|---|---|---|---|---|---|---|
| `project.create` | CATALOG_ONLY | — | — | n/a | no | UI-heavy; chưa use case node. |
| `project.task.create` | IMPLEMENT | selectable | `CreateProjectTaskAction` | Automation PASS | no | Dedup theo identity task trong service path. |
| `project.task.attach_article` | IMPLEMENT | selectable | `AttachArticleToProjectTaskAction` | Automation PASS | no | Idempotent attach. |
| `project.task.assign_owner` | CATALOG_ONLY | — | — | n/a | no | Owner sync service sẵn. |
| `project.task.mark_running` | CATALOG_ONLY | — | — | n/a | no | Status transition; verify race. |
| `project.task.mark_failed` | CATALOG_ONLY | — | — | n/a | no | |
| `project.task.mark_completed` | IMPLEMENT | selectable | `MarkProjectTaskCompletedAction` | Automation PASS | no | + `SeoProjectArticleOwnerSyncService`. |
| `project.task.prepare_retry` | CATALOG_ONLY | — | — | n/a | no | |
| `project.prompt_result.attach` | SUPERSEDED | — | use `prompt_result.attach` | n/a | — | Canonical key moved out of project module. |
| `prompt_result.attach` | IMPLEMENT | selectable | `AttachPromptResultAction` → `PromptResultAttachService` | Phase5C unit | no | Idempotent; allowlist article\|project_task\|project; no WP. |
| `project.pending_internal_link.create` | CATALOG_ONLY | — | — | n/a | no | overlap keyword pending link. |
| `project.run_everything` / `process` / `handle` | NOT_NEEDED | — | — | n/a | — | Orchestration — cấm. |
| Workflow run orchestration | INTERNAL_SERVICE_ONLY | — | — | n/a | no | `SeoProjectWorkflowRunService` — composition sau. |

### SEO Audit

| Action key | classification | selectability | handler | test status | production migrated | remaining risk / note |
|---|---|---|---|---|---|---|
| `seo.audit.run` | IMPLEMENT | selectable | `RunSeoAuditAction` → `SeoAuditScanService` | Automation PASS | no | Read-heavy; không sửa body. |
| `seo.audit.skip.set` | CATALOG_ONLY | — | — | n/a | no | |
| `seo.audit.result.read` | INTERNAL_SERVICE_ONLY | — | — | n/a | no | Query/cache đọc — không cần action node. |
| `seo.project_task.create_from_issue` | IMPLEMENT | selectable | `CreateProjectTaskFromSeoIssueAction` → **`SeoIssueProjectTaskAssignmentService`** | Automation PASS | no | Không còn Filament Resource. Dedup → `deduplicated`. |
| `seo.issue.classify` | CATALOG_ONLY | — | — | n/a | no | Pure classify nếu cần. |
| Auto-fix article / publish WP từ audit | NOT_NEEDED / BLOCKED | — | — | — | — | Cấm Phase 3. |

### Keyword

| Action key | classification | selectability | handler | test status | production migrated | remaining risk / note |
|---|---|---|---|---|---|---|
| `keyword.create` | CATALOG_ONLY | — | — | n/a | no | `KeywordPersistenceService`; observer link list. |
| `keyword.update` | CATALOG_ONLY | — | — | n/a | no | |
| `keyword.assign_to_project` | IMPLEMENT | selectable | `AssignKeywordToProjectAction` → **`KeywordProjectAssignmentService`** | Automation PASS | no | Không Filament Resource. CTA blacklist / primary keyword giữ logic service. |
| `keyword.vocabulary.save` | IMPLEMENT | selectable | `SaveKeywordVocabularyAction` | Automation PASS | **yes** (`TaskWorkflowTestRunner`) | `WorkflowKeywordResearchService`. |
| `keyword.topic_cluster.sync` | IMPLEMENT | selectable | `SyncKeywordTopicClusterAction` | Automation PASS | yes (via vocabulary path) | |
| `keyword.pending_internal_link.create` | CATALOG_ONLY | — | — | n/a | no | `ArticlePendingInternalLinkService`. |
| `keyword.domain_link_list.sync` | IMPLEMENT | selectable | `SyncKeywordDomainLinkListAction` + HookAction | architecture | **yes** — rule on `keyword.saved` | Observer emit only; không gọi sync service. |
| `keyword.review.set` | CATALOG_ONLY | — | — | n/a | no | |

### Site / context

| Capability | classification | note |
|---|---|---|
| `site.context.resolve` | INTERNAL_SERVICE_ONLY | `AutomationSiteContextResolver` |
| `site.settings.read` | INTERNAL_SERVICE_ONLY | Chuẩn bị context |
| `site.wordpress_capability.read` | INTERNAL_SERVICE_ONLY | Guard/capability check |
| Site CRUD | NOT_NEEDED | |

### WordPress

| Action key | classification | selectability | handler | test status | production migrated | remaining risk |
|---|---|---|---|---|---|---|
| `wordpress.article.sync_outbound` | CATALOG_ONLY (legacy) | legacy_not_selectable | none Phase 3 | Automation PASS (blocked workflow) | no | `implies_publish_status=true` |
| `wordpress.article.publish` | CATALOG_ONLY | internal_only | none | Automation PASS (PublishIntent) | no | critical; cần guard/idempotency trước handler |
| `wordpress.article.sync` | IMPLEMENT | selectable | `SyncArticleToWordPressHookAction` | ProductReviewArticleSyncIsolationTest | yes | article/product+media only; **no** review orchestration |
| `product-review.create` | IMPLEMENT | selectable | `CreateProductReviewsHookAction` | ProductReviewSyncPipelineTest | yes | Idempotent: `ProductReviewCreationPolicy` + `ProductReviewAutomationSettingsResolver` (rule `target_count`); Manual Sync/editor dùng cùng settings |
| `product-review.sync-wp` | IMPLEMENT | selectable | `SyncProductReviewsToWordPressHookAction` | ProductReviewSyncPipelineTest | yes | idempotent WP create → `reviewed`; SideEffectGuard allows |
| `wordpress.comment_review.publish` | DEPRECATED | hidden | `PublishWordPressCommentReviewHookAction` (no-op) | — | no | replaced by `product-review.sync-wp` |

---

## Implemented handlers (detail)

### `article.create`

| Field | Value |
|---|---|
| path | Eloquent + `KeywordFocusAttach` |
| side_effect | internal_write |
| risk | medium |
| idempotency | **limited** — xem matrix |
| lock | article create txn |
| events | `article.created` (chỉ khi tạo mới) |

### `article.content.update`

| Field | Value |
|---|---|
| path | `ArticleEditorPersistService` only |
| side_effect | internal_write |
| risk | medium |
| supports_dry_run | true (catalog + handler) |
| revision | **không hỗ trợ** `expected_revision` |
| events | `article.content_updated` |

### `article.seo_meta.update`

| Field | Value |
|---|---|
| path | local meta (tránh Orchestrator) |
| events | `article.seo_meta_updated` |

### `seo.project_task.create_from_issue` / `keyword.assign_to_project`

| Field | Value |
|---|---|
| domain services | `SeoIssueProjectTaskAssignmentService`, `KeywordProjectAssignmentService` |
| Filament | Resource chỉ notify UI; Action không import Resource |
| output | counts + `deduplicated` |

### Project / SEO / Keyword còn lại

Xem matrix + `ActionHandlerRegistrar`.

---

## Foundation

| Key | classification | selectability | handler |
|---|---|---|---|
| `automation.ping` | IMPLEMENT | selectable (dev) | `PingAction` |
