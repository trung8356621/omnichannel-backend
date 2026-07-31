> Status: Historical
> Not canonical
> Archived on: 2026-08-01
> Superseded by: ../../modules/SITE_SYNC.md
> Purpose: implementation history only
# Site Sync V2

WordPress = source of truth for public post/SEO/link data. Laravel = catalog, ownership, operations, agent context.

## Save vs Sync vs Publish vs Rebuild

| Action | What it does |
|--------|----------------|
| **Save Domain Settings** | Persist tone/CTA/short-desc/manual links only. No keyword sync, no HTML parse, no Site Sync jobs. |
| **Sync (Äá»“ng bá»™ & kiá»ƒm tra)** | `RunSiteSync` 8-step orchestrator â€” incremental/standard by default. |
| **Auto delta push** | WP outbox â†’ signed callback â†’ inbound inbox â†’ queue reconcile one post. |
| **Publish** | Content Project / article publish path â€” separate from Site Sync. |
| **Rebuild (full_rebuild)** | Explicit Advanced/CLI only â€” confirmation required for Agent. |

## Bootstrap / Backfill (Wave 3)

- First-time: [SITE_SYNC_V2_BOOTSTRAP.md](SITE_SYNC_V2_BOOTSTRAP.md)
- Legacy migrate: [SITE_SYNC_V2_BACKFILL.md](SITE_SYNC_V2_BACKFILL.md)
- Providers: [SITE_SYNC_V2_PROVIDER_MATRIX.md](SITE_SYNC_V2_PROVIDER_MATRIX.md)
- Test playbook: [SITE_SYNC_V2_TEST_PLAYBOOK.md](SITE_SYNC_V2_TEST_PLAYBOOK.md)

One UI button. ChÆ°a bootstrap â†’ preview â†’ confirm. ÄÃ£ bootstrap â†’ incremental.

## Ownership

`Manual > Provider > Workspace fallback`. Lower sources kept; effective value resolved separately.

## UI

One primary button. Legacy 4 buttons behind `SEO_SITE_SYNC_V2_LEGACY_ACTIONS` / emergency rollback.

## Ops

UI ops: **Operation Center** â†’ tab **Site Sync** (`ContentProjectOperationsCenter`). Page `SiteSyncOperationsCenter` (`/seo/.../site-sync-operations`) váº«n tá»“n táº¡i backend nhÆ°ng **khÃ´ng** Ä‘Äƒng kÃ½ sidebar. Actions (resume/cancel/reconcile/diagnostic) theo status run; qua CommandBus.

## Related docs

- [SITE_SYNC_V2_CONTRACT.md](SITE_SYNC_V2_CONTRACT.md)
- [SITE_SYNC_V2_OPERATIONS.md](SITE_SYNC_V2_OPERATIONS.md)
- [SITE_SYNC_V2_SECURITY.md](SITE_SYNC_V2_SECURITY.md)
- [SITE_SYNC_V2_CUTOVER.md](SITE_SYNC_V2_CUTOVER.md)
- [WP_PLUGIN_SITE_SYNC_V2.md](WP_PLUGIN_SITE_SYNC_V2.md)
