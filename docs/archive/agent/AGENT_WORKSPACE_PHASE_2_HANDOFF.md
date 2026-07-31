> Status: Historical
> Not canonical
> Archived on: 2026-08-01
> Superseded by: ../modules/AGENT_WORKSPACE.md
> Purpose: implementation history only
<!--
Status: Historical
Not canonical
Superseded by: docs/AGENT_WORKSPACE.md + docs/AGENT_WORKSPACE_V1_FREEZE.md
-->
# Agent Workspace Phase 2 Handoff â€” Execution Orchestration & Confirmation

## 1. Inspect findings

| Item | Finding |
|------|---------|
| `seo_agent_executions` | Phase 1 schema: public_ref, conversation_id, message_id, skill_key, capability, status(`pending`), operation_ref, confirmation_ref, input_summary, result_summary, error_code, started_at, completed_at. **Thiáº¿u** parent/plan/step, mode, input/preview/result/error payloads, confirmation hash/expiry, idempotency, attempt, cancelled_at. |
| Status legacy | `pending` / `completed` â€” map táº¡i Agent layer â†’ `draft` / `succeeded` (`AgentExecutionStatus::fromStorage`). KhÃ´ng rename column nguy hiá»ƒm. |
| `AgentWorkspaceApplicationService` | Phase 1 gá»i Gateway trá»±c tiáº¿p + táº¡o execution ad-hoc. Phase 2 á»§y quyá»n `AgentExecutionOrchestrator`. |
| `AgentGateway` | Facade â†’ `ContentProjectAgentGateway` (Freeze). KhÃ´ng refactor. |
| Confirmation Gateway | `ContentProjectPreviewToken` (cpprev_) váº«n dÃ¹ng trong Gateway. Agent layer thÃªm `awconf_` token riÃªng (hash trÃªn execution). |
| `AgentExecutionPlanService` | Chá»‰ detect multi-intent. Phase 2 thÃªm `AgentPlanStepRunner` sequential. |
| Idempotency | DÃ¹ng factory Agent riÃªng; Gateway/CommandBus idempotency giá»¯ nguyÃªn. |

## 2. Architecture

```
UI (Livewire)
  â†’ AgentWorkspaceApplicationService
    â†’ AgentExecutionOrchestrator (Defaultâ€¦)
      â†’ StateMachine + ConfirmationToken + IdempotencyFactory
      â†’ AgentGateway â†’ ContentProjectAgentGateway â†’ CommandBus / reads
      â†’ ResultRendererRegistry
      â†’ AgentExecutionContextUpdater (allowlist)
```

## 3â€“12. Lifecycle summary

- States: draft â†’ validating â†’ ready|awaiting_confirmation â†’ queued|running â†’ succeeded|failed|cancelled|expired.
- Preview: má»i executable skill; gateway dry_run khi Ä‘Æ°á»£c; fallback `preview_level=orchestration`.
- Confirmation: bind actor/site/conversation/execution/skill/capability/input_hash; hash only in DB; one-time cache.
- Read: execute sau preview náº¿u policy `none` vÃ  executable.
- Write: preview â†’ awaiting_confirmation â†’ confirm(execution_ref, token) â€” **khÃ´ng** tin láº¡i form payload.
- Idempotency: server `awex:{ref}:a{n}:{ulid}`; double confirm/running â†’ replay.
- Retry: execution má»›i + attempt++; re-preview/re-confirm náº¿u policy yÃªu cáº§u.
- Cancel: draft/ready/awaiting/queued OK; running unsupported â†’ khÃ´ng fake cancelled.
- Plan: `AgentPlanStepRunner` â€” no Run All; step lock; allowlisted binder; failure stops plan.
- Context: allowlist keys only; khÃ´ng Ä‘á»•i site binding.

## 13. UI

- `agent-execution-card.blade.php` â€” preview/confirmation/result/error/plan.
- Confirm/Cancel/Retry/poll (5s chá»‰ status queued/running).
- KhÃ´ng cÃ²n fake token `agent-ui-confirmed`.

## 14. Files created

- Enums: `AgentExecutionStatus`, `AgentErrorCategory`
- Execution/* orchestrator, state machine, tokens, idempotency, context updater, plan binder/runner, DTOs, Rendering/*
- Models: `SeoAgentExecutionPlan` (+ extended `SeoAgentExecution`)
- Migration: `2026_07_28_210000_phase2_agent_execution_orchestration.php`
- Views: `agent-execution-card.blade.php`
- Tests: `AgentExecutionStateMachineTest`, `AgentConfirmationTokenTest`, `AgentResultRendererTest`, `AgentMultiIntentPlanTest`, `AgentExecutionContextTest`
- Docs: this handoff + AGENT_EXECUTION.md, AGENT_CONFIRMATION.md, AGENT_RESULT_RENDERING.md, AGENT_EXECUTION_PLANS.md

## 15. Files modified

- `AgentWorkspaceApplicationService`, `AgentWorkspacePage`, `SeoContentAiServiceProvider`
- `agent-message-structured.blade.php`, lang en/vi
- `AgentWorkspaceExecutionTest`
- `SUPER_MAP_INDEX.md`, `AGENT_WORKSPACE.md`, `AGENT_WORKSPACE_SECURITY.md`

## 16. Migration

Additive trÃªn `omi_seo_ai`: columns orchestration + table `seo_agent_execution_plans`.

## 17. Tests (manual remote)

```text
$PHP_BIN vendor/bin/phpunit --filter=AgentExecution
$PHP_BIN vendor/bin/phpunit --filter=AgentConfirmation
$PHP_BIN vendor/bin/phpunit --filter=AgentResultRenderer
$PHP_BIN vendor/bin/phpunit --filter=AgentExecutionContext
$PHP_BIN vendor/bin/phpunit --filter=AgentMultiIntent
$PHP_BIN vendor/bin/phpunit --filter=AgentWorkspace
$PHP_BIN vendor/bin/phpunit --filter=AgentGateway
$PHP_BIN vendor/bin/phpunit --filter=ExtensionArchitectureFreezeTest
```

Agent khÃ´ng cháº¡y local (remote-first).

## 18. Freeze verification

| Check | Result |
|-------|--------|
| CommandBus modified | **No** |
| Existing handlers modified | **No** |
| Gateway behavior refactored | **No** |
| Capability definitions modified | **No** |
| Business module direct writes | **No** |
| Autonomous execution | **No** |
| AI auto-confirmation | **No** |

## 19. Known limitations

- Running cancel chÆ°a gá»i Gateway cancel capability (chÆ°a expose) â€” giá»¯ running + message.
- Keyword/SERP Gateway adapters ngoÃ i ContentProject váº«n qua cÃ¹ng facade; renderer generic náº¿u data má»ng.
- Plan UI chÆ°a cÃ³ nÃºt â€œRun stepâ€ Filament riÃªng ngoÃ i message card (present + runner sáºµn).
- Poll chá»‰ refresh messages â€” chÆ°a merge operation status capability vÃ o card chi tiáº¿t.

## 20. Phase 3 candidates (khÃ´ng implement)

- Autonomous / multi-agent collaboration.
- Rich operation timeline streaming.
- Gateway cancel capability wiring.
- DAG planner (váº«n cáº¥m Phase 2).
- Cross-conversation shared executions.
