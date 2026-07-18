# Skill: Add Automation Action (SeoContentAi)

Hướng dẫn Cursor/dev thêm Business Action mới. Path gốc: `app/Addons/SeoContentAi/Automation/`.

## Khi nào cần Action

Cần Action khi:

- Workflow / Rule / automation node sẽ gọi capability theo **action key** (không gọi PHP class).
- Có use case automation thật (không “bọc cho đủ catalog”).
- Semantic rõ, side effect đã trace, permission + idempotency xác định được.

Không cần Action khi:

- Chỉ chuẩn bị `ActionContext` → giữ `AutomationSiteContextResolver` (`INTERNAL_SERVICE_ONLY`).
- Chỉ orchestration nhiều action → composition workflow sau này, không bọc Orchestrator mù.
- Capability nguy hiểm / chưa có domain semantic → `CATALOG_ONLY` hoặc `BLOCKED`.

## Trace side effect

Trước khi code handler:

1. Đọc inventory `docs/automation/AUTOMATION_SERVICE_INVENTORY.md`.
2. Grep caller thật (Filament, Job, Observer, Listener, Workflow node).
3. Ghi: write DB nào, queue nào, HTTP outbound nào, event nào.
4. Xác nhận không giấu WP outbound trong Article/Project/SEO/Keyword action.

## Naming

| Loại | Pattern | Ví dụ |
|---|---|---|
| Action | `<module>.<resource…>.<verb>` | `article.content.update` |
| Event | `<module>.<past_tense_phrase>` | `article.content_updated` |

Module WP outbound luôn `wordpress.*`. Cấm alias cùng nghĩa.

## Canonical IDs

Chỉ dùng: `team_id?`, `site_id`, `connection_id`, `article_id`, `wp_post_id`.

Cấm trong context: `website_id`, `domain_id`. Normalize qua `CanonicalIds`.

## ActionDefinition

Đăng ký:

1. Metadata trong `Registry/ActionCatalogBootstrap.php` (luôn có).
2. Handler class implement `Contracts/BusinessAction` nếu `IMPLEMENT`.
3. Đăng ký handler trong `Registry/ActionHandlerRegistrar.php`.

Field bắt buộc quan tâm: `key`, `module`, `sideEffect`, `riskLevel`, `selectability`, `inputSchema`, `outputSchema`, `idempotent`, `lockScope`, `supportsDryRun`, `emittedEvents`, `impliesPublishStatus` (WP).

**Catalog definition phải khớp handler** (đặc biệt `supportsDryRun`, schema). Handler definition thắng khi `registerHandler`.

## Input / output

- Input: scalar/array ổn định; validate qua Registry schema.
- Output: DTO/`ActionResult` array — **không** Eloquent Model.
- Write action nên khai báo: `changed`, `changed_fields?`, `entity_id`, `status?`, `revision?`, `deduplicated?`.

## Permission

- `ActionSupport::assertMutable` / site scope / policy hiện có.
- Wrong `site_id` / actor → reject, không silent write.

## Idempotency

| Pattern | Cách làm |
|---|---|
| Create | Business-source key thật (không chỉ title). Không có key → ghi limitation, `idempotent: false`. |
| Update | Prefer `expected_revision` nếu domain có revision. Chưa có → limitation, không giả lập. |
| Assign / pivot | Dedup theo identity thật; retry không tạo link trùng; set `deduplicated`. |
| No-op | Không emit event `*.created` / `*_updated` khi không đổi. |

## Lock / transaction

- `lockScope` khớp entity (`article`, `project_task`, …).
- Write trong `DB::connection('omi_seo_ai')->transaction()` khi multi-write.
- Emit event **sau** commit (Runner dispatch khi `success`).

## Event

- Dùng `EventEnvelope` + key trong event catalog.
- Không phát event khi dry-run / dedup no-op / failure.

## Tests (bắt buộc chạy)

```text
php artisan test app/Addons/SeoContentAi/tests --filter=Automation
```

Checklist test:

- [ ] Foundation/catalog unique keys
- [ ] Handler contract + selectability
- [ ] Legacy / WP non-selectable blocked từ workflow origin
- [ ] PublishIntent cho publish
- [ ] Redaction
- [ ] Boundary: không WP outbound / không Filament Resource trong Action module local
- [ ] Idempotency / wrong site / permission / serializable output
- [ ] No-op không emit event sai

## Docs cập nhật

- `AUTOMATION_ACTION_CATALOG.md` — classification, selectability, test status, migrated, remaining risk
- `AUTOMATION_EVENT_CATALOG.md` — event mới
- `AUTOMATION_SERVICE_INVENTORY.md` — map service → action
- `AUTOMATION_BOUNDARIES.md` — nếu đổi boundary
- `AUTOMATION_MIGRATION_STATUS.md` — kết quả test thật
- `AUTOMATION_PHASE3_BLOCKERS.md` — nếu BLOCKED

## Blocker policy

Đưa `BLOCKED` khi:

- Không có domain service đúng semantic.
- Side effect nguy hiểm chưa tách (vd. xóa media + clear WP queue).
- Cần boolean flag để che behavior.

Không “fix assertion” để hợp thức hóa bug.

## Checklist trước selectable

- [ ] Handler chạy được + test pass
- [ ] Side effect documented
- [ ] Idempotency + lock
- [ ] Không Filament Resource/Page dependency
- [ ] Không WP outbound (trừ module `wordpress`)
- [ ] `selectability = selectable` có chủ đích

## Checklist trước migrate caller

- [ ] Phase adapter stable
- [ ] Production path parity verified
- [ ] Feature flag / dual-run nếu rủi ro cao
- [ ] Cập nhật migration table `Migrated = yes`
- [ ] Không migrate lén trong Phase catalog-only

## Cấm

- Bọc mù Orchestrator / `ArticleEditorSyncOrchestrator` vào Article local action.
- Expose `wordpress.article.publish` / `comment_review.publish` cho Rule UI.
- Tạo `project.run_everything` / `project.process`.
- Dùng `markArticleReviewed` cho `article.review.request`.
