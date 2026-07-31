> Status: Historical
> Not canonical
> Archived on: 2026-08-01
> Superseded by: ../../modules/AGENT_WORKSPACE.md
> Purpose: implementation history only
# Agent Capability Diagnostics (Phase 1)

Panel debug cho manager/admin â€” inspect skill â†” capability mapping mÃ  **khÃ´ng** lá»™ credentials.

## Access

- UI button **Diagnostics** trÃªn `AgentWorkspacePage` (header)
- `SeoAccessControl::canAccessManagerFeatures()` â€” non-manager â†’ `diagnostics = []`, panel hidden
- Livewire: `loadDiagnostics()` â†’ `AgentCapabilityDiagnosticsService::list()`

## Panel contents

Má»—i row (má»i skill, ká»ƒ cáº£ hidden):

| Field | Source |
|-------|--------|
| `skill_key`, `slash_command`, `name` | `AgentSkillDefinition` |
| `capability` | Skill mapping |
| `availability` | `AgentSkillAvailabilityService` (status, reason, usable) |
| `scopes` | `required_scopes` |
| `provider_dependency` | `availability_policy.provider` |
| `extension_dependency` | `availability_policy.extension` |
| `confirmation_policy` | Skill policy |
| `last_execution` | Latest `SeoAgentExecution` (status, error_code, operation_ref) |
| `input_schema` | Skill input schema |
| `capability_schema` | `CanonicalCapabilityRegistry` â€” name, risk_level, confirmation, input_schema |

Service: `Services/AgentWorkspace/AgentCapabilityDiagnosticsService.php`

## Availability states (diagnostics)

| Status | Typical cause |
|--------|---------------|
| `available` | Skill usable |
| `permission_denied` | Missing scope / insufficient role |
| `not_configured` | Provider not configured (e.g. SERP) |
| `provider_unhealthy` | Provider health check failed |
| `extension_disabled` | Required extension off |
| `not_implemented` | Capability not in registry |
| `hidden` | `is_hidden` or feature flag off |
| `coming_soon` | Placeholder skill |
| `wrong_context` | Required ref missing (fail-closed) |
| `quota_exceeded` | Hourly execution quota |

UI consumes same DTO as palette disable reasons â€” **no duplicate branching** in Blade.

## Security notes

- No API keys, DB passwords, OAuth secrets in diagnostics payload
- Uses public refs in execution history only
- Read-only â€” no execute from diagnostics grid

## Related

- [AGENT_SKILLS.md](AGENT_SKILLS.md)
- [AGENT_WORKSPACE_SECURITY.md](AGENT_WORKSPACE_SECURITY.md)
- [CONTENT_PROJECT_AGENT_GATEWAY.md](CONTENT_PROJECT_AGENT_GATEWAY.md)
