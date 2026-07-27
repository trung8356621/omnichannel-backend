# Content Project Operation Center

Admin-only observability for Content Project. **No business logic / API / Queue / Lifecycle / Capability changes.**

## URLs

| Path | Panel | Notes |
|------|-------|--------|
| `/seo/{connection_hash}/content-operations` | SEO (`omi_seo_ai`) | Primary dashboard |
| `/admin/content-operations` | Admin | Redirect / link to SEO page |

Access: `SeoAccessControl::canAccessContentOperations()` (manager+). Not for customers.

## Surfaces

1. **Global dashboard** — AI (waiting/running/failed/retry), Publishing, Archive (pending/success/failed), Queue worker heartbeat, metrics today.
2. **Command Bus monitor** — operation id, command, actor, duration, status, result code, request id; filters (project/command/actor/tenant/result); **Replay** via `ContentProjectOpsReplayService` → `ContentProjectCommandBus` only.
3. **AI cost** — aggregate tokens/cost from `prompt_results` (never prompt text); by model / site.
4. **Publish analytics** — success %, retry %, avg publish ms, timeout/connection/API breakdown; by site.
5. **Business timeline** — `ContentProjectTimelineService` (not prompt timeline).
6. **System health** — database, redis, cache, queue, worker, storage, WordPress, AI provider, automation, scheduler.
7. **Site health** — waiting / publishing / failed / last publish / last sync (WP reachable & token = placeholder `unknown` until live probes exist).
8. **Error center** — top `result_code` counts from operations log.
9. **Audit search** — business audits only; no prompt/output.
10. **WP adapter metrics** — latency / failure / retry from `seo_content_project_publish_attempts`.
11. **Daily report** — yesterday generated/approved/published/failed/cost/avg queue/avg publish (`ContentProjectDailyReportService`).

## Metrics (Prometheus-ready keys)

Defined in `ContentProjectMetricKeys`:

- `ai_generate_total`
- `publish_total`
- `publish_retry_total`
- `archive_total`
- `restore_total`
- `workspace_destroy_total`
- `queue_wait_seconds` (reserved)
- `publish_duration_ms` (reserved)

Counters persist to `seo_content_project_ops_metrics` via `ContentProjectOpsMetrics` (CommandBus logger side-effect; never breaks business path).

## Storage

Migration: `2026_07_27_130000_create_content_project_operations_tables.php`

- `seo_content_project_operations`
- `seo_content_project_ops_metrics`

Model: `ContentProjectOperation`.

## Key classes

| Class | Role |
|-------|------|
| `Filament\Pages\ContentProjectOperationsCenter` | SEO UI |
| `App\Filament\Pages\ContentOperationsRedirect` | Admin alias |
| `Operations\ContentProjectOpsDashboardService` | Snapshot |
| `Operations\ContentProjectCommandBusMonitorService` | Ops query |
| `Operations\ContentProjectOpsReplayService` | Replay via CommandBus |
| `Operations\ContentProjectAiCostAggregateService` | Cost rollup |
| `Operations\ContentProjectPublishAnalyticsService` | Publish stats |
| `Operations\ContentProjectWpAdapterMetricsService` | WP adapter stats |
| `Operations\ContentProjectErrorCenterService` | Top errors |
| `Operations\ContentProjectOpsHealthService` | Infra checks |
| `Operations\ContentProjectSiteHealthService` | Per-site |
| `Operations\ContentProjectAuditSearchService` | Audit search |
| `Operations\ContentProjectDailyReportService` | Daily report |
| `Application\Support\ContentProjectOperationLogger` | Persist + metrics |

## Replay rules

- Failed ops only
- Replayable commands only (publish / retry / generate / schedule / …)
- Requires `metadata.command_class` + `command_payload`
- Always re-dispatch through CommandBus with new idempotency `ui:{user}:replay:{operationId}`

## Agent readiness

Future Agent should:

- Call Capability Registry only
- Observe Operation Center / daily report for health
- Not read DB / Queue / Run / Runtime directly

## Agent Plans / Approvals

Operation Center tabs: Agent plans + Approvals.

Metrics: `agent_plan_*`, `agent_step_*`, `agent_approval_*`, `agent_replan_total`.

See [CONTENT_PROJECT_AGENT_PLANNER.md](CONTENT_PROJECT_AGENT_PLANNER.md).

## Related docs

- `CONTENT_PROJECT_APPLICATION_API.md`
- `CONTENT_PROJECT_COMMAND_BUS_CUTOVER.md`
- `AGENT_CAPABILITIES.md`
- `PUBLISHING_DELIVERY.md`
