# Agent Confirmation (Phase 2)

## Token (`awconf_`)

Issued by `AgentConfirmationTokenService` after successful preview when policy ∈ {`preview`,`confirm`}.

Bind:

- actor_id, tenant_ref, site_ref
- conversation_id, execution_ref
- skill_key, capability_key
- input_hash (canonical normalized input)
- optional gateway_state
- expiry + nonce

**DB stores only `confirmation_token_hash`.** Raw token never logged.

## Confirm request

Browser sends: `execution_ref` + `confirmation_token`.

Server reloads `input_payload` from execution — **ignores form re-submit**.

Rejects: expired, already_used, actor/site/conversation/input mismatch, stale gateway state, terminal execution.

## UI

Confirmation card: action, site, project/workspace, item count, warnings, effects, Cancel, Confirm (loading + double-submit guard).

No auto-confirm. AI cannot confirm.
