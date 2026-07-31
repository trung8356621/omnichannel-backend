> Status: Historical
> Not canonical
> Archived on: 2026-08-01
> Superseded by: ../modules/AGENT_WORKSPACE.md
> Purpose: implementation history only
<!--
Status: Historical
Not canonical
Superseded by: docs/AGENT_WORKSPACE.md + docs/AGENT_WORKSPACE_V1_FREEZE.md
-->
# Agent Workspace v1 â€” Capability Matrix

Source of truth: `AgentCapabilityInventory` + live audit via `php artisan agent:capabilities:audit`.

Machine JSON: `storage/app/agent-audits/capability-coverage.json` (after `--sync`).

## Modules covered in inventory

| Module | Priority focus |
|--------|----------------|
| content_project | P0 createâ†’archive/stop/resume/reads |
| keyword_intelligence | P1 |
| serp_intelligence | P1 |
| operations | P1 health/report |
| agent_knowledge / automation / observability / packs | P1 meta |
| internal | sync_items internal-only |

## Status rules

- **complete**: capability + skill (+ confirmation for write/destructive) wired
- **partial**: some gaps
- **missing**: capability and/or skill absent
- **internal**: intentionally not exposed
- **deprecated**: inventory flag only

Recompute with audit â€” do not trust this doc as live numbers.

See also: [GAP_REPORT](AGENT_WORKSPACE_V1_GAP_REPORT.md), [COVERAGE](AGENT_CAPABILITY_COVERAGE.md).
