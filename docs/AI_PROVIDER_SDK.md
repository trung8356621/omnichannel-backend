# AI Provider SDK

> **Extension Cutover v1.0 hoàn tất.** `AiProviderResolver` là đường resolve canonical cho text/image generation — không phải scaffold tùy chọn. `PromptRunnerService` resolve provider qua `AiProviderResolver` (fail-closed), không hard-code vendor. Xem [ARCHITECTURE_DECISIONS.md](ARCHITECTURE_DECISIONS.md) ADR-012, ADR-017.

## Contract

`AiTextProviderInterface` (`generate`, `health`), `AiImageProviderInterface` (`generateImage`). `AiProviderDriver` (registry-facing scaffold):

- `id()`, `label()`
- `supportsChat()`, `supportsImage()`, `supportsEmbedding()`, `supportsModeration()`
- `health()`

Register: `$ctx->aiProviders()->register($id, $driver)`.

## Builtin

`AiProvidersExtensionProvider` (`Extension/Builtin/AiProviders/`) wires `GeminiAiTextProvider` + `ClaudeAiTextProvider` (wrapping `GeminiGenerateContentClient` / `ClaudeMessagesClient`).

## Resolve — PromptRunnerService chỉ dùng AiProviderResolver

```php
$this->aiProviderResolver->assertTextReady($providerId); // fail-closed
```

`AiProviderResolver::BUILTIN_EXTENSION_ID = 'ai-providers'`; error codes: `ai_provider.not_configured`, `ai_provider.not_registered`, `ai_provider.disabled`.

## Mục tiêu

Chuẩn hóa OpenAI / Gemini / Claude / OpenRouter / Ollama… mà PromptRunner / Prompt Hooks **không** hard-code vendor.

## Không làm ở đây

- Không đổi Prompt Hook engine contracts hiện có (chỉ extension registry song song)
