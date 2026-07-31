> Status: Historical
> Not canonical
> Archived on: 2026-08-01
> Superseded by: ../../modules/CONTENT_PROJECTS.md
> Purpose: implementation history only
# Content Project Agent Plan Lifecycle

## Plan status

`draft` â†’ `awaiting_confirmation` â†’ `ready` â†’ `running` â†” `waiting_operation` / `waiting_condition` / `paused`

Terminal: `completed` | `partially_completed` | `failed` | `cancelled` | `expired`

## Step status

`pending` â†’ `ready` â†’ `running` â†’ `waiting_operation` | `waiting_confirmation` | `waiting_condition` â†’ `completed` | `skipped` | `failed` | `cancelled`

## Executor rules

- 1 job = 1 step / 1 transition
- Revalidate state trÆ°á»›c má»—i step (`ContentProjectAgentPlanRevalidator`)
- Manual override detected â†’ pause + review (khÃ´ng undo user)
- Partial failure theo policy: continue / pause / stop
- Retry transient only; backoff 60s / 5m / 15m / 1h; cÃ¹ng idempotency key

## Cancel

- KhÃ´ng rollback business Ä‘Ã£ xong
- Cancel pending steps + approvals
- KhÃ´ng stop publish processing trá»« capability cho phÃ©p
- KhÃ´ng Ä‘á»¥ng RunEngine

## Replan

Fields: `plan_version`, `replan_reason`, `previous_plan_ref`, `replan_count` (max tá»« config).

KhÃ´ng thÃªm capability ngoÃ i policy; khÃ´ng xÃ³a dáº¥u váº¿t step cÅ©; khÃ´ng Ä‘á»•i destructive step Ä‘Ã£ approve thÃ nh destructive khÃ¡c.

## Retention

Config `retention.plan_days` / `approval_days` (default ~60/30).

Command: `CleanupContentProjectAgentPlansCommand` (+ scheduler daily).

Giá»¯ compact audit/metrics; **khÃ´ng** xÃ³a Project/Article.

## E2E example

```
content_project.plan
  objective: "Táº¡o project 20 bÃ i, generate, review, schedule 2/ngÃ y"
  constraints.item_seed: [...]
â†’ confirm_plan
â†’ start_plan
â†’ create â†’ generate â†’ wait_operation â†’ start_review â†’ wait_operation
â†’ schedule preview â†’ approval (náº¿u policy) â†’ schedule execute
â†’ completed
```
