# Agent Execution (Phase 2)

## Flow

```
Skill form / composer
  → AgentWorkspaceApplicationService::preview|execute|confirmExecution|cancelExecution|retryExecution
  → AgentExecutionOrchestrator
  → AgentExecutionStateMachine
  → AgentGateway (dry_run / execute)
  → persist seo_agent_executions
  → AgentResultRendererRegistry
  → assistant message (execution_*)
  → AgentExecutionContextUpdater (allowlist)
```

## Statuses

`draft`, `validating`, `ready`, `awaiting_confirmation`, `queued`, `running`, `succeeded`, `failed`, `cancelled`, `expired`

Legacy storage: `pending`→draft, `completed`→succeeded (`AgentExecutionStatus::fromStorage`).

## Key classes

| Class | Role |
|-------|------|
| `DefaultAgentExecutionOrchestrator` | preview/execute/confirm/cancel/retry |
| `AgentExecutionStateMachine` | allowed transitions |
| `AgentExecutionIdempotencyFactory` | server keys `awex:…` |
| `SeoAgentExecution` | persistence |

## Rules

- UI không set status tùy ý.
- Terminal không execute lại; retry = execution/attempt mới.
- Browser không đặt idempotency key.
- Không CommandBus từ Livewire/Blade.
- **Scope bridge:** `DefaultAgentExecutionOrchestrator::toAgentContext()` phải pass `scopes: $context->scopes` từ `AgentWorkspaceContext` (fail-closed qua `ContentProjectAgentPolicy::assertScopes`). Không hardcode `scopes: []`.
- Read / `confirmation_policy=none`: sau preview executable → `execute` với `_execution_ref` ngay (không chờ Yes).
