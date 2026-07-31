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
# Agent Workspace Phase 3 Handoff â€” AI Planning & Guarded Copilot

## 1. Inspect findings

| Item | Finding |
|------|---------|
| `AgentIntentRouter` | Order: slash â†’ alias â†’ template â†’ multi â†’ deterministic â†’ optional `ai_intent` â†’ **assistant**. Phase 3 hooks **assistant** only for NL planning. Slash never calls model. |
| AI providers | `AiProviderResolver` + `AiTextProviderInterface` (Gemini/Claude builtins). Planner/UI **khÃ´ng** import vendor SDK. |
| `AiModelRouterService` | Site connection models/categories â€” `RegistryAgentModelRouter` dÃ¹ng opaque `provider` key tá»« connection. |
| Conversation | CÃ³ `context_summary`. Phase 3 thÃªm `summary`, `summary_version`, `summary_until_message_id`, `summary_updated_at`. |
| Phase 2 | `AgentExecutionOrchestrator` / `AgentPlanStepRunner.createPlan` váº«n lÃ  execution boundary. Planning chá»‰ **save plan records**, khÃ´ng run step. |
| Free chat path | TrÆ°á»›c Phase 3: assistant fallback text. Sau: `planNaturalLanguage` â†’ structured cards. |

## 2. Architecture

```
UI (AgentWorkspacePage)
  â†’ AgentIntentRouter (deterministic first)
  â†’ (SOURCE_ASSISTANT only) AgentWorkspaceApplicationService::planNaturalLanguage
    â†’ AgentPlanningOrchestrator
      â†’ ContextAssembler + SkillCatalogPresenter + BudgetManager
      â†’ AgentModelRouter â†’ AgentModelGateway (AiTextProviderInterface)
      â†’ OutputSanitizer + DeterministicRepair (1Ã—) + PlanValidator
      â†’ Proposed intent/plan/clarification/unsupported
  â†’ User review / edit / savePlan
  â†’ Phase 2 AgentPlanStepRunner / ExecutionOrchestrator
```

AI **khÃ´ng** náº±m trÃªn execution path sau khi user quyáº¿t Ä‘á»‹nh cháº¡y.

## 3. Deterministic vs Copilot

| Path | AI? |
|------|-----|
| Slash / alias / template / selected skill / form / confirm / retry / cancel | No |
| Multi-intent detect / deterministic rules / structured `ai_intent` option | No (existing) |
| `SOURCE_ASSISTANT` natural language | Yes â€” planning only |

## 4â€“10. Core pieces

- **Model router:** `RegistryAgentModelRouter` â€” task type, structured support, health via resolver, user model, fallback flag.
- **Gateway:** `ProviderAgentModelGateway` â€” `plan` / `summarize`; JSON decode; no business execution.
- **Schema:** `clarification` \| `single_intent` \| `execution_plan` \| `assistant_answer` \| `unsupported`.
- **Catalog:** presentation-safe rows from registry; relevance rank; max count.
- **Validator:** authoritative; skill/visibility/availability/input allowlist/plan graph/bindings/no auto-exec.
- **Repair:** once â€” slashâ†’key, field alias, indexes, strip forbidden fields.
- **Confidence:** â‰¥0.80 show; 0.55â€“0.79 uncertain; &lt;0.55 clarification. Server adjusts down.

## 11â€“15. Clarification / summary / budget / security / persistence

- Clarification structured; answers re-plan; no auto-exec.
- Summary versioned; threshold; failure â†’ recent messages.
- Budget drops whole sections; never mid-JSON truncate.
- Untrusted marker + input/output sanitizers; server strips `auto_execute` / `auto_confirm` / `run_all`.
- Table `seo_agent_planning_runs` â€” no raw prompt by default; redacted structured_response.

## 16. UI

Cards: `planning_status`, `proposed_intent`, `proposed_plan`, `clarification`, `unsupported`. Save plan â†’ Phase 2 plan row, **executed=false**, **no Run All**.

## 17â€“18. Files

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

Agent khÃ´ng cháº¡y test local (remote-first).

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
- Deterministic NL rules váº«n tháº¯ng AI khi match â‰¥0.55 (by design).

## 23. Phase 4 candidates (DO NOT implement)

Autonomous agent loop, scheduled agent, long-term vector memory, cross-site memory, AI-created capabilities, browser automation, GSC/Audit/Linking new skills.
