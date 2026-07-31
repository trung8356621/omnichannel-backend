> Status: Historical
> Not canonical
> Archived on: 2026-08-01
> Superseded by: ../../modules/AGENT_WORKSPACE.md
> Purpose: implementation history only
# Agent Workspace v1.0 Freeze

**Version:** `1.0.0` (`AgentWorkspaceVersion`)  
**Freeze date:** 2026-07-28

## Frozen contracts

1. Public routes: `/seo/{connection_hash}/agent` (+ admin redirect).
2. Application boundary: `AgentWorkspaceApplicationService` â€” no CommandBus.
3. Execution: Phase 2 orchestrator â†’ AgentGateway â†’ CommandBus â†’ Handler.
4. Planning: propose-only; never execute.
5. Knowledge / Automation / Observability / Pack contracts as Phase 4â€“7.
6. Pack manifest schema `1.0` / skill schema `1.0`.
7. Evaluation dataset schema (builtin installer v1.0.0).
8. Capability binding: Canonical Capability Registry authority.
9. Confirmation: never downgrade vs canonical; archive/destructive â‰¥ `confirm`.
10. Slash conflicts fail closed.
11. Site isolation: server-derived connection/site; browser cannot switch site.
12. Security invariants: no AI auto-confirm, no autonomous destructive, no internal MCP exposure.
13. Migrations: additive only in `omi_seo_ai` for Agent tables; no drop in v1 sweep.
14. Extension points: Extension SDK + declarative packs only.
15. Deprecation: report candidates; no silent renames of core keys.
16. Compatibility: additive v1.x OK; breaking â†’ v2.
17. Core capability/skill keys cannot be silently renamed.
18. Pack schema backward compatible within v1; optional fields need defaults.
19. Internal skills remain internal (`content_project.sync_items` not in catalog).
20. v1 limitations: see Final Handoff Â§ Known limitations.

## Semantic policy

- Additive changes allowed in v1.x.
- Breaking contract changes require v2.
- Confirmation cannot be downgraded.
- New optional fields require safe defaults.
