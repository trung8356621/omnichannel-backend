> Status: Historical
> Not canonical
> Archived on: 2026-08-01
> Superseded by: ../../modules/SEO_AUDIT_AND_KEYWORDS.md
> Purpose: implementation history only
# GSC Provider Contract

Path: `Services/GscIntelligence/Contracts/GscIntelligenceProviderInterface.php`

Registry: `GscIntelligenceProviderRegistry` (bound in `SeoContentAiServiceProvider`).

## Providers (registered)

| Key | Class | Role |
|-----|-------|------|
| `manual_import` | `ManualImportGscProvider` | CSV Search Analytics rows |
| `fake_local` | `FakeLocalGscProvider` | Synthetic rows (dev/test) |

**Not registered:** live Google Search Analytics HTTP/SDK provider (legacy path remains `GoogleSearchConsoleSyncService` â†’ SiteMeta snapshot).

## Resolution (`GscProviderResolver`)

Fail-closed â€” **no silent fallback**:

| Error code | When |
|------------|------|
| `gsc_provider.not_configured` | Missing provider key |
| `gsc_provider.not_registered` | Unknown key |
| `gsc_provider.disabled` | Not in enabled list |
| `gsc_provider.incompatible` | `supports()` false |
| `gsc_provider.unhealthy` | `health().healthy !== true` |
| `gsc_provider.capability_unsupported` | Missing required capability |

Config file: `app/Addons/SeoContentAi/config/gsc_intelligence.php`  
Merged as: `seo-content-ai.gsc_intelligence`  
Enabled default: `providers.enabled` = `['manual_import', 'fake_local']`.  
Runtime override: context `enabled_providers` (array) on resolve/sync.

Handlers under `Application/Handlers/` must not import `Google\Client` or `Google_Service` â€” provider boundary only.
