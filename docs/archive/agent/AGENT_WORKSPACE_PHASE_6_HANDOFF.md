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
# Agent Workspace Phase 6 â€” Observability, Evaluation & Governance

## 1. Inspect findings

- Logging: `RuntimeLogger` (web_app) â€” reuse for security fallback.
- Metrics pattern: `ContentProjectOpsMetrics` fail-open upsert â€” mirrored for Agent aggregates.
- Redaction: `SensitivePayloadRedactor` wrapped by `AgentObservabilityRedactor`.
- Event bus style: `ExtensionEventBus` try/catch per listener.
- IDs: planning `aplanreq_`, execution `public_ref`, automation `aauto_`/`aarun_`; Phase 6 adds `atrace_`/`aspan_`/`amet_`/`aeval_`/`arev_`/`afb_`.
- Diagnostics: manager/`agent:diagnostics` scopes via `AgentGovernancePolicyService`.
- Model usage: `ProviderAgentModelGateway` returns `usage` + `latency_ms`.
- Retention: Automation cleanup command pattern â†’ `agent:observability:prune`.
- No duplicate global APM â€” Agent-scoped side-channel only.

## 2. Architecture

```
Decorators (Planning/Execution/Knowledge/Automation)
  â†’ AgentTraceService + AgentMetricRecorder + PolicyDetector
  â†’ EventBus (allowlisted) + RuntimeLogger fallback (security)

Offline: agent:evaluate â†’ AgentEvaluationRunner (NO Phase 2 / CommandBus)
Aggregate: agent:metrics:aggregate (idempotent)
Prune: agent:observability:prune
```

## 3â€“13. Models

Trace/spans/events/aggregates; evaluation datasets/cases/runs/results; reviews; feedback.  
Evaluators deterministic; quality gates no auto-promotion; governance does not override capability confirmation.

## 14. UI

Manager **Operations** tab: overview, metrics, reviews, evaluations, gates, policy counts.

## 15â€“17. Files / migration

`Services/AgentWorkspace/Observability/**`, decorators, jobs/commands, skills, tests.  
Migration `2026_07_28_250000_phase6_agent_observability.php`.

## 18. Commands/jobs

- `agent:evaluate`
- `agent:metrics:aggregate`
- `agent:observability:prune`
- Jobs: `RunAgentEvaluationJob`, `AggregateAgentMetricsJob`, `ApplyAgentRetentionJob`

## 19. Tests

Filters: AgentObservability, AgentEvaluation, AgentGovernance, AgentTrace, AgentMetric, AgentReview.

## 20. Freeze verification

| Check | Result |
|---|---|
| CommandBus modified | No |
| Handlers modified | No |
| AgentGateway behavior modified | No |
| Execution/Planning/Knowledge/Automation behavior | Decorated only (same returns) |
| Business direct writes | No |
| Evaluation executes business | No |
| Autonomous remediation | No |
| Auto model/prompt promotion | No |

## 21. Known limitations

- Offline eval fixture-driven by default (no live model required).
- Cost estimate requires pricing registry config (else unknown).
- Feedback thumbs UI on message cards minimal (service ready; slash/ops primary).
- Alerts = review + RuntimeLogger; no external PagerDuty.
- **Gap closed in Phase 7:** builtin dataset installer (`agent:evaluations:install-builtin`) so `core-routing` exists for `agent:evaluate`.

## 22. Phase 7 candidates (IMPLEMENTED in Phase 7 â€” see PHASE_7 handoff)

- Builtin evaluation dataset installer
- Extensible skill packs / Skill Studio

## 23. Phase 8 candidates (DO NOT IMPLEMENT here)

- Live model judge evaluator
- External APM exporters
- Auto remediation / auto pause beyond Phase 5 fail-closed
- Prompt/model auto-promotion pipelines
