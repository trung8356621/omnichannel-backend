> Status: Historical
> Not canonical
> Archived on: 2026-08-01
> Superseded by: ../../modules/CONTENT_PROJECTS.md
> Purpose: implementation history only
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

Read token **khÃ´ng** Ä‘Æ°á»£c write. Token value **khÃ´ng** log.

## Policy (`ContentProjectAgentPolicy`)

- KhÃ´ng archive khi AI writing hoáº·c publishing processing
- KhÃ´ng publish item chÆ°a approved
- KhÃ´ng retry item Ä‘Ã£ published
- KhÃ´ng numeric ID trong refs
- KhÃ´ng Ä‘á»•i tenant/site ngoÃ i context
- KhÃ´ng detach article / xÃ³a Article / sá»­a WP credentials
- KhÃ´ng restore + generate cÃ¹ng má»™t call/plan
- Má»—i write = má»™t business intent

## Error codes (MCP mapping)

`agent.authentication_failed`, `agent.permission_denied`, `agent.invalid_input`, `agent.capability_not_found`, `agent.capability_not_allowed`, `agent.context_missing`, `agent.rate_limited`,

`confirmation.required|invalid|expired|stale`,

`lifecycle.invalid_transition`, `operation.locked`, `operation.already_processing`, `quota.exceeded`, `resource.not_found`, `tenant.access_denied`

KhÃ´ng tráº£ exception class / stack trace.

## Rate limits / budgets

Config: `seo-content-ai.content_project_agent` (`config/content_project_agent.php`).

- requests / minute
- create / hour
- archive / hour
- poll / operation (min seconds)
- max items per request

Codes: `agent.rate_limited`, `quota.exceeded`, `operation.too_large` (+ `retry_after` khi cÃ³).

## Observability

Gateway/CommandBus ghi Operation Center vá»›i `actor_type=agent`. Filter Actor=Agent trÃªn Command Bus monitor.

## Forbidden internals

Agent/MCP **khÃ´ng** Ä‘Æ°á»£c:

- Eloquent tÃ¹y Ã½ ngoÃ i Gateway/Read/Policy
- Gá»i Handler / domain service / WordPress API trá»±c tiáº¿p
- Biáº¿t `SeoProjectRun`, queue token, runtime lock
- Bypass lifecycle / tenant / quota / confirmation / idempotency
