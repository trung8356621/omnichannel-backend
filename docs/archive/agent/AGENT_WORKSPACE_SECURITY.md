> Status: Historical
> Not canonical
> Archived on: 2026-08-01
> Superseded by: ../../modules/AGENT_WORKSPACE.md
> Purpose: implementation history only
# Agent Workspace Security (Phase 1â€“7)

Security boundary cho Filament Agent UI â€” reuse gateway policies, khÃ´ng bypass CommandBus.

Phase 5 automations: [AGENT_AUTOMATION_SECURITY.md](AGENT_AUTOMATION_SECURITY.md) â€” definitions untrusted; AI cannot create/activate/approve; scheduler/job never hit CommandBus; approval tokens hashed (`awautoapr_`); permission recheck per run; no admin fallback; no autonomous destructive writes.

Phase 6 observability: [AGENT_GOVERNANCE.md](AGENT_GOVERNANCE.md), [AGENT_RETENTION_PRIVACY.md](AGENT_RETENTION_PRIVACY.md) â€” side-channel only; allowlisted events/metrics; redaction; policy violation detector; evaluation never executes business; no auto-promotion / no autonomous remediation.

Phase 7 packs: [AGENT_PACK_SECURITY.md](AGENT_PACK_SECURITY.md) â€” declarative only; no executable upload; Canonical Capability Registry authority; confirmation never downgraded; imported packs disabled + unverified until gate + explicit enable; no AI auto-enable.

v1.0 freeze: [AGENT_WORKSPACE_V1_FREEZE.md](AGENT_WORKSPACE_V1_FREEZE.md) â€” no new Agent frameworks; coverage audit + doctor are non-destructive.

## Site isolation

- Context build: `AgentWorkspaceContextService::fromAuthenticatedUser()`
- Báº¯t buá»™c `site_id` > 0 + `SeoAccessControl::canAccessSite($siteId)`
- Public refs (`project_ref`, `article_ref`, â€¦) decode + **reject cross-site** fail-closed
- Conversations scoped: `tenant_id`, `site_id`, `created_by`
- Execution ops: site + actor mismatch fail-closed (`agent.execution.site_mismatch` / `actor_mismatch`)

## Fail-closed context

| Check | Error / behavior |
|-------|------------------|
| Missing site | `agent.context.site_required` |
| Site denied | `agent.context.site_denied` |
| Site not found | `agent.context.site_not_found` |
| Cross-site ref | `InvalidArgumentException` / reject |
| `fail_closed_context` policy | Skill status `wrong_context` â€” khÃ´ng usable |

Provider SERP: `providers.serp` false â†’ skill `not_configured` (vd. `/collect-serp`).

## Confirmation (Phase 2)

Hai lá»›p:

1. **Agent Workspace token (`awconf_`)** â€” `AgentConfirmationTokenService`: bind actor/site/conversation/execution/skill/capability/input_hash; **chá»‰ hash** trÃªn `seo_agent_executions`; one-time cache; confirm chá»‰ gá»­i `execution_ref` + token (server reload canonical input).
2. **Gateway token (`cpprev_`)** â€” váº«n dÃ¹ng bÃªn trong `ContentProjectAgentGateway` khi capability `confirmation_requirement`.

Skill `confirmation_policy`:

- `none` â€” read/meta; execute sau preview náº¿u executable
- `preview` / `confirm` â€” awaiting_confirmation + UI Confirm/Cancel

Reject: expired, already_used, actor/site/conversation/input mismatch, stale, terminal.

**KhÃ´ng** auto-confirm. **KhÃ´ng** AI confirm. **KhÃ´ng** fake UI token.

Error categories UI: `AgentErrorCategory` (+ gateway `AgentErrorCodes`).

**KhÃ´ng** auto-execute write tá»« NL (`AgentIntentRouter`).

## Popup launcher boundary

Popup `global-ai-chat` **khÃ´ng** gá»i `AgentWorkspaceApplicationService` / Gateway / CommandBus.
NgÃ´i sao AI chá»‰ deep-link (`AgentWorkspaceDeepLink::forCurrentRequest` + `location.assign`).
Deep link params chá»‰ dá»±ng context â€” khÃ´ng auto-run skill.

## No credential logging

- HTTP path: `RuntimeLogger` / `web_app` channel only
- Diagnostics **khÃ´ng** expose credentials, API keys, OAuth tokens
- Gateway logs redact sensitive input per [CONTENT_PROJECT_AGENT_SECURITY.md](CONTENT_PROJECT_AGENT_SECURITY.md)

## Scopes & roles

Scopes gÃ¡n tá»« `SeoAccessControl` trong context service:

| Scope | When |
|-------|------|
| `content-project:read` | Base |
| `content-project:write` | Mutate projects |
| `content-project:generate` | Generate / rerun |
| `content-project:review` | Review / approve |
| `content-project:schedule` | Schedule |
| `content-project:publish` | Publish queue |
| `content-project:archive` | Archive |

Wildcard: `*` hoáº·c `content-project:*` bypass per-scope check trong availability UI; **Gateway váº«n enforce**.

Role rank: admin/owner > manager > planner > content_manager/staff (`AgentSkillAvailabilityService::roleAllows`).

Page access: `AgentWorkspacePage::canAccess()` â€” manager OR mutate OR content features.

Diagnostics panel: **manager/admin only** (`canAccessManagerFeatures`).

## Conversation deletion â‰  business ops

`AgentConversationService`:

- Conversation / messages / executions = **presentation audit**
- `deleteEmpty()` xÃ³a conversation rá»—ng + linked executions â€” **khÃ´ng** xÃ³a Content Project operations, CommandBus audits, WP data
- Archive conversation chá»‰ Ä‘á»•i status presentation

Business archive/destroy: skill `/archive-project` qua gateway vá»›i preview báº¯t buá»™c â€” xem [CONTENT_PROJECT_AGENT_WORKFLOWS.md](CONTENT_PROJECT_AGENT_WORKFLOWS.md).

## Quotas

`AgentWorkspaceQuotaService` â€” conversations/hour, skill executions/hour â†’ availability `quota_exceeded`.

## Phase 3 planning security

- AI output untrusted: sanitize + validate before UI.
- Strip `auto_execute` / `auto_confirm` / `run_all` / command classes.
- Untrusted content marker for injection-like text.
- No raw prompt persistence; fingerprint only.
- Details: [AGENT_PROMPT_SECURITY.md](AGENT_PROMPT_SECURITY.md).

## Phase 4 knowledge security

- Knowledge/memory khÃ´ng ghi business tables.
- Cross-site fail closed.
- No autonomous memory persist.
- Details: [AGENT_KNOWLEDGE_SECURITY.md](AGENT_KNOWLEDGE_SECURITY.md), [AGENT_WORKSPACE_PHASE_4_HANDOFF.md](archive/AGENT_WORKSPACE_PHASE_4_HANDOFF.md) *(historical)*.

## Related

- [AGENT_WORKSPACE.md](AGENT_WORKSPACE.md)
- [AGENT_WORKSPACE_PHASE_3_HANDOFF.md](archive/AGENT_WORKSPACE_PHASE_3_HANDOFF.md) *(historical)*
- [AGENT_WORKSPACE_PHASE_4_HANDOFF.md](archive/AGENT_WORKSPACE_PHASE_4_HANDOFF.md) *(historical)*
- [CONTENT_PROJECT_AGENT_SECURITY.md](CONTENT_PROJECT_AGENT_SECURITY.md)
- [EXTENSION_SECURITY_BOUNDARY.md](EXTENSION_SECURITY_BOUNDARY.md)
