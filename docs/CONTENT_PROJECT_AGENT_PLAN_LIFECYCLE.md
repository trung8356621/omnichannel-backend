# Content Project Agent Plan Lifecycle

## Plan status

`draft` → `awaiting_confirmation` → `ready` → `running` ↔ `waiting_operation` / `waiting_condition` / `paused`

Terminal: `completed` | `partially_completed` | `failed` | `cancelled` | `expired`

## Step status

`pending` → `ready` → `running` → `waiting_operation` | `waiting_confirmation` | `waiting_condition` → `completed` | `skipped` | `failed` | `cancelled`

## Executor rules

- 1 job = 1 step / 1 transition
- Revalidate state trước mỗi step (`ContentProjectAgentPlanRevalidator`)
- Manual override detected → pause + review (không undo user)
- Partial failure theo policy: continue / pause / stop
- Retry transient only; backoff 60s / 5m / 15m / 1h; cùng idempotency key

## Cancel

- Không rollback business đã xong
- Cancel pending steps + approvals
- Không stop publish processing trừ capability cho phép
- Không đụng RunEngine

## Replan

Fields: `plan_version`, `replan_reason`, `previous_plan_ref`, `replan_count` (max từ config).

Không thêm capability ngoài policy; không xóa dấu vết step cũ; không đổi destructive step đã approve thành destructive khác.

## Retention

Config `retention.plan_days` / `approval_days` (default ~60/30).

Command: `CleanupContentProjectAgentPlansCommand` (+ scheduler daily).

Giữ compact audit/metrics; **không** xóa Project/Article.

## E2E example

```
content_project.plan
  objective: "Tạo project 20 bài, generate, review, schedule 2/ngày"
  constraints.item_seed: [...]
→ confirm_plan
→ start_plan
→ create → generate → wait_operation → start_review → wait_operation
→ schedule preview → approval (nếu policy) → schedule execute
→ completed
```
