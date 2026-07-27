# Builtin WordPress Extension

> Ref: [PUBLISHER_SDK.md](PUBLISHER_SDK.md) · [ARCHITECTURE_DECISIONS.md](ARCHITECTURE_DECISIONS.md#adr-014--wordpress-là-builtin-extension-không-phải-core-hard-code)

## Path

```
app/Addons/SeoContentAi/Extension/Builtin/Wordpress/
  plugin.json
  WordpressExtensionProvider.php
  WordPressPublisher.php
  WordpressPublisherDriver.php
```

## Contract

`WordPressPublisher implements ContentPublisher` (`Services/ContentProject/Application/Publishing/ContentPublisher.php`).

Publish idempotent theo `external_reference` / `wp_post_id` (at-least-once — xem ADR-009): trước khi tạo bài mới luôn thử reconcile bài đã tồn tại.

## Registration

`WordpressExtensionProvider::register(ExtensionContext $ctx)`:

```php
$ctx->contentPublishers()->register($this->id(), $this->publisher);   // ContentPublisherRegistry
$ctx->publishers()->register($this->id(), $this->publisherDriver);    // PublisherRegistry
```

- `ContentPublisherRegistry` — dùng bởi `PublisherResolver` (Application layer resolve theo site).
- `PublisherRegistry` — driver phía Extension SDK (health check, tương lai UI Extensions list).

`id() === 'wordpress'`, khớp `plugin.json` và `Site::getMeta('seo_platform') === 'wordpress'` (mặc định publisher_key khi site không set `seo_publisher_key` riêng).

## Application chỉ dùng PublisherResolver

`Application/Handlers` (ví dụ `ProcessScheduledProjectItemPublishHandler`) **không** import `WordPressPublisher` trực tiếp. Resolve luôn qua:

```php
$publisher = $this->publisherResolver->resolveForSiteId($siteId);
$result = $publisher->publish($payload);
```

`PublisherResolver` tra `ContentPublisherRegistry` theo `publisher_key`/`seo_platform`, kiểm tra extension enabled + health trước khi trả driver — fail closed nếu chưa cấu hình (không fallback ngầm về WordPress).

## Forbidden

- `Application/Handlers/*.php` import `Extension\Builtin\Wordpress\WordPressPublisher` hoặc `WordPressContentPublisher`.
- File `Application/Publishing/WordPressContentPublisher.php` tồn tại lại (đã loại bỏ — publisher cụ thể chỉ sống dưới `Extension/Builtin/*`).
- Agent layer import bất kỳ class nào dưới `Extension\Builtin\*`.

## Enforcement

`app/Addons/SeoContentAi/tests/Unit/ExtensionArchitectureFreezeTest.php`, `ExtensionSdkFoundationTest.php`.
