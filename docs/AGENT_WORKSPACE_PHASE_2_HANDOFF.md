# Agent Workspace Phase 2 Handoff — Execution Orchestration & Confirmation

## 1. Inspect findings

| Item | Finding |
|------|---------|
| `seo_agent_executions` | Phase 1 schema: public_ref, conversation_id, message_id, skill_key, capability, status(`pending`), operation_ref, confirmation_ref, input_summary, result_summary, error_code, started_at, completed_at. **Thiếu** parent/plan/step, mode, input/preview/result/error payloads, confirmation hash/expiry, idempotency, attempt, cancelled_at. |
| Status legacy | `pending` / `completed` — map tại Agent layer → `draft` / `succeeded` (`AgentExecutionStatus::fromStorage`). Không rename column nguy hiểm. |
| `AgentWorkspaceApplicationService` | Phase 1 gọi Gateway trực tiếp + tạo execution ad-hoc. Phase 2 ủy quyền `AgentExecutionOrchestrator`. |
| `AgentGateway` | Facade → `ContentProjectAgentGateway` (Freeze). Không refactor. |
| Confirmation Gateway | `ContentProjectPreviewToken` (cpprev_) vẫn dùng trong Gateway. Agent layer thêm `awconf_` token riêng (hash trên execution). |
| `AgentExecutionPlanService` | Chỉ detect multi-intent. Phase 2 thêm `AgentPlanStepRunner` sequential. |
| Idempotency | Dùng factory Agent riêng; Gateway/CommandBus idempotency giữ nguyên. |

## 2. Architecture

```
UI (Livewire)
  → AgentWorkspaceApplicationService
    → AgentExecutionOrchestrator (Default…)
      → StateMachine + ConfirmationToken + IdempotencyFactory
      → AgentGateway → ContentProjectAgentGateway → CommandBus / reads
      → ResultRendererRegistry
      → AgentExecutionContextUpdater (allowlist)
```

## 3–12. Lifecycle summary

- States: draft → validating → ready|awaiting_confirmation → queued|running → succeeded|failed|cancelled|expired.
- Preview: mọi executable skill; gateway dry_run khi được; fallback `preview_level=orchestration`.
- Confirmation: bind actor/site/conversation/execution/skill/capability/input_hash; hash only in DB; one-time cache.
- Read: execute sau preview nếu policy `none` và executable.
- Write: preview → awaiting_confirmation → confirm(execution_ref, token) — **không** tin lại form payload.
- Idempotency: server `awex:{ref}:a{n}:{ulid}`; double confirm/running → replay.
- Retry: execution mới + attempt++; re-preview/re-confirm nếu policy yêu cầu.
- Cancel: draft/ready/awaiting/queued OK; running unsupported → không fake cancelled.
- Plan: `AgentPlanStepRunner` — no Run All; step lock; allowlisted binder; failure stops plan.
- Context: allowlist keys only; không đổi site binding.

## 13. UI

- `agent-execution-card.blade.php` — preview/confirmation/result/error/plan.
- Confirm/Cancel/Retry/poll (5s chỉ status queued/running).
- Không còn fake token `agent-ui-confirmed`.

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

Additive trên `omi_seo_ai`: columns orchestration + table `seo_agent_execution_plans`.

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

Agent không chạy local (remote-first).

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

- Running cancel chưa gọi Gateway cancel capability (chưa expose) — giữ running + message.
- Keyword/SERP Gateway adapters ngoài ContentProject vẫn qua cùng facade; renderer generic nếu data mỏng.
- Plan UI chưa có nút “Run step” Filament riêng ngoài message card (present + runner sẵn).
- Poll chỉ refresh messages — chưa merge operation status capability vào card chi tiết.

## 20. Phase 3 candidates (không implement)

- Autonomous / multi-agent collaboration.
- Rich operation timeline streaming.
- Gateway cancel capability wiring.
- DAG planner (vẫn cấm Phase 2).
- Cross-conversation shared executions.
