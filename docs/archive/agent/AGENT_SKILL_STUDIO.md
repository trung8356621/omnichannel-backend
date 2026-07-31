> Status: Historical
> Not canonical
> Archived on: 2026-08-01
> Superseded by: ../../modules/AGENT_WORKSPACE.md
> Purpose: implementation history only
# Agent Skill Studio

Manager UI (Packs panel) + slash validate/enable.

- Edit declarative skill/template metadata (no PHP/code editor).
- Preview: skill card/form/sample input/availability/confirmation/planning/automation â€” **never executes capability**.
- Advanced JSON still passes canonical validators (`AgentPackCompiler` / binder / safe schema+mapping).
- Lifecycle only via `AgentPackOrchestrator`.
