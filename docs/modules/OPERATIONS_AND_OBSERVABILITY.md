# Operations and Observability

> Status: Canonical  
> Owner: SeoContentAi  
> Last verified: 2026-08-01  
> Supersedes: `docs/archive/content-projects/CONTENT_PROJECT_OPERATIONS.md` (dashboard/ops durable), `docs/archive/automation/AUTOMATION_SERVICE_INVENTORY.md` (ops-facing inventory notes only), `docs/archive/agent/AGENT_METRICS.md`, `AGENT_OBSERVABILITY.md`, `AGENT_TRACING.md` — **not** phase handoffs

## 1. Purpose

Durable ops surfaces for Content Project, Agent telemetry, logging channels, health, and daily report.  
**No** business logic ownership change: Observation ≠ CommandBus; metrics are side-channel.

Project workspace (`ViewSeoProject`): content production only — Draft / Pending / Needs Review / In Review / Failed. Schedule/Publish live on **Publishing Queue** page (`publishing_queued_at` handoff). Summary ≡ List via classifiers. Save/Sync never equals Publish. Queue health strip: runner last-run + Stuck Publishing count; recover via `content_project.recover_stuck_publishing`.

**Datetime observability**: user-facing timestamps format qua `SystemDateTime` (System timezone + preset). Machine/API/locks/TTL/queue compare = UTC. Settings: SEO Settings → Date & Time.

## 2. Canonical routes

| Path | Panel | Role |
|------|-------|------|
| `/seo/{connection_hash}/content-operations` | SEO | `ContentProjectOperationsCenter` — primary |
| `/admin/content-operations` | Admin | Redirect → SEO ops |
| Agent Workspace → Operations tab | SEO Agent | Health / metrics / traces entry |
| Slash | `/agent-health`, `/agent-metrics`, `/agent-trace`, … | Agent observability |

Access: `SeoAccessControl::canAccessContentOperations()` (manager+). Not customer-facing.

## 3. Main components

### Operation Center surfaces

1. Global dashboard — AI waiting/running/failed/retry; Publishing; Archive; queue heartbeat; metrics today.  
2. Command Bus monitor — operation id, command, actor, duration, status, result code; filters; **Replay** via `ContentProjectOpsReplayService` → CommandBus only.  
3. AI cost — tokens/cost from `prompt_results` (**never** prompt text).  
4. Publish analytics — success/retry %, latency breakdown.  
5. Business timeline — `ContentProjectTimelineService`.  
6. System health — DB, redis, cache, queue, worker, storage, WP, AI, automation, scheduler.  
7. Site health — waiting/publishing/failed/last publish/sync.  
8. Error center — top `result_code` counts.  
9. Audit search — business audits only.  
10. WP adapter metrics — `seo_content_project_publish_attempts`.  
11. Daily report — `ContentProjectDailyReportService`.  
12. Agent plans / Approvals tabs — plan/step/approval metrics.

### Key classes

| Class | Role |
|-------|------|
| `Filament/Pages/ContentProjectOperationsCenter` | SEO UI |
| `App\Filament\Pages\ContentOperationsRedirect` | Admin alias |
| `Operations/ContentProjectOpsDashboardService` | Snapshot |
| `Operations/ContentProjectCommandBusMonitorService` | Ops query |
| `Operations/ContentProjectOpsReplayService` | Replay |
| `Operations/ContentProjectAiCostAggregateService` | Cost |
| `Operations/ContentProjectPublishAnalyticsService` | Publish stats |
| `Operations/ContentProjectWpAdapterMetricsService` | WP adapter |
| `Operations/ContentProjectErrorCenterService` | Errors |
| `Operations/ContentProjectOpsHealthService` | Infra |
| `Operations/ContentProjectSiteHealthService` | Per-site |
| `Operations/ContentProjectAuditSearchService` | Audits |
| `Operations/ContentProjectDailyReportService` | Daily report |
| `Application/Support/ContentProjectOperationLogger` | Persist + metrics |
| `ContentProjectMetricKeys` / `ContentProjectOpsMetrics` | Counter keys |

### Agent observability

| Component | Role |
|-----------|------|
| `AgentTraceService` | `atrace_` / `aspan_` correlation |
| `AgentMetricRecorder` + `AgentMetricAggregator` | Allowlisted metrics + daily aggregates |
| `AgentObservabilityCatalog` | Span/metric allowlists |
| Jobs | `AggregateAgentMetricsJob`, retention/eval jobs as registered |

Decorators only — **no** CommandBus instrumentation.

### Logging

| Runtime | API | File |
|---------|-----|------|
| HTTP / Livewire / Filament / REST | `App\Support\RuntimeLogger` or `Log::channel('web_app')` | `storage/logs/web-app-YYYY-MM-DD.log` |
| CLI / cron / queue | `Log::` default / job channel | `laravel.log`, `queue-cron.log`, `watchdog.log` |

## 4. Data ownership

| Store | Connection | Notes |
|-------|------------|-------|
| `seo_content_project_operations` | `omi_seo_ai` | CommandBus op log |
| `seo_content_project_ops_metrics` | `omi_seo_ai` | Counters via `ContentProjectOpsMetrics` |
| `prompt_results` | `omi_seo_ai` | Cost rollup source (no text in UI aggregates) |
| Agent metric/trace tables | `omi_seo_ai` | Allowlisted keys; fail-open writes |
| Automation heartbeats / executions | core `mysql` | Business Hook health |
| Log files | filesystem | Ownership: cron/root vs PHP-FPM `www` |

## 5. Read path

- Ops UI services are **read-only** except Replay (which re-dispatches CommandBus).  
- Health checks diagnose; they do not start generate/publish.  
- Agent slash/ops tab → Trace/Metric services with cross-site timeline **fail-closed**.  
- Tail web errors: `storage/logs/web-app-$(date +%F).log`.

## 6. Write path

```text
CommandBus dispatch
  → always business audit + ContentProjectOperationLogger
  → ContentProjectOpsMetrics counters (never breaks business path)

Replay (failed + replayable only)
  → ContentProjectOpsReplayService
  → CommandBus with idempotency ui:{user}:replay:{operationId}

Agent execution (side channel)
  → AgentTraceService + AgentMetricRecorder
  → AggregateAgentMetricsJob (daily idempotent aggregates)
```

## 7. Public capabilities

- Manager+ view Operation Center.  
- Replay eligible failed commands (publish / retry / generate / schedule / …) when `metadata.command_class` + `command_payload` present.  
- Agent read-only health/metrics/trace slash (scoped).  
- Daily report yesterday: generated/approved/published/failed/cost/avg queue/avg publish.

## 8. Internal-only capabilities

| Item | Notes |
|------|-------|
| Metric key definitions | `ContentProjectMetricKeys`: `ai_generate_total`, `publish_total`, `publish_retry_total`, `archive_total`, `restore_total`, `workspace_destroy_total`, reserved wait/duration |
| Agent metric keys | Allowlist + dimension allowlist (reject high-cardinality) |
| SERP/GSC lock keys in monitor | Visible when those commands dispatch — ownership stays SERP/GSC modules |
| Automation inventory naming traps | Local “publish” ≠ WP (see AUTOMATION module) |

## 9. Authorization and confirmation

- Ops Center: manager+.  
- Replay: same CommandBus auth/tenant as original actor context reconstruction rules.  
- Agent traces: no CoT/secrets; cross-site fail-closed.  
- Audit search: business audits only — never prompt/output dump.

## 10. Queue and scheduler ownership

| Concern | Owner |
|---------|-------|
| Op log / metrics persist | Sync side-effect of CommandBus |
| Agent metric aggregate | Scheduled/queued `AggregateAgentMetricsJob` |
| Worker heartbeat display | Ops health reads queue heartbeat — workers owned by SCHEDULER_AND_WORKERS |
| Automation heartbeat | Business Hook tables on core |

See `docs/operations/SCHEDULER_AND_WORKERS.md`.

## 11. Transactions and side effects

- Metrics/log writes fail-open relative to business success.  
- Replay always new idempotency key — not mutate old op row as success.  
- Logging on HTTP must not touch root-owned `laravel.log` (Permission denied).

## 12. Retry and recovery

- Replay for failed replayable ops only.  
- Stuck CP runs: engine status/health + resume/recover CLI (QUEUE contract) — not Ops metrics alone.  
- Agent metric write errors: fail-open; aggregates remain idempotent daily.

## 13. Compatibility paths

- Admin `/admin/content-operations` redirect.  
- Site Sync / Agent Automations appear as health tiles — deep semantics in their modules.  
- Archive phase ops docs are historical.

## 14. Forbidden paths

- Treat Operation Center as second CommandBus or mutate domain outside Replay.  
- Instrument CommandBus handlers with Agent metrics as business dependency.  
- `Log::` / `logger()` / `report()` on HTTP paths to default `laravel.log`.  
- Set `LOG_CHANNEL=web_app` globally in `.env` (breaks cron).  
- Fallback `web_app` → `laravel.log` on write failure.  
- `chown`/delete/rename root `laravel.log` as “fix”.  
- Show full prompts/outputs in cost or audit UIs.  
- Use PHASE handoff docs as SoT.

## 15. Tests and invariants

| Area | Test |
|-------|------|
| Ops Center + Site Sync tab | `ContentProjectOperationsCenterTest` |
| RuntimeLogger channel | `RuntimeLoggerWebAppChannelTest` |
| Architecture lock | `ArchitectureHardeningLockContractTest` |

**Invariants:** observe ≠ mutate (except Replay→Bus); RuntimeLogger on HTTP; allowlisted metrics; daily report durable.

## 16. Related documents

- `docs/modules/CONTENT_PROJECTS.md`
- `docs/modules/AGENT_WORKSPACE.md`
- `docs/modules/AUTOMATION.md`
- `docs/contracts/QUEUE_SCHEDULER_AND_IDEMPOTENCY.md`
- `docs/operations/SCHEDULER_AND_WORKERS.md`
- `docs/operations/TROUBLESHOOTING.md`
- `docs/operations/DEPLOYMENT.md`
- `docs/operations/TESTING.md`

