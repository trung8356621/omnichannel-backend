> Status: Historical
> Not canonical
> Archived on: 2026-08-01
> Superseded by: ../../modules/AGENT_WORKSPACE.md
> Purpose: implementation history only
# Agent Automation Approvals

Guarded write runs create `seo_agent_automation_approvals`.

Token prefix `awautoapr_` â€” **raw never persisted**; only `token_hash`.

Binds: actor, automation, run, definition version/hash, execution preview ref, site, expiry.

After approval â†’ Phase 2 `AgentExecutionOrchestrator` confirmation policy still applies.

AI cannot approve (`ai:` token / empty rejected).

Stale definition / actor mismatch / site mismatch / expired â†’ fail closed.
