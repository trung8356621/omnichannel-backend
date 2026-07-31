> Status: Historical
> Not canonical
> Archived on: 2026-08-01
> Superseded by: ../../modules/AUTOMATION.md
> Purpose: implementation history only
# PromptResult Boundary

## Locked

| Concern | Owner |
|---|---|
| Execution/audit artifact | Hook runtime may create opaque audit fingerprint/log + optional `prompt_result_id` in provider meta |
| Attach to Article/Task/Project | **Business Action** `prompt_result.attach` / `PromptResultAttachService` â€” **not** Hook Engine |

## Flow (Phase 5C)

```text
Caller / orchestrator
  â†’ Hook execution (AI only)
  â†’ PromptResult audit artifact (PromptRunner may persist PromptResult row)
  â†’ Business Action prompt_result.attach  OR  PromptResultAttachService (same domain boundary)
```

Forbidden:

```text
PromptHookRuntimeEngine â†’ attach Article/Task/Project
```

## Action contract

| Input | Notes |
|---|---|
| `prompt_result_id` | required |
| `target_type` | allowlist: `article` \| `project_task` \| `project` |
| `target_id` | required |
| `site_id` | required; wrong-context fail |
| `relation` / `purpose` | optional |

| Output | |
|---|---|
| `attached` | bool |
| `deduplicated` | bool (idempotent) |
| `prompt_result_id` | int |
| `target_type` / `target_id` | echoed |

No WordPress sync. No Eloquent model input. No unrelated events.

## Phase 1 UI

`PromptHookExecutionService` attaches via `PromptResultAttachService` after AI (orchestrator role) â€” preserves title/meta UI BC. Runtime Engine path unchanged (no attach).

## Alias

Catalog historically had `project.prompt_result.attach` (CATALOG_ONLY). Canonical key is **`prompt_result.attach`**.
