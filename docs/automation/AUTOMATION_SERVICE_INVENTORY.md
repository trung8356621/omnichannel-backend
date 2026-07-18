# Automation Service Inventory — SeoContentAi

**Phase:** 1 audit + 1b lock (Phase 2 foundation riêng)  
**Ngày:** 2026-07-18  
**Nguồn chân lý:** code hiện tại; docs MAP_* dùng để định vị, đã đối chiếu khi mâu thuẫn.  
**Canonical IDs / naming / selectability:** xem `AUTOMATION_BOUNDARIES.md`, `AUTOMATION_ACTION_CATALOG.md`.

## 1. Kết luận ngắn

| Boundary | Runtime hiện tại |
|---|---|
| Content Project → Article | Local write (`PromptTestPublishService`, `CreateArticlesFromTaskService`) |
| Content Project → WordPress **article** publish/sync | **Không** gọi `WordPressArticleSyncService` |
| Content Project → WordPress **khác** | **Có** nếu workflow có node `post_comment_review` → `WordPressCommentReviewService` |
| Article Editor save | Local only (`ArticleEditorPersistService`) |
| Article Editor sync / queue / scheduled | Outbound WP; status payload **luôn** `publish` |
| SEO Audit | Đọc + skip meta + tạo `SeoProjectTask`; không sửa/publish article |
| Keyword vocab / topic cluster | Local DB (+ domain link list); không publish article WP |

**Rủi ro đặt tên (gây side-effect “ẩn” trong quá khứ / khi design automation):**

- `PromptTestPublishService::publishArticle` = lưu Laravel, **không** WP.
- `CreateArticlesFromTaskService::runPublishWorkflowForContext` = chạy workflow tạo/cập nhật bài local.
- `ArticleEditorReadinessService::syncWpPostContentFromBody` = ghi meta local `wp_post_content`, **không** HTTP WP.
- `ArticleEditorSyncOrchestrator::syncFromEditorBundle` = **persist local + outbound WP** trong một pipeline.
- Outbound article sync ≈ publish trên WP (`resolveWordPressStatusPayload` → `status=publish`).

## 2. Docs vs code

| Claim trong docs | Code |
|---|---|
| MAP_SEO_PROJECTS / MAP_SEO_WP: Content Project + `PromptTestPublishService` local-only | **Đúng** cho article content |
| MAP_SEO_EDITOR §2.6.1: Outbound gồm “workflow” | **Sai / mơ hồ** — project workflow không sync article WP; chỉ comment-review node mới outbound |
| Outbound không gửi draft / luôn publish | **Đúng** (`resolveWordPressStatusPayload`) |
| Approval project sync WP | **Sai nếu hiểu vậy** — `SeoProjectApprovalService` chỉ đổi status project + notify |

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
| Project | `TaskWorkflowTestRunner` | CreateArticles, TestTask, Editor media WF | SeoTask + context | steps | ✓ | ✓ | — | **có nếu** `post_comment_review` | comment WP | Action nodes: save_article (local), save_vocabulary, **post_comment_review (WP)** | map từng action_type → business action |
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
| WP | `WordPressCommentReviewService` | TaskWorkflowTestRunner, TestPrompt | AI comments | WP meta comments | ✓ | — | — | ✓ | — | **Outbound từ workflow** | `wordpress.comment_review.publish` |
| Site | Site / connection models + `SeoAccessControl` | mọi nơi | ids | scope | ✓ | — | — | — | — | Cross-DB | `AutomationSiteContextResolver` (không CRUD action) |

## 4. Call path chi tiết (đã xác nhận code)

### 4.1 Local article save

`	ext
UI (React saveDraft localStorage) — không server

API POST /api/seo/articles/{id}/save
  → ArticleEditorSyncController::save
    → ArticleEditorPersistService::persistLocal
      → guard empty body
      → persistLocalSilent (BundleApply + post images + markLocalEditPending)
      → SeoArticleScoringQueueService::dispatchForArticle (AnalyzeArticleSeoJob)
  ✗ không WordPressArticleSyncService
  ✗ không enqueue WP queue

Livewire EditArticle persistArticleLocal (path cũ) → cùng PersistService
`

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

`	ext
TaskWorkflowTestRunner actionType=post_comment_review
  → WordPressCommentReviewService::publishFromAiOutput
    → requires wp_post_id + canSyncArticlesToWordPress
    → VirtualCommentService::pushToWordPress
  Action chuẩn: wordpress.comment_review.publish
`

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
