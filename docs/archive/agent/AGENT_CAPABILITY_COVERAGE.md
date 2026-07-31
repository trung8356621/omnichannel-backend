> Status: Historical
> Not canonical
> Archived on: 2026-08-01
> Superseded by: ../../modules/AGENT_WORKSPACE.md
> Purpose: implementation history only
# Agent Capability Coverage

Service: `AgentCapabilityCoverageAuditService`  
Inventory: `AgentCapabilityInventory`  
Command: `php artisan agent:capabilities:audit [--module=] [--only-missing] [--json] [--fail-on-critical] [--sync]`

Compares explicit inventory to Canonical Capability Registry + Agent Skill Registry (+ Gateway read list). No runtime source regex.

Output summary: modules/features/complete/partial/missing/internal/deprecated/critical_gaps.
