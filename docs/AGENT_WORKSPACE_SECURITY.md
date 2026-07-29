# Agent Workspace Security (Phase 1–7)

Security boundary cho Filament Agent UI — reuse gateway policies, không bypass CommandBus.

Phase 5 automations: [AGENT_AUTOMATION_SECURITY.md](AGENT_AUTOMATION_SECURITY.md) — definitions untrusted; AI cannot create/activate/approve; scheduler/job never hit CommandBus; approval tokens hashed (`awautoapr_`); permission recheck per run; no admin fallback; no autonomous destructive writes.

Phase 6 observability: [AGENT_GOVERNANCE.md](AGENT_GOVERNANCE.md), [AGENT_RETENTION_PRIVACY.md](AGENT_RETENTION_PRIVACY.md) — side-channel only; allowlisted events/metrics; redaction; policy violation detector; evaluation never executes business; no auto-promotion / no autonomous remediation.

Phase 7 packs: [AGENT_PACK_SECURITY.md](AGENT_PACK_SECURITY.md) — declarative only; no executable upload; Canonical Capability Registry authority; confirmation never downgraded; imported packs disabled + unverified until gate + explicit enable; no AI auto-enable.

v1.0 freeze: [AGENT_WORKSPACE_V1_FREEZE.md](AGENT_WORKSPACE_V1_FREEZE.md) — no new Agent frameworks; coverage audit + doctor are non-destructive.

## Site isolation

- Context build: `AgentWorkspaceContextService::fromAuthenticatedUser()`
- Bắt buộc `site_id` > 0 + `SeoAccessControl::canAccessSite($siteId)`
- Public refs (`project_ref`, `article_ref`, …) decode + **reject cross-site** fail-closed
- Conversations scoped: `tenant_id`, `site_id`, `created_by`
- Execution ops: site + actor mismatch fail-closed (`agent.execution.site_mismatch` / `actor_mismatch`)

## Fail-closed context

| Check | Error / behavior |
|-------|------------------|
| Missing site | `agent.context.site_required` |
| Site denied | `agent.context.site_denied` |
| Site not found | `agent.context.site_not_found` |
| Cross-site ref | `InvalidArgumentException` / reject |
| `fail_closed_context` policy | Skill status `wrong_context` — không usable |

Provider SERP: `providers.serp` false → skill `not_configured` (vd. `/collect-serp`).

## Confirmation (Phase 2)

Hai lớp:

1. **Agent Workspace token (`awconf_`)** — `AgentConfirmationTokenService`: bind actor/site/conversation/execution/skill/capability/input_hash; **chỉ hash** trên `seo_agent_executions`; one-time cache; confirm chỉ gửi `execution_ref` + token (server reload canonical input).
2. **Gateway token (`cpprev_`)** — vẫn dùng bên trong `ContentProjectAgentGateway` khi capability `confirmation_requirement`.

Skill `confirmation_policy`:

- `none` — read/meta; execute sau preview nếu executable
- `preview` / `confirm` — awaiting_confirmation + UI Confirm/Cancel

Reject: expired, already_used, actor/site/conversation/input mismatch, stale, terminal.

**Không** auto-confirm. **Không** AI confirm. **Không** fake UI token.

Error categories UI: `AgentErrorCategory` (+ gateway `AgentErrorCodes`).

**Không** auto-execute write từ NL (`AgentIntentRouter`).

## Popup launcher boundary

Popup `global-ai-chat` **không** gọi `AgentWorkspaceApplicationService` / Gateway / CommandBus.
Ngôi sao AI chỉ deep-link (`AgentWorkspaceDeepLink::forCurrentRequest` + `location.assign`).
Deep link params chỉ dựng context — không auto-run skill.

## No credential logging

- HTTP path: `RuntimeLogger` / `web_app` channel only
- Diagnostics **không** expose credentials, API keys, OAuth tokens
- Gateway logs redact sensitive input per [CONTENT_PROJECT_AGENT_SECURITY.md](CONTENT_PROJECT_AGENT_SECURITY.md)

## Scopes & roles

Scopes gán từ `SeoAccessControl` trong context service:

| Scope | When |
|-------|------|
| `content-project:read` | Base |
| `content-project:write` | Mutate projects |
| `content-project:generate` | Generate / rerun |
| `content-project:review` | Review / approve |
| `content-project:schedule` | Schedule |
| `content-project:publish` | Publish queue |
| `content-project:archive` | Archive |

Wildcard: `*` hoặc `content-project:*` bypass per-scope check trong availability UI; **Gateway vẫn enforce**.

Role rank: admin/owner > manager > planner > content_manager/staff (`AgentSkillAvailabilityService::roleAllows`).

Page access: `AgentWorkspacePage::canAccess()` — manager OR mutate OR content features.

Diagnostics panel: **manager/admin only** (`canAccessManagerFeatures`).

## Conversation deletion ≠ business ops

`AgentConversationService`:

- Conversation / messages / executions = **presentation audit**
- `deleteEmpty()` xóa conversation rỗng + linked executions — **không** xóa Content Project operations, CommandBus audits, WP data
- Archive conversation chỉ đổi status presentation

Business archive/destroy: skill `/archive-project` qua gateway với preview bắt buộc — xem [CONTENT_PROJECT_AGENT_WORKFLOWS.md](CONTENT_PROJECT_AGENT_WORKFLOWS.md).

## Quotas

`AgentWorkspaceQuotaService` — conversations/hour, skill executions/hour → availability `quota_exceeded`.

## Phase 3 planning security

- AI output untrusted: sanitize + validate before UI.
- Strip `auto_execute` / `auto_confirm` / `run_all` / command classes.
- Untrusted content marker for injection-like text.
- No raw prompt persistence; fingerprint only.
- Details: [AGENT_PROMPT_SECURITY.md](AGENT_PROMPT_SECURITY.md).

## Phase 4 knowledge security

- Knowledge/memory không ghi business tables.
- Cross-site fail closed.
- No autonomous memory persist.
- Details: [AGENT_KNOWLEDGE_SECURITY.md](AGENT_KNOWLEDGE_SECURITY.md), [AGENT_WORKSPACE_PHASE_4_HANDOFF.md](AGENT_WORKSPACE_PHASE_4_HANDOFF.md).

## Related

- [AGENT_WORKSPACE.md](AGENT_WORKSPACE.md)
- [AGENT_WORKSPACE_PHASE_3_HANDOFF.md](AGENT_WORKSPACE_PHASE_3_HANDOFF.md)
- [AGENT_WORKSPACE_PHASE_4_HANDOFF.md](AGENT_WORKSPACE_PHASE_4_HANDOFF.md)
- [CONTENT_PROJECT_AGENT_SECURITY.md](CONTENT_PROJECT_AGENT_SECURITY.md)
- [EXTENSION_SECURITY_BOUNDARY.md](EXTENSION_SECURITY_BOUNDARY.md)
