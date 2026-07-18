# Automation Phase 3 Blockers

**Updated:** 2026-07-18

| Action key | Blocker | Decision |
|---|---|---|
| `article.review.request` | Không có service “request review” thuần. `SeoProjectApprovalService::approveLinkedProject` / `submitStaffEditingComplete` **approve project** (status=approved) + notify — semantic khác request. `markArticleReviewed` xóa local media + clear WP queue. | **Không** adapter. Catalog `internal_only`, không handler. Không thêm boolean flag che side effect. |
| `wordpress.article.update` | Rejected (Phase 1b). | Dùng `wordpress.article.sync_outbound` legacy_not_selectable. |
| Safe WP content update without publish status | Runtime luôn `status=publish`. | Không tạo action update an toàn. |
| `wordpress.article.publish` handler | Chưa chứng minh đủ guard + idempotency + PublishIntent end-to-end cho production. | Giữ `internal_only`, không handler Phase 3, không expose UI. |
| `wordpress.comment_review.publish` handler | Outbound WP từ workflow node hiện tại; cần migrate có kiểm soát. | Giữ `internal_only`, không handler Phase 3. |

## Technical debt

| Item | Status |
|---|---|
| Filament Resource trong Action | **Resolved** — `SeoIssueProjectTaskAssignmentService` + `KeywordProjectAssignmentService` |
| Catalog `supportsDryRun` lệch handler | **Fixed** — `article.content.update` (+ seo_meta) catalog aligned |
| `article.create` không reuse `CreateArticlesFromTaskService::createDraftArticle` | Accepted — method private + couple workflow |
| `article.create` idempotency | **Resolved Phase 4A** — origin_type/origin_id |
| `article.content.update` expected_revision | **Mitigated** — expected_updated_at / expected_content_hash (no revision column) |
| `keyword.domain_link_list.sync` action | **Open** — catalog-only; observer vẫn chạy khi persist keyword |
