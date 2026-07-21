# Automation Phase 3 Blockers (closed ledger)

**Updated:** 2026-07-21  
**Status:** Phase 3 minimum/full **done**. File này chỉ còn quyết định khóa — không phải backlog mở.

| Action key | Decision |
|---|---|
| `article.review.request` | **Không** adapter. Catalog `internal_only`. Approve project ≠ request review. |
| `wordpress.article.update` | Rejected (1b). Dùng `wordpress.article.sync_outbound` `legacy_not_selectable`. |
| Safe WP content update without publish | Không tạo. Runtime sync luôn `status=publish`. |
| `wordpress.article.publish` | `internal_only`, chưa handler production (guard/idempotency/PublishIntent). |
| `wordpress.comment_review.publish` | **Done** — xem [ACTION_CATALOG](AUTOMATION_ACTION_CATALOG.md) + [MAP_SEO_WP](../MAP_SEO_WP.md). |

## Technical debt (còn mở)

| Item | Status |
|---|---|
| `keyword.domain_link_list.sync` | **Open** — catalog-only; observer vẫn chạy khi persist keyword |

Debt đã resolve (Filament extract, dry-run align, `article.create` idempotency, content conflict hash) → không liệt kê lại; xem [AUTOMATION_MIGRATION_STATUS](AUTOMATION_MIGRATION_STATUS.md).
