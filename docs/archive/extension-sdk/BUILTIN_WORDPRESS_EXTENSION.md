> Status: Historical
> Not canonical
> Archived on: 2026-08-01
> Superseded by: ../../modules/EXTENSION_SDK.md
> Purpose: implementation history only
# Builtin WordPress Extension

> Ref: [PUBLISHER_SDK.md](PUBLISHER_SDK.md) Â· [ARCHITECTURE_DECISIONS.md](ARCHITECTURE_DECISIONS.md#adr-014--wordpress-lÃ -builtin-extension-khÃ´ng-pháº£i-core-hard-code)

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

Publish idempotent theo `external_reference` / `wp_post_id` (at-least-once â€” xem ADR-009): trÆ°á»›c khi táº¡o bÃ i má»›i luÃ´n thá»­ reconcile bÃ i Ä‘Ã£ tá»“n táº¡i.

## Registration

`WordpressExtensionProvider::register(ExtensionContext $ctx)`:

```php
$ctx->contentPublishers()->register($this->id(), $this->publisher);   // ContentPublisherRegistry
$ctx->publishers()->register($this->id(), $this->publisherDriver);    // PublisherRegistry
```

- `ContentPublisherRegistry` â€” dÃ¹ng bá»Ÿi `PublisherResolver` (Application layer resolve theo site).
- `PublisherRegistry` â€” driver phÃ­a Extension SDK (health check, tÆ°Æ¡ng lai UI Extensions list).

`id() === 'wordpress'`, khá»›p `plugin.json` vÃ  `Site::getMeta('seo_platform') === 'wordpress'` (máº·c Ä‘á»‹nh publisher_key khi site khÃ´ng set `seo_publisher_key` riÃªng).

## Application chá»‰ dÃ¹ng PublisherResolver

`Application/Handlers` (vÃ­ dá»¥ `ProcessScheduledProjectItemPublishHandler`) **khÃ´ng** import `WordPressPublisher` trá»±c tiáº¿p. Resolve luÃ´n qua:

```php
$publisher = $this->publisherResolver->resolveForSiteId($siteId);
$result = $publisher->publish($payload);
```

`PublisherResolver` tra `ContentPublisherRegistry` theo `publisher_key`/`seo_platform`, kiá»ƒm tra extension enabled + health trÆ°á»›c khi tráº£ driver â€” fail closed náº¿u chÆ°a cáº¥u hÃ¬nh (khÃ´ng fallback ngáº§m vá» WordPress).

## Forbidden

- `Application/Handlers/*.php` import `Extension\Builtin\Wordpress\WordPressPublisher` hoáº·c `WordPressContentPublisher`.
- File `Application/Publishing/WordPressContentPublisher.php` tá»“n táº¡i láº¡i (Ä‘Ã£ loáº¡i bá» â€” publisher cá»¥ thá»ƒ chá»‰ sá»‘ng dÆ°á»›i `Extension/Builtin/*`).
- Agent layer import báº¥t ká»³ class nÃ o dÆ°á»›i `Extension\Builtin\*`.

## Enforcement

`app/Addons/SeoContentAi/tests/Unit/ExtensionArchitectureFreezeTest.php`, `ExtensionSdkFoundationTest.php`.
