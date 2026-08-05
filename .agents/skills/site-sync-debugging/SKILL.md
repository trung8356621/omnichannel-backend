---
name: site-sync-debugging
description: "Trigger for Site Sync, WordPress bridge sync, snapshot, delta, outbox, reconcile, sync queue, bridge capability, taxonomy sync, or stale sync debugging. Use for backend-first investigation with plugin contract checks. Do not use for unrelated publishing-only bugs."
---

# Purpose

Investigate Site Sync and WordPress bridge sync issues without breaking the Laravel/plugin contract.

# Trigger conditions

Use for requests mentioning site sync, WP bridge, snapshot, delta, outbox, sync v2, reconcile, capabilities, taxonomy sync, bridge too old, or `seo-wp-bridge`.

# Required context

- Backend docs: `docs/modules/SITE_SYNC.md`, `docs/modules/WORDPRESS_BRIDGE.md`, `docs/contracts/API_AND_AUTHORIZATION.md`, `docs/contracts/QUEUE_SCHEDULER_AND_IDEMPOTENCY.md`.
- Backend routes: `app/Addons/SeoContentAi/routes/api.php`.
- Backend controllers/services for the affected endpoint.
- Plugin files as needed: `..\wp-seo-ai\includes\class-rest-controller.php`, `class-site-sync-v2-provider.php`, `class-site-sync-outbox.php`, `docs/site_sync_v1_contract.json`.

# Workflow

1. Identify direction: Laravel pulls from WP, WP pushes delta to Laravel, or Laravel writes to WP.
2. Verify route, auth token type, payload shape, idempotency key, and queue/scheduler ownership.
3. Check both repos before changing any contract.
4. Preserve legacy push ownership rules; V2 writer must not dual-apply legacy enrichment.
5. Check worker/scheduler requirements before declaring code dead or stuck.

# Verification

- Prefer source-level verification first.
- Recommend focused PHPUnit filters from `docs/operations/TESTING.md` when code changes occur.
- Do not run migrations or deploy.

# Safety and approval boundaries

- MUST NOT log or expose tokens.
- MUST NOT read plugin option values from live sites.
- MUST NOT remove compatibility endpoints until zero callers are proven in both repos.

# Expected final report

- Endpoint/flow inspected.
- Backend files and plugin files touched or checked.
- Contract risk.
- Verification command run or recommended.
