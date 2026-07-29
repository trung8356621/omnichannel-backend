# Agent Workspace Phase 3 Handoff — AI Planning & Guarded Copilot

## 1. Inspect findings

| Item | Finding |
|------|---------|
| `AgentIntentRouter` | Order: slash → alias → template → multi → deterministic → optional `ai_intent` → **assistant**. Phase 3 hooks **assistant** only for NL planning. Slash never calls model. |
| AI providers | `AiProviderResolver` + `AiTextProviderInterface` (Gemini/Claude builtins). Planner/UI **không** import vendor SDK. |
| `AiModelRouterService` | Site connection models/categories — `RegistryAgentModelRouter` dùng opaque `provider` key từ connection. |
| Conversation | Có `context_summary`. Phase 3 thêm `summary`, `summary_version`, `summary_until_message_id`, `summary_updated_at`. |
| Phase 2 | `AgentExecutionOrchestrator` / `AgentPlanStepRunner.createPlan` vẫn là execution boundary. Planning chỉ **save plan records**, không run step. |
| Free chat path | Trước Phase 3: assistant fallback text. Sau: `planNaturalLanguage` → structured cards. |

## 2. Architecture

```
UI (AgentWorkspacePage)
  → AgentIntentRouter (deterministic first)
  → (SOURCE_ASSISTANT only) AgentWorkspaceApplicationService::planNaturalLanguage
    → AgentPlanningOrchestrator
      → ContextAssembler + SkillCatalogPresenter + BudgetManager
      → AgentModelRouter → AgentModelGateway (AiTextProviderInterface)
      → OutputSanitizer + DeterministicRepair (1×) + PlanValidator
      → Proposed intent/plan/clarification/unsupported
  → User review / edit / savePlan
  → Phase 2 AgentPlanStepRunner / ExecutionOrchestrator
```

AI **không** nằm trên execution path sau khi user quyết định chạy.

## 3. Deterministic vs Copilot

| Path | AI? |
|------|-----|
| Slash / alias / template / selected skill / form / confirm / retry / cancel | No |
| Multi-intent detect / deterministic rules / structured `ai_intent` option | No (existing) |
| `SOURCE_ASSISTANT` natural language | Yes — planning only |

## 4–10. Core pieces

- **Model router:** `RegistryAgentModelRouter` — task type, structured support, health via resolver, user model, fallback flag.
- **Gateway:** `ProviderAgentModelGateway` — `plan` / `summarize`; JSON decode; no business execution.
- **Schema:** `clarification` \| `single_intent` \| `execution_plan` \| `assistant_answer` \| `unsupported`.
- **Catalog:** presentation-safe rows from registry; relevance rank; max count.
- **Validator:** authoritative; skill/visibility/availability/input allowlist/plan graph/bindings/no auto-exec.
- **Repair:** once — slash→key, field alias, indexes, strip forbidden fields.
- **Confidence:** ≥0.80 show; 0.55–0.79 uncertain; &lt;0.55 clarification. Server adjusts down.

## 11–15. Clarification / summary / budget / security / persistence

- Clarification structured; answers re-plan; no auto-exec.
- Summary versioned; threshold; failure → recent messages.
- Budget drops whole sections; never mid-JSON truncate.
- Untrusted marker + input/output sanitizers; server strips `auto_execute` / `auto_confirm` / `run_all`.
- Table `seo_agent_planning_runs` — no raw prompt by default; redacted structured_response.

## 16. UI

Cards: `planning_status`, `proposed_intent`, `proposed_plan`, `clarification`, `unsupported`. Save plan → Phase 2 plan row, **executed=false**, **no Run All**.

## 17–18. Files

**Created:** `Services/AgentWorkspace/Planning/**`, migration `2026_07_28_220000_phase3_agent_planning_runs.php`, model `SeoAgentPlanningRun`, views `partials/agent-workspace/*`, unit tests `AgentPlan*`, `AgentModelRouter*`, `AgentContextBudget*`, `AgentSkillCatalog*`, `AgentPlanningSecurity*`, `AgentNaturalLanguage*`, `AgentConversationSummarizer*`, docs below.

**Modified:** `AgentWorkspaceApplicationService`, `AgentWorkspacePage`, `SeoContentAiServiceProvider`, `SeoAgentConversation`, `agent-message-structured.blade.php`, `agent-workspace.blade.php`, `SUPER_MAP_INDEX.md`.

## 19. Migration

`omi_seo_ai`: `seo_agent_planning_runs` + conversation summary columns. Additive only.

## 20. Tests / Manual verification

```text
Manual verification:

$PHP_BIN vendor/bin/phpunit --filter=AgentPlanning
$PHP_BIN vendor/bin/phpunit --filter=AgentPlanValidator
$PHP_BIN vendor/bin/phpunit --filter=AgentModelRouter
$PHP_BIN vendor/bin/phpunit --filter=AgentConversationSummarizer
$PHP_BIN vendor/bin/phpunit --filter=AgentContextBudget
$PHP_BIN vendor/bin/phpunit --filter=AgentPlanningSecurity
$PHP_BIN vendor/bin/phpunit --filter=AgentNaturalLanguage
$PHP_BIN vendor/bin/phpunit --filter=AgentWorkspace
$PHP_BIN vendor/bin/phpunit --filter=AgentExecution
$PHP_BIN vendor/bin/phpunit --filter=AgentIntentRouter
$PHP_BIN vendor/bin/phpunit --filter=ExtensionArchitectureFreezeTest

php artisan migrate
php artisan optimize:clear
```

Agent không chạy test local (remote-first).

## 21. Freeze verification

| Check | Result |
|-------|--------|
| CommandBus modified | No |
| Existing handlers modified | No |
| AgentGateway refactored | No |
| Execution Orchestrator bypassed | No |
| Capability definitions duplicated | No |
| Direct business writes | No |
| Autonomous execution | No |
| Run All | No |
| AI auto-confirm | No |
| AI vendor imported into UI | No |

## 22. Known limitations

- Max 1 planning provider call per message (+ optional summary when threshold).
- Model repair call **not** default.
- Suggested next actions from model keys only after availability filter.
- Feature tests needing live provider/DB left for remote host.
- Deterministic NL rules vẫn thắng AI khi match ≥0.55 (by design).

## 23. Phase 4 candidates (DO NOT implement)

Autonomous agent loop, scheduled agent, long-term vector memory, cross-site memory, AI-created capabilities, browser automation, GSC/Audit/Linking new skills.
