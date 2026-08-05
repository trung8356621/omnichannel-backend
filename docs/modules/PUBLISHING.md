# Publishing

> Status: Canonical  
> Owner: SeoContentAi  
> Last verified: 2026-08-02  
> Supersedes: `docs/archive/content-projects/CONTENT_PROJECT_PUBLISHING_DELIVERY.md`, publish sections of `CONTENT_PROJECT_CANONICAL_ARCHITECTURE.md` / `CONTENT_PROJECT_COMMAND_BUS_CUTOVER.md`, publish routes in `docs/MAP_SEO_PROJECTS.md`

## 1. Purpose

**Publishing Queue** owns schedule distribution and WordPress publication.

**Content Project** owns content production only (Draft / Pending / Needs Review / In Review / Failed generation). CP does **not** show Scheduled/Published as active workflow cards.

Handoff: `content_project.send_to_publishing_queue` stamps `seo_project_tasks.publishing_queued_at` (+ `publishing_queued_by`) → Unscheduled. No WordPress. No auto schedule. Return via `content_project.return_to_content_project` before Published (not archive).

## 2. Canonical routes

| Surface | Path / entry |
|---------|----------------|
| Filament Publishing Queue | Hub `/publishing-queue` (`PublishingQueueHub`, shared CP ops UI components); nested `/content-projects/{id}/publishing-queue` redirects to hub |
| Content Project ops | production working set only (`publishing_queued_at` IS NULL) |
| Cron | `seo:publish-scheduled-articles` |
| Agent/MCP | `send_to_publishing_queue`, `schedule`, `auto_schedule` (modes `project_month` / `quick`), `unschedule`, `publish_now`, `retry_publish`, `return_to_content_project` |

## 3. Publishing Queue states

| State | Meaning |
|-------|---------|
| Unscheduled | Handed off; no `scheduled_publish_at` |
| Scheduled | Có `scheduled_publish_at` (future **hoặc** due chưa claim); execution `none` / `waiting` / `retrying` — **chưa** Publishing |
| Publishing | `publish_queue_status=processing` sau khi runner claim **và** publisher dispatch thành công (có `last_publish_attempt_at`) |
| Published | WP publisher xác nhận thành công (`publish_published_at` / queue published) |
| Failed | Queue failed; retry/reschedule/cancel |

**Không** chuyển Publishing chỉ vì schedule đã đến hạn, Save/Sync, Auto/Quick, hoặc `scheduled_publish_at` quá khứ.

`ContentProjectPublishingQueueService::schedule`: luôn stamp execution **none** (plan). Waiting chỉ từ Publish Now / retry enqueue. Runner claim + publisher accept mới → processing.

Stuck Publishing (`PublishingQueueStuckPublishingDefinition`): processing thiếu attempt, quá TTL (30 phút), **hoặc** `scheduled_publish_at` đã lố ≥ `PAST_DUE_GRACE_MINUTES` (5) → `content_project.recover_stuck_publishing` (scheduled / unscheduled / failed). Retry/Publish now **bị chặn** khi queue processing (`Publish queue is active`) — dùng Recover, không Cancel thường (`processing → cancelled` vẫn invalid).

**Connection bootstrap (cron):** `seo:publish-scheduled-articles` → `ScheduledArticlePublishRunner` reuse `SeoDatabaseConnectionService::bootstrapAndVerifyFromConnection()`. Candidate filter: `PublishingConnectionCandidateResolver` (active, non-orphan, skip demo/manual without users). **Per-connection try/catch continue** — một connection lỗi không abort command. Health cache **scoped theo connection_id** (`ContentProjectQueueHealthService`); UI Publishing Queue bind `SeoConnectionContext` — lỗi hash A không sơn unhealthy hash B. Audit/cleanup: `seo:audit-seo-database-connections` (`--disable-orphans`). Schema probe sau PDO verify. Auth fail log `publishing.connection_bootstrap_failed`.

## 4. Auto / Quick Mode

Cả hai là **project-level** trên Publishing Queue — **không cần** checkbox selection.

- **Date/time SoT**: System timezone từ Settings → Date & Time (`SeoDateTimeSettingsService` / `SystemDateTime`). **Không** lấy timezone WordPress. Storage + runner = UTC.
- **Auto (`project_month`)**: phân bố eligible items trong tháng dự án; UI/parse qua `SystemDateTime`; DB lưu UTC.
- **Quick Mode**:
  - `in_day`: interval 5/10/15/20/30/45/60 (+ custom ≥5); safety delay 5 phút; `slot[n] = slot[0] + n × interval`. Preview + Apply dùng cùng `PublishingSchedulePlan` (`item_schedule_map` mỗi task một UTC ISO). Persist qua `ContentProjectPublishingQueueService::schedulePlan` — **không** bulk-set cùng timestamp.
  - `quick` / N days (1/2/3/5/7): phân bố đều + min interval.
- Eligibility: Unscheduled (+ Scheduled khi allow reschedule). **Exclude** Publishing + Published. Không cancel Publishing.
- Preview: eligible/excluded count, first/last publish, timezone chip. Không success toast.
- **Pending UX** (presentation only): `pendingTaskIds` / `pendingPhase` trên Livewire — không thêm DB enum `updating`. Publish Now: command accepted → phase `accepted` / state Publishing; **Published** chỉ khi publisher WP xác nhận.

## 4b. System Date & Time

| Concern | Owner |
|---------|--------|
| Settings UI | `/seo/.../settings/date-time` (`SeoSettingsDateTime`) |
| Store | `wp_options.seo_datetime_settings` via `SeoDateTimeSettingsService` |
| PHP formatter | `SystemDateTime` (`SeoDisplayTimezone` = BC facade) |
| JS formatter | `resources/js/utils/systemDateTime.js` + `window.__SYSTEM_DATETIME__` |
| Machine timestamps | UTC ISO-8601 |
| User-visible | System timezone + preset `vi` \| `en` |

## 5. Select All

Publishing Queue: header checkbox (trang hiện tại) → banner “Chọn toàn bộ {filtered_total}” → `selectAllMatchingResults`. Clear khi đổi project/filter/search.

## 5. Main components

| Role | Class |
|------|--------|
| Queue mutate API | `ContentProjectPublishingQueueService` (`acceptHandoff`, `schedule`, `returnToContentProject`, …) |
| Due dispatcher | `ContentProjectPublishingQueueRunner::dispatchDue()` |
| Auto / Quick | `ContentProjectAutoScheduleService` + `PublishingSchedulePlan` |
| Read model | `ContentProjectPublishingQueueReadModel` + `PublishingQueueStateClassifier` |
| Date/time | `SystemDateTime`, `SeoDateTimeSettingsService` |
| Handoff eligibility | `PublishingQueueHandoffEligibility` |

Operations center may show queue health; it does not own a second dispatcher.

## 3. Main components

| Role | Class |
|------|--------|
| Queue mutate API (domain) | `ContentProjectPublishingQueueService` |
| Transition rules | `ContentProjectPublishTransitionGuard` |
| Due dispatcher | `ContentProjectPublishingQueueRunner::dispatchDue()` |
| Scheduler shell | `PublishScheduledArticlesCommand` → `ScheduledArticlePublishRunner` |
| Process one due item | `ProcessScheduledProjectItemPublishHandler` |
| Resolve publisher | `PublisherResolver` + `ContentPublisherRegistry` |
| WP implementation | `Extension/Builtin/Wordpress/WordPressPublisher` (`ContentPublisher`) |
| SDK adapter | `WordpressPublisherDriver` |
| Auto schedule | `ContentProjectAutoScheduleService` |
| Queue health | `ContentProjectQueueHealthService` |
| Attempt / external ref | `seo_content_project_publish_attempts` (`external_reference = omi_seo_article_{id}`) |
| Item lock | `ContentProjectBusinessLock::itemPublish` (TTL 300s) |

## 4. Data ownership

| Field | Owner |
|-------|--------|
| `scheduled_publish_at` | SaaS / CP schedule commands |
| `publish_queue_status` | Queue service via CommandBus handlers |
| `publish_published_at` | Process/publish handlers after successful reconcile |
| `articles.wp_post_id` | Publisher success path (sticky identity) |
| WP post body/meta at publish time | `WordPressPublisher` → `WordPressArticleSyncService` |

When `ARTICLE_EDITOR_JSON_PUBLISH_FROM_JSON=true`, sync prepares `post_content` via `ArticleEditorDocumentWriter::ensureDerivedBodyForPublish` (re-render from `editor_document`, refresh stale `body`). Default off — see [`ARTICLE_EDITOR_JSON_PERSISTENCE.md`](../architecture/ARTICLE_EDITOR_JSON_PERSISTENCE.md).

**Queue status enum** `ContentProjectPublishQueueStatus`:

| Value | Meaning |
|-------|---------|
| `none` | Unscheduled |
| `waiting` | Scheduled / queued |
| `processing` | In-flight publish |
| `retrying` | Retry wait |
| `published` / `failed` / `skipped` / `cancelled` | Terminal (or terminal-ish) |

Active queue: `waiting`, `processing`, `retrying` (`isActiveQueue()`).

Resolved **item publish state** for UI: `ContentProjectItemPublishState` via `ContentProjectItemStateResolver` (not raw status alone).

## 5. Read path

- Ops table / KPI: resolver → `publishState` + lifecycle `WaitingPublish` / `Published`.
- Queue health services read task queue columns + attempt rows.
- Agent read tools expose schedule/queue fields through Agent read service (resolver-backed lifecycle).

## 6. Write path

### User / Agent schedule & control

```text
Schedule* / PublishNow / Retry / Skip / Cancel
  → ContentProjectCommandBus
  → Handler (+ ActionGuard / confirmation)
  → ContentProjectPublishingQueueService (+ TransitionGuard)
  → stamps scheduled_publish_at / publish_queue_status
```

`Publish Now` enqueues for immediate processing (does not stamp a fake future schedule to skip the runner).

### Scheduler delivery (canonical — one dispatcher)

```text
Hosting cron
  → php artisan schedule:run
    → seo:publish-scheduled-articles
      → ScheduledArticlePublishRunner
        → ContentProjectPublishingQueueRunner::dispatchDue()   ← CP path
            → ProcessScheduledProjectItemPublishCommand
               (CommandBus, ActorContext::queue)
        → (legacy) articles.status=scheduled + published_at
            → BusinessHookEmitter ArticlePublishRequested
```

- **Do not** register a second schedule for the CP runner or Process command.
- Command is a thin entry (legacy name kept). Semantics = **queue dispatch**, not direct WP HTTP from the artisan command.
- Schedule event: `withoutOverlapping()`. Empty due set → exit `0`.

### Process handler flow

1. Item publish lock + idempotency key (`scheduler:{item}:{scheduled_publish_at}` or actor key).
2. Mark processing.
3. `PublisherResolver` → `ContentPublisher::publish(ArticlePublishPayload)`.
4. Queue actor + `deliveryRequested` → emit `article.publish_requested` (**phải** có automation rule `dispatch-publish-request` → `wordpress.article.sync`). `AUTOMATION_RULE_NOT_FOUND` → mark Failed (không để stuck Processing). Seed: `php artisan automation:seed-rules` + queue worker.
5. Reconcile attempts / `wp_post_id` / external_reference.
6. Mark published or failed.

## 7. Public capabilities

| Capability | Command | Notes |
|------------|---------|-------|
| `content_project.schedule` | `ScheduleProjectItemsCommand` | Dry-run preview |
| `content_project.auto_schedule` | `AutoScheduleProjectItemsCommand` | |
| `content_project.unschedule` | `UnscheduleProjectItemsCommand` | |
| `content_project.move_schedule` | `MoveProjectItemScheduleCommand` | |
| `content_project.publish_now` | `PublishProjectItemsNowCommand` | Confirmation |
| `content_project.retry_publish` | `RetryProjectItemPublishingCommand` | |
| `content_project.skip_publish` | `SkipProjectItemPublishingCommand` | Confirmation |
| `content_project.cancel_publish` | `CancelProjectItemPublishingCommand` | Confirmation |

Eligibility (ActionGuard): schedule / publish-now blocked while Archived / Generating / Draft / Failed (as configured); unschedule/cancel/skip while queued/waiting/retrying/processing.

## 8. Internal-only capabilities

| Path | Notes |
|------|-------|
| `ProcessScheduledProjectItemPublishCommand` | Scheduler/queue actor only — not Agent/MCP capability |
| Legacy non-CP scheduled article branch inside `ScheduledArticlePublishRunner` | Compatibility; not CP CommandBus |
| Extension SDK publisher drivers | Register via registry; Core only sees `ContentPublisher` |

## 9. Authorization and confirmation

- Same tenant + Filament gates as Content Projects.
- Destructive publish controls require confirmation tokens (publish_now, skip, cancel; schedule may use dry-run preview).
- Staff who may Sync WP content are not automatically allowed to Publish (separate access checks on WP side-effect paths).

## 10. Queue and scheduler ownership

Owned detail: [QUEUE_SCHEDULER_AND_IDEMPOTENCY.md](../contracts/QUEUE_SCHEDULER_AND_IDEMPOTENCY.md).

Publishing-specific:

- Sole cron: `seo-content-ai:publish-scheduled-articles`.
- Item lock TTL **300s**; schedule project lock **180s**.
- Idempotency: Filament `ui:…`, queue `queue:{job-uuid}:…`, scheduler `scheduler:{item}:{scheduled_publish_at}`.
- Registrar fail-soft: broken additive KI/SERP/GSC handler bindings must not kill publish cron DI.
- Agent Workspace console commands register only when `class_exists` (partial deploy safe).

## 11. Transactions and side effects

- Model: at-least-once + idempotent publish.
- Duplicate protection order: (1) `articles.wp_post_id`, (2) `external_reference = omi_seo_article_{id}` in attempts, (3) timeout after WP success → reconcile, **no second create**.
- Success stamps `publish_published_at` + queue `published`; sticky published lifecycle in item resolver.
- Failure stamps `failed` with error attribution `ContentProjectItemErrorSource::Publish`.
- Automation events may fire after successful process — presentation maps elsewhere; do not double-dispatch publish from Automation Actions.

## 12. Retry and recovery

- User/Agent: `retry_publish` moves failed → retrying/waiting per transition guard.
- Runner picks `waiting` / `retrying` / eligible `none` with due `scheduled_publish_at`.
- Blocked transitions (examples): `published → retry/cancel`, `processing → unschedule` — enforced by `ContentProjectPublishTransitionGuard`.
- After WP success but local timeout: reconcile existing post; do not create duplicate.

## 13. Compatibility paths

- Legacy scheduled **articles** (not on CP queue) still flow through the same artisan command’s legacy branch.
- Publishing Queue Filament page (`ContentProjectPublishingQueue`) is a real Livewire page (summary cards + filters + table + Auto/Quick Mode) — no longer a redirect shell to CP ops.
- Extension `PublisherRegistry` (SDK array drivers) wraps the same WP publisher for Extension SDK consumers — Application path uses `ContentPublisherRegistry`.

## 14. Forbidden paths

1. Second Laravel schedule entry for CP publish dispatch.
2. Stamp `scheduled_publish_at` / `publish_queue_status` from Sync WP / workspace save.
3. Call `ContentPublisher` or mutate queue from Filament callbacks outside CommandBus handlers.
4. Push future WP-native schedule from CP (SaaS owns schedule).
5. Silent fallback to WordPress when `PublisherResolver` cannot resolve key (`PublisherResolutionException` — fail closed).
6. Treat Sync WP / Site Sync / Rebuild as Publish.
7. Expose `process_scheduled_publish` as Agent/MCP capability.

## 15. Tests and invariants

| Test | Invariant |
|------|-----------|
| `PublishScheduledArticlesCanonicalRunnerContractTest` | Artisan command = thin shell over `ScheduledArticlePublishRunner`; single dispatcher |
| `ContentProjectPublishingLifecyclePolishTest` | Queue status active/terminal helpers |
| `ContentProjectCommandBusCutoverTest` | Publish commands on bus |
| `ContentProjectPublicCapabilityContractTest` | Publish caps exposure |
| `ContentProjectItemStateResolverTest` | Publish → lifecycle mapping / sticky published |

## 16. Related documents

- [CONTENT_PROJECTS.md](CONTENT_PROJECTS.md) — item state, ActionGuard, CommandBus
- [QUEUE_SCHEDULER_AND_IDEMPOTENCY.md](../contracts/QUEUE_SCHEDULER_AND_IDEMPOTENCY.md)
- [SITE_SYNC.md](SITE_SYNC.md) — WP catalog SoT / RunSiteSync (not publish)
- [WORDPRESS_BRIDGE.md](WORDPRESS_BRIDGE.md) — bridge auth / REST
- [ARTICLE_EDITOR.md](ARTICLE_EDITOR.md) — editor Publish Now vs local save

---

## WP publish path vs Site Sync

| Concern | Publish (this module) | Site Sync |
|---------|----------------------|-----------|
| Goal | Create/update **one article post** from approved CP item | Sync **site catalog / scores / links / deltas** |
| Trigger | Schedule / publish_now / cron due | Manual, provider, workspace, ops RunSiteSync |
| SoT schedule | SaaS `scheduled_publish_at` | N/A |
| Identity | `wp_post_id` + `omi_seo_article_{id}` | Site Sync run/event idempotency |
| CommandBus | CP publish commands | Site Sync jobs/services (separate) |
| Workspace local Save | Laravel content + `last_synced_at` only | Not a substitute for either |
| Post-publish editorial sync | `SyncPublishedArticleToWordPressCommand` — UPDATE existing `wp_post_id` | Not Site Sync; not initial publish |

**Save ≠ Sync ≠ Publish ≠ Rebuild.**

### Initial publish vs post-publish editorial sync

| Concern | Initial publish (Publishing Queue) | Post-publish sync (editor / bulk) |
|---------|-------------------------------------|-----------------------------------|
| Owner | `ProcessScheduledProjectItemPublish` / Publish Now / Retry | `SyncPublishedArticleToWordPressCommand` |
| Create WP post | Allowed (idempotent) | **Never** — update only |
| Eligibility | Queue waiting/processing | `publish_queue_status=published` + `publish_published_at` + tenant |
| Queue status after | → published | **Stays** published |
| Missing `wp_post_id` | Reconcile then create if needed | Reconcile only; block if 0/ambiguous matches |
| Retry counters | Initial publish retries | Separate post-publish sync error meta |
| Bulk repair | N/A | `BulkResyncPublishedArticlesToWordPressCommand` → `updated/skipped/failed` |

Symbols: `PostPublishWordPressSyncEligibility`, `PostPublishWordPressPostReconciler`, `WordPressArticleSyncService::updatePublishedArticleOnly`.

Active CP unpublished “Sync WP” remains blocked — Publishing Queue owns first create.

)
