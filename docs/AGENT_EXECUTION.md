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
