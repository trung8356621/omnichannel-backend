> Status: Historical
> Not canonical
> Archived on: 2026-08-01
> Superseded by: ../../modules/AUTOMATION.md
> Purpose: implementation history only
# Prompt Hook Runtime (Phase 5B)

**Status:** runtime core **defined + registered**; callers **wired** at **legacy** default  
**Updated:** 2026-07-18

## Modes

| Mode | Behavior |
|---|---|
| `legacy` | Caller legacy path only (default) |
| `shadow` | Legacy SoT; validate/render hook; **no** AI twice (unless `PROMPT_HOOK_LIVE_SHADOW_ENABLED`) |
| `hook` | RuntimeEngine + provider once; no legacy fallback after provider |

## Core classes

| Class | Role |
|---|---|
| `Canonical\PromptHookDefinition` (+ Key/Version/Status/â€¦) | Immutable definition |
| `Runtime\PromptHookDefinitionLoader` | Spec v0.1 + Phase 1 dual-read |
| `Runtime\PromptHookRuntimeRegistry` | `get(key, version)` â€” no latest for experimental |
| `Runtime\PromptHookExecutionInput` | Envelope; reject Eloquent |
| `Runtime\PromptHookRuntimeEngine` | Single-hook execute; **no domain write** |
| `Runtime\PromptHookCallerBridge` | legacy\|shadow\|hook |
| `Provider\*` | Capability + Fake / Unconfigured adapters |
| `Output\PromptHookRuntimeOutputPipeline` | Normalize + validate |
| `Output\MarkdownSectionsOutputParser` | Definition-driven multi-section Markdown parse |

## markdown_sections

Exceptional type for one provider response with multiple START/END section markers. Used only by `article.outline.generate@0.1.0` (experimental). Template may use `source: legacy_prompt_content` (SeoPrompt DB body = template SoT).

Workflow mapping (existing nodeOutputs): section ports â†’ Task 1/2; `total` â†’ `out_main` / Total (AI). No new workflow engine.

## legacy_prompt_content (article generate/rewrite)

`article.content.generate@0.1.0` and `article.content.rewrite@0.1.0` use Prompt DB markdown as template; Hook JSON owns I/O contract only. Output type `markdown` â†’ workflow `out_main` / Total (AI). Domain persist stays outside Hook Runtime.

## Config

`config/seo-content-ai.php` â†’ `prompt_hooks.*`  
Flags: `PROMPT_HOOK_MIGRATION_*` default `legacy`. Live shadow default **off**.

## Command

```text
php artisan seo:prompt-hooks:clear-cache
```

## Production note

Hook mode uses production `PromptRunnerProviderAdapter` when wired; repo migration defaults stay **legacy**. Explicit editor binding may run Hook Runtime for one Prompt without flipping global mode.