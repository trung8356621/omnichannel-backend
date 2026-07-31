> Status: Historical
> Not canonical
> Archived on: 2026-08-01
> Superseded by: ../../modules/AGENT_WORKSPACE.md
> Purpose: implementation history only
# Agent Pack Compatibility

`AgentPackCompatibilityService` checks:

- SDK + Agent Workspace constraints (manifest validator)
- Required dependencies present / available
- Circular dependencies
- Active conflicts
- Deterministic dependency load order

Do not silently ignore required dependency.
