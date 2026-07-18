# Automation Event Catalog — SeoContentAi

**Status:** Phase 1 chốt + Phase 2 envelope  
**Updated:** 2026-07-18

## Naming convention (LOCKED)

Một convention duy nhất cho **event keys**:

```text
<module>.<past_tense_phrase>
```

Rules:

1. Toàn bộ lowercase, dot-separated.
2. Segment cuối = **past tense / completed**, dùng `snake_case`: `content_updated`, `task_created`, `article_published`.
3. Không dùng dạng action (`article.content.update`) cho event.
4. Không nhân bản cùng nghĩa (`article.published` vs `article.publish_completed` — chọn **một**; catalog dùng `article.published` cho local status, `wordpress.article_published` cho outbound).
5. Module WordPress outbound: prefix `wordpress.`.

## Envelope chuẩn

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

Canonical context IDs: xem `AUTOMATION_BOUNDARIES.md` § Canonical IDs. Dùng `site_id`, **không** `website_id`.

## Authorization note

`article.publish_requested` = tín hiệu/audit.  
**Không** tự cấp quyền chạy `wordpress.article.publish`. Runner phải check permission + `PublishIntent` hợp lệ.

## Catalog

| event_key | entity | payload gợi ý | Producer |
|---|---|---|---|
| `article.created` | article | `post_type`, `site_id` | local create |
| `article.content_updated` | article | `changed_fields` | Persist / PromptTestPublish |
| `article.seo_meta_updated` | article | keys | SeoMeta |
| `article.review_requested` | article | reason? | review flow |
| `article.approved` | article | `project_id` | ApprovalService |
| `article.publish_requested` | article | `publish_intent` | trước queue/cron — **không authorize** |
| `article.published` | article | local `status` | local → published |
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
| `wordpress.comment_review_published` | article | count | WordPressCommentReviewService |

## Emit rules (Phase 3)

- Chỉ emit khi `ActionResult.success` và có thay đổi thật (`changed` / không `deduplicated` no-op tạo mới).
- Dry-run / failure: không emit.
- Assign actions: nếu `deduplicated=true` và `added=0` → không emit `*.created` giả.
