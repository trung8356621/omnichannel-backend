> Status: Historical
> Not canonical
> Archived on: 2026-08-01
> Superseded by: ../../modules/AUTOMATION.md
> Purpose: implementation history only
# Automation Event Catalog â€” SeoContentAi

**Status:** Phase 1 chá»‘t + Phase 2 envelope  
**Updated:** 2026-07-18

## Naming convention (LOCKED)

Má»™t convention duy nháº¥t cho **event keys**:

```text
<module>.<past_tense_phrase>
```

Rules:

1. ToÃ n bá»™ lowercase, dot-separated.
2. Segment cuá»‘i = **past tense / completed**, dÃ¹ng `snake_case`: `content_updated`, `task_created`, `article_published`.
3. KhÃ´ng dÃ¹ng dáº¡ng action (`article.content.update`) cho event.
4. KhÃ´ng nhÃ¢n báº£n cÃ¹ng nghÄ©a (`article.published` vs `article.publish_completed` â€” chá»n **má»™t**; catalog dÃ¹ng `article.published` cho local status, `wordpress.article_published` cho outbound).
5. Module WordPress outbound: prefix `wordpress.`.

## Envelope chuáº©n

```json
{
  "event_key": "article.content_updated",
  "event_id": "uuid",
  "occurred_at": "ISO-8601",
  "entity": { "type": "article", "id": 123 },
  "context": {
    "correlation_id": "uuid",
    "causation_id": "uuid",
    "origin": "project.task.run",
    "actor_id": 10,
    "team_id": null,
    "site_id": 5,
    "connection_id": 2
  },
  "payload": {
    "changed_fields": ["content"]
  }
}
```

Canonical context IDs: xem `AUTOMATION_BOUNDARIES.md` Â§ Canonical IDs. DÃ¹ng `site_id`, **khÃ´ng** `website_id`.

## Authorization note

`article.publish_requested` = tÃ­n hiá»‡u/audit.  
**KhÃ´ng** tá»± cáº¥p quyá»n cháº¡y `wordpress.article.publish`. Runner pháº£i check permission + `PublishIntent` há»£p lá»‡.

## Catalog

| event_key | entity | payload gá»£i Ã½ | Producer |
|---|---|---|---|
| `article.created` | article | `post_type`, `site_id` | local create |
| `article.content_updated` | article | `changed_fields` | **UpdateArticleContentAction** / **UpdateArticleSeoMetaAction** (bridge) |
| `article.seo_meta_updated` | article | keys | **UpdateArticleSeoMetaAction** |
| `article.review_requested` | article | reason? | review flow |
| `article.approved` | article | `project_id` | **ApproveArticleAction** |
| `keyword.saved` | keyword | phrase, site_id, operation | KeywordLinkListSyncObserver (emit only) |
| `article.publish_requested` | article | `publish_intent` | trÆ°á»›c queue/cron â€” **khÃ´ng authorize** |
| `article.published` | article | local `status` | local â†’ published |
| `project.created` | project | `site_id` | SeoProject create |
| `project.task_created` | project_task | `type`, `article_id?` | task assign/sync |
| `project.task_completed` | project_task | `article_id` | WorkflowRunService |
| `project.run_started` | project_run | `mode` | startRun |
| `project.run_completed` | project_run | counts | completeRunQueue |
| `project.run_failed` | project_run | error | failure |
| `project.approved` | project | `article_id` | ApprovalService |
| `seo.audit_completed` | site/scan | counts | SeoAuditScanService |
| `seo.issue_detected` | article | rule keys | SeoAnalyzer |
| `seo.issue_skipped` | article | `skip_seo_audit` | ArticlesOptimal |
| `keyword.created` | keyword | `phrase`, `site_id` | KeywordPersistence |
| `keyword.assigned_to_project` | keyword/task | `project_id` | assign |
| `keyword.vocabulary_saved` | article/keyword | groups | WorkflowKeywordResearch |
| `keyword.topic_cluster_synced` | article | counts | syncTopicCluster |
| `wordpress.article_created` | article | `wp_post_id` | createForArticle |
| `wordpress.article_updated` | article | fingerprint | sync_outbound hub |
| `wordpress.article_published` | article | `wp_post_id`, `publish_intent` | publish* |
| `wordpress.synced` | article | `sync_operation_id`, `origin` | ManualJob / SyncArticleToWordPressHookAction (dedupe by `event_uuid` = `sync_operation_id`; HookAction dÃ¹ng sha256 64 hex â†’ cá»™t `business_events.event_uuid` VARCHAR(64), migration `2026_07_22_120000_widen_business_events_event_uuid`) |
| `wordpress.comment_review_published` | article/review | `review_id`, `wp_comment_id`, `deduplicated` | PublishWordPressCommentReviewHookAction |
| `wordpress.comment_review_publish_failed` | article/review | `review_id`, `error_code` | PublishWordPressCommentReviewHookAction |
| `article.product_reviews_generated` | article | `review_ids`, `review_count`, `connection_id` | ArticleProductReviewStoreService |
| `article.product_review_publish_requested` | article/review | `review_id`, `publish_intent` | StoreService fan-out / QueuePending |

## Emit rules (Phase 3)

- Chá»‰ emit khi `ActionResult.success` vÃ  cÃ³ thay Ä‘á»•i tháº­t (`changed` / khÃ´ng `deduplicated` no-op táº¡o má»›i).
- Dry-run / failure: khÃ´ng emit.
- Assign actions: náº¿u `deduplicated=true` vÃ  `added=0` â†’ khÃ´ng emit `*.created` giáº£.
