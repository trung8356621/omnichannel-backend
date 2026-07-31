# Agent and MCP Contracts

> Status: Canonical  
> Owner: SeoContentAi  
> Last verified: 2026-08-01  
> Supersedes: `docs/archive/content-projects/CONTENT_PROJECT_AGENT_GATEWAY.md`, `docs/archive/content-projects/CONTENT_PROJECT_MCP_TOOLS.md`, `docs/archive/content-projects/CONTENT_PROJECT_AGENT_CAPABILITIES.md`, `docs/archive/content-projects/CONTENT_PROJECT_AGENT_SECURITY.md`, `docs/archive/content-projects/CONTENT_PROJECT_AGENT_APPROVALS.md`, `docs/archive/content-projects/CONTENT_PROJECT_AGENT_PLANNER.md`, `docs/archive/content-projects/CONTENT_PROJECT_AGENT_PLAN_LIFECYCLE.md`, `docs/archive/content-projects/CONTENT_PROJECT_AGENT_WORKFLOWS.md`, `docs/archive/agent/AGENT_CONFIRMATION.md`, `docs/archive/agent/AGENT_SLASH_COMMANDS.md` (contract slices)

Module UX/runtime: `docs/modules/AGENT_WORKSPACE.md`. Automation owners: `docs/modules/AUTOMATION.md`.

---

## 1. Gateway stack

```
Transport (Filament Agent UI | MCP HTTP | Agent execute API)
  → AgentGateway                          (facade; no duplicate logic)
  → ContentProjectAgentGateway            (orchestration only)
      → CanonicalCapabilityRegistry
      → ContentProjectAgentPolicy / SchemaValidator / RateLimiter / Session
      → ContentProjectPreviewToken (confirmation)
      → ContentProjectAgentCommandFactory
      → ContentProjectCommandBus → Handler
```

**Rules**

- Gateway contains **no** business logic.  
- Writes always go CommandBus after registry/policy/confirmation.  
- Reads use `ContentProjectAgentGateway::READ_CAPABILITIES` + dedicated ReadServices.  
- `AgentWorkspaceApplicationService` must not import/inject `ContentProjectCommandBus`.

### HTTP entry

| Method | Path | Behavior |
|--------|------|----------|
| GET/POST | `/api/v1/agent/mcp/tools` | Catalog from `ContentProjectMcpToolCatalog` |
| POST | `/api/v1/agent/mcp/call` | Tool call via Gateway / plan gateway |
| POST | `/api/v1/agent/execute` | Capability execute via Gateway |

Auth: Sanctum. Read token cannot write. Token values never logged.

### execute() contract

`ContentProjectAgentGateway::execute(AgentExecutionContext $context, string $capability, array $input): AgentCapabilityResult`

**Context fields:** `actor_ref`, `tenant_ref`, `site_ref`, `session_ref`, `request_ref`, `idempotency_key`, `confirmation_token`, `dry_run`, `locale`, `timezone`, `scopes`.

**Result:** `success`, `code`, `message`, `data`, `warnings`, `next_actions`, `meta` (`request_ref`, `operation_ref`, `idempotent_replay`, `requires_confirmation`).

---

## 2. Capability registry boundaries

### CanonicalCapabilityRegistry

Authority for Agent/MCP exposure:

- Merges core `ContentProjectCapabilityRegistry` + enabled extension contributions.  
- Core names (`content_project.*` or core-owned) **cannot** be overridden by extensions.  
- Name collisions (extension↔extension or extension↔core) are **excluded** and recorded in `conflicts()`.  
- Disabled extensions contribute nothing.  
- `isAgentWriteExposed` / `isMcpWriteExposed` gate surfaces (MCP ⊆ Agent).

### Capability metadata (each cap)

`name`, `description`, `input_schema`, `required_permission`, `allowed_lifecycle_phases`, `handler`, `confirmation_requirement`, `risk_level` (`read|write|publish|destructive`), `idempotency_support`, `dry_run_support`, optional `mcp_exposed`.

### Public refs

Agent/MCP I/O uses public refs only (`project_ref`, `item_ref`, `site_ref`, …). Raw numeric `project_id` / internal run IDs are forbidden in public responses and as Agent inputs.

---

## 3. MCP tool catalog ownership

**Owner class:** `ContentProjectMcpToolCatalog`  
**Built from:** `CanonicalCapabilityRegistry` write caps where `isMcpWriteExposed` + hardcoded read schemas.  
**Schemas:** single source — `ContentProjectCapabilityRegistry::buildJsonSchema()` / registry jsonSchema. Do not duplicate divergent REST schemas.

### Read tools (summary)

- CP: `list_projects`, `get_project`, `list_items`, `get_item`, `get_status`, `get_publishing_queue`, `get_timeline`, `get_daily_report`, `get_site_health`, `get_operation`  
- Keyword Intelligence: list/get workspace, keywords, clusters, topical map, topics, conflicts, link suggestions, map versions, conversion, cannibalization, analysis operation  
- SERP Intelligence: list/get queries, snapshots, results, features, cluster evidence, content gaps, competitors, operation  
- GSC Intelligence: list/get properties, sync runs, mappings, aggregates, opportunities, operation  
- SEO Audit: `seo_audit.list`

### Write tools (CP core, MCP-exposed)

`create`, `update`, `add_items`, `update_item`, `generate`, `rerun_items` (alias of registry `rerun`), `start_review`, `approve`, schedule family (`schedule`, `auto_schedule`, `unschedule`, `move_schedule`), publish family (`publish_now`, `retry_publish`, `skip_publish`, `cancel_publish`), `archive`, `archive_items`, `restore`.

Keyword Intelligence writes may be MCP-exposed when registry marks them; **SERP/GSC writes are Agent-only** (not MCP catalog).

### Plan / automation MCP tools

Routed via `ContentProjectAgentPlanGateway` (**not** CommandBus). Planner may emit `wait_operation` steps; execution still requires Agent Gateway confirmation for writes.

Routed tools:  
`plan`, `confirm_plan`, `start_plan`, `pause_plan`, `resume_plan`, `cancel_plan`, `retry_plan_step`, `get_agent_plan`, `list_agent_plans`, `get_agent_policy`, `list_pending_approvals`, `approve_agent_action`, `reject_agent_action`.

### Not exposed on MCP

| Name / class | Reason |
|--------------|--------|
| `content_project.sync_items` | Internal sync |
| `content_project.process_scheduled_publish` | Scheduler |
| `content_project.stop_execution` / `resume_execution` | Agent-only mid-flight control |
| SERP/GSC write caps | Agent/CommandBus only |
| Run/runtime/queue token/lock/raw prompt | Internal |
| SQL / update_model / call_service / run_command | Never |

---

## 4. Confirmation and dry_run

### Gateway preview token

1. Write cap with `confirmation_requirement` and missing token (and not satisfied dry_run path) → preview + `confirmation_token` + `requires_confirmation`.  
2. Client resubmits with token; Gateway validates + consumes one-time token.  
3. Filament `user` path may use UI auth for some flows; API/Agent dangerous ops need dry_run then token.

Dangerous ops always confirmable: archive, restore, publish_now, cancel_publish, skip_publish, archive_items (and any cap marked confirm).

### Agent Workspace token (`awconf_`)

- Issued by `AgentConfirmationTokenService` after preview when policy ∈ {`preview`,`confirm`}.  
- Bound to actor/site/conversation/execution/skill/capability/`input_hash`.  
- DB stores **`confirmation_token_hash` only**; raw token never logged.  
- Confirm request: `execution_ref` + plaintext token; server reloads canonical input.  
- UI: confirmation card Yes/No/Edit; `execution_preview` alone does not show Yes for confirm-required writes.  
- **No auto-confirm. AI cannot confirm.**

Reject: expired, already_used, actor/site/conversation/input mismatch, stale, terminal execution.

### dry_run

Supported where capability declares `dry_run_support`. Dry run must not mutate domain state; used to produce preview + token for confirmable writes.

---

## 5. Slash vs natural language

Intent order (`AgentIntentRouter`):

1. Exact slash command  
2. Slash alias  
3. Chat template (`skill_key`)  
4. Deterministic NL / legacy adapter / multi-intent  
5. Structured `ai_intent` (low-confidence guard)  
6. General assistant → planning (propose only)

**Contracts**

- Slash **always wins** over AI.  
- Free text must **not** auto-execute write capabilities.  
- Slash conflict at boot: fail closed (`agent.skill_command_conflict` / `agent.slash_command_conflict`).  
- Palette is curated CLI (`AgentCliCommandCatalog` + FE mirror) — not a dump of `CanonicalCapabilityRegistry`.  
- Meta slash (help/status) may avoid Gateway business writes.

---

## 6. Idempotency tokens

| Layer | Key shape | Rule |
|-------|-----------|------|
| Agent execution | `awex:…` (`AgentExecutionIdempotencyFactory`) | Server-issued; retry = new execution/attempt |
| Gateway / CommandBus | `idempotency_key` on context/input | Handlers honor; meta may set `idempotent_replay` |
| MCP call | Client may pass `idempotency_key` (e.g. generate) | Same key + same actor/scope = safe replay |
| Confirmation | One-time preview token | Consume on success; replay without new preview fails |
| Publish | Article + revision lock | Prevents double publish |

Missing tenant/site context → fail closed (no default site invent).

---

## 7. Auth, scopes, errors

**Sanctum abilities (examples):** `content-project:read`, `content-project:generate`, approve/publish/archive scopes as registered. Wildcard UI scopes do not bypass Gateway enforcement.

**Error code families:**  
`agent.authentication_failed|permission_denied|invalid_input|capability_not_found|capability_not_allowed|context_missing|rate_limited`,  
`confirmation.required|invalid|expired|stale`,  
`quota.exceeded`, `operation.too_large` (+ `retry_after` when present).

**Forbidden internals (never expose):** `SeoProjectRun` / startRun internals, queue tokens, runtime locks, WordPress client from Agent, direct `publish_queue_status` model updates, bypass lifecycle/tenant/quota/confirmation/idempotency.

---

## 8. Related documents

- `docs/modules/AGENT_WORKSPACE.md`  
- `docs/modules/AUTOMATION.md`  
- `docs/modules/CONTENT_PROJECTS.md`  
- `docs/modules/EXTENSION_SDK.md`  
- `docs/contracts/EXTENSION_AND_REGISTRY_CONTRACTS.md`  
- `docs/contracts/QUEUE_SCHEDULER_AND_IDEMPOTENCY.md`  
- `docs/contracts/API_AND_AUTHORIZATION.md`  
- `docs/architecture/ARCHITECTURE_FREEZE_V1.md`  
- `docs/architecture/ARCHITECTURE_DECISIONS.md`
