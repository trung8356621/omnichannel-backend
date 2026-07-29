# Agent Planning

Guarded copilot for Agent Workspace. AI **proposes** structured intents/plans; Phase 2 executes after user review.

## Flow

Natural language → `AgentPlanningOrchestrator` → model structured JSON → sanitize/repair/validate → UI cards → user save/open form → Phase 2.

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

- ≥ 0.80 — show proposal
- 0.55–0.79 — uncertain, user confirms interpretation
- &lt; 0.55 — clarification only

Server lowers confidence for unavailable skills, assumptions, missing site, vague destructive goals.

See `AGENT_WORKSPACE_PHASE_3_HANDOFF.md`.
