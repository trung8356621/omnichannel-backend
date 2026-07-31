> Status: Historical
> Not canonical
> Archived on: 2026-08-01
> Superseded by: ../../modules/CONTENT_PROJECTS.md
> Purpose: implementation history only
# Content Project Agent Gateway

> **Batch E freeze:** see [`docs/architecture/CONTENT_PROJECT_BACKEND_FREEZE_V1.md`](architecture/CONTENT_PROJECT_BACKEND_FREEZE_V1.md) for the frozen entry-path contract, authoritative-class table, and forbidden bypasses; [`docs/architecture/CONTENT_PROJECT_CANONICAL_ARCHITECTURE.md`](architecture/CONTENT_PROJECT_CANONICAL_ARCHITECTURE.md) for the full flow/capability matrix.

## Architecture

```
MCP Client / Agent
      â†“
ContentProjectAgentMcpController  (/api/v1/agent/*)
      â†“
ContentProjectMcpServer / ContentProjectAgentGateway
      â†“
Capability Registry (+ schema/policy/quota/confirmation)
      â†“
ContentProjectCommandBus
      â†“
Handlers â†’ Domain
```

Gateway **khÃ´ng** chá»©a business logic. Chá»‰ Ä‘iá»u phá»‘i.

## Entry points

| Method | Path | Role |
|--------|------|------|
| GET/POST | `/api/v1/agent/mcp/tools` | List MCP tools |
| POST | `/api/v1/agent/mcp/call` | Call tool via Gateway |
| POST | `/api/v1/agent/execute` | Execute capability |
| POST | `/api/v1/agent/sessions` | Create agent session |
| POST | `/api/v1/agent/sessions/{ref}/touch` | Touch session TTL |

Auth: `auth:sanctum` + `SetDynamicSeoDatabase`. Token abilities `content-project:*`.

## execute()

`ContentProjectAgentGateway::execute(AgentExecutionContext, capability, input): AgentCapabilityResult`

1. Require `actor_type=agent`, `tenant_ref`, `site_ref` (opaque `cps_*`)
2. Rate limit
3. Session resolve (optional)
4. Read caps â†’ `ContentProjectAgentReadService`
5. Write caps â†’ registry schema â†’ policy scopes/safety â†’ confirmation â†’ CommandFactory â†’ CommandBus
6. Map `ContentProjectActionResult` â†’ `AgentCapabilityResult` (+ `operation_ref`)

## Context

`AgentExecutionContext`: actor_ref, tenant_ref, site_ref, session_ref, request_ref, idempotency_key, confirmation_token, dry_run, locale, timezone, scopes.

KhÃ´ng nháº­n numeric project/site ID. KhÃ´ng fallback global tenant.

## Result contract

`success`, `code`, `message`, `data`, `warnings`, `next_actions`, `meta` (`request_ref`, `operation_ref`, `idempotent_replay`, `requires_confirmation`).

Agent Ä‘á»c `code`, khÃ´ng parse `message`.

## Confirmation

Required: publish_now, archive, restore, cancel_publish, skip_publish.

Flow: dry_run / missing token â†’ preview + `confirmation_token` â†’ user confirm â†’ execute vá»›i token.

Archive preview **must** state workspace destroy (AI Workspace, Prompt History, Execution, local media, SaaS revisions).

## Sessions

Table `seo_content_project_agent_sessions` â€” compact metadata only (`last_project_ref`, `last_operation_ref`, `pending_confirmation_ref`). TTL + `seo:agent-sessions:cleanup`.

After archive: clear workspace context on session.

## Operation tracking

CommandBus gáº¯n `operation_id`/`operation_ref` vÃ o result metadata. Agent poll `content_project.get_operation` (rate-limited).

## Additive intelligence namespaces

| Namespace | Gateway | MCP catalog (Phase status) |
|-----------|---------|----------------------------|
| `keyword_intelligence.*` | READ list/get + write via registry | Read tools listed; writes via registry auto-include pattern in docs |
| `serp_intelligence.*` | READ list/get (+ writes registered) | Read tools listed |
| `gsc_intelligence.*` | READ list/get in `READ_CAPABILITIES`; writes registered on CommandBus | **Read tools only** in `ContentProjectMcpToolCatalog` |

GSC details: [GSC_INTELLIGENCE.md](GSC_INTELLIGENCE.md). SERP: [SERP_INTELLIGENCE.md](SERP_INTELLIGENCE.md).

## Related

- [CONTENT_PROJECT_MCP_TOOLS.md](CONTENT_PROJECT_MCP_TOOLS.md)
- [CONTENT_PROJECT_AGENT_SECURITY.md](CONTENT_PROJECT_AGENT_SECURITY.md)
- [CONTENT_PROJECT_AGENT_WORKFLOWS.md](CONTENT_PROJECT_AGENT_WORKFLOWS.md)
- [CONTENT_PROJECT_AGENT_PLANNER.md](CONTENT_PROJECT_AGENT_PLANNER.md)
- [CONTENT_PROJECT_AUTOMATION_POLICY.md](CONTENT_PROJECT_AUTOMATION_POLICY.md)
- [CONTENT_PROJECT_AGENT_APPROVALS.md](CONTENT_PROJECT_AGENT_APPROVALS.md)
- [CONTENT_PROJECT_AGENT_PLAN_LIFECYCLE.md](CONTENT_PROJECT_AGENT_PLAN_LIFECYCLE.md)
- [CONTENT_PROJECT_AGENT_CAPABILITIES.md](CONTENT_PROJECT_AGENT_CAPABILITIES.md)
- [CONTENT_PROJECT_OPERATIONS.md](CONTENT_PROJECT_OPERATIONS.md)
