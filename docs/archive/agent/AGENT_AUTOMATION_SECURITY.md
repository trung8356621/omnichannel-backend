> Status: Historical
> Not canonical
> Archived on: 2026-08-01
> Superseded by: ../../modules/AGENT_WORKSPACE.md
> Purpose: implementation history only
# Agent Automation Security

Untrusted input: definitions, conditions, notification config, NL proposals.

Controls:

- Actor/site authorization; no browser owner/connection override
- Schema allowlists; schedule limits; condition path allowlists
- No arbitrary code / raw cron / nested automation / DAG
- Secret redaction; safe RuntimeLogger; definition size limits
- Rate/quota limits; permission recheck per run
- Cross-site rejection; approval token hashing; audit trail
- No hidden internal skills; no AI auto-approval/create
- Planning/knowledge content cannot alter automation policy

Freeze: Scheduler/Job never call CommandBus or business services directly.
