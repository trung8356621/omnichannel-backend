# Agent Metrics

`AgentMetricRecorder` + `AgentMetricAggregator`.

Allowlisted metric keys + dimension allowlist (reject high-cardinality). Fail-open on write errors. Daily idempotent aggregates kept longer than raw events.
