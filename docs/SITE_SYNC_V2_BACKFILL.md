# Site Sync V2 — Backfill

```bash
php artisan seo:site-sync-v2-backfill {site_id} --dry-run
php artisan seo:site-sync-v2-backfill {site_id} --execute --only=links,keywords
```

## Rules

- Never delete legacy
- Manual preserved
- Unknown score/keyword → `legacy_unknown` (never invent Rank Math/Yoast)
- Domain link list → Manual Site Links only
- No HTML full-site parse
- No AI

## Modes

`profile` | `links` | `keywords` | `scores` | `articles` | `all`
