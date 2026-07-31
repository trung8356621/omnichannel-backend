> Status: Historical
> Not canonical
> Archived on: 2026-08-01
> Superseded by: ../../modules/AGENT_WORKSPACE.md
> Purpose: implementation history only
# Agent Packs

Declarative Skill Packs for Agent Workspace (Phase 7).

- Orchestrator: `AgentPackOrchestrator`
- Registry: `AgentPackRegistry` / Discovery / Loader / State
- Compiler: `AgentPackCompiler` â†’ skills/templates/planning/automation/eval refs
- Path: Pack â†’ SkillRegistry â†’ Workspace â†’ ExecutionOrchestrator â†’ Gateway â†’ Canonical capability
- Types: builtin | extension | custom | imported
- UI: Agent Workspace â†’ Packs (manager)
- Slash: `/agent-packs`, `/pack-status`, `/validate-pack`, `/evaluate-pack`, `/enable-pack`, `/disable-pack`, `/pack-skills`
- Compatibility uses Agent Workspace version metadata (`AgentWorkspaceVersion`) alongside pack schema 1.0.

See: [PHASE_7](archive/AGENT_WORKSPACE_PHASE_7_HANDOFF.md) *(historical)*, [MANIFEST](AGENT_PACK_MANIFEST.md), [STUDIO](AGENT_SKILL_STUDIO.md), [COMPAT](AGENT_PACK_COMPATIBILITY.md), [IMPORT](AGENT_PACK_IMPORT_EXPORT.md), [SECURITY](AGENT_PACK_SECURITY.md), [EVAL](AGENT_PACK_EVALUATION.md), [V1 FREEZE](AGENT_WORKSPACE_V1_FREEZE.md).
