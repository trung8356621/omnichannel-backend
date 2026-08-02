# Agent Workspace

> Status: Canonical  
> Owner: SeoContentAi  
> Last verified: 2026-08-01  
> Supersedes: `docs/AGENT_WORKSPACE.md`, `docs/archive/agent/AGENT_WORKSPACE_*.md`, `docs/archive/agent/AGENT_SKILLS.md`, `docs/archive/agent/AGENT_SLASH_COMMANDS.md`, `docs/archive/agent/AGENT_CHAT_TEMPLATES.md`, `docs/archive/agent/AGENT_CONFIRMATION.md`, `docs/archive/agent/AGENT_EXECUTION.md`, `docs/archive/agent/AGENT_EXECUTION_PLANS.md`, `docs/archive/agent/AGENT_PLANNING.md`, `docs/archive/agent/AGENT_CONTEXT_BUDGET.md`, `docs/archive/agent/AGENT_CONVERSATION_SUMMARY.md`, `docs/archive/agent/AGENT_WORKSPACE_SECURITY.md`, `docs/archive/agent/AGENT_CAPABILITY_*.md`, `docs/archive/agent/AGENT_PACKS.md`, `docs/archive/agent/AGENT_PACK_*.md`, `docs/archive/agent/AGENT_SKILL_STUDIO.md`, `docs/archive/agent/AGENT_KNOWLEDGE_*.md`, `docs/archive/agent/AGENT_MEMORY.md`, `docs/archive/agent/AGENT_OBSERVABILITY.md`, `docs/archive/agent/AGENT_EVALUATION.md`, `docs/archive/agent/AGENT_METRICS.md`, `docs/archive/agent/AGENT_TRACING.md`, `docs/archive/agent/AGENT_COST_USAGE.md`, `docs/archive/agent/AGENT_GOVERNANCE.md`, `docs/archive/agent/AGENT_HUMAN_REVIEW.md`, `docs/archive/agent/AGENT_QUALITY_GATES.md`, `docs/archive/agent/AGENT_RESULT_RENDERING.md`, `docs/archive/agent/AGENT_RETENTION_PRIVACY.md`, `docs/archive/agent/AGENT_PROMPT_SECURITY.md`, `docs/archive/agent/AGENT_MODEL_ROUTING.md`, `docs/archive/agent/AGENT_V1_DOCTOR.md`

## 1. Purpose

Filament **Agent Workspace** is the skill-based orchestration UI on the SEO panel. It proposes, previews, confirms, and executes capabilities through `AgentGateway` → `ContentProjectAgentGateway` → `ContentProjectCommandBus`. It does **not** own Content Project business logic.

Tabs: **Chat | Knowledge | Automations | Operations | Packs | Diagnostics**.

## 2. Canonical routes

| Entry | Path | Notes |
|-------|------|-------|
| Primary | `/seo/{connection_hash}/agent` | Filament slug `agent` (`AgentWorkspacePage`) |
| Admin alias | `/admin/agent` | `AgentWorkspaceRedirect` → SEO panel URL |
| Deep link | `AgentWorkspaceDeepLink::tryUrl([...])` | Query: `project_ref`, `workspace_ref`, `article_ref`, `operation_ref`, `conversation`, `skill`, `template`. Fail closed without `connection_hash`. Prefill only — **no auto write**. |
| MCP tools | `GET|POST /api/v1/agent/mcp/tools` | Sanctum |
| MCP call | `POST /api/v1/agent/mcp/call` | Via Gateway |
| Agent execute | `POST /api/v1/agent/execute` | Via Gateway |

Nav group: Content Projects. Access: manager / content-project mutate / content features (`SeoAccessControl`).

## 3. Main components

| Component | Path | Role |
|-----------|------|------|
| Page | `Filament/Pages/AgentWorkspacePage.php` | Livewire orchestration |
| View | `resources/views/filament/pages/agent-workspace.blade.php` | Full-height chat + Alpine palette |
| Messages | `components/seo-agent-chat/*` | Presentation; user bubble without structured morph issues |
| App service | `AgentWorkspaceApplicationService` | openSkill / preview / execute / confirm / cancel / retry / planNaturalLanguage |
| Context | `AgentWorkspaceContextService` | Fail-closed auth + public refs |
| Intent | `AgentIntentRouter` | Slash → alias → template → NL → planning |
| Execution | `DefaultAgentExecutionOrchestrator` + `AgentExecutionStateMachine` | Tokens, idempotency, statuses |
| Planning | `AgentPlanningOrchestrator` | Propose only — never Gateway/CommandBus |
| Skills | `AgentSkillRegistry` + `AgentSkillAvailabilityService` | Presentation catalog |
| CLI palette | `AgentCliCommandCatalog` + `resources/js/agent/command-catalog.js` | Curated `/` UX; zero-network filter |
| Facade | `AgentGateway` | Thin delegate to `ContentProjectAgentGateway` |
| Gateway | `ContentProjectAgentGateway` | Scopes, schema, confirmation, dry_run, dispatch |
| Registry | `CanonicalCapabilityRegistry` | Core + enabled extension caps |

**Quick Assistant vs Workspace:** popup `global-ai-chat` Team tab stays team chat; AI star is **launcher only** (`openAgentWorkspace()`). Full runtime lives on `/seo/{hash}/agent` only.

## 4. Data ownership

Connection: `omi_seo_ai`.

| Table | Model | Owner |
|-------|-------|-------|
| `seo_agent_conversations` | `SeoAgentConversation` | Chat threads (+ summary fields) |
| `seo_agent_messages` | `SeoAgentMessage` | Message history |
| `seo_agent_executions` | `SeoAgentExecution` | Skill run audit (`operation_ref`, confirmation hash) |
| `seo_agent_execution_plans` | `SeoAgentExecutionPlan` | Sequential plans |
| `seo_agent_planning_runs` | `SeoAgentPlanningRun` | Planning diagnostics |
| `seo_agent_automations*` | Agent automation models | Agent Automations tab (see `AUTOMATION.md`) |
| Knowledge / packs / eval tables | Agent Workspace models | Knowledge, Packs, Operations tabs |

Content Project domain tables (`seo_projects`, tasks, articles, runs) are **not** owned by Agent Workspace — write only via CommandBus handlers.

## 5. Read path

```
UI / MCP
  → AgentGateway::execute | readCapabilities
  → ContentProjectAgentGateway
  → READ_CAPABILITIES branch → *ReadService (CP / Keyword / SERP / GSC / SeoAudit)
```

Read caps (Gateway constant): `content_project.list_*|get_*`, keyword/serp/gsc intelligence list/get, `seo_audit.list`. No CommandBus for pure reads.

Site health: `ContentProjectSiteHealthService` + presenters — evidence-based, not hardcoded `unknown`.

## 6. Write path

```
UI (AgentWorkspacePage)
  → AgentWorkspaceApplicationService
  → AgentExecutionOrchestrator (state machine, awex idempotency, awconf_ tokens)
  → AgentGateway
  → ContentProjectAgentGateway
      → CanonicalCapabilityRegistry + policy + schema + confirmation
      → ContentProjectAgentCommandFactory
      → ContentProjectCommandBus → Handler
```

NL / planning: propose plan only. **Slash always wins AI.** Free text must not auto-execute write caps.

Confirm: browser sends `execution_ref` + plaintext `confirmation_token`; server reloads canonical input. DB stores **hash only**.

## 7. Public capabilities

- Skill / slash / CLI catalog entries gated for agent exposure + context (`AgentCliCapabilityGate`).
- MCP tool subset from `ContentProjectMcpToolCatalog` (`isMcpWriteExposed`) + read schemas.
- Core write surface examples: create/update/add_items/generate/rerun/start_review/approve/schedule*/publish*/archive*/restore + plan tools (see contracts).
- CP/Publishing Queue split: `content_project.send_to_publishing_queue` (CP handoff, no WordPress) and `content_project.return_to_content_project` (PQ → CP) are separate capabilities from `schedule*`/`publish*` (PQ-only, act on `publishing_queued_at` items).
- Public refs only (`cpj_`, `cpi_`, `cps_`, …) — never raw numeric project/item IDs in Agent I/O.

## 8. Internal-only capabilities

| Cap / surface | Rule |
|---------------|------|
| `content_project.sync_items` | Not in agent skill catalog / MCP |
| `content_project.process_scheduled_publish` | Scheduler/internal |
| `content_project.stop_execution` / `resume_execution` | **Agent-ok, MCP-no** |
| SERP/GSC write tools | CommandBus/registry OK; **not** MCP catalog |
| Pack internal / doctor | Diagnostics / manager only |
| Extension conflicted names | Excluded via `CanonicalCapabilityRegistry::conflicts()` |

## 9. Authorization and confirmation

- Site isolation: server-derived `connection_hash` / `site_id`; browser cannot switch site. Cross-site public refs fail closed.
- Conversation scoped: `tenant_id`, `site_id`, `created_by`.
- Confirmation policy cannot be downgraded vs canonical; archive/destructive ≥ confirm.
- No AI auto-confirm. No autonomous destructive. Planning/knowledge cannot alter automation policy.
- Sanctum scopes for API: read token cannot write (`content-project:*` scopes on MCP/execute).
- Rate limits / quotas: `ContentProjectAgentRateLimiter` + budget guards.

## 10. Queue and scheduler ownership

| Owner | Command / job | Table |
|-------|---------------|-------|
| Agent Automations | `agent:automations:dispatch-due` → `RunAgentAutomationJob` | `seo_agent_automations` |
| Business Hook Automation | `automation:dispatch-scheduled` | `automation_rules` (core) |
| CP Automation Policy | `seo-content-ai:dispatch-automation-policies` | `seo_content_project_automation_policies` |

Three owners must not claim the same occurrence. Agent automation jobs call Runner only — **not** CommandBus directly (see `AUTOMATION.md`).

Session cleanup: `seo:agent-sessions:cleanup` for `seo_content_project_agent_sessions`.

## 11. Transactions and side effects

- Gateway orchestration only — no domain writes inside Gateway.
- Side effects (generate, publish, archive) happen in CommandBus handlers under existing locks/idempotency.
- Conversation delete ≠ business ops (no cascade archive/publish).
- Result rendering / context updater: allowlisted fields only.

## 12. Retry and recovery

- Execution statuses: `draft` → … → `awaiting_confirmation` → `running` → terminal (`succeeded|failed|cancelled|expired`).
- Terminal executions are not re-run; retry = new execution/attempt + new idempotency key (`awex:…`).
- Confirmation reject: expired, used, actor/site/conversation/input mismatch, stale, terminal.
- Operation poll: `content_project.get_operation` (rate-limited).

## 13. Compatibility paths

- Admin `/admin/agent` redirect.
- Popup AI launcher → Workspace URL.
- Additive Agent Workspace migrations in `omi_seo_ai` only (no silent core key renames).
- Pack / skill schema `1.0` backward-compatible within v1.x; breaking → v2.
- Related contracts: `docs/contracts/AGENT_AND_MCP_CONTRACTS.md`.

## 14. Forbidden paths

- Skills / Orchestrator / Planning import Eloquent business models (`SeoProject`, `SeoProjectTask`, articles) or Handler classes.
- Agent call domain Service methods bypassing Gateway.
- AI auto-confirm or auto-execute writes from free text.
- Expose internal MCP tools (`sync_items`, stop/resume, queue tokens, locks, raw prompt results).
- Log raw confirmation tokens, Sanctum secrets, credentials.
- `AgentWorkspaceApplicationService` inject/import `ContentProjectCommandBus`.
- Dump full `CanonicalCapabilityRegistry` into slash palette.

## 15. Tests and invariants

Key PHPUnit filters (remote `$PHP_BIN vendor/bin/phpunit --filter=…`):

| Area | Tests |
|------|-------|
| Path lock | `ArchitectureHardeningLockContractTest`, `ExtensionArchitectureFreezeTest` |
| Gateway | `ContentProjectAgentGatewayTest`, `AgentWorkspaceExecutionTest` |
| Slash / CLI | `AgentSlashCommandTest`, `AgentSlashCommandRestoreTest`, `AgentCliCommandTest`, `AgentCliUxCapabilityFixTest` |
| Confirm | `AgentConfirmationTokenTest`, `AgentExecutionStateMachineTest` |
| Context / intent | `AgentWorkspaceContextTest`, `AgentIntentRouterTest`, `AgentDeepLinkTest` |
| Skills | `AgentSkillRegistryTest`, `AgentSkillAvailabilityTest`, `AgentContentProjectSkillsTest` |
| Automation | `AgentAutomationTest`, `AgentAutomationSecurityTest`, `AgentAutomationScheduleTest` |
| UI binding | `AgentWorkspaceUiTest`, `AgentWorkspaceLivewireBindingTest`, `GlobalAiChatRouteRetiredContractTest` |
| MCP surface | `McpCapabilityMarkdownPresenterTest`, `ContentProjectPublicCapabilityContractTest` |

**Invariants:** Gateway → CommandBus only for writes; planning never executes; slash > AI; site fail-closed; confirmation never downgraded; MCP ⊂ Agent write surface.

## 16. Related documents

- `docs/modules/AUTOMATION.md`
- `docs/contracts/AGENT_AND_MCP_CONTRACTS.md`
- `docs/modules/CONTENT_PROJECTS.md`
- `docs/modules/EXTENSION_SDK.md`
- `docs/modules/OPERATIONS_AND_OBSERVABILITY.md`
- `docs/architecture/ARCHITECTURE_FREEZE_V1.md`
- `docs/architecture/ARCHITECTURE_DECISIONS.md`
- `docs/operations/TESTING.md` (ops extract; module §15 is primary test list)
