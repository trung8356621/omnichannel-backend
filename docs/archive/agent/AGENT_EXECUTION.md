> Status: Historical
> Not canonical
> Archived on: 2026-08-01
> Superseded by: ../../modules/AGENT_WORKSPACE.md
> Purpose: implementation history only
# Agent Execution (Phase 2)

## Flow

```
Skill form / composer
  â†’ AgentWorkspaceApplicationService::preview|execute|confirmExecution|cancelExecution|retryExecution
  â†’ AgentExecutionOrchestrator
  â†’ AgentExecutionStateMachine
  â†’ AgentGateway (dry_run / execute)
  â†’ persist seo_agent_executions
  â†’ AgentResultRendererRegistry
  â†’ assistant message (execution_*)
  â†’ AgentExecutionContextUpdater (allowlist)
```

## Statuses

`draft`, `validating`, `ready`, `awaiting_confirmation`, `queued`, `running`, `succeeded`, `failed`, `cancelled`, `expired`

Legacy storage: `pending`â†’draft, `completed`â†’succeeded (`AgentExecutionStatus::fromStorage`).

## Key classes

| Class | Role |
|-------|------|
| `DefaultAgentExecutionOrchestrator` | preview/execute/confirm/cancel/retry |
| `AgentExecutionStateMachine` | allowed transitions |
| `AgentExecutionIdempotencyFactory` | server keys `awex:â€¦` |
| `SeoAgentExecution` | persistence |

## Rules

- UI khÃ´ng set status tÃ¹y Ã½.
- Terminal khÃ´ng execute láº¡i; retry = execution/attempt má»›i.
- Browser khÃ´ng Ä‘áº·t idempotency key.
- KhÃ´ng CommandBus tá»« Livewire/Blade.
- **Scope bridge:** `DefaultAgentExecutionOrchestrator::toAgentContext()` pháº£i pass `scopes: $context->scopes` tá»« `AgentWorkspaceContext` (fail-closed qua `ContentProjectAgentPolicy::assertScopes`). KhÃ´ng hardcode `scopes: []`.
- Read / `confirmation_policy=none`: sau preview executable â†’ `execute` vá»›i `_execution_ref` ngay (khÃ´ng chá» Yes).
