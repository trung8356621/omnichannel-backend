# Agent Capability Coverage

Service: `AgentCapabilityCoverageAuditService`  
Inventory: `AgentCapabilityInventory`  
Command: `php artisan agent:capabilities:audit [--module=] [--only-missing] [--json] [--fail-on-critical] [--sync]`

Compares explicit inventory to Canonical Capability Registry + Agent Skill Registry (+ Gateway read list). No runtime source regex.

Output summary: modules/features/complete/partial/missing/internal/deprecated/critical_gaps.
