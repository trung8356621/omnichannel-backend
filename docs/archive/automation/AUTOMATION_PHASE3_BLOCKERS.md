> Status: Historical
> Not canonical
> Archived on: 2026-08-01
> Superseded by: ../../modules/AUTOMATION.md
> Purpose: implementation history only
# Automation Phase 3 Blockers (closed ledger)

**Updated:** 2026-07-21  
**Status:** Phase 3 minimum/full **done**. File nÃ y chá»‰ cÃ²n quyáº¿t Ä‘á»‹nh khÃ³a â€” khÃ´ng pháº£i backlog má»Ÿ.

| Action key | Decision |
|---|---|
| `article.review.request` | **KhÃ´ng** adapter. Catalog `internal_only`. Approve project â‰  request review. |
| `wordpress.article.update` | Rejected (1b). DÃ¹ng `wordpress.article.sync_outbound` `legacy_not_selectable`. |
| Safe WP content update without publish | KhÃ´ng táº¡o. Runtime sync luÃ´n `status=publish`. |
| `wordpress.article.publish` | `internal_only`, chÆ°a handler production (guard/idempotency/PublishIntent). |
| `wordpress.comment_review.publish` | **Done** â€” xem [ACTION_CATALOG](AUTOMATION_ACTION_CATALOG.md) + [MAP_SEO_WP](../MAP_SEO_WP.md). |

## Technical debt (cÃ²n má»Ÿ)

| Item | Status |
|---|---|
| `keyword.domain_link_list.sync` | **Open** â€” catalog-only; observer váº«n cháº¡y khi persist keyword |

Debt Ä‘Ã£ resolve (Filament extract, dry-run align, `article.create` idempotency, content conflict hash) â†’ khÃ´ng liá»‡t kÃª láº¡i; xem [AUTOMATION_MIGRATION_STATUS](AUTOMATION_MIGRATION_STATUS.md).
