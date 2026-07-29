# Agent Execution Plans (Phase 2–3)

`AgentExecutionPlanService` — detect multi-intent (presentation).

`AgentPlanStepRunner` — sequential execution:

- createPlan(steps)
- runCurrentStep only
- cancelPlan
- present() → `can_run_all: false`

Phase 3: `DefaultAgentPlanningOrchestrator::savePlan` gọi `createPlan` sau user review — **không** chạy step; **không** Run All.

Rules:

- No autonomous Run All
- Step N locked until N-1 succeeded
- Output binding via `AgentPlanOutputBinder` allowlist
- Each step = own execution + own preview/confirmation
- Failure stops plan; no auto retry/skip
- AI-proposed plans must pass `AgentPlanValidator` before save

Table: `seo_agent_execution_plans` (omi_seo_ai).
