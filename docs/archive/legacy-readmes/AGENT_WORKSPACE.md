> Status: Historical
> Not canonical
> Archived on: 2026-08-01
> Superseded by: ../../modules/AGENT_WORKSPACE.md
> Purpose: implementation history only
# Agent Workspace (Phase 1â€“7 â†’ **v1.0 Freeze**)

Filament UI cho skill-based Agent trÃªn SEO panel â€” orchestration layer, **khÃ´ng** duplicate business logic.

Phase 2: execution â€” [PHASE_2](archive/AGENT_WORKSPACE_PHASE_2_HANDOFF.md) *(historical)*.  
Phase 3: AI planning â€” [PHASE_3](archive/AGENT_WORKSPACE_PHASE_3_HANDOFF.md) *(historical)*.  
Phase 4: scoped knowledge/memory grounding â€” [PHASE_4](archive/AGENT_WORKSPACE_PHASE_4_HANDOFF.md) *(historical)*.  
Phase 5: scheduled automations / monitoring â€” [PHASE_5](archive/AGENT_WORKSPACE_PHASE_5_HANDOFF.md) *(historical)*, [AUTOMATIONS](AGENT_AUTOMATIONS.md).  
Phase 6: observability / evaluation / governance â€” [PHASE_6](archive/AGENT_WORKSPACE_PHASE_6_HANDOFF.md) *(historical)*, [OBSERVABILITY](AGENT_OBSERVABILITY.md), [EVALUATION](AGENT_EVALUATION.md).  
Phase 7: skill packs / Skill Studio â€” [PHASE_7](archive/AGENT_WORKSPACE_PHASE_7_HANDOFF.md) *(historical)*, [PACKS](AGENT_PACKS.md).  

**v1.0:** [FREEZE](AGENT_WORKSPACE_V1_FREEZE.md) Â· [FINAL HANDOFF](archive/AGENT_WORKSPACE_V1_FINAL_HANDOFF.md) *(historical)* Â· [TEST PLAN](archive/AGENT_WORKSPACE_V1_TEST_PLAN.md) *(historical)* Â· [DOCTOR](AGENT_V1_DOCTOR.md) Â· [COVERAGE](AGENT_CAPABILITY_COVERAGE.md) Â· [SKILLS](AGENT_SKILLS.md).

Tabs: **Chat | Knowledge | Automations | Operations | Packs | Diagnostics**.

## Route & entry

| Entry | Path | Ghi chÃº |
|-------|------|---------|
| **Primary** | `/seo/{connection_hash}/agent` | Filament slug `agent` trÃªn SEO panel (`AgentWorkspacePage`) |
| **Admin alias** | `/admin/agent` | `AgentWorkspaceRedirect` â€” redirect sang SEO panel URL tháº­t |
| **Deep link** | `AgentWorkspaceDeepLink::tryUrl([...])` / `forCurrentRequest()` | Query: `project_ref`, `workspace_ref`, `article_ref`, `operation_ref`, `conversation`, `skill`, `template`. Fail closed náº¿u thiáº¿u `connection_hash`. |

Navigation group: **Content Projects**. Access: manager / content-project mutate / content features (`SeoAccessControl`).

## Quick Assistant vs Agent Workspace

| Surface | Vai trÃ² | Runtime |
|---------|---------|---------|
| Popup `global-ai-chat` â€” tab **Team** | Quick assistant / team chat | Team SSE + attachment (giá»¯ nguyÃªn) |
| Popup â€” tab/ngÃ´i sao **AI** | **Launcher only** | `openAgentWorkspace()` â†’ navigate `/seo/{hash}/agent` |
| Page `/seo/{hash}/agent` | Agent Workspace Ä‘áº§y Ä‘á»§ | `AgentWorkspaceApplicationService` â†’ `AgentGateway` â†’ CommandBus |

Shared: presentation components `seo-content-ai::seo-agent-chat.*` + CSS `global-ai-chat.css` / `agent-workspace.css`.  
KhÃ´ng shared: conversation Agent storage, slash execution, Gateway.

Deep link chá»‰ prefill context (`project_ref` / `skill` / `template`) â€” **khÃ´ng auto execute** write.

## Chat Workspace popup

`global-ai-chat.blade.php`: Team giá»¯ nguyÃªn. NgÃ´i sao AI **khÃ´ng** render Agent/AI runtime trong popup.

## Layout (3 cá»™t)

```
â”Œâ”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”¬â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”¬â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”
â”‚ Conversationsâ”‚ Chat + composer         â”‚ Context     â”‚
â”‚ (sidebar)    â”‚ + slash palette         â”‚ panel       â”‚
â”‚              â”‚ + skill form drawer     â”‚             â”‚
â””â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”´â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”´â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”˜
```

Mobile: drawers Alpine (`conversationsOpen`, `contextOpen`) â€” khÃ´ng chá» Livewire Ä‘á»ƒ toggle layout.

## Components chÃ­nh

| ThÃ nh pháº§n | File | Vai trÃ² |
|------------|------|---------|
| Page | `Filament/Pages/AgentWorkspacePage.php` | Livewire orchestration, composer, CLI draft flow |
| View | `resources/views/filament/pages/agent-workspace.blade.php` | Full-height chat + Alpine palette |
| Messages | `components/seo-agent-chat/message.blade.php` + `partials/agent-message-structured.blade.php` | User bubble **khÃ´ng** include structured (trÃ¡nh Livewire morph newlines + `pre-wrap` lÃ m bubble mÃ©o). `AgentMessageOutputSanitizer` strip marker `<!--[if BLOCK/ENDBLOCK]>` lÃºc persist + lazy refresh |
| CLI catalog FE | `resources/js/agent/command-catalog.js` | `window.AgentCommandCatalog` â€” filter `/` zero-network; group Core/Site/Project/â€¦ |
| CLI catalog BE | `Services/AgentWorkspace/Cli/AgentCliCommandCatalog.php` + `AgentCliCapabilityGate.php` | Curated CLI â†’ skill/`capability_key`; khÃ´ng dump full registry |
| Slash palette | Alpine `paletteOpen` / `filteredCommands` | Compact monospace; khÃ´ng `paletteSkills` Livewire round-trip |
| Site health | `ContentProjectSiteHealthService` + `SiteInfoPresenter` | Evidence sync/handshake/capabilities â€” khÃ´ng hardcode `unknown` |

## Execution flow

```
UI (AgentWorkspacePage)
  â†’ AgentWorkspaceApplicationService   (openSkill / preview / execute / confirm / cancel / retry / planNaturalLanguage)
  â†’ AgentPlanningOrchestrator          (Phase 3 â€” propose only; no Gateway)
  â†’ AgentExecutionOrchestrator         (Phase 2 â€” state machine, tokens, idempotency)
  â†’ AgentGateway                       (facade â€” khÃ´ng duplicate gateway logic)
    â†’ ContentProjectAgentGateway       (scopes, confirmation, dry_run)
      â†’ CanonicalCapabilityRegistry
        â†’ ContentProjectCommandBus
```

**NguyÃªn táº¯c:** Skills / Orchestrator **khÃ´ng** gá»i Eloquent business models trá»±c tiáº¿p â€” chá»‰ qua `AgentGateway`.  
Confirm: `execution_ref` + token; server reload canonical input. KhÃ´ng auto-confirm.  
Phase 3 AI **khÃ´ng** gá»i Gateway/CommandBus.

## Intent routing

`AgentIntentRouter` â€” thá»© tá»± resolve:

1. Exact slash command
2. Slash alias
3. Chat template (`skill_key` set)
4. Deterministic NL / legacy adapter / multi-intent
5. Structured `ai_intent` option (low confidence guard)
6. General assistant â†’ **Phase 3** `planNaturalLanguage` (copilot)

**KhÃ´ng** auto-execute write capabilities tá»« free text. Slash luÃ´n tháº¯ng AI.

## Persistence (`omi_seo_ai`)

| Table | Model | Má»¥c Ä‘Ã­ch |
|-------|-------|----------|
| `seo_agent_conversations` | `SeoAgentConversation` | Chat threads (+ summary fields Phase 3) |
| `seo_agent_messages` | `SeoAgentMessage` | Message history |
| `seo_agent_executions` | `SeoAgentExecution` | Skill run audit (link `operation_ref`) |
| `seo_agent_execution_plans` | `SeoAgentExecutionPlan` | Sequential plans (Phase 2) |
| `seo_agent_planning_runs` | `SeoAgentPlanningRun` | AI planning diagnostics (Phase 3) |

Migration: `2026_07_28_190000_create_seo_agent_workspace_tables.php`

## Services (addon)

| Service | Role |
|---------|------|
| `AgentWorkspaceContextService` | Fail-closed context tá»« auth + public refs |
| `AgentWorkspaceApplicationService` | openSkill / preview / execute |
| `AgentConversationService` | CRUD conversation (presentation only) |
| `AgentSkillRegistry` | Presentation catalog |
| `AgentSkillAvailabilityService` | UI availability tá»« capability + context |
| `AgentIntentRouter` | Composer â†’ skill resolution |
| `AgentChatTemplateRegistry` | Builtin + featured templates |
| `AgentCapabilityDiagnosticsService` | Manager diagnostics panel |

## Docs liÃªn quan

- [AGENT_SKILLS.md](AGENT_SKILLS.md) â€” skill catalog & availability
- [AGENT_SLASH_COMMANDS.md](AGENT_SLASH_COMMANDS.md) â€” slash UX + full command list
- [AGENT_CHAT_TEMPLATES.md](AGENT_CHAT_TEMPLATES.md) â€” template shortcuts
- [AGENT_WORKSPACE_SECURITY.md](AGENT_WORKSPACE_SECURITY.md) â€” isolation & scopes
- [AGENT_CAPABILITY_DIAGNOSTICS.md](AGENT_CAPABILITY_DIAGNOSTICS.md) â€” diagnostics panel
- [CONTENT_PROJECT_AGENT_GATEWAY.md](CONTENT_PROJECT_AGENT_GATEWAY.md) â€” gateway contract
- [CONTENT_PROJECT_AGENT_SECURITY.md](CONTENT_PROJECT_AGENT_SECURITY.md) â€” confirmation tokens, rate limits
