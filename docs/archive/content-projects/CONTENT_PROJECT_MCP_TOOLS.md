> Status: Historical
> Not canonical
> Archived on: 2026-08-01
> Superseded by: ../../modules/CONTENT_PROJECTS.md
> Purpose: implementation history only
# Content Project MCP Tools

> **Batch E freeze (closure 2026-07-31):** canonical architecture + capability matrix in
> [`docs/architecture/CONTENT_PROJECT_CANONICAL_ARCHITECTURE.md`](architecture/CONTENT_PROJECT_CANONICAL_ARCHITECTURE.md) and
> [`docs/architecture/CONTENT_PROJECT_BACKEND_FREEZE_V1.md`](architecture/CONTENT_PROJECT_BACKEND_FREEZE_V1.md).
> **`stop_execution` / `resume_execution`:** Agent-only, not MCP â€” MCP must not control mid-flight run lifecycle (ops/automation controls); Agent + Automation workflows need them for assisted runs. Enforced via `MCP_EXCLUDED_NAMES` + `mcp_exposed=false`.
> **`content_project.archive_items`:** exposed MCP + Agent capability, fully wired (Factory arm + handler + confirmation + idempotency). See freeze doc Â§7.
> Treat this file as tool-schema reference only; for exposure policy/decisions, defer to the architecture docs above.

Schemas láº¥y tá»« `ContentProjectCapabilityRegistry::jsonSchema()` (write) + catalog read schemas. **Má»™t nguá»“n** â€” khÃ´ng duplicate lá»‡ch REST.

MCP adapter: `ContentProjectMcpServer` â†’ **chá»‰** `ContentProjectAgentGateway`.

## Read tools

| Tool | Input (required) |
|------|------------------|
| `content_project.list_projects` | â€” |
| `content_project.get_project` | `project_ref` |
| `content_project.list_items` | `project_ref` |
| `content_project.get_item` | `item_ref` |
| `content_project.get_status` | `project_ref` |
| `content_project.get_publishing_queue` | `project_ref` |
| `content_project.get_timeline` | `project_ref` |
| `content_project.get_daily_report` | â€” |
| `content_project.get_site_health` | â€” |
| `content_project.get_operation` | `operation_ref` |

Read DTO: public refs only (`project_ref`, `item_ref`, `site_ref`). KhÃ´ng leak numeric ID, runtime payload, prompt/output, credentials.

### Keyword Intelligence read tools (additive, xem [KEYWORD_INTELLIGENCE.md](KEYWORD_INTELLIGENCE.md))

| Tool | Input (required) |
|------|------------------|
| `keyword_intelligence.list_workspaces` | â€” |
| `keyword_intelligence.get_workspace` | `workspace_ref` |
| `keyword_intelligence.list_keywords` | `workspace_ref` |
| `keyword_intelligence.list_clusters` | `workspace_ref` |
| `keyword_intelligence.get_topical_map` | `workspace_ref` |
| `keyword_intelligence.get_cannibalization` | `workspace_ref` |
| `keyword_intelligence.get_analysis_operation` | `operation_ref` |

Write tools: `keyword_intelligence.create_workspace`, `import_keywords`, `analyze_workspace`, `approve_keywords`, `approve_clusters`, `build_topical_map`, `preview_convert`, `convert_to_content_project`, `archive_workspace` â€” schema tá»« `ContentProjectCapabilityRegistry` nhÆ° core (auto-included, khÃ´ng cáº§n liá»‡t kÃª riÃªng).

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

MCP catalog **khÃ´ng** liá»‡t kÃª SERP write tools (write váº«n cÃ³ trÃªn CommandBus/registry náº¿u gá»i Agent execute trá»±c tiáº¿p).

### GSC Intelligence read tools (additive, xem [GSC_INTELLIGENCE.md](GSC_INTELLIGENCE.md))

| Tool | Input (required) |
|------|------------------|
| `gsc_intelligence.list_properties` | â€” |
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

MCP catalog **khÃ´ng** liá»‡t kÃª GSC write tools. Writes (`sync_performance`, `import_performance`, `map_*`, `detect_opportunities`, â€¦) tá»“n táº¡i trÃªn CommandBus + `ContentProjectCapabilityRegistry` cho app/Filament (vÃ  Agent execute náº¿u gá»i tÃªn capability).

### SEO Audit read tools (additive, xem [MAP_SEO_AUDIT.md](MAP_SEO_AUDIT.md))

| Tool | Input (required) |
|------|------------------|
| `seo_audit.list` | `site_ref` (context); optional `post_type` / `limit` |

Site-level â€” khÃ´ng cáº§n `project_ref`. Reuse Web `SeoAuditKeywordFlagService` query surface.

`get_status` tráº£: `current_phase`, `allowed_capabilities`, `blocked_capabilities`, `blockers`, `recommended_next_actions`.

## Write tools

| Tool | Notes |
|------|-------|
| `content_project.create` | site from context |
| `content_project.update` | |
| `content_project.add_items` | content_type: write_new\|rewrite\|improve |
| `content_project.update_item` | |
| `content_project.generate` | async â†’ `operation_ref` |
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
| `content_project.archive_items` | confirmation; item-level archive (keeps WordPress post) |
| `content_project.restore` | confirmation; `workspace_reused=false` |

## Not exposed

- `content_project.sync_items`
- `content_project.process_scheduled_publish`
- `content_project.stop_execution` / `resume_execution` â€” **Agent-only by design:** MCP tool surface must not pause/resume mid-flight runs; Agent + Automation retain these for assisted/ops workflows (`MCP_EXCLUDED_NAMES`, `mcp_exposed=false`)
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
