> Status: Historical
> Not canonical
> Archived on: 2026-08-01
> Superseded by: ../../modules/AGENT_WORKSPACE.md
> Purpose: implementation history only
# Agent v1 Doctor

Service: `AgentV1ReadinessService`  
Command: `php artisan agent:v1:doctor [--fix-safe] [--json] [--sync] [--skip-provider]`

UI: Operations â†’ **Run v1 readiness check** (manager; no Artisan shell from Blade).

`--fix-safe` may only: install builtin eval datasets, invalidate Agent skill/pack caches, create `storage/app/agent-audits`.  
Must not: business writes, publish, archive, global `optimize:clear`, CommandBus.

Overall: `ready` | `ready_with_warnings` | `not_ready`.
