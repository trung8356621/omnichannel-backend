# Content Project Publishing Delivery

Model: **at-least-once** + **idempotent publish**.

WordPress never receives future schedule from Content Project — SaaS owns `scheduled_publish_at`.

## Scheduler path (canonical — one dispatcher)

```
Hosting cron
  → php artisan schedule:run
    → seo:publish-scheduled-articles   (schedule name: seo-content-ai:publish-scheduled-articles)
      → ScheduledArticlePublishRunner
        → ContentProjectPublishingQueueRunner::dispatchDue()   ← canonical CP path
          → ProcessScheduledProjectItemPublishCommand (CommandBus, ActorContext::queue)
        → (legacy) articles.status=scheduled + published_at → BusinessHookEmitter ArticlePublishRequested
```

- **Do not** register a second schedule for `ContentProjectPublishingQueueRunner` or `ProcessScheduledProjectItemPublish`.
- Command is a thin scheduler entry (legacy name kept). Semantics = queue dispatch, **not** direct WP publish.
- Overlap: `withoutOverlapping()` on schedule event.
- Empty due set → exit `0`. Scheduler-level Throwable → printed + exit non-zero (not silent 255).

## Flow

1. Command (`schedule` / `publish_now` / `retry`) → `ContentProjectPublishingQueueService` (+ transition guard).
2. Runner `dispatchDue` → `ProcessScheduledProjectItemPublish` via CommandBus (`ActorContext::queue`).
3. Handler: item lock → `ContentPublisher` reconcile → emit Automation event if needed → mark published/failed.
4. No UI/ManualSync stamps schedule to fake Publish Now.

## Sync WP (active Content Project)

`ContentProjectWorkspaceSaveService` only:

- Save Laravel content/media
- Update `last_synced_at`
- **No** `scheduled_publish_at` / queue status / publisher / publish attempt

## Duplicate protection

1. Article `wp_post_id`
2. `external_reference` = `omi_seo_article_{id}` in `seo_content_project_publish_attempts`
3. Timeout after WP success → reconcile, no second create

## Lock / idempotency

See cutover doc. Publish lock TTL **300s**. Scheduler key: `scheduler:{item}:{scheduled_publish_at}`.

## Transitions

See `ContentProjectPublishTransitionGuard`.

## Fail-safe notes (scheduler)

- `ContentProjectCommandBusRegistrar` skips broken additive handlers (KI/SERP/GSC) so one bad binding cannot kill publish cron DI.
- Agent Workspace console commands register only when `class_exists` (partial deploy must not crash `seo:publish-scheduled-articles`).
