> Status: Historical
> Not canonical
> Archived on: 2026-08-01
> Superseded by: ../../modules/AGENT_WORKSPACE.md
> Purpose: implementation history only
# Agent Automation Conditions

Evaluator: `DefaultAgentAutomationConditionEvaluator`

Mode: `all` | `any`

Operators: equals, not_equals, gt/gte/lt/lte, contains, not_contains, in, not_in, is_empty, is_not_empty, changed, increased, decreased, older_than_minutes

Paths allowlisted from previous step output schema.

Reject: PHP, JS, SQL, regex, class/method, dynamic code, template eval.

Typed comparisons â€” incompatible types error (no silent coerce).

Baseline stored in `seo_agent_automation_states` (fingerprint).
