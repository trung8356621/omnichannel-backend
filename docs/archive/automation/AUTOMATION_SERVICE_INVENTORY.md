> Status: Historical
> Not canonical
> Archived on: 2026-08-01
> Superseded by: ../../modules/AUTOMATION.md
> Purpose: implementation history only
# Automation Service Inventory â€” SeoContentAi

**Phase:** living inventory (Business Hook + product review outbound)  
**Cáº­p nháº­t:** 2026-07-21  
**Nguá»“n chÃ¢n lÃ½:** code hiá»‡n táº¡i; docs MAP_* / ACTION_CATALOG / EVENT_CATALOG.  
**Canonical IDs / naming / selectability:** xem `AUTOMATION_BOUNDARIES.md`, `AUTOMATION_ACTION_CATALOG.md`.

## 1. Káº¿t luáº­n ngáº¯n

| Boundary | Runtime hiá»‡n táº¡i |
|---|---|
| Content Project â†’ Article | Local write (`PromptTestPublishService`, `CreateArticlesFromTaskService`) |
| Content Project â†’ WordPress **article** publish/sync | **KhÃ´ng** gá»i `WordPressArticleSyncService` |
| Content Project â†’ WordPress **khÃ¡c** | **KhÃ´ng** path riÃªng cho review. Product reviews: AI/UI â†’ local pending `article_product_reviews` â†’ `SyncArticleToWordPressPipeline` (cÃ¹ng `article > wordpress`) |
| Article Editor save | `BusinessActionDispatcher` â†’ `article.content.update` / `article.seo_meta.update` |
| Article Editor sync / queue / scheduled | Outbound WP; status payload **luÃ´n** `publish` |
| SEO Audit | Äá»c + skip meta + táº¡o `SeoProjectTask`; khÃ´ng sá»­a/publish article |
| Keyword vocab / topic cluster | Action Runtime; domain link list = Rule trÃªn `keyword.saved` |

**Rá»§i ro Ä‘áº·t tÃªn (gÃ¢y side-effect â€œáº©nâ€ trong quÃ¡ khá»© / khi design automation):**

- `PromptTestPublishService::publishArticle` = lÆ°u Laravel, **khÃ´ng** WP.
- `CreateArticlesFromTaskService::runPublishWorkflowForContext` = cháº¡y workflow táº¡o/cáº­p nháº­t bÃ i local.
- `ArticleEditorReadinessService::syncWpPostContentFromBody` = ghi meta local `wp_post_content`, **khÃ´ng** HTTP WP.
- `ArticleEditorSyncOrchestrator::syncFromEditorBundle` = **persist local + outbound WP** trong má»™t pipeline.
- Outbound article sync â‰ˆ publish trÃªn WP (`resolveWordPressStatusPayload` â†’ `status=publish`).

## 2. Docs vs code

| Claim | Status |
|---|---|
| Content Project + `PromptTestPublishService` local-only (article) | **ÄÃºng** â€” [MAP_SEO_PROJECTS](../MAP_SEO_PROJECTS.md) / [MAP_SEO_WP](../MAP_SEO_WP.md) |
| Project workflow khÃ´ng sync article WP; comment-review outbound qua Automation | **ÄÃºng** â€” [MAP_SEO_EDITOR](../MAP_SEO_EDITOR.md) Reviews + [ACTION_CATALOG](AUTOMATION_ACTION_CATALOG.md) |
| Outbound article sync luÃ´n `status=publish` | **ÄÃºng** |
| `SeoProjectApprovalService` chá»‰ status project + notify (khÃ´ng sync WP) | **ÄÃºng** |

## 3. Báº£ng inventory

ChÃº thÃ­ch cá»™t: R=Read DB, W=Write DB, J=Dispatch Job, E=External API, P=CÃ³ thá»ƒ publish WP article, S=Side effect áº©n / tÃªn lá»‡ch.

| Module | Class/Service/Job | Gá»i tá»« Ä‘Ã¢u | Input | Output | R | W | J | E | P | Side effect áº©n | Äá» xuáº¥t Action |
|---|---|---|---|---|---|---|---|---|---|---|---|
| Article | `ArticleEditorPersistService` | `ArticleEditorSyncController::save`, Orchestrator (silent), EditArticle paths | article + HTML + context | local save result | âœ“ | âœ“ | âœ“ scoring | â€” | â€” | Dispatch `AnalyzeArticleSeoJob`; mark sync-pending flag | `article.content.update` |
| Article | `ArticleEditorSeoMetaService` | API `saveSeoMeta`, editor | seo fields | meta saved | âœ“ | âœ“ | â€” | â€” | â€” | CÃ³ thá»ƒ Ä‘á»•i slug local | `article.seo_meta.update` |
| Article | `ArticleEditorBundleApplyService` | Persist pipeline | editor bundle | applied fields | âœ“ | âœ“ | â€” | â€” | â€” | Featured/album/post_type meta | (gá»™p vÃ o content/seo/media update) |
| Article | `ArticleEditorSyncOrchestrator` | Queue job, sync API | editor bundle | sync result steps | âœ“ | âœ“ | âœ“ media job | âœ“ WP | âœ“ | **Persist + ensure WP post + editor-sync** | **KhÃ´ng** map 1 action mÆ¡ há»“; tÃ¡ch `article.*` rá»“i `wordpress.article.*` |
| Article | `ArticleWpSyncQueueService` | Editor syncWp, ListArticles, ListQueue | article + bundle | queue meta | âœ“ | âœ“ | âœ“ | â€” | (deferred) | `applyPublishImmediatelyToBundle` Ã©p Laravel published | enqueue â†’ `wordpress.article.publish` (intent) / internal sync_outbound |
| Article | `SyncArticleToWordPressFromQueueJob` | Queue `seo` | article_id | orchestrator result | âœ“ | âœ“ | â€” | âœ“ | âœ“ | Retry path | same |
| Article | `SyncArticleBodyMediaToWordPressJob` | Orchestrator sau sync | article_id | media sync | âœ“ | âœ“ | â€” | âœ“ | â€” | Media only | internal media path (chÆ°a action selectable) |
| Article | `WordPressArticleSyncService` | EditArticle, ListArticles, Orchestrator, Scheduled, FaqSync | article | WP result | âœ“ | âœ“ | âœ“ | âœ“ | âœ“ | Hub outbound; status luÃ´n publish | `wordpress.article.publish` + legacy `wordpress.article.sync_outbound` (not selectable) |
| Article | `ScheduledArticlePublishRunner` | cron `seo:publish-scheduled-articles` | due articles | stats | âœ“ | âœ“ | â€” | âœ“ | âœ“ | intent=`scheduled_publish` | `wordpress.article.publish` |
| Article | `ArticleScheduleReconcileService` | EditArticle hydrate | article | status reconcile | âœ“ | âœ“ | â€” | (read?) | â€” | CÃ³ thá»ƒ Ä‘á»•i local status khi quÃ¡ háº¡n | `article.schedule.reconcile` (internal) |
| Article | `SeoArticleObserver` | model saved | article | TOC meta | âœ“ | âœ“ | â€” | â€” | â€” | TOC extract | (khÃ´ng cáº§n action workflow) |
| Article | `ArticleWordPressSyncFlagService` | Persist / PromptTestPublish | article | meta flags | âœ“ | âœ“ | â€” | â€” | â€” | ÄÃ¡nh dáº¥u dirty sync | internal |
| Article | `ArticleEditorReadinessService` | Project run post-success | article | readiness DTO | âœ“ | âœ“ | â€” | â€” | â€” | TÃªn `syncWpPostContent*` chá»‰ local meta | internal / `article.readiness.refresh` |
| Project | `SeoProjectWorkflowRunService` | Filament run pages | project/run/task | run items | âœ“ | âœ“ | â€” | â€” | â€” | Orchestrate local article create; post-success links/readiness | **KhÃ´ng** `project.run_everything`; dÃ¹ng `project.task.run` má»ng |
| Project | `CreateArticlesFromTaskService` | WorkflowRunService | TaskTestContext | article_id + steps | âœ“ | âœ“ | â€” | â€” | â€” | TÃªn â€œPublishâ€; gá»i domain link keyword sync | `project.task.run` â†’ internal article.create/update |
| Project | `TaskWorkflowTestRunner` | CreateArticles, TestTask, Editor media WF | SeoTask + context | steps | âœ“ | âœ“ | â€” | **local only** `post_comment_review` | comment WP via Automation | Action nodes: save_article (local), save_vocabulary, **post_comment_review (local+event)** | map tá»«ng action_type â†’ business action |
| Project | `PromptTestPublishService` | TaskWorkflowTestRunner | AI output | local article | âœ“ | âœ“ | â€” | â€” | â€” | TÃªn publish; analyze SEO local; mark pending sync | `article.content.update` / create |
| Project | `SeoProjectTaskSyncService` | Create/Edit project | tasksData | tasks rows | âœ“ | âœ“ | â€” | â€” | â€” | Monthly limit | `project.task.create` (bulk sync) |
| Project | `SeoProjectApprovalService` | EditArticle / ArticleResource approve | article + user | project approved | âœ“ | âœ“ | â€” | â€” | â€” | Relink task article_id; notify | `project.approve` / `article.approve` |
| Project | `SeoProjectArticleOwnerSyncService` | project owner change | project | articles user_id | âœ“ | âœ“ | â€” | â€” | â€” | | `project.article_owner.sync` |
| Project | `SeoProjectObserver` | project create/update | project | notification | âœ“ | â€” | â€” | â€” | â€” | | event only |
| Project | `PromptResultLinkService` | WorkflowRunService | steps + ids | links | âœ“ | âœ“ | â€” | â€” | â€” | | internal |
| Project | `ArticlePendingInternalLinkService` | EditArticle assign keyword | phrase + project | task + pending link | âœ“ | âœ“ | â€” | â€” | â€” | Táº¡o task project | `keyword.pending_internal_link.create` |
| Audit | `ArticlesOptimal` (Livewire) | UI | filters / ids | scan UI | âœ“ | âœ“ skip | â€” | â€” | â€” | Skip = meta only | UI |
| Audit | `SeoAuditScanService` | ArticlesOptimal | query + rules | rows | âœ“ | â€” | â€” | â€” | â€” | | `seo.audit.run` (read) |
| Audit | `SeoAnalyzerService` / `AnalyzeArticleSeoJob` | Persist, inbound sync, job | article | score + violations | âœ“ | âœ“ | â€” | â€” | â€” | Ghi score local | `seo.audit.analyze_article` |
| Audit | `AssignmentCallerBridge` | ArticleResource assign (flag) | articles + project | summary | âœ“ | âœ“ | â€” | â€” | â€” | Phase 4A legacy/shadow/action | `seo.project_task.create_from_issue` |
| Keyword | `AssignmentCallerBridge` | KeywordResource assign (flag) | keywords + project | summary | âœ“ | âœ“ | â€” | â€” | â€” | Phase 4A | `keyword.assign_to_project` |
| Project | `ProjectArticleCreateCallerBridge` | **wired** â€” `CreateArticlesFromTaskService` (default legacy) | input + legacy/action callables | normalized output | â€” | â€” | â€” | â€” | â€” | Flag `project_article_create` | `article.create` |
| Project | `ProjectArticleContentCallerBridge` | **wired** â€” `PromptTestPublishService::publishArticle` (default legacy) | content input + state snapshot | normalized output | â€” | â€” | â€” | â€” | â€” | Flag `project_article_content_update` | `article.content.update` |
| Project | `ProjectArticleSeoMetaCallerBridge` | **wired** â€” `PromptTestPublishService::persistMetaDescription` (default legacy) | seo meta input + state | normalized output | â€” | â€” | scoring deferred khi `dispatch_scoring=false` | â€” | â€” | Flag `project_article_seo_meta_update` | `article.seo_meta.update` |
| Keyword | `KeywordPersistenceService` | Keyword UI / discovery | phrase + site | keyword | âœ“ | âœ“ | â€” | â€” | â€” | Link list attach | `keyword.create` / `.update` |
| Keyword | `WorkflowKeywordResearchService` | TaskWorkflowTestRunner | groups | topic cluster | âœ“ | âœ“ | â€” | â€” | â€” | CTA blacklist khÃ´ng cháº·n focus | `keyword.vocabulary.save` / `keyword.topic_cluster.sync` |
| Keyword | `KeywordLinkListSyncObserver` | Keyword saved/deleted | keyword | domain link list | âœ“ | âœ“ | â€” | â€” | â€” | Side effect domain settings | `keyword.domain_link_list.sync` |
| Keyword | `AiKeywordDiscoveryService` | AiKeywordDiscovery page | seed | suggestions | â€” | â€” | â€” | âœ“ AI/SERP? | â€” | KhÃ´ng táº¡o article | `keyword.discover` (read/external) |
| Keyword | `DomainLinkListKeywordSyncService` | CreateArticles, Observer | site + phrase | link list | âœ“ | âœ“ | â€” | â€” | â€” | | site settings write |
| Domain/WP inbound | `SyncDomainContentService` | WP bridge push | WP payload | local articles | âœ“ | âœ“ | âœ“ scoring | âœ“ inbound | â€” | Pull WP â†’ Laravel | `wordpress.article.fetch` / import |
| WP | `WordPressFaqSyncService` | (wrapper) | article | delegates syncForArticle | âœ“ | âœ“ | â€” | âœ“ | âœ“ | Wrapper rá»™ng | deprecate / map sync_outbound (not selectable) |
| WP | `WordPressLocalMediaSyncService` | Sync prepare/complete | article HTML | media IDs | âœ“ | âœ“ | â€” | âœ“ | â€” | | `wordpress.article.update_media` |
| WP | `WordPressProductReviewService` / `ProductReviewLocalBatchCreator` / `ArticleWordPressBusinessSequence` | Linear actions + manual + editor API | AI/UI/batch | local pending | âœ“ | â€” | â€” | via `product-review.sync-wp` | â€” | **Outbound** `product-review.sync-wp` | `product-review.create` + `product-review.sync-wp` |
| Site | Site / connection models + `SeoAccessControl` | má»i nÆ¡i | ids | scope | âœ“ | â€” | â€” | â€” | â€” | Cross-DB | `AutomationSiteContextResolver` (khÃ´ng CRUD action) |

## 4. Call path chi tiáº¿t (Ä‘Ã£ xÃ¡c nháº­n code)

### 4.1 Local article save

```text
API POST /api/seo/articles/{id}/save
  â†’ ArticleEditorSyncController::save
    â†’ BusinessActionDispatcher â†’ article.content.update
      â†’ UpdateArticleContentAction
        â†’ ArticleEditorPersistService::persistLocal
      â†’ emit article.content_updated (Action owns)
  âœ— khÃ´ng WordPressArticleSyncService
  âœ— khÃ´ng enqueue WP queue
  âœ— PersistService khÃ´ng emit BusinessHook

API POST .../seo-meta
  â†’ BusinessActionDispatcher â†’ article.seo_meta.update
    â†’ ArticleEditorSeoMetaService::persist
    â†’ emit article.seo_meta_updated + article.content_updated
```

### 4.2 Editor WP sync (phased / publishForArticle)

`	ext
EditArticle Sync / Alpine __seoRunWordPressPhasedSync
  â†’ WordPressArticleSyncService::publishForArticle
       OR prepareEditorSyncPayload â†’ executeEditorSyncRequest â†’ completeEditorSyncResponse
  â†’ resolveWordPressStatusPayload â†’ status=publish
  â†’ HTTP REST plugin
  Intent â‰ˆ manual_publish (caller chÆ°a gáº¯n enum)
`

### 4.3 Queue WP sync

`	ext
Publish tab / API syncWp / Ctrl+Shift+S
  â†’ ArticleEditorSyncController::syncWp
    â†’ ArticleWpSyncQueueService::enqueueFromEditorBundle
         (optional applyPublishImmediatelyToBundle)
    â†’ SyncArticleToWordPressFromQueueJob (queue seo)
  Worker â†’ ArticleEditorSyncOrchestrator::syncFromEditorBundle
         â†’ persistLocalSilent + ensureWordPressPost + editor-sync (status=publish)
         â†’ optional SyncArticleBodyMediaToWordPressJob
`

### 4.4 Scheduled publish

`	ext
everyMinute â†’ seo:publish-scheduled-articles
  â†’ ScheduledArticlePublishRunner
    â†’ publishScheduledArticle (status=publish)
  PublishIntent chuáº©n hÃ³a: scheduled_publish
`

### 4.5 Content Project article write

`	ext
ViewSeoProjectRun / retryTask
  â†’ SeoProjectWorkflowRunService::runOneTask
    â†’ CreateArticlesFromTaskService::runPublishWorkflowForContext
      â†’ TaskWorkflowTestRunner (save_article* â†’ PromptTestPublishService local)
    â†’ markTaskCompleted + PromptResultLink + Readiness (local meta)
  âœ— khÃ´ng WordPressArticleSyncService cho article body
`

### 4.6 post_comment_review outbound WP

```text
TaskWorkflowTestRunner actionType=post_comment_review
  â†’ WordPressCommentReviewService::storeLocalFromAiOutput
  â†’ ArticleProductReviewStoreService (table + article.product_reviews_generated)
  â†’ schedule (max_delay_time) â†’ DispatchScheduledProductReviewPublishJob
  â†’ ProductReviewPublishDispatchService â†’ article.product_review_publish_requested
  â†’ Rule execute-wordpress-comment-review-publish (sync) â†’ wordpress.comment_review.publish
  â†’ POST /omi-seo-ai/v1/posts/{id}/virtual-comments (upsert _omi_review_id)
  â†’ WP frontend: Virtual_Comments (CusRev compat â‰¥ 1.0.59)
```

Action chuáº©n: wordpress.comment_review.publish

### 4.7 SEO Audit / Keyword â†’ Project

`	ext
ArticleResource::assignArticlesToContentProject â†’ INSERT seo_project_tasks
ArticlePendingInternalLinkService::assignFromEditor â†’ task + pending link
âœ— khÃ´ng WP article publish
`

## 5. XÃ¡c nháº­n pháº¡m vi quÃ©t

| Loáº¡i | Káº¿t quáº£ |
|---|---|
| Jobs | 14 trong Jobs/ â€” WP article: SyncArticleToWordPressFromQueueJob, SyncArticleBodyMediaToWordPressJob; AnalyzeArticleSeoJob; domain/GSC/keyword/media/import khÃ¡c |
| Laravel Listeners | KhÃ´ng cÃ³ domain Listener / Event::listen cho article publish trong addon |
| Observers | SeoArticleObserver (TOC) â€” audit-only this phase; SeoProjectObserver (**noop**, no notify); KeywordLinkListSyncObserver (emit BusinessHook only) |
| Model boot | Keyword phrase normalize; SeoMedia auxiliary meta; SeoPrompt saving; SeoDatabaseConnection hash_id (core) |
| Scheduled | seo:publish-scheduled-articles everyMinute; cleanup notifications monthly |
| Console | PublishScheduled, BackfillPromptResultLinks, CleanCtaKeywords, ExtractOldArticleTocs |
| Filament/Livewire | EditArticle save/sync/approve; ListArticles/ListQueue; ArticlesOptimal; project run; Keyword pages |
| Static helpers | ArticleResource::assignArticlesToContentProject, quickCreateContentProject, syncGlobalSiteForArticle |
| Cross-DB | Domain SEO models trÃªn `omi_seo_ai`; Site/User + **automation tables** trÃªn core (`config('automation.connection')`); SeoDatabaseConnection core; no cross-DB FK |

## 6. WordPress capability matrix

| Capability | Äá»™c láº­p? | Catalog |
|---|---|---|
| fetch/inbound | CÃ³ | SyncDomainContentService |
| create_draft outbound | KhÃ´ng | khÃ´ng Ä‘Äƒng kÃ½ |
| update khÃ´ng Ä‘á»•i publish status | KhÃ´ng | khÃ´ng cÃ³ action update an toÃ n |
| sync_outbound hub | CÃ³ | wordpress.article.sync_outbound â€” legacy_not_selectable |
| publish | CÃ³ | wordpress.article.publish + PublishIntent |
| WP future schedule | KhÃ´ng | Laravel scheduled only |
| comment review push | CÃ³ | wordpress.comment_review.publish |

## 7. Vocabulary

Chá»‘t: AUTOMATION_ACTION_CATALOG.md / AUTOMATION_EVENT_CATALOG.md.  
Rejected: wordpress.article.update.

## 8. Caller Æ°u tiÃªn migrate (Phase 4)

1. Persist vs Orchestrator
2. Queue + cron â†’ wordpress.article.publish + intent
3. Project article write â†’ article.* / project.task.*
4. post_comment_review â†’ wordpress.comment_review.publish
5. Assign â†’ project.task.create*

## 9. Phase 2

Foundation: app/Addons/SeoContentAi/Automation/ â€” khÃ´ng migrate production callers.

## 10. Business Hook / Rule Engine (core)

Event â†’ Rule â†’ Conditions â†’ Ordered Actions â†’ Queue â†’ Execution logs. KhÃ´ng lÆ°u PHP class trong DB.

| Symbol | Vai trÃ² | Path |
|--------|---------|------|
| `BusinessEventDispatcher` | Persist `business_events`, afterCommit match rules | `Automation/BusinessHook/Services/BusinessEventDispatcher.php` |
| `AutomationRuleMatcher` | Enabled rules + condition engine | `.../Services/AutomationRuleMatcher.php` |
| `AutomationExecutionService` | Claim/run actions, idempotency, delay continuation | `.../Services/AutomationExecutionService.php` |
| `ExecuteAutomationRuleJob` | Queue worker theo `automation_execution_id` | `.../Jobs/ExecuteAutomationRuleJob.php` |
| `BridgingAutomationEventDispatcher` | ActionRunner envelopes â†’ business events | `.../Events/BridgingAutomationEventDispatcher.php` |
| `BusinessHookEmitter` | Emit tá»« archive / WP queue / run complete / task fail | `.../Support/BusinessHookEmitter.php` |
| `SyncArticleToWordPressHookAction` | `wordpress.article.sync` wrap `WordPressArticleSyncService` | `.../Actions/SyncArticleToWordPressHookAction.php` |

**Tables (core DB via `config('automation.connection')` / `AUTOMATION_DB_CONNECTION`):** `business_events` (`event_uuid` VARCHAR(64) â€” UUID 36 hoáº·c sha256 hex 64), `automation_rules` (+ graph/version/schedule columns), `automation_rule_actions|nodes|edges`, `automation_rule_versions` (+ version nodes/edges), `automation_executions`, `automation_action_executions`, `automation_node_executions`, `automation_action_runs`, `automation_scheduler_heartbeats`. Schema: `database/migrations/2026_07_23_140000_create_core_automation_tables.php`. Models: `App\Support\Automation\AutomationModel`. Copy/verify: `php artisan automation:migrate-to-core`.

**CLI:** `automation:migrate-to-core`, `automation:migrate` (ensure core schema), `automation:seed-rules`, `automation:list-events|list-actions|dispatch|run-rule|retry|diagnose`, `automation:audit-wordpress-coupling [--strict]`.

**Seed rules (business enabled):** `sync-article-to-wordpress`, `dispatch-publish-request`, `seo-analysis-on-content-updated`, `notify-workflow-failure`. Product-review legacy rules (`publish-generated-*`, `publish-pending-*`, `execute-wordpress-comment-review-publish`) = **deprecated + hidden + disabled**. Graph sample stays disabled. List UI default: `classification=business` + `visibility=user`.

**Product Review ownership (2026-07-21 linear 3-action):**
- Business rule `article > wordpress` (`sync-article-to-wordpress`):
  1. `wordpress.article.sync` â€” article/product + media only
  2. `product-review.create` â€” idempotent `ProductReviewCreationPolicy` (`target_count` = maintain AI total; `block_if_real_reviews_exist`) â†’ local pending only for `missing`
  3. `product-review.sync-wp` â€” idempotent WP create â†’ `reviewed`
- Settings: `ProductReviewAutomationSettingsResolver` (rule action `product-review.create`, prefer `sync-article-to-wordpress`) â€” Manual Sync + editor API cÃ¹ng nguá»“n
- Manual: `ArticleWordPressBusinessSequence` (same sequence; `sync_product_reviews` option)
- WordPress = SoT for display (`WordPressProductReviewStatusService` + `GET .../product-review-status`)
- Reviewed article: `deleteLocalForArticle` xÃ³a local; **khÃ´ng** auto-run `ArticleQuickPostReviewService`
- Generated meta: `source=seo_content_ai`, `generated=true`, `_omi_*`
- Legacy schedule/queue/publish rules = deprecated+hidden+disabled

- Explicit manual sync: `WordPressManualSyncService` + `ManualSyncContext` + `ManualWordPressSyncJob` â†’ `ArticleWordPressBusinessSequence` (+ resolver settings).

Cutover detail: [AUTOMATION_CUTOVER_AUDIT.md](AUTOMATION_CUTOVER_AUDIT.md).

**Invariant:** Content Project khÃ´ng sync WP trá»±c tiáº¿p; WP outbound automation chá»‰ khi rule enabled. Task completed + `article_id` â†’ emit `content_project.task.completed` vÃ  `article.completed`.

**Cutover (2026-07-20) â€” WordPress coupling:**

- Automatic WordPress side effects require an enabled published Automation Rule.
- Disabled rule blocks future automatic executions; pending/processing get `cancellation_requested_at` and cancel at run/bootstrap (no WP side effect).
- Explicit manual sync: `WordPressManualSyncService` + `ManualSyncContext` + `ManualWordPressSyncJob` (queue `seo`). Does **not** require enabled Automation Rule. Emits real `wordpress.synced` (`origin=manual`) after success so pending product-review rule can run.
- Content Project / Article completion never dispatch `SyncArticleToWordPressFromQueueJob` / `WordPressArticleSyncService` directly.
- `ExecuteAutomationRuleJob` â†’ queue `automation-critical`. WP action nodes â†’ `automation-external`. Legacy manual job â†’ `seo` (not `default`).
- `ArticleScheduleReconcileService` must not call WordPress.
- Duplicate enabled WP rules for same event: `AutomationRuleService::findConflictingWordpressRules` + audit command warn.

**UI:** Filament `AutomationRuleResource`, `AutomationExecutionResource` (group Automation).

**Migrate:** `php artisan automation:migrate --only-business-hook` (trÃ¡nh full-folder migrate Ä‘á»¥ng báº£ng cÅ©).

**Invariants (hardening):**

- Automatic WordPress sync must go through Automation Engine.
- Manual sync is an explicit user action.
- Business events are emitted after commit.
- Rules store action codes, never PHP classes/methods.
- Run items remain Content Project execution source.
- Automation executions are separate from Content Project run items.
- Enable/disable does not bump rule version; config/action changes do.

## 11. Release freeze â€” Automation V2/V3 (2026-07-20)

| Layer | Contract |
|-------|----------|
| Task | Business identity (`source_key` + stable task ID) |
| Run item | Content Project execution attempt history |
| Automation execution | Workflow run; binds **immutable published version** |
| Draft nodes | Editor only â€” **never** execute |
| External side effect | Requires **enabled + published** rule |
| Scheduled occurrence | Idempotent (`rule_id` + version + scheduled_at) |
| Graph engine | Node jobs independent; delay = queue delay (no worker sleep) |

**V3 schema:** `automation_rule_versions` / `_nodes` / `_edges`, `automation_scheduler_heartbeats`; execution.`automation_rule_version_id`.

**CLI add:** `automation:migrate --only-v2|--only-v3`, `automation:migrate-rule-versions`, `automation:dispatch-scheduled`, `automation:recover-stale`, `automation:health`, `automation:export|import`.

**Release freeze (2026-07-20):** Executions never auto-publish. Graph/versioned rule without `published_version_id` â†’ skip. `ensurePublishedVersion` chá»‰ cho migrate/admin CLI, khÃ´ng trÃªn execution path.
**Seed:** production rules enabled+published by `AutomationDefaultRulesSeeder` promote helpers. Graph sample stays disabled. See [AUTOMATION_CUTOVER_AUDIT.md](AUTOMATION_CUTOVER_AUDIT.md).

**UI:** Visual builder `/seo/automation/workflow-builder`, Ops `/seo/automation/operations`.

## 12. Module SDK (2026-07-20)

Registry platform hÃ³a â€” domain (WP, Content, SEO, Media) qua `Automation/Modules/*` providers. Core chá»‰ engine + `CoreAutomationModuleProvider`. Chi tiáº¿t: [MODULE_SDK.md](MODULE_SDK.md).

| Symbol | Vai trÃ² | Path |
|--------|---------|------|
| `AutomationModuleProvider` | Contract Ä‘Äƒng kÃ½ events/actions/conditions/menu/permissions/health/settings | `Automation/Platform/Contracts/` |
| `AutomationPlatformKernel` | Boot má»™t láº§n module registry â†’ wire singleton event/action registries | `Automation/Platform/` |
| `AutomationModuleRegistry` | Load modules tá»« `config/automation-modules.php` (khÃ´ng phá»¥ thuá»™c config cache) | `Automation/Platform/` |
| `CoreAutomationModuleProvider` | delay, webhook, notification, dispatch_event | `Automation/Modules/Core/` |
| `WordPressAutomationModuleProvider` | WP events + `wordpress.article.sync` | `Automation/Modules/WordPress/` |
| `ContentAutomationModuleProvider` | article + content_project events + generate_content | `Automation/Modules/Content/` |
| `SeoAutomationModuleProvider` / `MediaAutomationModuleProvider` | SEO / media events + actions | `Automation/Modules/Seo|Media/` |
| `SampleAutomationModuleProvider` | VÃ­ dá»¥ SDK â€” disabled máº·c Ä‘á»‹nh | `Automation/Modules/Sample/` |

