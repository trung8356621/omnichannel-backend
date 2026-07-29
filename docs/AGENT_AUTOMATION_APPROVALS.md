# Agent Automation Approvals

Guarded write runs create `seo_agent_automation_approvals`.

Token prefix `awautoapr_` — **raw never persisted**; only `token_hash`.

Binds: actor, automation, run, definition version/hash, execution preview ref, site, expiry.

After approval → Phase 2 `AgentExecutionOrchestrator` confirmation policy still applies.

AI cannot approve (`ai:` token / empty rejected).

Stale definition / actor mismatch / site mismatch / expired → fail closed.
