# Content Project Agent Security

## Auth scopes (Sanctum abilities)

| Scope | Caps |
|-------|------|
| `content-project:read` | list/get_* |
| `content-project:write` | create/update/add_items/update_item/restore |
| `content-project:generate` | generate/rerun_items |
| `content-project:review` | start_review/approve |
| `content-project:schedule` | schedule/auto_schedule/unschedule/move_schedule |
| `content-project:publish` | publish_now/retry/skip/cancel |
| `content-project:archive` | archive |
| `content-project:admin` | all |

Read token **không** được write. Token value **không** log.

## Policy (`ContentProjectAgentPolicy`)

- Không archive khi AI writing hoặc publishing processing
- Không publish item chưa approved
- Không retry item đã published
- Không numeric ID trong refs
- Không đổi tenant/site ngoài context
- Không detach article / xóa Article / sửa WP credentials
- Không restore + generate cùng một call/plan
- Mỗi write = một business intent

## Error codes (MCP mapping)

`agent.authentication_failed`, `agent.permission_denied`, `agent.invalid_input`, `agent.capability_not_found`, `agent.capability_not_allowed`, `agent.context_missing`, `agent.rate_limited`,

`confirmation.required|invalid|expired|stale`,

`lifecycle.invalid_transition`, `operation.locked`, `operation.already_processing`, `quota.exceeded`, `resource.not_found`, `tenant.access_denied`

Không trả exception class / stack trace.

## Rate limits / budgets

Config: `seo-content-ai.content_project_agent` (`config/content_project_agent.php`).

- requests / minute
- create / hour
- archive / hour
- poll / operation (min seconds)
- max items per request

Codes: `agent.rate_limited`, `quota.exceeded`, `operation.too_large` (+ `retry_after` khi có).

## Observability

Gateway/CommandBus ghi Operation Center với `actor_type=agent`. Filter Actor=Agent trên Command Bus monitor.

## Forbidden internals

Agent/MCP **không** được:

- Eloquent tùy ý ngoài Gateway/Read/Policy
- Gọi Handler / domain service / WordPress API trực tiếp
- Biết `SeoProjectRun`, queue token, runtime lock
- Bypass lifecycle / tenant / quota / confirmation / idempotency
