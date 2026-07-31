> Status: Historical
> Not canonical
> Archived on: 2026-08-01
> Superseded by: ../../modules/CONTENT_PROJECTS.md
> Purpose: implementation history only
# Content Project Agent Approvals

## Entity

`seo_content_project_agent_approvals` (`apv_*`)

Status: `pending` | `approved` | `rejected` | `expired` | `cancelled`

Bind: tenant, actor, plan, step, resolved input, **state fingerprint**. Token stale / reuse â†’ reject.

## Flow

1. Executor gáº·p confirmation / policy gate
2. `ContentProjectAgentApprovalService` táº¡o approval + compact preview
3. UI/MCP approve|reject
4. Approve â†’ resume step vá»›i confirmation (khÃ´ng reuse token cÅ© náº¿u state Ä‘á»•i)

## Archive preview

Pháº£i nÃªu rÃµ Destroy Workspace:

AI Workspace, Prompt History, Execution, local media, SaaS revisions.

## UI

Operation Center tabs: **Approvals** + **Agent plans** (`ContentProjectOperationsCenter`).

Actions: Approve, Reject, View plan, Pause/Resume/Cancel/Retry step â€” qua `ContentProjectAgentPlanApplicationService` / ApprovalService.

KhÃ´ng cÃ³ â€œApprove all future actionsâ€ trÃªn UI nÃ y (chá»‰nh Policy riÃªng).

## MCP

- `content_project.list_pending_approvals`
- `content_project.approve_agent_action`
- `content_project.reject_agent_action`

## Metrics

`agent_approval_requested_total`, `agent_approval_rejected_total`
