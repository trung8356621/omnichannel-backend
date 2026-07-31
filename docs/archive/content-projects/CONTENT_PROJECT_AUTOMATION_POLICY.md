> Status: Historical
> Not canonical
> Archived on: 2026-08-01
> Superseded by: ../../modules/CONTENT_PROJECTS.md
> Purpose: implementation history only
# Content Project Automation Policy

## Levels

| Level | Behavior |
|-------|----------|
| `manual` | Plan only, no auto run |
| `assisted` | Safe steps; stop before important writes |
| `reviewed_automation` | May generate/review; **must confirm** approve/publish/archive/restore |
| `full_automation` | Allowed caps may auto-run â€” **hard gates váº«n báº¯t buá»™c** |

## Hard safety gates (khÃ´ng táº¯t báº±ng policy)

- Confirmation: `archive`, `restore`, `publish_now`, `cancel_publish`, `skip_publish`
- KhÃ´ng cÃ³ `ignore_lifecycle` / `ignore_quota` / `ignore_tenant` / `force_publish` / `force_archive`
- Lifecycle, tenant, quota, lock, idempotency, processing gate, publish eligibility váº«n enforce qua Agent Gateway

## Policy entity

`seo_content_project_automation_policies`

Fields: allowed/blocked capabilities, auto_* flags, require_confirmation_for, budgets, retry, pause_on_*, publish windows, timezone.

Resolve: tenant + optional site. Agent **khÃ´ng** Ä‘Æ°á»£c sá»­a policy (`content-project:admin` only for management â€” CRUD UI phase sau).

Preview MCP: `content_project.get_agent_policy`

## Triggers (phase nÃ y)

Enabled: `manual`, `api`, `scheduled`

Registry cÃ³ thá»ƒ chá»©a event triggers nhÆ°ng **máº·c Ä‘á»‹nh táº¯t**. Loop guard + lock `automation-policy:{policy_ref}:{period}`.

Job: `DispatchContentProjectAutomationPoliciesJob` (hourly).

## Budget

`ContentProjectAgentBudgetGuard` â€” daily actions/items/cost. Exceed â†’ `budget.exceeded` + plan pause.

## Never auto

- Keyword web research / discovery ngoÃ i capability
- Sá»­a prompt / credentials / policy self-escalation
- Infinite generate loops
- Publish khi policy chÆ°a báº­t `auto_publish` + eligibility
- Archive hÃ ng loáº¡t chá»‰ vÃ¬ â€œxong projectâ€
