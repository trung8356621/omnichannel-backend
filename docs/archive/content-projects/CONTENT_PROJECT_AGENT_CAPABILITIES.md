> Status: Historical
> Not canonical
> Archived on: 2026-08-01
> Superseded by: ../../modules/CONTENT_PROJECTS.md
> Purpose: implementation history only
# Content Project Agent Capabilities

> **Batch E freeze:** canonical architecture + capability matrix now live in
> [`docs/architecture/CONTENT_PROJECT_CANONICAL_ARCHITECTURE.md`](architecture/CONTENT_PROJECT_CANONICAL_ARCHITECTURE.md) and
> [`docs/architecture/CONTENT_PROJECT_BACKEND_FREEZE_V1.md`](architecture/CONTENT_PROJECT_BACKEND_FREEZE_V1.md).
> Policy: `stop_execution` / `resume_execution` are **Agent-ok, MCP-no** (Agent can call them via `ContentProjectAgentCommandFactory`; they must never be listed as MCP tools â€” see freeze doc Â§8 for the current MCP-exclusion gap).
> `archive_items` is registered and Agent/MCP-exposed by design; see freeze doc Â§8 for the pending command-factory wiring gap.

Registry: `ContentProjectCapabilityRegistry`.

Agent/MCP **must** call Application Commands through this registry (or CommandBus).

Each capability declares: `name`, `description`, `input_schema`, `required_permission`, `allowed_lifecycle_phases`, `handler`, `confirmation_requirement`, `risk_level` (`read|write|publish|destructive`), `idempotency_support`, `dry_run_support`.

## Registered

create, update, sync_items, add_items, update_item, generate, rerun_items, start_review, approve, schedule, auto_schedule, unschedule, move_schedule, publish_now, retry_publish, skip_publish, cancel_publish, archive, restore.

## Not registered (internal)

- `content_project.process_scheduled_publish`
- `content_project.stop_execution` / `resume_execution`

## Confirmation

`api`/`agent`: dangerous ops need `dry_run` then `confirmation_token`.  
Filament `user`: UI auth may skip preview token.

## Forbidden

- `SeoProjectRun` / `startRun` internals
- WordPress client / WP Schedule
- Direct `publish_queue_status` model updates
- AI prompt/output in business audit

## Additive namespaces (Keyword / SERP / GSC)

NgoÃ i `content_project.*`, registry cÅ©ng Ä‘Äƒng kÃ½ write capabilities:

- `keyword_intelligence.*`
- `serp_intelligence.*`
- `gsc_intelligence.*` (Phase 5)

Gateway `READ_CAPABILITIES` + MCP catalog expose **read** surfaces cho cÃ¡c namespace nÃ y (GSC MCP = read-only list). Chi tiáº¿t CommandBus/refs: [KEYWORD_INTELLIGENCE.md](KEYWORD_INTELLIGENCE.md), [SERP_INTELLIGENCE.md](SERP_INTELLIGENCE.md), [GSC_INTELLIGENCE.md](GSC_INTELLIGENCE.md).
