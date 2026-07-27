# Content Project Agent Gateway

## Architecture

```
MCP Client / Agent
      ↓
ContentProjectAgentMcpController  (/api/v1/agent/*)
      ↓
ContentProjectMcpServer / ContentProjectAgentGateway
      ↓
Capability Registry (+ schema/policy/quota/confirmation)
      ↓
ContentProjectCommandBus
      ↓
Handlers → Domain
```

Gateway **không** chứa business logic. Chỉ điều phối.

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
4. Read caps → `ContentProjectAgentReadService`
5. Write caps → registry schema → policy scopes/safety → confirmation → CommandFactory → CommandBus
6. Map `ContentProjectActionResult` → `AgentCapabilityResult` (+ `operation_ref`)

## Context

`AgentExecutionContext`: actor_ref, tenant_ref, site_ref, session_ref, request_ref, idempotency_key, confirmation_token, dry_run, locale, timezone, scopes.

Không nhận numeric project/site ID. Không fallback global tenant.

## Result contract

`success`, `code`, `message`, `data`, `warnings`, `next_actions`, `meta` (`request_ref`, `operation_ref`, `idempotent_replay`, `requires_confirmation`).

Agent đọc `code`, không parse `message`.

## Confirmation

Required: publish_now, archive, restore, cancel_publish, skip_publish.

Flow: dry_run / missing token → preview + `confirmation_token` → user confirm → execute với token.

Archive preview **must** state workspace destroy (AI Workspace, Prompt History, Execution, local media, SaaS revisions).

## Sessions

Table `seo_content_project_agent_sessions` — compact metadata only (`last_project_ref`, `last_operation_ref`, `pending_confirmation_ref`). TTL + `seo:agent-sessions:cleanup`.

After archive: clear workspace context on session.

## Operation tracking

CommandBus gắn `operation_id`/`operation_ref` vào result metadata. Agent poll `content_project.get_operation` (rate-limited).

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
