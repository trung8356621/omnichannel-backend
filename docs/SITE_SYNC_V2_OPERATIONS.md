# Site Sync V2 Operations

## Commands (CLI)

```bash
php artisan seo:site-sync {site_id} [--snapshot] [--sync]
php artisan seo:site-sync-reconcile {site_id?} --mode=quick|standard|full_rebuild --limit=50
php artisan seo:site-sync-v2-backfill {site_id} --dry-run
php artisan seo:site-sync-v2-backfill {site_id} --execute --only=links,keywords --batch=200
```

## Scheduler

- Hourly: `seo-content-ai:site-sync-reconcile-quick` (`--mode=quick --limit=30`)
- Skips when site lock held by sync run.

## Handshake / Diagnostic / Cutover

- `site.validate_handshake`
- `site.generate_diagnostic`
- `site.preview_cutover` / `site.enter_shadow` / `site.activate_v2` / `site.rollback_legacy`
- `site.generate_comparison` / `site.preview_repair` / `site.execute_repair`

Agent `site.sync` **never** activates cutover.

## CommandBus control commands

- `ResumeSiteSync` / `RetrySiteSyncStep` / `RetrySiteSyncBatch`
- `CancelSiteSync` — no rollback of successfully reconciled data
- `ReconcileSiteSync`
- `RequeueSiteSyncInboundEvent`
- `PreviewBootstrapSiteSync` / `BootstrapSiteSync`
- `BackfillSiteSyncV2`
- `ValidateSiteSyncHandshake` / `GenerateSiteSyncDiagnostic`

## Inbound statuses

`received` → `validated` → `queued` → `processing` → `completed`  
Terminal: `failed`, `dead_letter`, `ignored_duplicate`, `ignored_stale`

## Troubleshooting

1. Dead letters → Ops Center Requeue
2. Stuck run → Resume / Cancel
3. Drift → `seo:site-sync-reconcile {id} --mode=standard`
4. Emergency → `SEO_SITE_SYNC_V2_EMERGENCY_ROLLBACK=true`
