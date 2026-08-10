# SeoContentAi Compatibility Shell

> Status: Canonical (compatibility-only)  
> Last verified: 2026-08-10  
> Architecture: [ADDON_ARCHITECTURE.md](ADDON_ARCHITECTURE.md) · Handoff: [NEW_AGENT_HANDOFF.md](NEW_AGENT_HANDOFF.md)

## Why the shell still exists

`app/Addons/SeoContentAi/` is **not** an active business owner. It remains because:

- Filament still loads **~239 Blade views** under namespace `seo-content-ai::` (`loadViewsFrom` in `SeoPanelProvider`).
- Panel bootstrap (`panel_provider` / `SeoPanelProvider`) still wires the SEO Filament panel, Livewire registrations, translations, and API route group load.
- Config/lang still merge under legacy keys `seo-content-ai.*` / `seo-content-ai::`.

**Goal is not “zero files”.** Goal is **zero undocumented business ownership**.  
Do **not** mechanically relocate 239 views just to shrink the tree.

Active business lives in `/addons/*`.

## Categories retained + exact consumers

| Category | Path | Consumers / role |
|----------|------|------------------|
| Discovery | `addon.json` | Addon registry; `provider` + `panel_provider`; legacy slug `seo-content-ai`; `legacy: true` |
| Bootstrap | `SeoContentAiServiceProvider.php` | Compat DI, `mergeConfigFrom`, views/lang schedule wiring, observers that still register from shell |
| Panel | `Providers/SeoPanelProvider.php` | Filament SEO panel, Livewire, translations, loads `routes/api.php` group, `view('seo-content-ai::…')` hooks |
| Routes shim | `routes/api.php` | Requires peer route files: `addons/wordpress/routes/seo-wp-bridge.php`, `addons/content-projects/routes/api-v1.php` |
| Routes stub | `routes/web.php` | Stub only (no active web bodies) |
| Config | `config/*` | Merged as `seo-content-ai.*` (tests + runtime `config('seo-content-ai…')`) |
| Lang | `lang/*` | Namespace `seo-content-ai::` via panel provider |
| Views | `resources/views/**` (~239) | Filament pages/resources/widgets still `seo-content-ai::…` |
| Settings | `Settings.php` | `App\Models\SiteService` defaults via `{Addon}\Settings::getDefaults()`; `Omnichannel\Addons\SearchFoundation\Support\SeoSiteServiceDatabaseConfigurator` calls `(new Settings)->getDefaults()` |
| Tests | `tests/Compat/UsesSeoDatabase.php` | PHPUnit trait used by peer addon tests (e.g. content/wordpress) to skip when `omi_seo_ai` missing |
| Docs stub | `README.md` | Local shell inventory (compat); architecture SoT is this file + ADDON_ARCHITECTURE |

Also present: `README_ADDON_SEOCONTENTAI.md` (legacy longform — **not** architecture SoT), `database.local.php.example` (legacy example).

## What MUST NOT be added here

- Models / Eloquent domain classes  
- Services / Actions with business logic  
- Business React/JS (active SeoContentAi JS must stay **0**)  
- New migrations owned by this shell  
- New Filament Resources/Pages (product UI belongs in peer addons)  
- Extension Builtin PHP / new Extension trees (discovery root: `addons/agent/src/Extension/Builtin`)  

If something is **ACTIVE business**, move it to the **owner peer addon**.  
If something is **COMPAT** with a known caller, keep and document the caller here.  
If **DEAD**, delete.

## Active code location

| Concern | Owner path |
|---------|------------|
| Articles / editor | `addons/content` |
| SEO scoring / audit | `addons/seo` |
| Media / featured | `addons/media` |
| WP bridge | `addons/wordpress` |
| Publishing | `addons/publishing` |
| Content projects | `addons/content-projects` |
| Prompts | `addons/ai-prompt` |
| Performance Hub | `addons/search-intelligence` |
| SEO DB bootstrap | `addons/search-foundation` |
| Site Sync | `addons/site-sync` |
| Agent / MCP / Extension manifests | `addons/agent` |

Vite entries already point at `addons/…` (see root `vite.config.js`).
