# Omnichannel Backend — Documentation Index

> Status: Canonical  
> Last verified: 2026-08-01

## Precedence (source of truth)

1. `docs/architecture/ARCHITECTURE_FREEZE_V1.md` + accepted ADRs in `ARCHITECTURE_DECISIONS.md`
2. `docs/modules/*` — one canonical doc per module
3. `docs/contracts/*` — public contracts / invariants
4. `docs/operations/*` — deploy, workers, testing, troubleshooting
5. `docs/audits/*` — current audits only (if any)
6. `docs/archive/*` — **historical only**, never SoT

Root `/README.md` = repository landing.  
`app/Addons/SeoContentAi/README_ADDON_SEOCONTENTAI.md` = compatibility stub only.  
`docs/SUPER_MAP_INDEX.md` = thin legacy pointer for tooling.

## Architecture

| Doc | Role |
|-----|------|
| [SYSTEM_OVERVIEW.md](architecture/SYSTEM_OVERVIEW.md) | System map |
| [DATA_AND_RUNTIME_BOUNDARIES.md](architecture/DATA_AND_RUNTIME_BOUNDARIES.md) | DB / logging / addon boundaries |
| [ARCHITECTURE_FREEZE_V1.md](architecture/ARCHITECTURE_FREEZE_V1.md) | Frozen public contracts |
| [ARCHITECTURE_DECISIONS.md](architecture/ARCHITECTURE_DECISIONS.md) | ADR-001.. |

## Modules

| Module | Doc |
|--------|-----|
| Agent Workspace | [AGENT_WORKSPACE.md](modules/AGENT_WORKSPACE.md) |
| Automation | [AUTOMATION.md](modules/AUTOMATION.md) |
| Content Projects | [CONTENT_PROJECTS.md](modules/CONTENT_PROJECTS.md) |
| Publishing | [PUBLISHING.md](modules/PUBLISHING.md) |
| Article Editor | [ARTICLE_EDITOR.md](modules/ARTICLE_EDITOR.md) |
| Site Sync | [SITE_SYNC.md](modules/SITE_SYNC.md) |
| WordPress Bridge | [WORDPRESS_BRIDGE.md](modules/WORDPRESS_BRIDGE.md) |
| Site MCP / Domains | [SITE_MCP_AND_DOMAINS.md](modules/SITE_MCP_AND_DOMAINS.md) |
| SEO Audit / Keywords | [SEO_AUDIT_AND_KEYWORDS.md](modules/SEO_AUDIT_AND_KEYWORDS.md) |
| Prompts / AI | [PROMPTS_AND_AI.md](modules/PROMPTS_AND_AI.md) |
| Media / Gallery | [MEDIA_AND_GALLERY.md](modules/MEDIA_AND_GALLERY.md) |
| Extension SDK | [EXTENSION_SDK.md](modules/EXTENSION_SDK.md) |
| Operations / Observability | [OPERATIONS_AND_OBSERVABILITY.md](modules/OPERATIONS_AND_OBSERVABILITY.md) |

## Contracts

| Contract | Doc |
|----------|-----|
| Agent / MCP | [AGENT_AND_MCP_CONTRACTS.md](contracts/AGENT_AND_MCP_CONTRACTS.md) |
| API / Authorization | [API_AND_AUTHORIZATION.md](contracts/API_AND_AUTHORIZATION.md) |
| Queue / Scheduler / Idempotency | [QUEUE_SCHEDULER_AND_IDEMPOTENCY.md](contracts/QUEUE_SCHEDULER_AND_IDEMPOTENCY.md) |
| Extension / Registry | [EXTENSION_AND_REGISTRY_CONTRACTS.md](contracts/EXTENSION_AND_REGISTRY_CONTRACTS.md) |

## Operations

| Topic | Doc |
|-------|-----|
| Deployment | [DEPLOYMENT.md](operations/DEPLOYMENT.md) |
| Scheduler / Workers | [SCHEDULER_AND_WORKERS.md](operations/SCHEDULER_AND_WORKERS.md) |
| Testing | [TESTING.md](operations/TESTING.md) |
| Troubleshooting | [TROUBLESHOOTING.md](operations/TROUBLESHOOTING.md) |

## Archive

Historical handoffs, phase reports, and superseded MAP_* satellites: [archive/README.md](archive/README.md).  
Do not treat archive paths as architecture or runtime contracts.
