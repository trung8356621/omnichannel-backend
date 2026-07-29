# Agent Workspace (Phase 1–7 → **v1.0 Freeze**)

Filament UI cho skill-based Agent trên SEO panel — orchestration layer, **không** duplicate business logic.

Phase 2: execution — [PHASE_2](AGENT_WORKSPACE_PHASE_2_HANDOFF.md).  
Phase 3: AI planning — [PHASE_3](AGENT_WORKSPACE_PHASE_3_HANDOFF.md).  
Phase 4: scoped knowledge/memory grounding — [PHASE_4](AGENT_WORKSPACE_PHASE_4_HANDOFF.md).  
Phase 5: scheduled automations / monitoring — [PHASE_5](AGENT_WORKSPACE_PHASE_5_HANDOFF.md), [AUTOMATIONS](AGENT_AUTOMATIONS.md).  
Phase 6: observability / evaluation / governance — [PHASE_6](AGENT_WORKSPACE_PHASE_6_HANDOFF.md), [OBSERVABILITY](AGENT_OBSERVABILITY.md), [EVALUATION](AGENT_EVALUATION.md).  
Phase 7: skill packs / Skill Studio — [PHASE_7](AGENT_WORKSPACE_PHASE_7_HANDOFF.md), [PACKS](AGENT_PACKS.md).  

**v1.0:** [FREEZE](AGENT_WORKSPACE_V1_FREEZE.md) · [FINAL HANDOFF](AGENT_WORKSPACE_V1_FINAL_HANDOFF.md) · [TEST PLAN](AGENT_WORKSPACE_V1_TEST_PLAN.md) · [DOCTOR](AGENT_V1_DOCTOR.md) · [COVERAGE](AGENT_CAPABILITY_COVERAGE.md) · [SKILLS](AGENT_SKILLS.md).

Tabs: **Chat | Knowledge | Automations | Operations | Packs | Diagnostics**.

## Route & entry

| Entry | Path | Ghi chú |
|-------|------|---------|
| **Primary** | `/seo/{connection_hash}/agent` | Filament slug `agent` trên SEO panel (`AgentWorkspacePage`) |
| **Admin alias** | `/admin/agent` | `AgentWorkspaceRedirect` — redirect sang SEO panel URL thật |
| **Deep link** | `AgentWorkspaceDeepLink::tryUrl([...])` / `forCurrentRequest()` | Query: `project_ref`, `workspace_ref`, `article_ref`, `operation_ref`, `conversation`, `skill`, `template`. Fail closed nếu thiếu `connection_hash`. |

Navigation group: **Content Projects**. Access: manager / content-project mutate / content features (`SeoAccessControl`).

## Quick Assistant vs Agent Workspace

| Surface | Vai trò | Runtime |
|---------|---------|---------|
| Popup `global-ai-chat` — tab **Team** | Quick assistant / team chat | Team SSE + attachment (giữ nguyên) |
| Popup — tab/ngôi sao **AI** | **Launcher only** | `openAgentWorkspace()` → navigate `/seo/{hash}/agent` |
| Page `/seo/{hash}/agent` | Agent Workspace đầy đủ | `AgentWorkspaceApplicationService` → `AgentGateway` → CommandBus |

Shared: presentation components `seo-content-ai::seo-agent-chat.*` + CSS `global-ai-chat.css` / `agent-workspace.css`.  
Không shared: conversation Agent storage, slash execution, Gateway.

Deep link chỉ prefill context (`project_ref` / `skill` / `template`) — **không auto execute** write.

## Chat Workspace popup

`global-ai-chat.blade.php`: Team giữ nguyên. Ngôi sao AI **không** render Agent/AI runtime trong popup.

## Layout (3 cột)

```
┌─────────────┬──────────────────────────┬─────────────┐
│ Conversations│ Chat + composer         │ Context     │
│ (sidebar)    │ + slash palette         │ panel       │
│              │ + skill form drawer     │             │
└─────────────┴──────────────────────────┴─────────────┘
```

Mobile: drawers Alpine (`conversationsOpen`, `contextOpen`) — không chờ Livewire để toggle layout.

## Components chính

| Thành phần | File | Vai trò |
|------------|------|---------|
| Page | `Filament/Pages/AgentWorkspacePage.php` | Livewire orchestration, composer, palette, skill form |
| View | `resources/views/filament/pages/agent-workspace.blade.php` | Layout 3 cột + diagnostics panel |
| Conversations | `partials/agent-conversation-list.blade.php` | Danh sách / chọn / pin / archive |
| Messages | `partials/agent-message.blade.php` | text, preview, tool_result, error |
| Skill form | `partials/agent-skill-form.blade.php` | Dynamic form từ `form_schema` |
| Context | `partials/agent-context-panel.blade.php` | site, refs, recommended skills |
| Slash palette | Inline trong page view | Filter `paletteSkills` khi gõ `/` |

## Execution flow

```
UI (AgentWorkspacePage)
  → AgentWorkspaceApplicationService   (openSkill / preview / execute / confirm / cancel / retry / planNaturalLanguage)
  → AgentPlanningOrchestrator          (Phase 3 — propose only; no Gateway)
  → AgentExecutionOrchestrator         (Phase 2 — state machine, tokens, idempotency)
  → AgentGateway                       (facade — không duplicate gateway logic)
    → ContentProjectAgentGateway       (scopes, confirmation, dry_run)
      → CanonicalCapabilityRegistry
        → ContentProjectCommandBus
```

**Nguyên tắc:** Skills / Orchestrator **không** gọi Eloquent business models trực tiếp — chỉ qua `AgentGateway`.  
Confirm: `execution_ref` + token; server reload canonical input. Không auto-confirm.  
Phase 3 AI **không** gọi Gateway/CommandBus.

## Intent routing

`AgentIntentRouter` — thứ tự resolve:

1. Exact slash command
2. Slash alias
3. Chat template (`skill_key` set)
4. Deterministic NL / legacy adapter / multi-intent
5. Structured `ai_intent` option (low confidence guard)
6. General assistant → **Phase 3** `planNaturalLanguage` (copilot)

**Không** auto-execute write capabilities từ free text. Slash luôn thắng AI.

## Persistence (`omi_seo_ai`)

| Table | Model | Mục đích |
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
| `AgentWorkspaceContextService` | Fail-closed context từ auth + public refs |
| `AgentWorkspaceApplicationService` | openSkill / preview / execute |
| `AgentConversationService` | CRUD conversation (presentation only) |
| `AgentSkillRegistry` | Presentation catalog |
| `AgentSkillAvailabilityService` | UI availability từ capability + context |
| `AgentIntentRouter` | Composer → skill resolution |
| `AgentChatTemplateRegistry` | Builtin + featured templates |
| `AgentCapabilityDiagnosticsService` | Manager diagnostics panel |

## Docs liên quan

- [AGENT_SKILLS.md](AGENT_SKILLS.md) — skill catalog & availability
- [AGENT_SLASH_COMMANDS.md](AGENT_SLASH_COMMANDS.md) — slash UX + full command list
- [AGENT_CHAT_TEMPLATES.md](AGENT_CHAT_TEMPLATES.md) — template shortcuts
- [AGENT_WORKSPACE_SECURITY.md](AGENT_WORKSPACE_SECURITY.md) — isolation & scopes
- [AGENT_CAPABILITY_DIAGNOSTICS.md](AGENT_CAPABILITY_DIAGNOSTICS.md) — diagnostics panel
- [CONTENT_PROJECT_AGENT_GATEWAY.md](CONTENT_PROJECT_AGENT_GATEWAY.md) — gateway contract
- [CONTENT_PROJECT_AGENT_SECURITY.md](CONTENT_PROJECT_AGENT_SECURITY.md) — confirmation tokens, rate limits
