# Agent Prompt Security

Orchestration-level defenses for Phase 3 planning.

## Markers / sanitizers

- `AgentUntrustedContentMarker` — wrap imported/injection-like text as data.
- `AgentPlanningInputSanitizer` — redact secret keys before prompt/persist.
- `AgentPlanningOutputSanitizer` — strip `auto_execute`, `auto_confirm`, `run_all`, `command_class`, site overrides, credentials.

## Server authority

Model cannot: bypass confirmation, pick internal tools, invent tools, change site/actor, call Gateway/CommandBus, auto-run plans.

Validator + Phase 2 execution boundary are decisive — not system prompt alone.

## Persistence

No raw prompt by default; `prompt_fingerprint` only. Redact `structured_response` before save.

## Phase 4 knowledge

Grounded knowledge remains DATA (`UNTRUSTED_DATA`). Citations are server handles only. See `AGENT_KNOWLEDGE_SECURITY.md`.
