# Content Project Automation Policy

## Levels

| Level | Behavior |
|-------|----------|
| `manual` | Plan only, no auto run |
| `assisted` | Safe steps; stop before important writes |
| `reviewed_automation` | May generate/review; **must confirm** approve/publish/archive/restore |
| `full_automation` | Allowed caps may auto-run — **hard gates vẫn bắt buộc** |

## Hard safety gates (không tắt bằng policy)

- Confirmation: `archive`, `restore`, `publish_now`, `cancel_publish`, `skip_publish`
- Không có `ignore_lifecycle` / `ignore_quota` / `ignore_tenant` / `force_publish` / `force_archive`
- Lifecycle, tenant, quota, lock, idempotency, processing gate, publish eligibility vẫn enforce qua Agent Gateway

## Policy entity

`seo_content_project_automation_policies`

Fields: allowed/blocked capabilities, auto_* flags, require_confirmation_for, budgets, retry, pause_on_*, publish windows, timezone.

Resolve: tenant + optional site. Agent **không** được sửa policy (`content-project:admin` only for management — CRUD UI phase sau).

Preview MCP: `content_project.get_agent_policy`

## Triggers (phase này)

Enabled: `manual`, `api`, `scheduled`

Registry có thể chứa event triggers nhưng **mặc định tắt**. Loop guard + lock `automation-policy:{policy_ref}:{period}`.

Job: `DispatchContentProjectAutomationPoliciesJob` (hourly).

## Budget

`ContentProjectAgentBudgetGuard` — daily actions/items/cost. Exceed → `budget.exceeded` + plan pause.

## Never auto

- Keyword web research / discovery ngoài capability
- Sửa prompt / credentials / policy self-escalation
- Infinite generate loops
- Publish khi policy chưa bật `auto_publish` + eligibility
- Archive hàng loạt chỉ vì “xong project”
