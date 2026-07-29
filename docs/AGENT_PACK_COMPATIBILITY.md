# Agent Pack Compatibility

`AgentPackCompatibilityService` checks:

- SDK + Agent Workspace constraints (manifest validator)
- Required dependencies present / available
- Circular dependencies
- Active conflicts
- Deterministic dependency load order

Do not silently ignore required dependency.
