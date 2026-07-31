> Status: Historical
> Not canonical
> Archived on: 2026-08-01
> Superseded by: ../../modules/AUTOMATION.md
> Purpose: implementation history only
# Prompt Hook Provider Capabilities

Hook declares desired behavior (`structured_output`, output type).

`PromptProviderCapabilityResolver` picks strategy:

| Strategy | When |
|---|---|
| `native_schema` | Provider supports native structured output |
| `json_mode` | Provider JSON mode |
| `prompt_enforced_json` | Fallback instruction-only |
| `plain_text` | Text/markdown hooks |

## Production path (`PromptRunnerProviderAdapter`)

| Capability | Supported |
|---|---|
| text generation | yes |
| system messages | yes (compiled) |
| temperature | yes |
| max tokens | yes |
| JSON mode | soft yes |
| native structured output | **no** |
| streaming | no |
| usage reporting | when provider returns |
| finish reason | when present |

Adapters: `PromptRunnerProviderAdapter` (prod), `FakePromptProviderAdapter` (tests), `UnconfiguredPromptProviderAdapter` (fail-closed).

See `PROMPT_HOOK_PROVIDER_ADAPTER_PRODUCTION.md`.
