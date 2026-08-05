# Automation

> Status: Canonical  
> Owner: SeoContentAi  
> Last verified: 2026-08-01  
> Supersedes: `docs/archive/agent/AGENT_AUTOMATIONS.md`, `docs/archive/agent/AGENT_AUTOMATION_*.md`, `docs/archive/content-projects/CONTENT_PROJECT_AUTOMATION_POLICY.md`, `docs/automation/AUTOMATION_BOUNDARIES.md`, `docs/automation/AUTOMATION_ACTION_CATALOG.md`, `docs/automation/AUTOMATION_EVENT_CATALOG.md`, `docs/automation/AUTOMATION_SERVICE_INVENTORY.md`, `docs/automation/AUTOMATION_MIGRATION_STATUS.md`, `docs/automation/AUTOMATION_CUTOVER_AUDIT.md`, `docs/automation/AUTOMATION_PHASE*.md`, `docs/automation/MODULE_SDK.md`, `docs/automation/SKILL_ADD_AUTOMATION_ACTION.md`

## 1. Purpose

Canonical map for **three distinct automation owners** in SeoContentAi:

1. **Business Hook Automation** — scheduled/event rules → Action catalog → domain services.  
2. **Agent Workspace Automations** — user-defined scheduled Agent workflows (Automations tab).  
3. **Content Project Automation Policy** — tenant/site policy driving assisted/full CP agent plans.

They share safety culture (no arbitrary code, confirmation, tenant gates) but **must not share dispatch tables or claim the same occurrence**.

## 2. Canonical routes

| Surface | Path / entry | Owner |
|---------|--------------|-------|
| Agent Automations UI | `/seo/{connection_hash}/agent` → tab **Automations** | Agent Workspace |
| Agent slash | `/automations`, `/create-automation`, `/automation-status`, `/run-automation`, `/pause-automation`, `/resume-automation`, `/delete-automation`, `/automation-history` | Agent Workspace |
| Business Hook admin | Filament automation rule UI (addon Automation module) | Business Hook |
| CP policy preview (MCP/Agent) | capability `content_project.get_agent_policy` | CP Policy |
| Dispatch CLI | `automation:dispatch-scheduled`, `agent:automations:dispatch-due`, `seo-content-ai:dispatch-automation-policies` | Scheduler |

## 3. Main components

### Business Hook

| Component | Role |
|-----------|------|
| Action catalog + registry | PHP map of business action keys → handlers |
| `BusinessActionDispatcher` / `CatalogBusinessActionDispatcher` | Manual/UI/Command local mutations (not fake Automation Rule) |
| `AutomationCallerMigrator` | Production callers → Action path (emergency legacy flag) |
| Event catalog | Domain events with locked envelope / naming |
| Action handlers | Call domain services only — **no** Filament Page/Resource |

### Agent Automations

| Component | Role |
|-----------|------|
| `AgentAutomationOrchestrator` | Definition lifecycle |
| `AgentAutomationRunner` | Executes via Agent execution/planning paths only |
| `AgentAutomationDispatcher` | Due scan → `RunAgentAutomationJob` |
| `RunAgentAutomationJob` | Job → Runner only |

### Content Project policy

| Component | Role |
|-----------|------|
| `seo_content_project_automation_policies` | Policy entity |
| `DispatchContentProjectAutomationPoliciesJob` | Hourly dispatch on queue `automation-policy` |
| `ContentProjectAgentBudgetGuard` | Daily budget |
| Plan gateway | Plan create/confirm/start (not CommandBus) |

## 4. Data ownership

| Store | Connection | Owner |
|-------|------------|-------|
| `automation_rules` / executions / heartbeat | Core `mysql` (`AUTOMATION_DB_CONNECTION`) | Business Hook |
| `seo_agent_automations*` | `omi_seo_ai` | Agent Automations |
| `seo_content_project_automation_policies` | `omi_seo_ai` | CP Policy |
| Article/keyword/project domain rows | `omi_seo_ai` | Domain handlers (via Action or CommandBus) |

Canonical IDs (locked): `team_id?`, `site_id`, `connection_id`, `article_id`, `wp_post_id`.  
Forbidden aliases in Action/Event context: `website_id`, `domain_id`, vague `wp_id`/`post_id`.

## 5. Read path

- Agent Automations: list/status/history via Agent Workspace services + slash; no domain Eloquent from skills.  
- Business Hook: rule/event admin reads core automation tables.  
- CP policy: `content_project.get_agent_policy`, `list_pending_approvals`, plan get/list.

## 6. Write path

### Business Hook

```
Workflow / Rule / UI / Migrator
  → Business Action Key
  → Action Registry
  → Action Handler
  → Domain Service
```

Workflow JSON must not embed `::`, `@`, `App\`, `Services\`, or PHP class/method names.

### Agent Automations

```
Schedule / manual run
  → DispatchDueAgentAutomationsCommand
  → RunAgentAutomationJob
  → AgentAutomationRunner
  → Agent execution / planning (then Gateway for writes)
```

Types: `scheduled_report` | `condition_watch` | `planning_workflow` | `guarded_action`.

### CP Policy

```
DispatchContentProjectAutomationPoliciesJob
  → policy resolve (tenant + optional site)
  → plan lifecycle (PlanGateway)
  → confirmed steps → Agent Gateway → CommandBus
```

Levels: `manual` | `assisted` | `reviewed_automation` | `full_automation`. Hard gates always on.

## 7. Public capabilities

**Business actions (selectable examples):** `article.create`, `article.content.update`, `article.seo_meta.update`, content-project task helpers, keyword assign, `keyword.domain_link_list.sync` (via rule on `keyword.saved`), wordpress publish intents when eligible.

**Agent automation:** user CRUD of definitions after explicit preview/save; slash management commands.

**CP policy exposed:** get policy, list/approve/reject pending approvals, plan control tools (see contracts). Agent **cannot** mutate policy (admin scope only).

## 8. Internal-only capabilities

| Item | Rule |
|------|------|
| `wordpress.article.sync_outbound` | `legacy_not_selectable`; not workflow/UI |
| Legacy `wordpress.article.update` | Rejected |
| `PublishIntent.remote_update` | Reserved |
| Event triggers on CP policy | Registry may list; **default off** |
| Emergency legacy migrator | `AUTOMATION_MIGRATION_EMERGENCY_LEGACY=true` only |
| INTERNAL_SERVICE_ONLY / BLOCKED action keys | Not selectable nodes |

## 9. Authorization and confirmation

**Shared hard gates (CP policy — cannot disable):**

- Confirm required for: archive, restore, publish_now, cancel_publish, skip_publish.  
- No `ignore_lifecycle` / `ignore_quota` / `ignore_tenant` / `force_publish` / `force_archive`.  
- Lifecycle, tenant, quota, lock, idempotency, processing, publish eligibility still via Agent Gateway.

**Agent Automations safety:**

- AI cannot create/activate/approve.  
- Explicit save after preview; `auto_execute_safe_writes=false`.  
- No auto-confirm destructive. Cross-site fail closed. Permission recheck each run; no admin fallback.  
- Schema allowlists; schedule limits; condition path allowlists; no arbitrary code / raw cron / nested DAG.  
- Approval tokens hashed; secrets redacted.

**Publishing (Business Hook):** `wordpress.article.publish` needs valid article + matching site/connection, WP sync permission, explicit `PublishIntent` ∈ {manual, scheduled, republish}, idempotency, lock. Event `article.publish_requested` alone is **not** enough.

## 10. Queue and scheduler ownership

Registered in `SeoContentAiServiceProvider` (distinct names, `withoutOverlapping`):

| # | Schedule | Target |
|---|----------|--------|
| 1 | `automation:dispatch-scheduled` everyMinute | Business Hook rules |
| 2 | `agent:automations:dispatch-due` everyMinute | Agent automations |
| 3 | CP automation policies (hourly job) | CP policy plans |

Also: `seo:publish-scheduled-articles` for scheduled publish (publishing module — not Agent Automation).

**Freeze:** Scheduler/Job for Agent Automations never call CommandBus or business services directly — only Runner → Agent paths.

## 11. Transactions and side effects

- Cross-DB: logical IDs only; no FK core ↔ `omi_seo_ai`; no assumed atomic multi-DB transaction.  
- Article local actions may persist content/SEO/media — **must not** enqueue WP sync or change remote status.  
- Only `wordpress.*` actions may HTTP outbound to WordPress.  
- Content Project task complete / local article write must not call `wordpress.article.publish` implicitly.  
- Domain service must not emit business events when invoked from Action path (Action Runtime cutover).  
- Loop guard + lock key `automation-policy:{policy_ref}:{period}` for CP policy.

## 12. Retry and recovery

- Business Hook: action/event execution records + rule heartbeat; use catalog retry semantics per action.  
- Agent Automations: failed run audited; re-dispatch creates new run; permission revalidated.  
- CP policy: `pause_on_*` flags; budget exceed → `budget.exceeded` + plan pause; `retry_plan_step` via plan tools.  
- Publish: lock + idempotency (article+revision) against double publish.

## 13. Compatibility paths

- `AutomationCallerMigrator` bridges legacy callers to Action dispatcher.  
- Legacy local services that sounded like “publish” but write local only keep service name; contract is local (see boundaries).  
- Keyword observer emits event + phrase propagate — link-list sync is Action `keyword.domain_link_list.sync`, not observer side effect.  
- Prompt-hook / workflow docs under archived `docs/automation/prompt/` — runtime map lives in `docs/modules/PROMPTS_AND_AI.md`.

## 14. Forbidden paths

- Workflow JSON referencing PHP classes/methods.  
- Action handlers depending on Filament Resource/Page/Livewire.  
- Mixing the three dispatch owners on one occurrence/table.  
- Agent Automation Job → CommandBus / domain service directly.  
- Agent skills Eloquent-mutating domain models.  
- Auto keyword web research / credential edits / policy self-escalation / infinite generate loops.  
- Publish without `auto_publish` + eligibility (CP) or without PublishIntent (Business Hook).  
- SEO Audit actions auto-fix SEO **and** publish WP in one step.  
- Using `ArticleEditorSyncOrchestrator` as synonym of `article.content.update`.

## 15. Tests and invariants

| Area | Tests |
|------|-------|
| Dispatcher ownership | `AutomationDispatcherOwnershipContractTest` |
| Action cutover | `AutomationActionCutoverArchitectureTest`, `AutomationPhase4BWiringTest` |
| Agent automation | `AgentAutomationTest`, `AgentAutomationSecurityTest`, `AgentAutomationApprovalTest`, `AgentAutomationConditionTest`, `AgentAutomationScheduleTest` |
| Architecture lock | `ArchitectureHardeningLockContractTest` |

**Invariants:** three owners; Action path for local production mutations; Agent Job → Runner only; CP hard gates always on; canonical IDs locked; WP outbound only via `wordpress.*`.

## 16. Related documents

- `docs/modules/AGENT_WORKSPACE.md`
- `docs/contracts/AGENT_AND_MCP_CONTRACTS.md`
- `docs/modules/CONTENT_PROJECTS.md`
- `docs/modules/PUBLISHING.md`
- `docs/modules/PROMPTS_AND_AI.md`
- `docs/contracts/QUEUE_SCHEDULER_AND_IDEMPOTENCY.md`
- `docs/operations/SCHEDULER_AND_WORKERS.md`
- `docs/architecture/ARCHITECTURE_FREEZE_V1.md`
