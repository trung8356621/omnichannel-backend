> Status: Historical
> Not canonical
> Archived on: 2026-08-01
> Superseded by: ../../modules/AGENT_WORKSPACE.md
> Purpose: implementation history only
# Agent Planning

Guarded copilot for Agent Workspace. AI **proposes** structured intents/plans; Phase 2 executes after user review.

## Flow

Natural language â†’ `AgentPlanningOrchestrator` â†’ model structured JSON â†’ sanitize/repair/validate â†’ UI cards â†’ user save/open form â†’ Phase 2.

## Response types

`clarification` | `single_intent` | `execution_plan` | `assistant_answer` | `unsupported`

## Key classes

| Class | Role |
|-------|------|
| `DefaultAgentPlanningOrchestrator` | plan / clarify / edit / save / suggest |
| `DefaultAgentPlanValidator` | Authoritative schema + skill checks |
| `DeterministicAgentPlanRepairer` | One-shot safe repairs |
| `AgentSkillCatalogPresenter` | Prompt-safe skill list |
| `AgentPlanningContextAssembler` | Allowed context sections + fingerprint |

## Confidence

- â‰¥ 0.80 â€” show proposal
- 0.55â€“0.79 â€” uncertain, user confirms interpretation
- &lt; 0.55 â€” clarification only

Server lowers confidence for unavailable skills, assumptions, missing site, vague destructive goals.

See `archive/AGENT_WORKSPACE_PHASE_3_HANDOFF.md` *(historical)*.
