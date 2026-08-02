# ADR — Article Editor Runtime Public SDK Readiness

> Date: 2026-08-03  
> Status: Accepted (updated Phase 6C.4)  
> Task: `article-editor-runtime-completion-phase-6c-4`

## Context

Phases 6A–6C completed an internal article editor runtime: navigation, panels, media picker, AI chat cutover, scoped host hooks, and a shell compatibility bridge.

Extension SDK v1.0 already covers PHP publishers / AI / pipelines — a different boundary.

## Decision

**Ready for internal stability testing.**

**Not ready** for a public Article Editor Extension SDK.

## Why not public yet

1. Compatibility CustomEvents still required at shell boundary (documented deprecation registry).
2. Host API is internal contract version 1 — not a versioned public capability grant.
3. No sandbox, discovery, or third-party JS registration.
4. Publishing / AI history remain shell-owned; runtime surface is built-in modules only.
5. Remaining mid-rollout producers still dispatch legacy browser events.

## Why internal stability is acceptable

1. React owns dock navigation, health badges, Featured/Gallery, Links/FAQ/CTA, AI chat UI.
2. Modules use scoped hooks; ModuleHost removed.
3. Document mutations go through command layer; media via snapshot APIs.
4. Shell bridge is the sole cross-boundary event adapter (no business/TipTap logic).

## Next gates for public SDK (future ADR)

- Zero deprecated shell events with external consumers
- Stable capability-scoped public host surface + versioning policy
- Security sandbox / discovery separate from Extension SDK v1.0
- Explicit do-not-merge with PHP Extension SDK without design review

## Consequences

- Keep runtime **internal / build-time built-ins only**.
- Do not expose npm package or dynamic third-party registration.
- Document clearly in `EXTENSION_SDK.md` that editor runtime ≠ SDK v1.0 plugins.
