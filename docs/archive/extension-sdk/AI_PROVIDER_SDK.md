> Status: Historical
> Not canonical
> Archived on: 2026-08-01
> Superseded by: ../../modules/EXTENSION_SDK.md
> Purpose: implementation history only
# AI Provider SDK

> **Extension Cutover v1.0 hoÃ n táº¥t.** `AiProviderResolver` lÃ  Ä‘Æ°á»ng resolve canonical cho text/image generation â€” khÃ´ng pháº£i scaffold tÃ¹y chá»n. `PromptRunnerService` resolve provider qua `AiProviderResolver` (fail-closed), khÃ´ng hard-code vendor. Xem [ARCHITECTURE_DECISIONS.md](ARCHITECTURE_DECISIONS.md) ADR-012, ADR-017.

## Contract

`AiTextProviderInterface` (`generate`, `health`), `AiImageProviderInterface` (`generateImage`). `AiProviderDriver` (registry-facing scaffold):

- `id()`, `label()`
- `supportsChat()`, `supportsImage()`, `supportsEmbedding()`, `supportsModeration()`
- `health()`

Register: `$ctx->aiProviders()->register($id, $driver)`.

## Builtin

`AiProvidersExtensionProvider` (`Extension/Builtin/AiProviders/`) wires `GeminiAiTextProvider` + `ClaudeAiTextProvider` (wrapping `GeminiGenerateContentClient` / `ClaudeMessagesClient`).

## Resolve â€” PromptRunnerService chá»‰ dÃ¹ng AiProviderResolver

```php
$this->aiProviderResolver->assertTextReady($providerId); // fail-closed
```

`AiProviderResolver::BUILTIN_EXTENSION_ID = 'ai-providers'`; error codes: `ai_provider.not_configured`, `ai_provider.not_registered`, `ai_provider.disabled`.

## Má»¥c tiÃªu

Chuáº©n hÃ³a OpenAI / Gemini / Claude / OpenRouter / Ollamaâ€¦ mÃ  PromptRunner / Prompt Hooks **khÃ´ng** hard-code vendor.

## KhÃ´ng lÃ m á»Ÿ Ä‘Ã¢y

- KhÃ´ng Ä‘á»•i Prompt Hook engine contracts hiá»‡n cÃ³ (chá»‰ extension registry song song)
