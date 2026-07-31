> Status: Historical
> Not canonical
> Archived on: 2026-08-01
> Superseded by: ../../modules/AGENT_WORKSPACE.md
> Purpose: implementation history only
# Agent Confirmation (Phase 2)

## Token (`awconf_`)

Issued by `AgentConfirmationTokenService` after successful preview when policy âˆˆ {`preview`,`confirm`}.

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

Server reloads `input_payload` from execution â€” **ignores form re-submit**.

Rejects: expired, already_used, actor/site/conversation/input mismatch, stale gateway state, terminal execution.

## UI

Confirmation card (`execution_confirmation` only): Yes / No / Sá»­a. **`execution_preview` khÃ´ng hiá»‡n Yes** â€” skill read/`none` auto-execute sau preview.

Chat Yes path (`answerConversation` / composer): dÃ¹ng Livewire `pendingConfirmationToken` (plaintext tá»« preview response) â€” **khÃ´ng** gá»­i `confirmation_token_hash` tá»« DB. `submitComposer` gá»i `loadActiveDraftFromConversation` trÆ°á»›c khi route awaiting state.

No auto-confirm. AI cannot confirm.
