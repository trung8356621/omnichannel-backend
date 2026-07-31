> Status: Historical
> Not canonical
> Archived on: 2026-08-01
> Superseded by: ../../modules/EXTENSION_SDK.md
> Purpose: implementation history only
# Extension SDK

> **Extension Cutover v1.0 hoÃ n táº¥t** ([ARCHITECTURE_FREEZE_V1.md](ARCHITECTURE_FREEZE_V1.md)). Registry/Resolver lÃ  canonical path â€” khÃ´ng cÃ²n lÃ  scaffold tÃ¹y chá»n. Application/Agent báº¯t buá»™c resolve qua registry/resolver, khÃ´ng hard-code Builtin. Xem [ARCHITECTURE_DECISIONS.md](ARCHITECTURE_DECISIONS.md) ADR-012..017.

## Má»¥c tiÃªu

Core chá»‰ biáº¿t **stable contracts + registries**. KhÃ´ng hard-code Ghost/Shopify/Ahrefs/OpenAI trong Application.

```
Core / CommandBus / Agent
        â†‘
Stable Contracts
        â†‘
Extension SDK (registries + discovery)
        â†‘
Extensions (Builtin + Extensions/*)
```

SDK major hiá»‡n táº¡i: **1** (`SdkVersion::MAJOR`).

## KhÃ´ng lÃ m (phase nÃ y)

- Marketplace / auto-download
- `eval` / remote PHP execution
- Sandbox

Plugin chá»‰ load khi `class_exists(provider)` tá»« `plugin.json` trÃªn disk local.

## Cáº¥u trÃºc

```
app/Addons/SeoContentAi/Extension/
  Contracts/          # Publisher, AI, SEO, Pipeline, Capability, PromptHook, Media, Workflow
  Registry/           # *Registry + ContentPlatformRegistry facade
  Builtin/Wordpress/  # plugin.json + provider + driver
  ExtensionDiscovery.php
  ExtensionEventBus.php
  ExtensionCompatibilityChecker.php
  ExtensionHealthService.php
  ExtensionStateStore.php
```

User extensions: `app/Addons/SeoContentAi/Extensions/{id}/plugin.json` (+ provider class).

## plugin.json

```json
{
  "id": "wordpress",
  "name": "WordPress Publisher",
  "version": "1.0.0",
  "sdk": 1,
  "provider": "App\\...\\WordpressExtensionProvider",
  "providers": ["publisher"],
  "capabilities": [],
  "requires": []
}
```

## ExtensionProvider

```php
public function register(ExtensionContext $ctx): void;
public function boot(ExtensionContext $ctx): void;
```

`register()` inject drivers vÃ o registry. `boot()` subscribe events / warm caches.

## Registries

| Registry | Role |
|----------|------|
| `PublisherRegistry` | CMS publishers |
| `AiProviderRegistry` | Chat/Image/Embedding/Moderation |
| `SeoProviderRegistry` | Ahrefs/GSC/â€¦ |
| `PipelineRegistry` | Outline/Article/Review/â€¦ steps |
| `ExtensionCapabilityRegistry` | Agent-visible caps tá»« plugin |
| `PromptHookExtensionRegistry` | Hook contributors |
| `MediaProcessorRegistry` | Media pipeline |
| `WorkflowExtensionRegistry` | Workflow packs |
| `ExtensionRegistry` | Installed plugins |
| `ContentPlatformRegistry` | Facade |

## State / UI

Table `seo_extension_states` (`omi_seo_ai`): enabled, status (`healthy|error|disabled|needs_update`), health_payload.

Filament: **Settings â†’ Extensions** (`/seo/{hash}/extensions`).

## Events

`ExtensionEventBus` â€” in-process subscribe/dispatch. Bridged tá»« `ContentProjectDomainEvents` cho:

- `content_project.created`
- `content_project.items_generated`
- `content_project.published`
- `content_project.archived`

## Compatibility

`ExtensionCompatibilityChecker` â€” sdk major mismatch â†’ `needs_update` / `migration_needed`.

## Config

`config/extension_sdk.php` â†’ `seo-content-ai.extension_sdk`

## Docs liÃªn quan

- [PUBLISHER_SDK.md](PUBLISHER_SDK.md)
- [AI_PROVIDER_SDK.md](AI_PROVIDER_SDK.md)
- [CAPABILITY_SDK.md](CAPABILITY_SDK.md)
- [PIPELINE_SDK.md](PIPELINE_SDK.md)
- [ARCHITECTURE_DECISIONS.md](ARCHITECTURE_DECISIONS.md)
- [ARCHITECTURE_FREEZE_V1.md](ARCHITECTURE_FREEZE_V1.md)
- [BUILTIN_WORDPRESS_EXTENSION.md](BUILTIN_WORDPRESS_EXTENSION.md)
- [EXTENSION_SECURITY_BOUNDARY.md](EXTENSION_SECURITY_BOUNDARY.md)
