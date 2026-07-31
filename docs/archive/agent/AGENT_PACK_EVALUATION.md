> Status: Historical
> Not canonical
> Archived on: 2026-08-01
> Superseded by: ../../modules/AGENT_WORKSPACE.md
> Purpose: implementation history only
# Agent Pack Evaluation

Pack datasets namespaced `pack:{pack_key}:{dataset_key}`.

Cases cover: slash routing, NL selection, input mapping, invalid fields, unavailable capability, confirmation preservation, internal reject, automation policy, site isolation.

Offline eval never executes Phase 2 business actions (`AgentEvaluationRunner`).

Enable blocked on quality gate failure; no auto-enable.
