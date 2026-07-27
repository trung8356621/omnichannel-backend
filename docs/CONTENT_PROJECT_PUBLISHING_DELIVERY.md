# Content Project Publishing Delivery

Model: **at-least-once** + **idempotent publish**.

WordPress never receives future schedule from Content Project — SaaS owns `scheduled_publish_at`.

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
