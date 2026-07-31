> Status: Historical
> Not canonical
> Archived on: 2026-08-01
> Superseded by: ../../modules/SITE_SYNC.md
> Purpose: implementation history only
# Site Sync V2 â€” Rollback

## Policy

`v2_active â†’ legacy_active` requires confirmation token + `SEO_SITE_SYNC_V2_ALLOW_ROLLBACK`.

- Does **not** delete V2 tables/data
- Stops treating V2 as writer for new auto reconcile when back on `legacy_active` (inbound held)
- Re-enables legacy path via flags/mode
- Creates rollback checkpoint
- Exports which V2-only deltas may not exist in legacy

No automatic reverse sync of all V2 data into legacy.
