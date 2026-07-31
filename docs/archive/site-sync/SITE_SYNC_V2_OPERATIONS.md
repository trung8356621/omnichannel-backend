> Status: Historical
> Not canonical
> Archived on: 2026-08-01
> Superseded by: ../../modules/SITE_SYNC.md
> Purpose: implementation history only
# Site Sync V2 Operations

## Commands (CLI)

```bash
php artisan seo:site-sync {site_id} [--snapshot] [--sync]
php artisan seo:site-sync-reconcile {site_id?} --mode=quick|standard|full_rebuild --limit=50
php artisan seo:site-sync-v2-backfill {site_id} --dry-run
php artisan seo:site-sync-v2-backfill {site_id} --execute --only=links,keywords --batch=200
```

## Scheduler

- Hourly: `seo-content-ai:site-sync-reconcile-quick` (`seo:site-sync-reconcile --mode=quick --limit=30`)
- Scan sites: `whereHas(metas.seo_read_token)` â€” **not** `sites.settings` (column missing on core `sites`)
- Skips when site lock held by sync run; non-V2 writers skipped in loop (`SiteSyncCutoverStateService::isV2Writer`)
- Flag options (`--sync` / `--apply`) on other SEO schedules must be string form, not `['--flag' => true]`

## Handshake / Diagnostic / Cutover

- `site.validate_handshake`
- `site.generate_diagnostic`
- `site.preview_cutover` / `site.enter_shadow` / `site.activate_v2` / `site.rollback_legacy`
- `site.generate_comparison` / `site.preview_repair` / `site.execute_repair`

Agent `site.sync` **never** activates cutover.

## CommandBus control commands

- `ResumeSiteSync` / `RetrySiteSyncStep` / `RetrySiteSyncBatch`
- `CancelSiteSync` â€” no rollback of successfully reconciled data
- `ReconcileSiteSync`
- `RequeueSiteSyncInboundEvent`
- `PreviewBootstrapSiteSync` / `BootstrapSiteSync`
- `BackfillSiteSyncV2`
- `ValidateSiteSyncHandshake` / `GenerateSiteSyncDiagnostic`

## Inbound statuses

`received` â†’ `validated` â†’ `queued` â†’ `processing` â†’ `completed`  
Terminal: `failed`, `dead_letter`, `ignored_duplicate`, `ignored_stale`

## Troubleshooting

1. Dead letters â†’ Ops Center Requeue
2. Stuck run â†’ Resume / Cancel
3. Drift â†’ `seo:site-sync-reconcile {id} --mode=standard`
4. Emergency â†’ `SEO_SITE_SYNC_V2_EMERGENCY_ROLLBACK=true`
