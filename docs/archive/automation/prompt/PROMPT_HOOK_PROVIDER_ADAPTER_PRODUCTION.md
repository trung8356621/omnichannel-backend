> Status: Historical
> Not canonical
> Archived on: 2026-08-01
> Superseded by: ../../modules/AUTOMATION.md
> Purpose: implementation history only
# Prompt Hook â€” Production Provider Adapter (Phase 5C)

## Call map (wrap only)

```text
Caller (Heading / FAQ / Keyword / Phase1 UI)
  â†’ PromptHookCallerBridge (legacy|shadow|hook)
  â†’ PromptHookRuntimeEngine
  â†’ PromptRunnerProviderAdapter
  â†’ PromptRunnerService::runWithCompiledPrompt
  â†’ AiModelRouter (failover/retry)
  â†’ Gemini/Claude HTTP client
```

Default mode = **legacy** â†’ bridge short-circuits to legacy service; adapter **not** called.

## Adapter

| Class | Role |
|---|---|
| `PromptRunnerProviderAdapter` | Production `PromptProviderAdapter` |
| `PromptProviderUsageNormalizer` | Tokens/usage/cost DTO |
| `ConfigPromptCostEstimator` | Cost via `prompt_hooks.cost_rates` only |
| `UnconfiguredPromptProviderAdapter` | Fail-closed for tests / misconfig |
| `FakePromptProviderAdapter` | Unit tests |

### Mapping

| Canonical | Source |
|---|---|
| messages/system | `RenderedPromptRequest` â†’ compiled string |
| model/provider | SeoPrompt â†’ ApiConnection + `model_used` |
| temperature / max tokens | Prompt connection / request model settings (PromptRunner path) |
| response format | Capability strategy (`json_mode` / `prompt_enforced_json`) |
| timeout | PromptRunner HTTP timeout (~180s) â†’ `ProviderTimeout` |
| usage | `PromptResult.token_usage` |
| finish reason / request id | usage fields when present |
| credentials | ApiConnection on SeoPrompt â€” **never** in hook JSON / audit |

### Retry ownership (locked)

**PromptRunner / AiModelRouter owns retry.** Hook runtime does **not** retry provider calls. Adapter sets `meta.retry_owner = PromptRunner/AiModelRouter` and `attempts` reflects adapter view (1); router may have attempted more internally.

### Capability matrix (current production path)

| Capability | PromptRunner path |
|---|---|
| text generation | yes |
| system message | yes (compiled into prompt) |
| temperature | yes (via connection/prompt) |
| max tokens | yes |
| JSON mode | soft (instruction + normalize) |
| native structured output | **no** â†’ `UnsupportedProviderCapability` if forced |
| streaming | no |
| usage reporting | when provider returns tokens |
| finish reason | when present in usage |

Hook definitions must not hardcode Gemini/Claude/OpenAI branches.
