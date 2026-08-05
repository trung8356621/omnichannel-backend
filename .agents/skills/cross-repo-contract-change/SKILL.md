---
name: cross-repo-contract-change
description: "Trigger for changing backend-plugin REST APIs, payloads, auth headers, article import/export, publishing, media sync, plugin updater, capabilities, or backward compatibility. Use when both Laravel backend and WordPress plugin may be affected. Do not use for backend-only UI changes with no external contract."
---

# Purpose

Ensure API/contract changes are checked across the Laravel backend and WordPress plugin.

# Trigger conditions

Use for REST route, API client, payload, token/auth, site sync, publishing, article editor sync, media, plugin updater, capability manifest, or backward compatibility changes.

# Required context

- Backend `AGENTS.md` and `docs/README.md`.
- Relevant backend canonical module/contract docs.
- Backend routes/controllers/services.
- Plugin `..\wp-seo-ai\AGENTS.md`, `omi-seo-ai-bridge.php`, `includes/class-rest-controller.php`, related integration classes.

# Workflow

1. Map the producer and consumer on both sides.
2. Verify current route/method/auth/payload/status behavior in code.
3. Preserve additive/backward-compatible behavior where possible.
4. If a breaking change is unavoidable, document required versioning and rollout; do not bump plugin version unless explicitly requested.
5. Update canonical docs only when behavior changes and user scope allows docs edits.

# Verification

- Use focused PHPUnit commands for backend changes.
- For plugin changes, inspect PHP syntax risk and package/build commands; run packaging only when explicitly requested.
- Check both git statuses.

# Safety and approval boundaries

- MUST NOT assume zero consumers.
- MUST NOT remove fallback headers/endpoints without proof.
- MUST NOT auto bump plugin version, package, deploy, commit, or push.
- MUST NOT read credentials or live tokens.

# Expected final report

- Backend and plugin files inspected.
- Contract compatibility decision.
- Files changed.
- Verification status.
