> Status: Historical
> Not canonical
> Archived on: 2026-08-01
> Superseded by: ../../modules/CONTENT_PROJECTS.md ; ../../contracts/QUEUE_SCHEDULER_AND_IDEMPOTENCY.md
> Purpose: implementation history only
# Content Project Command Bus Cutover

Single path:

```text
Filament / REST / Queue / Agent
        â†“
Capability hoáº·c Command
        â†“
ContentProjectCommandBus
        â†“
Handler
        â†“
Domain services
```

## Entry point â†’ Command

| Entry | Command |
|---|---|
| Filament Create project | `CreateContentProject` (+ tasks sync) |
| Filament Edit project | `UpdateContentProject` + `SyncContentProjectItems` |
| Filament Generate / Test run | `GenerateProjectItems` |
| Filament Archive | `ArchiveContentProject` |
| Filament Restore | `RestoreContentProject` |
| Publishing Queue UI schedule/publish/retry/skip/cancel/auto | Schedule* / PublishNow / Retry / Skip / Cancel / AutoSchedule |
| Article editor Publish Now (active CP) | `PublishProjectItemsNow` |
| Sync WP (active CP) | `ContentProjectWorkspaceSaveService` only â€” **no** publish command |
| Cron / Queue runner due items | `ProcessScheduledProjectItemPublish` (internal) |
| Run UI Force Stop | `StopProjectExecution` |
| API `/api/v1/content-projects*` | Same commands via Controller |

## Internal-only commands (khÃ´ng Capability / Agent)

- `ProcessScheduledProjectItemPublish`
- `StopProjectExecution`
- `ResumeProjectExecution`

## Services UI/API/Agent khÃ´ng Ä‘Æ°á»£c gá»i trá»±c tiáº¿p

- `SeoProjectWorkflowRunService::startRun` (qua Generate/Rerun handler)
- `ArchiveContentProjectService::archive/restore` (qua Archive/Restore handler)
- `ContentProjectPublishingQueueService` mutate tá»« Filament callback (qua Schedule/Publish handlers)
- `ContentPublisher` ngoÃ i Process/Publish handlers
- Direct `seo_project_tasks` stamp `scheduled_publish_at` / `publish_queue_status`

## Publishing status (canonical map)

Existing enum `ContentProjectPublishQueueStatus`:

| Enum | Canonical |
|---|---|
| `none` | unscheduled |
| `waiting` | scheduled / queued |
| `processing` | processing |
| `retrying` | retry_wait |
| `published` / `failed` / `skipped` / `cancelled` | same |

Transitions enforced by `ContentProjectPublishTransitionGuard`.

Blocked: `published â†’ retry/cancel`, `processing â†’ unschedule`.

## Lock TTL

| Key | TTL |
|---|---|
| `project:*:generate` | 600s |
| `project:*:archive` | 300s |
| `project:*:restore` | 180s |
| `project:*:schedule` | 180s |
| `item:*:publish` | 300s |

Owner token; only owner release; no forceRelease foreign lock.

## Idempotency keys

- Filament: `ui:{actor}:{action}:{project}:{token}`
- Queue: `queue:{job-uuid}:{action}:{item}`
- Scheduler: `scheduler:{item}:{scheduled_publish_at}`

## Confirmation token

Binds tenant, actor, action, project_ref, item_refs, input hash, state fingerprint, expires_at.  
Codes: `confirmation.invalid` / `expired` / `stale`.

## Legacy cÃ²n giá»¯

- `SeoProjectRun` + Run Engine / ViewSeoProjectRun diagnostic mutations (retry step, mark completed) â€” runtime infrastructure, khÃ´ng API.
- `SeoProjectTaskSyncService` â€” domain, chá»‰ gá»i tá»« Sync/Create handlers.
- Archive gate/summary reads váº«n gá»i `ArchiveContentProjectService` (read-only preview UI).

## Restore

`workspace_reused = false`. KhÃ´ng restore execution/prompt/media/revision.
