# Omnichannel Backend — Documentation Index

> Status: Canonical  
> Last verified: 2026-08-01

## Precedence (source of truth)

1. `docs/architecture/ARCHITECTURE_FREEZE_V1.md` + accepted ADRs in `ARCHITECTURE_DECISIONS.md`
2. `docs/modules/*` — one canonical doc per module
3. `docs/contracts/*` — public contracts / invariants
4. `docs/operations/*` — deploy, workers, testing, troubleshooting
5. `docs/audits/*` — current audits only (if any). Active: [`ARTICLE_EDITOR_PERFORMANCE_PHASE1.md`](audits/ARTICLE_EDITOR_PERFORMANCE_PHASE1.md)
6. `docs/archive/*` — **historical only**, never SoT

Root `/README.md` = repository landing.  
`app/Addons/SeoContentAi/README_ADDON_SEOCONTENTAI.md` = compatibility stub only.  
`docs/SUPER_MAP_INDEX.md` = thin legacy pointer for tooling.

## Architecture

| Doc | Role |
|-----|------|
| [SYSTEM_OVERVIEW.md](architecture/SYSTEM_OVERVIEW.md) | System map |
| [ADDON_ARCHITECTURE.md](architecture/ADDON_ARCHITECTURE.md) | Peer-addon rules (Core protocol-only; SeoContentAi compat) |
| [NEW_AGENT_HANDOFF.md](architecture/NEW_AGENT_HANDOFF.md) | Post-refactor operational handoff for new agents |
| [SEO_CONTENT_AI_COMPAT_SHELL.md](architecture/SEO_CONTENT_AI_COMPAT_SHELL.md) | SeoContentAi shell retained categories + consumers |
| [POST_REFACTOR_MANUAL_CHECKLIST.md](architecture/POST_REFACTOR_MANUAL_CHECKLIST.md) | USER browser / WP E2E checklist (refactor CLOSED) |
| [FINAL_LOCAL_RELEASE_MANIFEST.md](architecture/FINAL_LOCAL_RELEASE_MANIFEST.md) | One-time ZIP inventory + post-upload commands (no deploy) |
| [DATA_AND_RUNTIME_BOUNDARIES.md](architecture/DATA_AND_RUNTIME_BOUNDARIES.md) | DB / logging / addon boundaries |
| [ARCHITECTURE_FREEZE_V1.md](architecture/ARCHITECTURE_FREEZE_V1.md) | Frozen public contracts |
| [ARCHITECTURE_DECISIONS.md](architecture/ARCHITECTURE_DECISIONS.md) | ADR-001.. |
| [ARTICLE_EDITOR_SEPARATION_INVENTORY.md](architecture/ARTICLE_EDITOR_SEPARATION_INVENTORY.md) | Article Editor island / lock / separation inventory |
| [ARTICLE_EDITOR_SESSION_LOCK.md](architecture/ARTICLE_EDITOR_SESSION_LOCK.md) | Phase 1 server edit-session lock + document_version |
| [ARTICLE_EDITOR_MEDIA_SNAPSHOT.md](architecture/ARTICLE_EDITOR_MEDIA_SNAPSHOT.md) | Phase 2A Featured/Gallery media snapshot ownership |
| [ARTICLE_EDITOR_ANALYSIS_OWNERSHIP.md](architecture/ARTICLE_EDITOR_ANALYSIS_OWNERSHIP.md) | Phase 2B React immediate analysis + Laravel policy |
| [ARTICLE_EDITOR_WIDGETS_OWNERSHIP.md](architecture/ARTICLE_EDITOR_WIDGETS_OWNERSHIP.md) | Phase 2C FAQ/CTA ownership + insertion context |
| [ARTICLE_EDITOR_DOCUMENT_MODEL.md](architecture/ARTICLE_EDITOR_DOCUMENT_MODEL.md) | Phase 3 TipTap JSON DocumentModel + selectors |
| [ARTICLE_EDITOR_COMMAND_LAYER.md](architecture/ARTICLE_EDITOR_COMMAND_LAYER.md) | Phase 4 Editor Command Layer + document-changed signal |
| [ARTICLE_EDITOR_JSON_PERSISTENCE.md](architecture/ARTICLE_EDITOR_JSON_PERSISTENCE.md) | Phase 5A TipTap JSON persistence + derived HTML body |
| [ARTICLE_EDITOR_RUNTIME.md](architecture/ARTICLE_EDITOR_RUNTIME.md) | Phase 6A internal editor runtime (built-in modules, not public SDK) |
| [ARTICLE_EDITOR_LEGACY_CLEANUP.md](architecture/ARTICLE_EDITOR_LEGACY_CLEANUP.md) | Post-cutover dead-code cleanup inventory + deleted/kept paths |
| [SEOCONTENTAI_CUTOVER_INVENTORY.json](architecture/SEOCONTENTAI_CUTOVER_INVENTORY.json) | SeoContentAi compatibility-shell cutover counts + MOVE_* buckets |

## Modules

| Module | Doc |
|--------|-----|
| Agent Workspace | [AGENT_WORKSPACE.md](modules/AGENT_WORKSPACE.md) |
| Automation | [AUTOMATION.md](modules/AUTOMATION.md) |
| Content Projects | [CONTENT_PROJECTS.md](modules/CONTENT_PROJECTS.md) |
| Publishing | [PUBLISHING.md](modules/PUBLISHING.md) — includes System Date & Time + queue pending UX |
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
| aaPanel queue runtime | [AAPANEL_QUEUE_RUNTIME.md](operations/AAPANEL_QUEUE_RUNTIME.md) |
| Scheduler / Workers | [SCHEDULER_AND_WORKERS.md](operations/SCHEDULER_AND_WORKERS.md) |
| Testing | [TESTING.md](operations/TESTING.md) |
| Troubleshooting | [TROUBLESHOOTING.md](operations/TROUBLESHOOTING.md) |

## Archive

Historical handoffs, phase reports, and superseded MAP_* satellites: [archive/README.md](archive/README.md).  
Do not treat archive paths as architecture or runtime contracts.
