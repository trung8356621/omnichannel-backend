> Status: Historical
> Not canonical
> Archived on: 2026-08-01
> Superseded by: ../../modules/AGENT_WORKSPACE.md
> Purpose: implementation history only
# Agent Automation Schedules

Resolver: `DefaultAgentAutomationScheduleResolver`

Frequencies: `hourly`, `daily`, `weekly`, `monthly`, `custom_interval`

Fields: timezone, time (HH:MM), days_of_week, day_of_month, interval_minutes, start_at, end_at, quiet_hours

Minimum interval: 15 minutes (default).

Monthly overflow: `last_valid_day` (default) | `skip`

Raw cron: **rejected**.

Returns: normalized trigger, next_run_at, preview 3 occurrences, warnings.

All calculations timezone-aware (IANA). No server timezone assumption.
