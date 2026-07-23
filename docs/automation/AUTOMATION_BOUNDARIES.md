# Automation Boundaries — SeoContentAi

**Status:** Locked 2026-07-18 (Phase 1 duyệt + Phase 2 foundation)

## 1. Content production boundary

```text
Workflow / Rule / UI
  → Business Action Key (catalog)
    → Action Registry (PHP map only)
    → Action Handler
    → Domain Service
```

Cấm workflow JSON chứa: `::`, `@`, `App\`, `Services\`, class/method PHP.

**Phase 3 Full:** Action handler **cấm** phụ thuộc Filament Resource / Page / Livewire. Extract domain service; Resource chỉ giữ notification/UI.

**Phase 4A:** Production callers local-only đi qua `AutomationCallerMigrator` — default **Action**. Emergency Legacy: `AUTOMATION_MIGRATION_EMERGENCY_LEGACY=true`.

**Action Runtime cutover:** Manual/UI/Command dùng `BusinessActionDispatcher` (không giả lập Automation Rule). Domain service không emit business event khi gọi từ Action.

## 2. Canonical IDs (LOCKED)

| Canonical field | Model / nguồn | DB | Ghi chú |
|---|---|---|---|
| `team_id` | Scope team/owner group khi có (SEO Team UI / membership) | core-oriented | **Optional**. Không có FK bắt buộc trên `articles`. Để null nếu chưa resolve. Không invent team từ `site_id`. |
| `site_id` | `App\Models\Site` | core `mysql` | **Canonical website/domain**. Cột `sites.id`. Domain string = `Site.domain`, không phải ID. |
| `connection_id` | `App\Models\SeoDatabaseConnection` | core `mysql` | Runtime SEO DB (`omi_seo_ai` bootstrap). Panel URL dùng `hash_id`; context automation lưu **numeric id**. |
| `article_id` | `SeoArticle` (`articles`) | `omi_seo_ai` | Local article PK. |
| `wp_post_id` | cột/meta trên `SeoArticle` | `omi_seo_ai` (+ WP remote) | ID bài bên WordPress. Không dùng thay `article_id`. |

### Cấm lẫn ID

| Forbidden trong ActionContext / Event context | Thay bằng |
|---|---|
| `website_id` | `site_id` |
| `domain_id` | `site_id` (domain là string trên Site) |
| `wp_id` / `post_id` mơ hồ | `wp_post_id` hoặc `article_id` cho đúng phía |

Resolver (`AutomationSiteContextResolver`) chỉ trả `site_id` + `connection_id`. Nếu input legacy có `website_id`, normalize → `site_id` rồi bỏ alias.

## 3. Article local persistence

**Được:** `article.create`, `article.content.update`, `article.seo_meta.update`, media/FAQ local, readiness meta.

**Cấm:** `WordPressArticleSyncService`, enqueue WP sync, đổi remote status.

Legacy tên lệch (giữ service, đổi contract):

| Legacy | Thực tế |
|---|---|
| `PromptTestPublishService::publishArticle` | local write |
| `CreateArticlesFromTaskService::runPublishWorkflowForContext` | local workflow |
| `ArticleEditorReadinessService::syncWpPostContentFromBody` | local meta `wp_post_content` |

## 4. SEO Audit boundary

Đọc / skip meta / tạo project task. Cấm auto-fix SEO + publish WP trong audit actions.

## 5. Keyword boundary

Tách action; không `keyword.process`. Domain link list = Action `keyword.domain_link_list.sync` qua Rule trên `keyword.saved`. Observer chỉ emit event + phrase propagate — không sync link list.

## 6. WordPress outbound boundary

Chỉ `wordpress.*` được HTTP outbound WP.

| Intent (`PublishIntent`) | Dùng khi |
|---|---|
| `manual_publish` | User/editor publish/sync ngay |
| `scheduled_publish` | Cron `seo:publish-scheduled-articles` |
| `republish` | Explicit đẩy lại |
| `remote_update` | **Reserved** — chỉ khi runtime update không đổi publish status |

### `wordpress.article.sync_outbound`

- `selectability = legacy_not_selectable`
- `implies_publish_status = true`
- **Không** expose workflow/rule/UI
- Tên cũ `wordpress.article.update` **rejected**

Chỉ tạo action “update an toàn” mới khi code thật sự không gửi publish status.

### Content Project

- Task complete / article write local **không** gọi `wordpress.article.publish`
- Node `post_comment_review` → bắt buộc `wordpress.comment_review.publish`

## 7. Publishing boundary

`wordpress.article.publish` cần:

1. Article hợp lệ + `site_id` / `connection_id` khớp  
2. Permission sync WP  
3. Explicit `PublishIntent` ∈ {manual, scheduled, republish}  
4. Idempotency article+revision  
5. Lock chống double publish  
6. Event `article.publish_requested` **không** đủ để chạy publish  

## 8. Orchestrator warning

`ArticleEditorSyncOrchestrator` = persist + WP. Không map vào `article.content.update`.

## 9. Cross-database

Logical IDs only. Không FK cross-DB. Không giả định transaction atomic core ↔ `omi_seo_ai`.

**Storage (2026-07-23):** rule/event/execution/heartbeat nằm **core** (`AUTOMATION_DB_CONNECTION`, default sau cutover = core/`mysql`). Addon SEO chỉ đăng ký action/trigger handler; domain write (article/keyword) vẫn `omi_seo_ai`.
