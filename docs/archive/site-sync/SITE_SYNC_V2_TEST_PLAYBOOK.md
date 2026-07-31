> Status: Historical
> Not canonical
> Archived on: 2026-08-01
> Superseded by: ../../modules/SITE_SYNC.md
> Purpose: implementation history only
# Site Sync V2 â€” Test Playbook (Wave 4)

1. Deploy Laravel (migrate omi_seo_ai).
2. Upload plugin **1.0.64** (no WP bump in Wave 4 unless WP changed â€” Wave 4 Laravel-only).
3. Handshake / Diagnostic on Ops.
4. Bootstrap site (one Sync button â†’ preview â†’ confirm).
5. Save one WP post â†’ inbound event.
6. `Generate comparison` (Ops).
7. Enter shadow (confirmation).
8. Observe inbound success / dead letters.
9. Activate V2 manually with token (not via Agent `site.sync`).
10. Rollback rehearsal with token â€” V2 data remains.
11. PHPUnit:
    `$PHP_BIN vendor/bin/phpunit --filter=SiteSyncV2Wave4CutoverFreezeTest`
