> Status: Historical
> Not canonical
> Archived on: 2026-08-01
> Superseded by: ../../modules/AGENT_WORKSPACE.md
> Purpose: implementation history only
# Agent Automations (Phase 5)

Scheduled Agent Workspace workflows. **Not** Content Project BusinessHook Automation.

## Entry

- UI tab Automations on `/seo/{hash}/agent`
- Slash: `/automations`, `/create-automation`, `/automation-status`, `/run-automation`, `/pause-automation`, `/resume-automation`, `/delete-automation`, `/automation-history`
- Orchestrator: `AgentAutomationOrchestrator`
- Runner: `AgentAutomationRunner` â†’ Phase 2 / Phase 3 only

## Types

`scheduled_report` | `condition_watch` | `planning_workflow` | `guarded_action`

## Dispatch

`php artisan agent:automations:dispatch-due` (everyMinute, withoutOverlapping)

Job `RunAgentAutomationJob` only calls Runner.

## Safety

- AI cannot create/activate/approve
- Explicit save after preview
- No auto-confirm destructive
- `auto_execute_safe_writes=false`
- Cross-site fail closed
- Permission recheck each run; no admin fallback

See also: `AGENT_AUTOMATION_SCHEDULES.md`, `AGENT_AUTOMATION_CONDITIONS.md`, `AGENT_AUTOMATION_APPROVALS.md`, `AGENT_AUTOMATION_NOTIFICATIONS.md`, `AGENT_AUTOMATION_SECURITY.md`, `archive/AGENT_WORKSPACE_PHASE_5_HANDOFF.md` *(historical)*.
