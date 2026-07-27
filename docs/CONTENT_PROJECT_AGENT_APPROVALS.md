# Content Project Agent Approvals

## Entity

`seo_content_project_agent_approvals` (`apv_*`)

Status: `pending` | `approved` | `rejected` | `expired` | `cancelled`

Bind: tenant, actor, plan, step, resolved input, **state fingerprint**. Token stale / reuse → reject.

## Flow

1. Executor gặp confirmation / policy gate
2. `ContentProjectAgentApprovalService` tạo approval + compact preview
3. UI/MCP approve|reject
4. Approve → resume step với confirmation (không reuse token cũ nếu state đổi)

## Archive preview

Phải nêu rõ Destroy Workspace:

AI Workspace, Prompt History, Execution, local media, SaaS revisions.

## UI

Operation Center tabs: **Approvals** + **Agent plans** (`ContentProjectOperationsCenter`).

Actions: Approve, Reject, View plan, Pause/Resume/Cancel/Retry step — qua `ContentProjectAgentPlanApplicationService` / ApprovalService.

Không có “Approve all future actions” trên UI này (chỉnh Policy riêng).

## MCP

- `content_project.list_pending_approvals`
- `content_project.approve_agent_action`
- `content_project.reject_agent_action`

## Metrics

`agent_approval_requested_total`, `agent_approval_rejected_total`
