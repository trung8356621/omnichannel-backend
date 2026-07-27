# Content Project Agent Capabilities

Registry: `ContentProjectCapabilityRegistry`.

Agent/MCP **must** call Application Commands through this registry (or CommandBus).

Each capability declares: `name`, `description`, `input_schema`, `required_permission`, `allowed_lifecycle_phases`, `handler`, `confirmation_requirement`, `risk_level` (`read|write|publish|destructive`), `idempotency_support`, `dry_run_support`.

## Registered

create, update, sync_items, add_items, update_item, generate, rerun_items, start_review, approve, schedule, auto_schedule, unschedule, move_schedule, publish_now, retry_publish, skip_publish, cancel_publish, archive, restore.

## Not registered (internal)

- `content_project.process_scheduled_publish`
- `content_project.stop_execution` / `resume_execution`

## Confirmation

`api`/`agent`: dangerous ops need `dry_run` then `confirmation_token`.  
Filament `user`: UI auth may skip preview token.

## Forbidden

- `SeoProjectRun` / `startRun` internals
- WordPress client / WP Schedule
- Direct `publish_queue_status` model updates
- AI prompt/output in business audit
