# Extension SDK

> **Extension Cutover v1.0 hoàn tất** ([ARCHITECTURE_FREEZE_V1.md](ARCHITECTURE_FREEZE_V1.md)). Registry/Resolver là canonical path — không còn là scaffold tùy chọn. Application/Agent bắt buộc resolve qua registry/resolver, không hard-code Builtin. Xem [ARCHITECTURE_DECISIONS.md](ARCHITECTURE_DECISIONS.md) ADR-012..017.

## Mục tiêu

Core chỉ biết **stable contracts + registries**. Không hard-code Ghost/Shopify/Ahrefs/OpenAI trong Application.

```
Core / CommandBus / Agent
        ↑
Stable Contracts
        ↑
Extension SDK (registries + discovery)
        ↑
Extensions (Builtin + Extensions/*)
```

SDK major hiện tại: **1** (`SdkVersion::MAJOR`).

## Không làm (phase này)

- Marketplace / auto-download
- `eval` / remote PHP execution
- Sandbox

Plugin chỉ load khi `class_exists(provider)` từ `plugin.json` trên disk local.

## Cấu trúc

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

`register()` inject drivers vào registry. `boot()` subscribe events / warm caches.

## Registries

| Registry | Role |
|----------|------|
| `PublisherRegistry` | CMS publishers |
| `AiProviderRegistry` | Chat/Image/Embedding/Moderation |
| `SeoProviderRegistry` | Ahrefs/GSC/… |
| `PipelineRegistry` | Outline/Article/Review/… steps |
| `ExtensionCapabilityRegistry` | Agent-visible caps từ plugin |
| `PromptHookExtensionRegistry` | Hook contributors |
| `MediaProcessorRegistry` | Media pipeline |
| `WorkflowExtensionRegistry` | Workflow packs |
| `ExtensionRegistry` | Installed plugins |
| `ContentPlatformRegistry` | Facade |

## State / UI

Table `seo_extension_states` (`omi_seo_ai`): enabled, status (`healthy|error|disabled|needs_update`), health_payload.

Filament: **Settings → Extensions** (`/seo/{hash}/extensions`).

## Events

`ExtensionEventBus` — in-process subscribe/dispatch. Bridged từ `ContentProjectDomainEvents` cho:

- `content_project.created`
- `content_project.items_generated`
- `content_project.published`
- `content_project.archived`

## Compatibility

`ExtensionCompatibilityChecker` — sdk major mismatch → `needs_update` / `migration_needed`.

## Config

`config/extension_sdk.php` → `seo-content-ai.extension_sdk`

## Docs liên quan

- [PUBLISHER_SDK.md](PUBLISHER_SDK.md)
- [AI_PROVIDER_SDK.md](AI_PROVIDER_SDK.md)
- [CAPABILITY_SDK.md](CAPABILITY_SDK.md)
- [PIPELINE_SDK.md](PIPELINE_SDK.md)
- [ARCHITECTURE_DECISIONS.md](ARCHITECTURE_DECISIONS.md)
- [ARCHITECTURE_FREEZE_V1.md](ARCHITECTURE_FREEZE_V1.md)
- [BUILTIN_WORDPRESS_EXTENSION.md](BUILTIN_WORDPRESS_EXTENSION.md)
- [EXTENSION_SECURITY_BOUNDARY.md](EXTENSION_SECURITY_BOUNDARY.md)
