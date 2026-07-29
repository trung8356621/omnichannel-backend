# Content Project Agent Workflows

## Agent Workspace UI (Phase 1)

Filament **Agent Workspace** (`/seo/{connection_hash}/agent`) expose cùng capabilities qua slash skills + form preview/confirm — không duplicate CommandBus handlers.

- Overview: [AGENT_WORKSPACE.md](AGENT_WORKSPACE.md)
- Slash catalog: [AGENT_SLASH_COMMANDS.md](AGENT_SLASH_COMMANDS.md)
- Security/scopes: [AGENT_WORKSPACE_SECURITY.md](AGENT_WORKSPACE_SECURITY.md)

Flow UI: `AgentWorkspaceApplicationService` → `AgentGateway` → `ContentProjectAgentGateway` → `CanonicalCapabilityRegistry` → `ContentProjectCommandBus`.

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

## Phase 4 — SERP capabilities (additive)

Future agent flow may include read-only SERP steps before convert:

```
serp_intelligence.import_snapshot (preview)
serp_intelligence.validate_cluster
serp_intelligence.apply_intent_suggestion  (blocked when manual intent locked)
```

Manual keyword intent (`field_sources.intent=manual`) wins — reconciler never auto-overwrites. See [SERP_INTENT_EVIDENCE.md](SERP_INTENT_EVIDENCE.md).

## Phase 5 — GSC Intelligence (additive)

Future agent flow may include GSC sync/import and opportunity review:

```
gsc_intelligence.import_performance_data (preview)
gsc_intelligence.detect_opportunities
gsc_intelligence.preview_create_content_project
gsc_intelligence.create_content_project_from_opportunities
```

Handlers under `Services/GscIntelligence/Application/Handlers/` must not import `Google\Client`. See [GSC_INTELLIGENCE.md](GSC_INTELLIGENCE.md).
