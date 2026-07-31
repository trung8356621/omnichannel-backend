> Status: Historical
> Not canonical
> Archived on: 2026-08-01
> Superseded by: ../../modules/SITE_SYNC.md
> Purpose: implementation history only
# Site Sync V2 Contract (`site_sync.v1`)

- Schema version: `site_sync.v1`
- Min bridge: `1.0.64` (`SiteSyncSchema::MIN_BRIDGE_VERSION`)
- Laravel + WP fixtures must stay parity under addon tests / WP fixtures.

## Delta event required fields

- site identifier / connection binding
- `wordpress_id`
- `event_type` (`article.created|updated|deleted|trashed|restored`, `permalink.changed`, `seo_metadata.changed`, `taxonomy.changed`, `capability.changed`)
- `origin`, `operation_id`, `provider`
- `changed_fields`, hashes (`content_hash`, `seo_meta_hash`, `link_hash`, `taxonomy_hash`)
- `snapshot_version`, `occurred_at`
- idempotency key / event id

## Compatibility

- Unknown optional fields tolerated.
- Required field validation fails closed (no mutate).
- `accept_v1_contract` flag gates acceptance.

## Endpoints

- WP REST: `/capabilities`, `/sync/v2/profile|delta|batches|manifest`
- Laravel: `POST /api/seo-wp-bridge/delta-event` (+ snapshot-callback compat path)
