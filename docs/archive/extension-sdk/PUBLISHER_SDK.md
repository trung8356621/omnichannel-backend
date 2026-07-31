> Status: Historical
> Not canonical
> Archived on: 2026-08-01
> Superseded by: ../../modules/EXTENSION_SDK.md
> Purpose: implementation history only
# Publisher SDK

> **Extension Cutover v1.0 hoÃ n táº¥t.** `PublisherResolver` + `ContentPublisherRegistry` lÃ  Ä‘Æ°á»ng resolve canonical, khÃ´ng pháº£i scaffold tÃ¹y chá»n. `Application/Handlers` khÃ´ng Ä‘Æ°á»£c import publisher cá»¥ thá»ƒ (`WordPressPublisher`) â€” xem [ARCHITECTURE_DECISIONS.md](ARCHITECTURE_DECISIONS.md) ADR-009, ADR-012, ADR-014.

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
- `WordPressPublisher implements ContentPublisher` â€” registered vÃ o `ContentPublisherRegistry` qua `WordpressExtensionProvider`
- `WordpressPublisherDriver implements PublisherDriver` â€” registered vÃ o `PublisherRegistry` (health/UI)
- Chi tiáº¿t: [BUILTIN_WORDPRESS_EXTENSION.md](BUILTIN_WORDPRESS_EXTENSION.md)

Ghost/Shopify/Webflow = plugin má»›i cÃ¹ng contract `ContentPublisher`/`PublisherDriver`, khÃ´ng sá»­a CommandBus/Handler.

## Resolve â€” Application chá»‰ dÃ¹ng PublisherResolver

```php
$publisher = $this->publisherResolver->resolveForSiteId($siteId); // fail-closed
$result = $publisher->publish($payload);
```

`PublisherResolver` tra `ContentPublisherRegistry` theo `publisher_key`/`seo_platform` cá»§a site, kiá»ƒm tra extension enabled + `health()` trÆ°á»›c khi tráº£ driver. KhÃ´ng silent fallback vá» WordPress khi chÆ°a cáº¥u hÃ¬nh.

## Health

Builtin health: class present + optional DB table check â€” **khÃ´ng** live WP HTTP call máº·c Ä‘á»‹nh.
