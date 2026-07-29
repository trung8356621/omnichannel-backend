# Agent Automation Notifications

Service: `DefaultAgentAutomationNotificationService`

Destinations: `agent_workspace`, `database_notification`, `email` (fallback workspace if email unavailable)

Policies: always, condition_matched, change_only, failure_only, digest, silent_success

Dedupe: normalized fingerprint + cooldown.

Quiet hours (timezone-aware): delay_notification | skip_non_critical | ignore

Delayed delivery retains original run reference.

Quota: notifications per hour (service config). Avoid alert loops on unchanged warnings.
