# WordPress Plugin — Site Sync V2

Plugin: `omi-seo-ai-bridge` **≥ 1.0.64**

## Auto delta outbox

- Hooks: `save_post`, `transition_post_status`, `before_delete_post`, `trashed_post`, `untrashed_post`, SEO meta / taxonomy / featured image / permalink changes
- Debounce same post → one effective delta
- Table: `omi_seo_sync_outbox` (schema v2)
- Flush: WP-Cron `omi_seo_ai_flush_sync_outbox` + transient overlap lock
- Max attempts → `dead_letter`; daily retention cleanup
- `Site_Sync_Outbox::health()` / `retry_dead_letter()`
- Loop prevention: `_omi_seo_ai_skip_push` / `Laravel_Push_Sync::is_suppressed()`

## Provider adapters

`includes/providers/` — Rank Math, Yoast, AIOSEO, None → Capability_Manifest

## Package

```powershell
.\compress_plugin.ps1
```

Đã tự động nâng cấp phiên bản lên **1.0.64** trong `omi-seo-ai-bridge.php`.
