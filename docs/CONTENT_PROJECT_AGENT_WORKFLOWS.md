# Content Project Agent Workflows

## E2E Create → Generate

```
content_project.create
  → content_project.add_items
  → content_project.generate
  → content_project.get_operation   (poll ≥ poll_min_seconds)
  → content_project.get_status
  → content_project.start_review
  → content_project.approve
  → content_project.auto_schedule (dry_run preview)
  → content_project.auto_schedule (confirm)
```

Tất cả dùng public refs + tenant/site context + idempotency_key.

### Create input (tối thiểu)

`site_ref` (từ context), `name`, `project_type`, `pipeline`, `language`, `timezone`, publishing strategy.

### Add items

`keyword`, `title?`, `description?`, `content_type` ∈ {`write_new`,`rewrite`,`improve`}, `article_ref?`, `scheduled_publish_at?`.

`improve` = sửa theo yêu cầu, không rerun full prompt stack. Item description ≠ Product `gallery_description`.

## Generate / Review (business only)

Agent thấy **Generate Article** / **Review Article** operations — không thấy từng prompt step (outline/image/audit…) trừ khi capability prompt-level được bật riêng.

## Schedule

Modes hiện có: `explicit`, `interval`, `per_day`, `random_windows`.

Preview trước bulk: sample `item_ref` + `planned_time` + timezone; stats `first_publish_at`, `last_publish_at`, `items_per_day`, `conflicts`.

Publishing = SaaS queue. **Không** tạo WP future post.

## Publish Now

1. dry_run / thiếu token → preview
2. User confirm
3. execute + `confirmation_token`
4. poll `get_operation` / `get_publishing_queue`

## Archive (Destroy Workspace)

Dry-run bắt buộc. Preview phải nêu rõ destroy:

- AI Workspace, Prompt History, Execution, local media, SaaS revisions

Result: `workspace_destroyed=true`, counts dọn (không list từng record).

Session clear workspace context; giữ project_ref + archive result tối giản.

## Restore

Confirmation. Chỉ clear archived metadata / mở business project.

`workspace_reused=false`, `requires_new_generation_context=true`.

Không phục hồi AI workspace / prompt / execution / media / publish process cũ.

## Plan (preview only)

`ContentProjectAgentPlan` — data only, max steps từ config. Mỗi step vẫn qua Gateway; publish/archive confirmation riêng. Không auto-execute toàn plan. Không thêm capability ngoài registry.

## NL adapter

`ContentProjectNaturalLanguageAdapter` → capability + structured input + missing_fields. Không đoán site / ngày đăng / số bài / archive target.

## Example MCP sequence

```json
{"name":"content_project.create","arguments":{"name":"Cafe Q3","attributes":{"name":"Cafe Q3"}}}
{"name":"content_project.add_items","arguments":{"project_ref":"cpj_...","items":[{"keyword":"cafe da lat","content_type":"write_new"}]}}
{"name":"content_project.generate","arguments":{"project_ref":"cpj_...","item_refs":["cpi_..."],"idempotency_key":"gen-1"}}
{"name":"content_project.get_operation","arguments":{"operation_ref":"..."}}
```
