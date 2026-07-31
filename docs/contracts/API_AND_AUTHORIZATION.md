# API and Authorization Contracts

> Status: Canonical  
> Owner: SeoContentAi  
> Last verified: 2026-08-01  
> Supersedes: auth slices formerly scattered across MAP_SEO_TEAM, CONTENT_PROJECT_APPLICATION_API, Agent security satellites

## 1. Purpose

Public HTTP / Sanctum / Filament access boundaries for SEO Content AI. Complements Agent confirmation rules in [AGENT_AND_MCP_CONTRACTS.md](AGENT_AND_MCP_CONTRACTS.md).

## 2. Surfaces

| Surface | Auth | Notes |
|---------|------|-------|
| Filament SEO panel `/seo/{connection_hash}/…` | Session + `SeoAccessControl` | Connection hash scopes tenant DB |
| Agent MCP HTTP `/api/v1/agent/mcp/*` | Sanctum | Read token cannot write |
| Agent execute `/api/v1/agent/execute` | Sanctum | Capability + confirmation gated |
| WordPress bridge REST | Site token / HMAC (bridge) | See [WORDPRESS_BRIDGE.md](../modules/WORDPRESS_BRIDGE.md) |
| Site Sync inbound callback | Signed callback | See [SITE_SYNC.md](../modules/SITE_SYNC.md) |

## 3. Tenant and connection

- SEO models use runtime connection `omi_seo_ai` bootstrapped from core `seo_database_connections`.
- Fail closed when connection hash / site scope missing or mismatched.
- Non-admin Filament queries must scope by permitted owner/site/domain (`SeoAccessControl` / policies).

## 4. Capability vs UI permission

- Filament role/menu visibility ≠ Agent Gateway write authority.
- Writes go `CanonicalCapabilityRegistry` → policy → confirmation → CommandBus.
- Wildcard UI scopes do not bypass Gateway enforcement.

## 5. Confirmation and destructive actions

- Confirm-required writes need one-time preview token (hash stored only).
- Archive / destroy workspace / force full rebuild: elevated confirmation; Agent never auto-confirms.
- Dry-run must not mutate durable domain state.

## 6. Secrets and logging

- Never log tokens, passwords, API keys, Authorization headers, or full sensitive payloads.
- HTTP paths use `RuntimeLogger` / `web_app` channel — not default `laravel.log` (see [DATA_AND_RUNTIME_BOUNDARIES.md](../architecture/DATA_AND_RUNTIME_BOUNDARIES.md)).

## 7. Forbidden

- Cross-tenant reads/writes via raw IDs without scope checks.
- Exposing numeric internal IDs as Agent/MCP public refs.
- CSRF exceptions outside narrowly scoped webhook/callback routes.
- Treating archive docs or addon README stub as auth SoT.

## 8. Related documents

- [AGENT_AND_MCP_CONTRACTS.md](AGENT_AND_MCP_CONTRACTS.md)
- [EXTENSION_AND_REGISTRY_CONTRACTS.md](EXTENSION_AND_REGISTRY_CONTRACTS.md)
- [SITE_MCP_AND_DOMAINS.md](../modules/SITE_MCP_AND_DOMAINS.md)
- [WORDPRESS_BRIDGE.md](../modules/WORDPRESS_BRIDGE.md)
- [ARCHITECTURE_FREEZE_V1.md](../architecture/ARCHITECTURE_FREEZE_V1.md)
