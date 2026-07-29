# Site Sync V2 — Provider Matrix

WP adapters (`includes/providers/`):

| Provider | Score | Focus KW | Redirect | 404 |
|----------|-------|----------|----------|-----|
| Rank Math | if exposed | yes | detect class | detect class |
| Yoast | if exposed | yes | detect | usually false unless class present |
| AIOSEO | false unless stable API | yes | false default | false |
| none | false → Workspace fallback | false → Workspace | false | Workspace validation |

Laravel **does not hardcode** Yoast missing 404 — uses WP capability manifest.
