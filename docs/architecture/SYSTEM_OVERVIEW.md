# System Overview

> Status: Canonical  
> Owner: Core + SeoContentAi  
> Last verified: 2026-08-01  
> Supersedes: `docs/archive/maps/MAP_CORE.md`, `FEATURE_MAP_FULL.md` (high-level only — not route/controller dumps)

## Shape

Omnichannel SaaS backend: **Laravel 12 / PHP 8.2+ / Filament v3 / MySQL multi-connection**.

| Plane | Path | Role |
|-------|------|------|
| **Core** | `app/`, `routes/`, `app/Filament` admin | Users, sites, subscriptions/wallets, addon registry, SEO DB credentials, plugin release distribution |
| **SEO Content AI addon** | `app/Addons/SeoContentAi/` | SEO panel `/seo/{connection_hash}`, articles, projects, sync, keywords, media, agent |
| **WP plugin** | external `wp-seo-ai` / `omi-seo-ai-bridge` | Live WP public content + Site Sync provider |

Active addons register from `services` table + `addon.json` — not static `config/app.php`. Slugs in `config/addons.php` → `skip_slugs` (default `wp-headless`) are ignored even if active.

## Panels

| Panel | Mount | Audience |
|-------|-------|----------|
| Admin | `/admin` | Core admin (`AdminPanelProvider`); staff redirected away |
| SEO | `/seo/{connection_hash}` | SeoContentAi Filament (`SeoPanelProvider`) — connection-scoped |
| Tools | `/tools` | Public-ish SEO tools page (no full SEO tenant) |

## Core responsibilities

- **Identity:** User roles `admin|owner|manager|staff` + SEO `seo_role`; hierarchy `parent_id` / `manager_id`.
- **Tenancy surface:** `Site` + `SiteMeta` + `SiteService` bindings.
- **Commerce shell:** subscriptions, wallets, transactions, invoices, usage logs (financial writes → `DB::transaction`).
- **Addon discovery:** `AddonManager`.
- **SEO DB credentials:** `SeoDatabaseConnection` (core table) → runtime `omi_seo_ai` via `SeoDatabaseConnectionService`.
- **Plugin distribution:** `ExternalPlugin*` services + update-check/download APIs for bridge ZIP releases.
- **Shared SEO analyze wrapper:** `SeoEngineService` (compat) — canonical scoring lives in addon registry/engine.

## SeoContentAi responsibilities (modules)

Canonical maps under `docs/modules/`:

| Module | Concern |
|--------|---------|
| Content Projects | Planning, CommandBus, Run Engine, item state |
| Publishing | Schedule / publish_now / publisher registry |
| Site Sync | WP catalog alignment V2 |
| WordPress Bridge | Auth, inbound/outbound REST contracts |
| Article Editor | EditArticle Livewire + React |
| Media and Gallery | Upload, watermark, WP media files |
| SEO Audit and Keywords | ArticlesOptimal, KI / SERP / GSC |
| Site MCP and Domains | Domain settings, Knowledge Profile, team RBAC |
| Agent Workspace / Automation | Agent, MCP, automations (separate module docs) |

Cross-cutting contracts: `docs/contracts/`. Ops: `docs/operations/`. Freeze: `ARCHITECTURE_FREEZE_V1.md` + ADR in `ARCHITECTURE_DECISIONS.md`.

## Runtime sketch

```text
Browser / Filament / React
  → HTTP (web_app logs via RuntimeLogger)
  → Core auth + SeoAccessControl
  → Connection hash middleware bootstraps omi_seo_ai
  → Addon services / CommandBus / jobs (queue seo / media_generation / default)

WordPress plugin
  → Bridge Bearer token APIs / Site Sync outbox
  → Laravel inbound jobs

CLI / cron / queue workers
  → default Log channels (laravel.log / queue-cron)
  → scheduled publish, sync, scoring, rank checks
```

## Data plane (summary)

| Connection | Owns |
|------------|------|
| `mysql` (default) | Users, sites, metas, services, wallets, `seo_database_connections`, GSC OAuth masters |
| `omi_seo_ai` | Articles, projects, media, keyword/GSC/SERP intelligence facts, runs |

No cross-DB foreign keys — logical IDs + application guards. Detail: [DATA_AND_RUNTIME_BOUNDARIES.md](DATA_AND_RUNTIME_BOUNDARIES.md).

## Non-goals of this doc

- Exhaustive route tables, every controller method, every job timeout.
- Phase handoffs and playbooks (see `docs/archive/`).
- Extension SDK method signatures (see freeze + Extension docs in archive/contracts as applicable).

## Related

- [DATA_AND_RUNTIME_BOUNDARIES.md](DATA_AND_RUNTIME_BOUNDARIES.md)
- [ARCHITECTURE_FREEZE_V1.md](ARCHITECTURE_FREEZE_V1.md)
- [ARCHITECTURE_DECISIONS.md](ARCHITECTURE_DECISIONS.md)
- [docs/README.md](../README.md)
