# Pipeline SDK

> **Extension Cutover v1.0 hoàn tất.** `PipelineResolver` + `PipelineDefinitionInterface` là đường canonical, không phải scaffold tùy chọn. Builtin pipelines (`article`, `rewrite`, `improve`, `translate`, `product`) đăng ký qua `ContentPipelinesExtensionProvider`. Xem [ARCHITECTURE_DECISIONS.md](ARCHITECTURE_DECISIONS.md) ADR-012, ADR-017.

## Contract

`PipelineDefinitionInterface`: `key()`, `name()`, `version()`, `supportedContentTypes()`, `steps()`, `requiredCapabilities()`, `validate()`.

`PipelineStepDriver` (step-level, registry-facing scaffold):

- `id()`, `label()`
- `stage()`: `outline|article|translate|review|image|seo_audit|custom`
- `health()`

Register: `$ctx->pipelines()->register($id, $driver)`.

## Resolve — fail-closed

`PipelineResolver::BUILTIN_EXTENSION_ID = 'content-pipelines'`; error codes: `pipeline.not_configured`, `pipeline.not_registered`, `pipeline.disabled`.

## Mục tiêu

Plugin thêm bước Outline / Article / Translate / Review / Image / SEO Audit **không sửa** Workflow core.

Runtime wiring vào Article Writing / Workflow engine = phase feature (Topical Map, Audit AI…).

## Related

Prompt Hook SDK song song: `PromptHookContributor` → `PromptHookExtensionRegistry` (không thay PromptHookDefinitionLoader ngay).
