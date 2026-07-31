> Status: Historical
> Not canonical
> Archived on: 2026-08-01
> Superseded by: ../../modules/EXTENSION_SDK.md
> Purpose: implementation history only
# Pipeline SDK

> **Extension Cutover v1.0 hoÃ n táº¥t.** `PipelineResolver` + `PipelineDefinitionInterface` lÃ  Ä‘Æ°á»ng canonical, khÃ´ng pháº£i scaffold tÃ¹y chá»n. Builtin pipelines (`article`, `rewrite`, `improve`, `translate`, `product`) Ä‘Äƒng kÃ½ qua `ContentPipelinesExtensionProvider`. Xem [ARCHITECTURE_DECISIONS.md](ARCHITECTURE_DECISIONS.md) ADR-012, ADR-017.

## Contract

`PipelineDefinitionInterface`: `key()`, `name()`, `version()`, `supportedContentTypes()`, `steps()`, `requiredCapabilities()`, `validate()`.

`PipelineStepDriver` (step-level, registry-facing scaffold):

- `id()`, `label()`
- `stage()`: `outline|article|translate|review|image|seo_audit|custom`
- `health()`

Register: `$ctx->pipelines()->register($id, $driver)`.

## Resolve â€” fail-closed

`PipelineResolver::BUILTIN_EXTENSION_ID = 'content-pipelines'`; error codes: `pipeline.not_configured`, `pipeline.not_registered`, `pipeline.disabled`.

## Má»¥c tiÃªu

Plugin thÃªm bÆ°á»›c Outline / Article / Translate / Review / Image / SEO Audit **khÃ´ng sá»­a** Workflow core.

Runtime wiring vÃ o Article Writing / Workflow engine = phase feature (Topical Map, Audit AIâ€¦).

## Related

Prompt Hook SDK song song: `PromptHookContributor` â†’ `PromptHookExtensionRegistry` (khÃ´ng thay PromptHookDefinitionLoader ngay).
