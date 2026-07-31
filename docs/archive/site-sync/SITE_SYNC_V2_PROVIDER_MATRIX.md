> Status: Historical
> Not canonical
> Archived on: 2026-08-01
> Superseded by: ../../modules/SITE_SYNC.md
> Purpose: implementation history only
# Site Sync V2 â€” Provider Matrix

WP adapters (`includes/providers/`):

| Provider | Score | Focus KW | Redirect | 404 |
|----------|-------|----------|----------|-----|
| Rank Math | if exposed | yes | detect class | detect class |
| Yoast | if exposed | yes | detect | usually false unless class present |
| AIOSEO | false unless stable API | yes | false default | false |
| none | false â†’ Workspace fallback | false â†’ Workspace | false | Workspace validation |

Laravel **does not hardcode** Yoast missing 404 â€” uses WP capability manifest.
