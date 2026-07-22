# Automation Service Inventory — SeoContentAi

**Phase:** living inventory (Business Hook + product review outbound)  
**Cập nhật:** 2026-07-21  
**Nguồn chân lý:** code hiện tại; docs MAP_* / ACTION_CATALOG / EVENT_CATALOG.  
**Canonical IDs / naming / selectability:** xem `AUTOMATION_BOUNDARIES.md`, `AUTOMATION_ACTION_CATALOG.md`.

## 1. Kết luận ngắn

| Boundary | Runtime hiện tại |
|---|---|
| Content Project → Article | Local write (`PromptTestPublishService`, `CreateArticlesFromTaskService`) |
| Content Project → WordPress **article** publish/sync | **Không** gọi `WordPressArticleSyncService` |
| Content Project → WordPress **khác** | **Không** path riêng cho review. Product reviews: AI/UI → local pending `article_product_reviews` → `SyncArticleToWordPressPipeline` (cùng `article > wordpress`) |
| Article Editor save | `BusinessActionDispatcher` → `article.content.update` / `article.seo_meta.update` |
| Article Editor sync / queue / scheduled | Outbound WP; status payload **luôn** `publish` |
| SEO Audit | Đọc + skip meta + tạo `SeoProjectTask`; không sửa/publish article |
| Keyword vocab / topic cluster | Action Runtime; domain link list = Rule trên `keyword.saved` |

**Rủi ro đặt tên (gây side-effect “ẩn” trong quá khứ / khi design automation):**

- `PromptTestPublishService::publishArticle` = lưu Laravel, **không** WP.
- `CreateArticlesFromTaskService::runPublishWorkflowForContext` = chạy workflow tạo/cập nhật bài local.
- `ArticleEditorReadinessService::syncWpPostContentFromBody` = ghi meta local `wp_post_content`, **không** HTTP WP.
- `ArticleEditorSyncOrchestrator::syncFromEditorBundle` = **persist local + outbound WP** trong một pipeline.
- Outbound article sync ≈ publish trên WP (`resolveWordPressStatusPayload` → `status=publish`).

## 2. Docs vs code

| Claim | Status |
|---|---|
| Content Project + `PromptTestPublishService` local-only (article) | **Đúng** — [MAP_SEO_PROJECTS](../MAP_SEO_PROJECTS.md) / [MAP_SEO_WP](../MAP_SEO_WP.md) |
| Project workflow không sync article WP; comment-review outbound qua Automation | **Đúng** — [MAP_SEO_EDITOR](../MAP_SEO_EDITOR.md) Reviews + [ACTION_CATALOG](AUTOMATION_ACTION_CATALOG.md) |
| Outbound article sync luôn `status=publish` | **Đúng** |
| `SeoProjectApprovalService` chỉ status project + notify (không sync WP) | **Đúng** |

## 3. Bảng inventory

Chú thích cột: R=Read DB, W=Write DB, J=Dispatch Job, E=External API, P=Có thể publish WP article, S=Side effect ẩn / tên lệch.

| Module | Class/Service/Job | Gọi từ đâu | Input | Output | R | W | J | E | P | Side effect ẩn | Đề xuất Action |
|---|---|---|---|---|---|---|---|---|---|---|---|
| Article | `ArticleEditorPersistService` | `ArticleEditorSyncController::save`, Orchestrator (silent), EditArticle paths | article + HTML + context | local save result | ✓ | ✓ | ✓ scoring | — | — | Dispatch `AnalyzeArticleSeoJob`; mark sync-pending flag | `article.content.update` |
| Article | `ArticleEditorSeoMetaService` | API `saveSeoMeta`, editor | seo fields | meta saved | ✓ | ✓ | — | — | — | Có thể đổi slug local | `article.seo_meta.update` |
| Article | `ArticleEditorBundleApplyService` | Persist pipeline | editor bundle | applied fields | ✓ | ✓ | — | — | — | Featured/album/post_type meta | (gộp vào content/seo/media update) |
| Article | `ArticleEditorSyncOrchestrator` | Queue job, sync API | editor bundle | sync result steps | ✓ | ✓ | ✓ media job | ✓ WP | ✓ | **Persist + ensure WP post + editor-sync** | **Không** map 1 action mơ hồ; tách `article.*` rồi `wordpress.article.*` |
| Article | `ArticleWpSyncQueueService` | Editor syncWp, ListArticles, ListQueue | article + bundle | queue meta | ✓ | ✓ | ✓ | — | (deferred) | `applyPublishImmediatelyToBundle` ép Laravel published | enqueue → `wordpress.article.publish` (intent) / internal sync_outbound |
| Article | `SyncArticleToWordPressFromQueueJob` | Queue `seo` | article_id | orchestrator result | ✓ | ✓ | — | ✓ | ✓ | Retry path | same |
| Article | `SyncArticleBodyMediaToWordPressJob` | Orchestrator sau sync | article_id | media sync | ✓ | ✓ | — | ✓ | — | Media only | internal media path (chưa action selectable) |
| Article | `WordPressArticleSyncService` | EditArticle, ListArticles, Orchestrator, Scheduled, FaqSync | article | WP result | ✓ | ✓ | ✓ | ✓ | ✓ | Hub outbound; status luôn publish | `wordpress.article.publish` + legacy `wordpress.article.sync_outbound` (not selectable) |
| Article | `ScheduledArticlePublishRunner` | cron `seo:publish-scheduled-articles` | due articles | stats | ✓ | ✓ | — | ✓ | ✓ | intent=`scheduled_publish` | `wordpress.article.publish` |
| Article | `ArticleScheduleReconcileService` | EditArticle hydrate | article | status reconcile | ✓ | ✓ | — | (read?) | — | Có thể đổi local status khi quá hạn | `article.schedule.reconcile` (internal) |
| Article | `SeoArticleObserver` | model saved | article | TOC meta | ✓ | ✓ | — | — | — | TOC extract | (không cần action workflow) |
| Article | `ArticleWordPressSyncFlagService` | Persist / PromptTestPublish | article | meta flags | ✓ | ✓ | — | — | — | Đánh dấu dirty sync | internal |
| Article | `ArticleEditorReadinessService` | Project run post-success | article | readiness DTO | ✓ | ✓ | — | — | — | Tên `syncWpPostContent*` chỉ local meta | internal / `article.readiness.refresh` |
| Project | `SeoProjectWorkflowRunService` | Filament run pages | project/run/task | run items | ✓ | ✓ | — | — | — | Orchestrate local article create; post-success links/readiness | **Không** `project.run_everything`; dùng `project.task.run` mỏng |
| Project | `CreateArticlesFromTaskService` | WorkflowRunService | TaskTestContext | article_id + steps | ✓ | ✓ | — | — | — | Tên “Publish”; gọi domain link keyword sync | `project.task.run` → internal article.create/update |
| Project | `TaskWorkflowTestRunner` | CreateArticles, TestTask, Editor media WF | SeoTask + context | steps | ✓ | ✓ | — | **local only** `post_comment_review` | comment WP via Automation | Action nodes: save_article (local), save_vocabulary, **post_comment_review (local+event)** | map từng action_type → business action |
| Project | `PromptTestPublishService` | TaskWorkflowTestRunner | AI output | local article | ✓ | ✓ | — | — | — | Tên publish; analyze SEO local; mark pending sync | `article.content.update` / create |
| Project | `SeoProjectTaskSyncService` | Create/Edit project | tasksData | tasks rows | ✓ | ✓ | — | — | — | Monthly limit | `project.task.create` (bulk sync) |
| Project | `SeoProjectApprovalService` | EditArticle / ArticleResource approve | article + user | project approved | ✓ | ✓ | — | — | — | Relink task article_id; notify | `project.approve` / `article.approve` |
| Project | `SeoProjectArticleOwnerSyncService` | project owner change | project | articles user_id | ✓ | ✓ | — | — | — | | `project.article_owner.sync` |
| Project | `SeoProjectObserver` | project create/update | project | notification | ✓ | — | — | — | — | | event only |
| Project | `PromptResultLinkService` | WorkflowRunService | steps + ids | links | ✓ | ✓ | — | — | — | | internal |
| Project | `ArticlePendingInternalLinkService` | EditArticle assign keyword | phrase + project | task + pending link | ✓ | ✓ | — | — | — | Tạo task project | `keyword.pending_internal_link.create` |
| Audit | `ArticlesOptimal` (Livewire) | UI | filters / ids | scan UI | ✓ | ✓ skip | — | — | — | Skip = meta only | UI |
| Audit | `SeoAuditScanService` | ArticlesOptimal | query + rules | rows | ✓ | — | — | — | — | | `seo.audit.run` (read) |
| Audit | `SeoAnalyzerService` / `AnalyzeArticleSeoJob` | Persist, inbound sync, job | article | score + violations | ✓ | ✓ | — | — | — | Ghi score local | `seo.audit.analyze_article` |
| Audit | `AssignmentCallerBridge` | ArticleResource assign (flag) | articles + project | summary | ✓ | ✓ | — | — | — | Phase 4A legacy/shadow/action | `seo.project_task.create_from_issue` |
| Keyword | `AssignmentCallerBridge` | KeywordResource assign (flag) | keywords + project | summary | ✓ | ✓ | — | — | — | Phase 4A | `keyword.assign_to_project` |
| Project | `ProjectArticleCreateCallerBridge` | **wired** — `CreateArticlesFromTaskService` (default legacy) | input + legacy/action callables | normalized output | — | — | — | — | — | Flag `project_article_create` | `article.create` |
| Project | `ProjectArticleContentCallerBridge` | **wired** — `PromptTestPublishService::publishArticle` (default legacy) | content input + state snapshot | normalized output | — | — | — | — | — | Flag `project_article_content_update` | `article.content.update` |
| Project | `ProjectArticleSeoMetaCallerBridge` | **wired** — `PromptTestPublishService::persistMetaDescription` (default legacy) | seo meta input + state | normalized output | — | — | scoring deferred khi `dispatch_scoring=false` | — | — | Flag `project_article_seo_meta_update` | `article.seo_meta.update` |
| Keyword | `KeywordPersistenceService` | Keyword UI / discovery | phrase + site | keyword | ✓ | ✓ | — | — | — | Link list attach | `keyword.create` / `.update` |
| Keyword | `WorkflowKeywordResearchService` | TaskWorkflowTestRunner | groups | topic cluster | ✓ | ✓ | — | — | — | CTA blacklist không chặn focus | `keyword.vocabulary.save` / `keyword.topic_cluster.sync` |
| Keyword | `KeywordLinkListSyncObserver` | Keyword saved/deleted | keyword | domain link list | ✓ | ✓ | — | — | — | Side effect domain settings | `keyword.domain_link_list.sync` |
| Keyword | `AiKeywordDiscoveryService` | AiKeywordDiscovery page | seed | suggestions | — | — | — | ✓ AI/SERP? | — | Không tạo article | `keyword.discover` (read/external) |
| Keyword | `DomainLinkListKeywordSyncService` | CreateArticles, Observer | site + phrase | link list | ✓ | ✓ | — | — | — | | site settings write |
| Domain/WP inbound | `SyncDomainContentService` | WP bridge push | WP payload | local articles | ✓ | ✓ | ✓ scoring | ✓ inbound | — | Pull WP → Laravel | `wordpress.article.fetch` / import |
| WP | `WordPressFaqSyncService` | (wrapper) | article | delegates syncForArticle | ✓ | ✓ | — | ✓ | ✓ | Wrapper rộng | deprecate / map sync_outbound (not selectable) |
| WP | `WordPressLocalMediaSyncService` | Sync prepare/complete | article HTML | media IDs | ✓ | ✓ | — | ✓ | — | | `wordpress.article.update_media` |
| WP | `WordPressProductReviewService` / `ProductReviewLocalBatchCreator` / `ArticleWordPressBusinessSequence` | Linear actions + manual + editor API | AI/UI/batch | local pending | ✓ | — | — | via `product-review.sync-wp` | — | **Outbound** `product-review.sync-wp` | `product-review.create` + `product-review.sync-wp` |
| Site | Site / connection models + `SeoAccessControl` | mọi nơi | ids | scope | ✓ | — | — | — | — | Cross-DB | `AutomationSiteContextResolver` (không CRUD action) |

## 4. Call path chi tiết (đã xác nhận code)

### 4.1 Local article save

```text
API POST /api/seo/articles/{id}/save
  → ArticleEditorSyncController::save
    → BusinessActionDispatcher → article.content.update
      → UpdateArticleContentAction
        → ArticleEditorPersistService::persistLocal
      → emit article.content_updated (Action owns)
  ✗ không WordPressArticleSyncService
  ✗ không enqueue WP queue
  ✗ PersistService không emit BusinessHook

API POST .../seo-meta
  → BusinessActionDispatcher → article.seo_meta.update
    → ArticleEditorSeoMetaService::persist
    → emit article.seo_meta_updated + article.content_updated
```

### 4.2 Editor WP sync (phased / publishForArticle)

`	ext
EditArticle Sync / Alpine __seoRunWordPressPhasedSync
  → WordPressArticleSyncService::publishForArticle
       OR prepareEditorSyncPayload → executeEditorSyncRequest → completeEditorSyncResponse
  → resolveWordPressStatusPayload → status=publish
  → HTTP REST plugin
  Intent ≈ manual_publish (caller chưa gắn enum)
`

### 4.3 Queue WP sync

`	ext
Publish tab / API syncWp / Ctrl+Shift+S
  → ArticleEditorSyncController::syncWp
    → ArticleWpSyncQueueService::enqueueFromEditorBundle
         (optional applyPublishImmediatelyToBundle)
    → SyncArticleToWordPressFromQueueJob (queue seo)
  Worker → ArticleEditorSyncOrchestrator::syncFromEditorBundle
         → persistLocalSilent + ensureWordPressPost + editor-sync (status=publish)
         → optional SyncArticleBodyMediaToWordPressJob
`

### 4.4 Scheduled publish

`	ext
everyMinute → seo:publish-scheduled-articles
  → ScheduledArticlePublishRunner
    → publishScheduledArticle (status=publish)
  PublishIntent chuẩn hóa: scheduled_publish
`

### 4.5 Content Project article write

`	ext
ViewSeoProjectRun / retryTask
  → SeoProjectWorkflowRunService::runOneTask
    → CreateArticlesFromTaskService::runPublishWorkflowForContext
      → TaskWorkflowTestRunner (save_article* → PromptTestPublishService local)
    → markTaskCompleted + PromptResultLink + Readiness (local meta)
  ✗ không WordPressArticleSyncService cho article body
`

### 4.6 post_comment_review outbound WP

```text
TaskWorkflowTestRunner actionType=post_comment_review
  → WordPressCommentReviewService::storeLocalFromAiOutput
  → ArticleProductReviewStoreService (table + article.product_reviews_generated)
  → schedule (max_delay_time) → DispatchScheduledProductReviewPublishJob
  → ProductReviewPublishDispatchService → article.product_review_publish_requested
  → Rule execute-wordpress-comment-review-publish (sync) → wordpress.comment_review.publish
  → POST /omi-seo-ai/v1/posts/{id}/virtual-comments (upsert _omi_review_id)
  → WP frontend: Virtual_Comments (CusRev compat ≥ 1.0.59)
```

Action chuẩn: wordpress.comment_review.publish

### 4.7 SEO Audit / Keyword → Project

`	ext
ArticleResource::assignArticlesToContentProject → INSERT seo_project_tasks
ArticlePendingInternalLinkService::assignFromEditor → task + pending link
✗ không WP article publish
`

## 5. Xác nhận phạm vi quét

| Loại | Kết quả |
|---|---|
| Jobs | 14 trong Jobs/ — WP article: SyncArticleToWordPressFromQueueJob, SyncArticleBodyMediaToWordPressJob; AnalyzeArticleSeoJob; domain/GSC/keyword/media/import khác |
| Laravel Listeners | Không có domain Listener / Event::listen cho article publish trong addon |
| Observers | SeoArticleObserver (TOC), SeoProjectObserver (notify), KeywordLinkListSyncObserver (link list) |
| Model boot | Keyword phrase normalize; SeoMedia auxiliary meta; SeoPrompt saving; SeoDatabaseConnection hash_id (core) |
| Scheduled | seo:publish-scheduled-articles everyMinute; cleanup notifications monthly |
| Console | PublishScheduled, BackfillPromptResultLinks, CleanCtaKeywords, ExtractOldArticleTocs |
| Filament/Livewire | EditArticle save/sync/approve; ListArticles/ListQueue; ArticlesOptimal; project run; Keyword pages |
| Static helpers | ArticleResource::assignArticlesToContentProject, quickCreateContentProject, syncGlobalSiteForArticle |
| Cross-DB | omi_seo_ai models + Site/User on mysql; SeoDatabaseConnection core; no cross-DB FK |

## 6. WordPress capability matrix

| Capability | Độc lập? | Catalog |
|---|---|---|
| fetch/inbound | Có | SyncDomainContentService |
| create_draft outbound | Không | không đăng ký |
| update không đổi publish status | Không | không có action update an toàn |
| sync_outbound hub | Có | wordpress.article.sync_outbound — legacy_not_selectable |
| publish | Có | wordpress.article.publish + PublishIntent |
| WP future schedule | Không | Laravel scheduled only |
| comment review push | Có | wordpress.comment_review.publish |

## 7. Vocabulary

Chốt: AUTOMATION_ACTION_CATALOG.md / AUTOMATION_EVENT_CATALOG.md.  
Rejected: wordpress.article.update.

## 8. Caller ưu tiên migrate (Phase 4)

1. Persist vs Orchestrator
2. Queue + cron → wordpress.article.publish + intent
3. Project article write → article.* / project.task.*
4. post_comment_review → wordpress.comment_review.publish
5. Assign → project.task.create*

## 9. Phase 2

Foundation: app/Addons/SeoContentAi/Automation/ — không migrate production callers.

## 10. Business Hook / Rule Engine (core)

Event → Rule → Conditions → Ordered Actions → Queue → Execution logs. Không lưu PHP class trong DB.

| Symbol | Vai trò | Path |
|--------|---------|------|
| `BusinessEventDispatcher` | Persist `business_events`, afterCommit match rules | `Automation/BusinessHook/Services/BusinessEventDispatcher.php` |
| `AutomationRuleMatcher` | Enabled rules + condition engine | `.../Services/AutomationRuleMatcher.php` |
| `AutomationExecutionService` | Claim/run actions, idempotency, delay continuation | `.../Services/AutomationExecutionService.php` |
| `ExecuteAutomationRuleJob` | Queue worker theo `automation_execution_id` | `.../Jobs/ExecuteAutomationRuleJob.php` |
| `BridgingAutomationEventDispatcher` | ActionRunner envelopes → business events | `.../Events/BridgingAutomationEventDispatcher.php` |
| `BusinessHookEmitter` | Emit từ archive / WP queue / run complete / task fail | `.../Support/BusinessHookEmitter.php` |
| `SyncArticleToWordPressHookAction` | `wordpress.article.sync` wrap `WordPressArticleSyncService` | `.../Actions/SyncArticleToWordPressHookAction.php` |

**Tables (`omi_seo_ai`):** `business_events` (`event_uuid` VARCHAR(64) — nhận cả UUID 36 và sha256 hex 64 từ `wordpressSyncedOnce` / HookAction idempotency), `automation_rules` (+`version`), `automation_rule_actions`, `automation_executions`, `automation_action_executions`.

**CLI:** `automation:migrate [--only-business-hook]`, `automation:seed-rules`, `automation:list-events|list-actions|dispatch|run-rule|retry|diagnose`, `automation:audit-wordpress-coupling [--strict]`.

**Seed rules (business enabled):** `sync-article-to-wordpress`, `dispatch-publish-request`, `seo-analysis-on-content-updated`, `notify-workflow-failure`. Product-review legacy rules (`publish-generated-*`, `publish-pending-*`, `execute-wordpress-comment-review-publish`) = **deprecated + hidden + disabled**. Graph sample stays disabled. List UI default: `classification=business` + `visibility=user`.

**Product Review ownership (2026-07-21 linear 3-action):**
- Business rule `article > wordpress` (`sync-article-to-wordpress`):
  1. `wordpress.article.sync` — article/product + media only
  2. `product-review.create` — idempotent `ProductReviewCreationPolicy` (`target_count` = maintain AI total; `block_if_real_reviews_exist`) → local pending only for `missing`
  3. `product-review.sync-wp` — idempotent WP create → `reviewed`
- Settings: `ProductReviewAutomationSettingsResolver` (rule action `product-review.create`, prefer `sync-article-to-wordpress`) — Manual Sync + editor API cùng nguồn
- Manual: `ArticleWordPressBusinessSequence` (same sequence; `sync_product_reviews` option)
- WordPress = SoT for display (`WordPressProductReviewStatusService` + `GET .../product-review-status`)
- Reviewed article: `deleteLocalForArticle` xóa local; **không** auto-run `ArticleQuickPostReviewService`
- Generated meta: `source=seo_content_ai`, `generated=true`, `_omi_*`
- Legacy schedule/queue/publish rules = deprecated+hidden+disabled

- Explicit manual sync: `WordPressManualSyncService` + `ManualSyncContext` + `ManualWordPressSyncJob` → `ArticleWordPressBusinessSequence` (+ resolver settings).

Cutover detail: [AUTOMATION_CUTOVER_AUDIT.md](AUTOMATION_CUTOVER_AUDIT.md).

**Invariant:** Content Project không sync WP trực tiếp; WP outbound automation chỉ khi rule enabled. Task completed + `article_id` → emit `content_project.task.completed` và `article.completed`.

**Cutover (2026-07-20) — WordPress coupling:**

- Automatic WordPress side effects require an enabled published Automation Rule.
- Disabled rule blocks future automatic executions; pending/processing get `cancellation_requested_at` and cancel at run/bootstrap (no WP side effect).
- Explicit manual sync: `WordPressManualSyncService` + `ManualSyncContext` + `ManualWordPressSyncJob` (queue `seo`). Does **not** require enabled Automation Rule. Emits real `wordpress.synced` (`origin=manual`) after success so pending product-review rule can run.
- Content Project / Article completion never dispatch `SyncArticleToWordPressFromQueueJob` / `WordPressArticleSyncService` directly.
- `ExecuteAutomationRuleJob` → queue `automation-critical`. WP action nodes → `automation-external`. Legacy manual job → `seo` (not `default`).
- `ArticleScheduleReconcileService` must not call WordPress.
- Duplicate enabled WP rules for same event: `AutomationRuleService::findConflictingWordpressRules` + audit command warn.

**UI:** Filament `AutomationRuleResource`, `AutomationExecutionResource` (group Automation).

**Migrate:** `php artisan automation:migrate --only-business-hook` (tránh full-folder migrate đụng bảng cũ).

**Invariants (hardening):**

- Automatic WordPress sync must go through Automation Engine.
- Manual sync is an explicit user action.
- Business events are emitted after commit.
- Rules store action codes, never PHP classes/methods.
- Run items remain Content Project execution source.
- Automation executions are separate from Content Project run items.
- Enable/disable does not bump rule version; config/action changes do.

## 11. Release freeze — Automation V2/V3 (2026-07-20)

| Layer | Contract |
|-------|----------|
| Task | Business identity (`source_key` + stable task ID) |
| Run item | Content Project execution attempt history |
| Automation execution | Workflow run; binds **immutable published version** |
| Draft nodes | Editor only — **never** execute |
| External side effect | Requires **enabled + published** rule |
| Scheduled occurrence | Idempotent (`rule_id` + version + scheduled_at) |
| Graph engine | Node jobs independent; delay = queue delay (no worker sleep) |

**V3 schema:** `automation_rule_versions` / `_nodes` / `_edges`, `automation_scheduler_heartbeats`; execution.`automation_rule_version_id`.

**CLI add:** `automation:migrate --only-v2|--only-v3`, `automation:migrate-rule-versions`, `automation:dispatch-scheduled`, `automation:recover-stale`, `automation:health`, `automation:export|import`.

**Release freeze (2026-07-20):** Executions never auto-publish. Graph/versioned rule without `published_version_id` → skip. `ensurePublishedVersion` chỉ cho migrate/admin CLI, không trên execution path.
**Seed:** production rules enabled+published by `AutomationDefaultRulesSeeder` promote helpers. Graph sample stays disabled. See [AUTOMATION_CUTOVER_AUDIT.md](AUTOMATION_CUTOVER_AUDIT.md).

**UI:** Visual builder `/seo/automation/workflow-builder`, Ops `/seo/automation/operations`.

## 12. Module SDK (2026-07-20)

Registry platform hóa — domain (WP, Content, SEO, Media) qua `Automation/Modules/*` providers. Core chỉ engine + `CoreAutomationModuleProvider`. Chi tiết: [MODULE_SDK.md](MODULE_SDK.md).

| Symbol | Vai trò | Path |
|--------|---------|------|
| `AutomationModuleProvider` | Contract đăng ký events/actions/conditions/menu/permissions/health/settings | `Automation/Platform/Contracts/` |
| `AutomationPlatformKernel` | Boot một lần module registry → wire singleton event/action registries | `Automation/Platform/` |
| `AutomationModuleRegistry` | Load modules từ `config/automation-modules.php` (không phụ thuộc config cache) | `Automation/Platform/` |
| `CoreAutomationModuleProvider` | delay, webhook, notification, dispatch_event | `Automation/Modules/Core/` |
| `WordPressAutomationModuleProvider` | WP events + `wordpress.article.sync` | `Automation/Modules/WordPress/` |
| `ContentAutomationModuleProvider` | article + content_project events + generate_content | `Automation/Modules/Content/` |
| `SeoAutomationModuleProvider` / `MediaAutomationModuleProvider` | SEO / media events + actions | `Automation/Modules/Seo|Media/` |
| `SampleAutomationModuleProvider` | Ví dụ SDK — disabled mặc định | `Automation/Modules/Sample/` |

