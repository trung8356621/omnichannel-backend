> Status: Historical
> Not canonical
> Archived on: 2026-08-01
> Superseded by: ../../modules/AUTOMATION.md
> Purpose: implementation history only
# Prompt Hook â€” Usage & Cost Metering (Phase 5C)

## Normalized fields

| Field | Rule |
|---|---|
| `input_tokens` | From provider usage only â€” never invented |
| `output_tokens` | From provider usage only |
| `total_tokens` | Provider total, else sum when both sides present |
| `cached_tokens` | Optional |
| `estimated_cost` | Via `PromptCostEstimator` + `config.prompt_hooks.cost_rates` |
| `provider` / `model` | From connection / result |
| `usage_source` | `provider` \| `estimated` \| `unknown` |

## Rules

- Missing provider tokens â†’ `usage_source=unknown` (do not fabricate counts).
- Cost without token counts may set `usage_source=estimated` only when estimator returns non-null.
- No hardcoded $/1M rates inside adapters â€” only `ConfigPromptCostEstimator`.
- Credentials never enter usage or audit metadata.

## Budget

| Interface | Impl |
|---|---|
| `PromptBudgetStore` | `InMemoryPromptBudgetStore` (default) |
| `PromptHookBudgetGuard` | `InMemoryPromptHookBudgetGuard` |

**Blocker:** multi-worker production live shadow requires durable `PromptBudgetStore` (DB/Redis). With `budget_store=memory`, live shadow stays blocked unless `live_shadow_allow_memory_budget=true` (local only).
