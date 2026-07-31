> Status: Historical
> Not canonical
> Archived on: 2026-08-01
> Superseded by: ../../modules/AGENT_WORKSPACE.md
> Purpose: implementation history only
# Agent Retention & Privacy

`agent:observability:prune` (+ `--dry-run`). Categories: metric events, traces/spans, aggregates (kept longer), evaluations, reviews, feedback.

Redaction: secrets, tokens, prompts, CoT, oversized strings. Exports via `AgentObservabilityExportService` sanitized.
