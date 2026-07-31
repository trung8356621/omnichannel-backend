> Status: Historical
> Not canonical
> Archived on: 2026-08-01
> Superseded by: ../../modules/SITE_SYNC.md
> Purpose: implementation history only
# Site Sync V2 â€” Backfill

```bash
php artisan seo:site-sync-v2-backfill {site_id} --dry-run
php artisan seo:site-sync-v2-backfill {site_id} --execute --only=links,keywords
```

## Rules

- Never delete legacy
- Manual preserved
- Unknown score/keyword â†’ `legacy_unknown` (never invent Rank Math/Yoast)
- Domain link list â†’ Manual Site Links only
- No HTML full-site parse
- No AI

## Modes

`profile` | `links` | `keywords` | `scores` | `articles` | `all`
