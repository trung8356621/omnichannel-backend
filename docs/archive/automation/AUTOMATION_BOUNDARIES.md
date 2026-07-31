> Status: Historical
> Not canonical
> Archived on: 2026-08-01
> Superseded by: ../../modules/AUTOMATION.md
> Purpose: implementation history only
# Automation Boundaries â€” SeoContentAi

**Status:** Locked 2026-07-18 (Phase 1 duyá»‡t + Phase 2 foundation)

## 1. Content production boundary

```text
Workflow / Rule / UI
  â†’ Business Action Key (catalog)
    â†’ Action Registry (PHP map only)
    â†’ Action Handler
    â†’ Domain Service
```

Cáº¥m workflow JSON chá»©a: `::`, `@`, `App\`, `Services\`, class/method PHP.

**Phase 3 Full:** Action handler **cáº¥m** phá»¥ thuá»™c Filament Resource / Page / Livewire. Extract domain service; Resource chá»‰ giá»¯ notification/UI.

**Phase 4A:** Production callers local-only Ä‘i qua `AutomationCallerMigrator` â€” default **Action**. Emergency Legacy: `AUTOMATION_MIGRATION_EMERGENCY_LEGACY=true`.

**Action Runtime cutover:** Manual/UI/Command dÃ¹ng `BusinessActionDispatcher` (khÃ´ng giáº£ láº­p Automation Rule). Domain service khÃ´ng emit business event khi gá»i tá»« Action.

## 2. Canonical IDs (LOCKED)

| Canonical field | Model / nguá»“n | DB | Ghi chÃº |
|---|---|---|---|
| `team_id` | Scope team/owner group khi cÃ³ (SEO Team UI / membership) | core-oriented | **Optional**. KhÃ´ng cÃ³ FK báº¯t buá»™c trÃªn `articles`. Äá»ƒ null náº¿u chÆ°a resolve. KhÃ´ng invent team tá»« `site_id`. |
| `site_id` | `App\Models\Site` | core `mysql` | **Canonical website/domain**. Cá»™t `sites.id`. Domain string = `Site.domain`, khÃ´ng pháº£i ID. |
| `connection_id` | `App\Models\SeoDatabaseConnection` | core `mysql` | Runtime SEO DB (`omi_seo_ai` bootstrap). Panel URL dÃ¹ng `hash_id`; context automation lÆ°u **numeric id**. |
| `article_id` | `SeoArticle` (`articles`) | `omi_seo_ai` | Local article PK. |
| `wp_post_id` | cá»™t/meta trÃªn `SeoArticle` | `omi_seo_ai` (+ WP remote) | ID bÃ i bÃªn WordPress. KhÃ´ng dÃ¹ng thay `article_id`. |

### Cáº¥m láº«n ID

| Forbidden trong ActionContext / Event context | Thay báº±ng |
|---|---|
| `website_id` | `site_id` |
| `domain_id` | `site_id` (domain lÃ  string trÃªn Site) |
| `wp_id` / `post_id` mÆ¡ há»“ | `wp_post_id` hoáº·c `article_id` cho Ä‘Ãºng phÃ­a |

Resolver (`AutomationSiteContextResolver`) chá»‰ tráº£ `site_id` + `connection_id`. Náº¿u input legacy cÃ³ `website_id`, normalize â†’ `site_id` rá»“i bá» alias.

## 3. Article local persistence

**ÄÆ°á»£c:** `article.create`, `article.content.update`, `article.seo_meta.update`, media/FAQ local, readiness meta.

**Cáº¥m:** `WordPressArticleSyncService`, enqueue WP sync, Ä‘á»•i remote status.

Legacy tÃªn lá»‡ch (giá»¯ service, Ä‘á»•i contract):

| Legacy | Thá»±c táº¿ |
|---|---|
| `PromptTestPublishService::publishArticle` | local write |
| `CreateArticlesFromTaskService::runPublishWorkflowForContext` | local workflow |
| `ArticleEditorReadinessService::syncWpPostContentFromBody` | local meta `wp_post_content` |

## 4. SEO Audit boundary

Äá»c / skip meta / táº¡o project task. Cáº¥m auto-fix SEO + publish WP trong audit actions.

## 5. Keyword boundary

TÃ¡ch action; khÃ´ng `keyword.process`. Domain link list = Action `keyword.domain_link_list.sync` qua Rule trÃªn `keyword.saved`. Observer chá»‰ emit event + phrase propagate â€” khÃ´ng sync link list.

## 6. WordPress outbound boundary

Chá»‰ `wordpress.*` Ä‘Æ°á»£c HTTP outbound WP.

| Intent (`PublishIntent`) | DÃ¹ng khi |
|---|---|
| `manual_publish` | User/editor publish/sync ngay |
| `scheduled_publish` | Cron `seo:publish-scheduled-articles` |
| `republish` | Explicit Ä‘áº©y láº¡i |
| `remote_update` | **Reserved** â€” chá»‰ khi runtime update khÃ´ng Ä‘á»•i publish status |

### `wordpress.article.sync_outbound`

- `selectability = legacy_not_selectable`
- `implies_publish_status = true`
- **KhÃ´ng** expose workflow/rule/UI
- TÃªn cÅ© `wordpress.article.update` **rejected**

Chá»‰ táº¡o action â€œupdate an toÃ nâ€ má»›i khi code tháº­t sá»± khÃ´ng gá»­i publish status.

### Content Project

- Task complete / article write local **khÃ´ng** gá»i `wordpress.article.publish`
- Node `post_comment_review` â†’ báº¯t buá»™c `wordpress.comment_review.publish`

## 7. Publishing boundary

`wordpress.article.publish` cáº§n:

1. Article há»£p lá»‡ + `site_id` / `connection_id` khá»›p  
2. Permission sync WP  
3. Explicit `PublishIntent` âˆˆ {manual, scheduled, republish}  
4. Idempotency article+revision  
5. Lock chá»‘ng double publish  
6. Event `article.publish_requested` **khÃ´ng** Ä‘á»§ Ä‘á»ƒ cháº¡y publish  

## 8. Orchestrator warning

`ArticleEditorSyncOrchestrator` = persist + WP. KhÃ´ng map vÃ o `article.content.update`.

## 9. Cross-database

Logical IDs only. KhÃ´ng FK cross-DB. KhÃ´ng giáº£ Ä‘á»‹nh transaction atomic core â†” `omi_seo_ai`.

**Storage (2026-07-23):** rule/event/execution/heartbeat náº±m **core** (`AUTOMATION_DB_CONNECTION`, default sau cutover = core/`mysql`). Addon SEO chá»‰ Ä‘Äƒng kÃ½ action/trigger handler; domain write (article/keyword) váº«n `omi_seo_ai`.
