# Content Project MCP Tools

Schemas lấy từ `ContentProjectCapabilityRegistry::jsonSchema()` (write) + catalog read schemas. **Một nguồn** — không duplicate lệch REST.

MCP adapter: `ContentProjectMcpServer` → **chỉ** `ContentProjectAgentGateway`.

## Read tools

| Tool | Input (required) |
|------|------------------|
| `content_project.list_projects` | — |
| `content_project.get_project` | `project_ref` |
| `content_project.list_items` | `project_ref` |
| `content_project.get_item` | `item_ref` |
| `content_project.get_status` | `project_ref` |
| `content_project.get_publishing_queue` | `project_ref` |
| `content_project.get_timeline` | `project_ref` |
| `content_project.get_daily_report` | — |
| `content_project.get_site_health` | — |
| `content_project.get_operation` | `operation_ref` |

Read DTO: public refs only (`project_ref`, `item_ref`, `site_ref`). Không leak numeric ID, runtime payload, prompt/output, credentials.

### Keyword Intelligence read tools (additive, xem [KEYWORD_INTELLIGENCE.md](KEYWORD_INTELLIGENCE.md))

| Tool | Input (required) |
|------|------------------|
| `keyword_intelligence.list_workspaces` | — |
| `keyword_intelligence.get_workspace` | `workspace_ref` |
| `keyword_intelligence.list_keywords` | `workspace_ref` |
| `keyword_intelligence.list_clusters` | `workspace_ref` |
| `keyword_intelligence.get_topical_map` | `workspace_ref` |
| `keyword_intelligence.get_cannibalization` | `workspace_ref` |
| `keyword_intelligence.get_analysis_operation` | `operation_ref` |

Write tools: `keyword_intelligence.create_workspace`, `import_keywords`, `analyze_workspace`, `approve_keywords`, `approve_clusters`, `build_topical_map`, `preview_convert`, `convert_to_content_project`, `archive_workspace` — schema từ `ContentProjectCapabilityRegistry` như core (auto-included, không cần liệt kê riêng).

### SERP Intelligence read tools (additive, xem [SERP_INTELLIGENCE.md](SERP_INTELLIGENCE.md))

| Tool | Input (required) |
|------|------------------|
| `serp_intelligence.list_queries` | `workspace_ref` |
| `serp_intelligence.get_query` | `workspace_ref`, `query_ref` |
| `serp_intelligence.list_snapshots` | `workspace_ref` |
| `serp_intelligence.get_snapshot` | `workspace_ref`, `snapshot_ref` |
| `serp_intelligence.list_results` | `snapshot_ref` |
| `serp_intelligence.list_features` | `snapshot_ref` |
| `serp_intelligence.get_cluster_evidence` | `workspace_ref`, `evidence_ref` |
| `serp_intelligence.list_content_gaps` | `workspace_ref` |
| `serp_intelligence.list_competitors` | `snapshot_ref` |
| `serp_intelligence.get_operation` | `operation_ref` |

MCP catalog **không** liệt kê SERP write tools (write vẫn có trên CommandBus/registry nếu gọi Agent execute trực tiếp).

### GSC Intelligence read tools (additive, xem [GSC_INTELLIGENCE.md](GSC_INTELLIGENCE.md))

| Tool | Input (required) |
|------|------------------|
| `gsc_intelligence.list_properties` | — |
| `gsc_intelligence.get_property` | `property_ref` |
| `gsc_intelligence.list_sync_runs` | `property_ref` |
| `gsc_intelligence.get_sync_run` | `property_ref`, `sync_run_ref` |
| `gsc_intelligence.list_query_mappings` | `property_ref` |
| `gsc_intelligence.get_query_mapping` | `property_ref`, `mapping_ref` |
| `gsc_intelligence.list_page_mappings` | `property_ref` |
| `gsc_intelligence.get_page_mapping` | `property_ref`, `mapping_ref` |
| `gsc_intelligence.list_aggregates` | `property_ref` |
| `gsc_intelligence.get_aggregate` | `property_ref`, `aggregate_ref` |
| `gsc_intelligence.list_opportunities` | `property_ref` |
| `gsc_intelligence.get_opportunity` | `property_ref`, `opportunity_ref` |
| `gsc_intelligence.get_operation` | `operation_ref` |

MCP catalog **không** liệt kê GSC write tools. Writes (`sync_performance`, `import_performance`, `map_*`, `detect_opportunities`, …) tồn tại trên CommandBus + `ContentProjectCapabilityRegistry` cho app/Filament (và Agent execute nếu gọi tên capability).

`get_status` trả: `current_phase`, `allowed_capabilities`, `blocked_capabilities`, `blockers`, `recommended_next_actions`.

## Write tools

| Tool | Notes |
|------|-------|
| `content_project.create` | site from context |
| `content_project.update` | |
| `content_project.add_items` | content_type: write_new\|rewrite\|improve |
| `content_project.update_item` | |
| `content_project.generate` | async → `operation_ref` |
| `content_project.rerun_items` | alias of registry `rerun` |
| `content_project.start_review` | |
| `content_project.approve` | |
| `content_project.schedule` | dry_run preview |
| `content_project.auto_schedule` | modes: explicit/interval/per_day/random_windows |
| `content_project.unschedule` | |
| `content_project.move_schedule` | |
| `content_project.publish_now` | confirmation |
| `content_project.retry_publish` | |
| `content_project.skip_publish` | confirmation |
| `content_project.cancel_publish` | confirmation |
| `content_project.archive` | confirmation + destroy workspace |
| `content_project.restore` | confirmation; `workspace_reused=false` |

## Not exposed

- `content_project.sync_items`
- `content_project.process_scheduled_publish`
- `content_project.stop_execution` / `resume_execution`
- run / run_item / runtime / queue token / lock / prompt result raw
- SQL / update_model / call_service / run_command

## Call example

```http
POST /api/v1/agent/mcp/call
Authorization: Bearer {sanctum_token}

{
  "name": "content_project.generate",
  "arguments": {
    "project_ref": "cpj_...",
    "item_refs": ["cpi_..."],
    "idempotency_key": "gen-1"
  },
  "tenant_ref": "tenant:cps_...",
  "site_ref": "cps_...",
  "session_ref": "ags_...",
  "request_ref": "req_..."
}
```

## Plan / Automation MCP tools

| Tool | Role |
|------|------|
| `content_project.plan` | Create draft plan |
| `content_project.confirm_plan` | Confirm plan |
| `content_project.start_plan` | Start execution |
| `content_project.pause_plan` / `resume_plan` / `cancel_plan` | Control |
| `content_project.retry_plan_step` | Retry failed step |
| `content_project.get_agent_plan` / `list_agent_plans` | Read |
| `content_project.get_agent_policy` | Policy preview |
| `content_project.list_pending_approvals` | Approvals |
| `content_project.approve_agent_action` / `reject_agent_action` | Gate |

Routed via `ContentProjectAgentPlanGateway` (not CommandBus).

## Schema shape

```json
{
  "name": "content_project.generate",
  "description": "...",
  "inputSchema": {
    "type": "object",
    "required": ["project_ref"],
    "properties": { "...": {} },
    "additionalProperties": false
  }
}
```
