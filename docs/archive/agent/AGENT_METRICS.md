> Status: Historical
> Not canonical
> Archived on: 2026-08-01
> Superseded by: ../../modules/AGENT_WORKSPACE.md
> Purpose: implementation history only
# Agent Metrics

`AgentMetricRecorder` + `AgentMetricAggregator`.

Allowlisted metric keys + dimension allowlist (reject high-cardinality). Fail-open on write errors. Daily idempotent aggregates kept longer than raw events.
