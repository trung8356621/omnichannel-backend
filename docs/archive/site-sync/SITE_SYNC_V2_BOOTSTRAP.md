> Status: Historical
> Not canonical
> Archived on: 2026-08-01
> Superseded by: ../../modules/SITE_SYNC.md
> Purpose: implementation history only
# Site Sync V2 â€” Bootstrap

First-time sync for sites without V2 state.

## Flow

1. Check plugin/contract
2. Capability + lightweight manifest
3. Preview workload (batches)
4. Confirm â†’ `BootstrapSiteSync` CommandBus
5. Snapshot orchestrator (queued steps)
6. Stamp `seo_site_sync_v2_bootstrapped_at` on finalize

## UI

One button **Äá»“ng bá»™ & kiá»ƒm tra website**:
- chÆ°a bootstrap â†’ preview + xÃ¡c nháº­n
- Ä‘Ã£ bootstrap â†’ incremental

## Commands

- `site.preview_bootstrap`
- `site.bootstrap`
