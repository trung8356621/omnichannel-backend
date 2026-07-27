# Publisher SDK

> **Extension Cutover v1.0 hoàn tất.** `PublisherResolver` + `ContentPublisherRegistry` là đường resolve canonical, không phải scaffold tùy chọn. `Application/Handlers` không được import publisher cụ thể (`WordPressPublisher`) — xem [ARCHITECTURE_DECISIONS.md](ARCHITECTURE_DECISIONS.md) ADR-009, ADR-012, ADR-014.

## Contract

`App\Addons\SeoContentAi\Extension\Contracts\PublisherDriver`

| Method | Role |
|--------|------|
| `publish(array $payload): array` | Create/publish remote content |
| `update(array $payload): array` | Update remote |
| `delete(array $payload): array` | Delete remote |
| `find(array $query): ?array` | Lookup by external ref |
| `health(): array{ok,message}` | No destructive side effects |

Register qua `ExtensionContext::publishers()->register($id, $driver)`.

## Builtin: WordPress

- Manifest: `Extension/Builtin/Wordpress/plugin.json`
- `WordPressPublisher implements ContentPublisher` — registered vào `ContentPublisherRegistry` qua `WordpressExtensionProvider`
- `WordpressPublisherDriver implements PublisherDriver` — registered vào `PublisherRegistry` (health/UI)
- Chi tiết: [BUILTIN_WORDPRESS_EXTENSION.md](BUILTIN_WORDPRESS_EXTENSION.md)

Ghost/Shopify/Webflow = plugin mới cùng contract `ContentPublisher`/`PublisherDriver`, không sửa CommandBus/Handler.

## Resolve — Application chỉ dùng PublisherResolver

```php
$publisher = $this->publisherResolver->resolveForSiteId($siteId); // fail-closed
$result = $publisher->publish($payload);
```

`PublisherResolver` tra `ContentPublisherRegistry` theo `publisher_key`/`seo_platform` của site, kiểm tra extension enabled + `health()` trước khi trả driver. Không silent fallback về WordPress khi chưa cấu hình.

## Health

Builtin health: class present + optional DB table check — **không** live WP HTTP call mặc định.
