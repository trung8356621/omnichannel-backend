<?php

declare(strict_types=1);

/**
 * Agent Workspace Phase 5 — Scheduled Workflows & Proactive Monitoring
 */

## 1. Inspect findings

- Scheduler: `SeoContentAiServiceProvider` `booted` → `Schedule::command(...)->everyMinute()->withoutOverlapping()`.
- Existing Content Project / BusinessHook Automation is separate; Phase 5 lives under `Services/AgentWorkspace/Automation/`.
- Jobs: `ShouldQueue` under `Jobs/`; bootstrap SEO DB via `SeoDatabaseConnectionService`.
- Locks: Laravel `Cache::lock` (same pattern as confirmation tokens).
- Notifications: RuntimeLogger + cache dedupe/cooldown/quiet hours (agent_workspace destination primary).
- Timezone: schedule resolver uses explicit IANA timezone on trigger — no server-TZ assumption.
- Phase 2 API: `AgentExecutionOrchestrator::{preview,execute,confirm,...}`.
- Phase 3 API: `AgentPlanningOrchestrator::plan` (proposal only).
- Skills: `AgentSkillRegistry` + `BuiltinSkillCatalog` (+ `AutomationSkills`).
- Quota: `AgentAutomationQuotaService` (config/service, not UI constants).
- Hash IDs: `aauto_`, `aarun_`, `aaapr_` ULID prefixes.
- Audit/log: `RuntimeLogger` on HTTP/notification paths.

## 2. Architecture

```
Schedule everyMinute
  → agent:automations:dispatch-due (AgentAutomationDispatcher)
    → claim occurrence (idempotent)
    → RunAgentAutomationJob
      → AgentAutomationRunner
        → read_skill / execution_preview → AgentExecutionOrchestrator → AgentGateway → CommandBus
        → planning → AgentPlanningOrchestrator (proposal only)
        → condition → AgentAutomationConditionEvaluator
        → notification → AgentAutomationNotificationService
```

AI never persists/activates automations. Explicit save required.

## 3. Types

- `scheduled_report` — read skills, recurring notify
- `condition_watch` — read + deterministic condition
- `planning_workflow` — Phase 3 proposal only
- `guarded_action` — Phase 2 preview → waiting_for_approval

Default: `auto_execute_safe_writes = false`

## 4. Definition / schema

Validated by `AgentAutomationDefinitionValidator` (auth, type, TZ, schedule min interval, skills, capabilities, steps≤5 sequential, condition allowlist, notification, quota, no auto-confirm, no raw cron, no cross-site).

## 5. Scheduler integration

- Command: `agent:automations:dispatch-due`
- Schedule name: `seo-content-ai:agent-automations-dispatch-due`
- everyMinute + withoutOverlapping
- Command does **not** execute workflow

## 6. Run lifecycle

States: pending → queued → running → waiting_for_approval | succeeded | no_change | failed | cancelled | skipped | expired  
`AgentAutomationRunStateMachine` — no arbitrary mutation. Terminal not rerun in place; retry increments attempt, keeps occurrence.

## 7. Condition engine

Operators allowlisted; paths allowlisted from prior step schema; typed comparisons; reject PHP/JS/SQL/regex/code.

## 8. Read / planning / write

- Read: execute via Phase 2 when `confirmation_policy=none`
- Planning: Phase 3 only
- Write: preview only → approval record (hash, not raw token) → Phase 2 confirm still independent

## 9. Approval

Token `awautoapr_*`, hash stored. Binds actor/automation/run/definition/site/expiry. AI cannot approve.

## 10. Notifications

Destinations: agent_workspace, database_notification, email (fallback to workspace if email unavailable).  
Policies: always, condition_matched, change_only, failure_only, digest, silent_success.  
Dedupe fingerprint + cooldown. Quiet hours: delay / skip_non_critical / ignore.

## 11. Quiet hours

Timezone-aware on automation trigger; delayed notification retains run ref.

## 12. Quota / concurrency / idempotency

Occurrence key: `automation:{id}:{scheduled_at_utc}`  
Lock: `automation:{id}` owner token + TTL  
Quotas: active/site, /user, runs hour/day, concurrent, notifications/hour, planning, reads.

## 13. Retry / control

Retryable: provider_error, queue_error, rate_limited, transient_internal_error.  
Pause/resume/delete/run-now via Orchestrator. Catch-up default `skip_missed`. Soft delete keeps history.

## 14. UI

Tabs: Chat | Knowledge | Automations | Diagnostics  
Panel: list, history, run now, pause/resume, delete, manager diagnostics.  
Slash skills through registry → ApplicationService → Orchestrator.

## 15. Files created

See migration + `Services/AgentWorkspace/Automation/**`, Job, Command, Skills, tests, docs.

## 16. Files modified

- `BuiltinSkillCatalog`, `AgentWorkspaceApplicationService`, `AgentWorkspacePage`, blade, `SeoContentAiServiceProvider`, `AgentPlanningResponse`, SUPER_MAP / AGENT_WORKSPACE docs.

## 17. Migration

`2026_07_28_240000_phase5_agent_automations.php` — 4 tables on `omi_seo_ai`.

## 18. Tests / results

Pure PHPUnit filters: `AgentAutomation`, `AgentAutomationSchedule`, `AgentAutomationCondition`, `AgentAutomationApproval`, `AgentAutomationSecurity`.  
Local not executed (remote-first).

## 19. Freeze verification

| Check | Result |
|---|---|
| CommandBus modified | No |
| Existing handlers modified | No |
| AgentGateway modified | No |
| ExecutionOrchestrator bypassed | No |
| PlanningOrchestrator bypassed | No |
| Knowledge rewritten | No |
| Scheduler executes business | No |
| Job executes business directly | No |
| Cross-site automation | No (fail closed) |
| AI auto-creates | No |
| AI auto-confirms | No |
| Autonomous destructive | No |

## 20. Known limitations

- Email destination stub (fallback workspace); DB Filament notification wiring minimal (RuntimeLogger + cache).
- NL `automation_proposal` type allowed in Phase 3 schema; full NL→draft assembler polish deferred.
- Wizard is slash-form driven (no drag-drop builder) — as specified.
- Permission recheck uses owner User existence + site snapshot; full SeoAccessControl re-eval on cron may need Phase 6 hardening when auth context absent.

## 21. Phase 6 candidates (DO NOT IMPLEMENT)

- Rich digest email digests
- Cross-conversation automation inbox UI
- Safe-write auto-exec with `automation_safe` capability metadata
- Multi-step DAG / Run All
- Vector/time-series condition baselines
- Manager impersonation audit for resume-as-owner
