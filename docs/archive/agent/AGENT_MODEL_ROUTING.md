> Status: Historical
> Not canonical
> Archived on: 2026-08-01
> Superseded by: ../../modules/AGENT_WORKSPACE.md
> Purpose: implementation history only
# Agent Model Routing

`RegistryAgentModelRouter` picks provider/model for planning tasks via AI Provider Registry.

## Inputs

Task type, structured-output need, context size, user-selected model, connection id, fallback policy.

## Task types

`intent_classification` | `plan_generation` | `clarification` | `conversation_summary` | `assistant_answer` | `plan_repair`

## Rules

- No vendor `if ($provider === 'gemini')` in Livewire/planner.
- User model only if registered/enabled/supports mode.
- Fallback recorded in diagnostics (`fallback_used`).
- Gateway: `ProviderAgentModelGateway` â†’ `AiTextProviderInterface` only.
