> Status: Historical
> Not canonical
> Archived on: 2026-08-01
> Superseded by: ../../modules/EXTENSION_SDK.md
> Purpose: implementation history only
# Extension Security Boundary

> Ref: [EXTENSION_SDK.md](EXTENSION_SDK.md) Â· [ARCHITECTURE_DECISIONS.md](ARCHITECTURE_DECISIONS.md) Â· [ARCHITECTURE_FREEZE_V1.md](ARCHITECTURE_FREEZE_V1.md)

## No marketplace / download / eval

KhÃ´ng cÃ³ marketplace, khÃ´ng auto-download, khÃ´ng remote code execution. `ExtensionDiscovery` chá»‰:

- Glob `plugin.json` trÃªn Ä‘Ä©a local (`Extension/Builtin/*`, `Extensions/{id}/*`).
- `class_exists($providerClass)` trÆ°á»›c khi `$app->make()`.
- KhÃ´ng `eval(`, khÃ´ng `include`/`require` Ä‘á»™ng theo path láº¥y tá»« dá»¯ liá»‡u ngoÃ i.

## Whitelist discovery

Extension chá»‰ Ä‘Æ°á»£c náº¡p náº¿u:

1. ThÆ° má»¥c náº±m trong `Extension/Builtin/` (built-in, ship cÃ¹ng addon) hoáº·c `Extensions/{id}/` (`config('seo-content-ai.extension_sdk.extensions_path')`, máº·c Ä‘á»‹nh `app/Addons/SeoContentAi/Extensions`).
2. CÃ³ `plugin.json` há»£p lá»‡ (`ExtensionManifest::fromFile`), `sdk` tÆ°Æ¡ng thÃ­ch (`ExtensionCompatibilityChecker`).
3. Provider class tá»“n táº¡i (`class_exists`) â€” khÃ´ng tá»“n táº¡i â†’ ghi `status: error`, khÃ´ng crash discovery.
4. Tráº¡ng thÃ¡i `enabled` theo `ExtensionStateStore` (DB `seo_extension_states`, connection `omi_seo_ai`) â€” nguá»“n sá»± tháº­t lÃ  DB/cache, khÃ´ng pháº£i `manifest.enabled`.

KhÃ´ng cÃ³ cÆ¡ cháº¿ náº¡p extension tá»« URL, upload zip runtime, hay Git remote.

## Extension id pattern

`extension_id` pháº£i khá»›p:

```
/^[a-z0-9][a-z0-9._-]*$/
```

(xem `config/seo_architecture.php` â†’ `extension_id_pattern`). KhÃ´ng khoáº£ng tráº¯ng, khÃ´ng kÃ½ tá»± hoa, khÃ´ng path traversal (`..`, `/`).

## Settings namespace

Má»i setting/metadata do extension ghi pháº£i náº±m trong namespace riÃªng:

```
extensions.{id}.*
```

VÃ­ dá»¥: `extensions.wordpress.default_status`. Extension **khÃ´ng** Ä‘Æ°á»£c Ä‘á»c/ghi key setting ngoÃ i namespace cá»§a chÃ­nh nÃ³ (khÃ´ng Ä‘á»¥ng `seo_project_agent.*`, `seo_content_ai.*` core settings).

## Event isolation â€” after-commit, try/catch riÃªng tá»«ng listener

`ContentProjectDomainEvents::dispatchAfterCommit()` chá»‰ bridge sang `ExtensionEventBus` **sau khi DB commit** (hoáº·c ngay náº¿u khÃ´ng cÃ³ transaction). `ExtensionEventBus::dispatch()` bá»c tá»«ng listener trong `try { â€¦ } catch (Throwable) { â€¦ }` riÃªng biá»‡t:

- Listener lá»—i khÃ´ng rollback transaction domain (Ä‘Ã£ commit rá»“i).
- Listener lá»—i khÃ´ng cháº·n listener khÃ¡c cháº¡y tiáº¿p.
- Domain path chÃ­nh (`event($event)` ná»™i bá»™ Laravel) khÃ´ng phá»¥ thuá»™c káº¿t quáº£ extension bus.

## No credentials in events / health

Payload event (`ExtensionEventEnvelope::make(...)`) vÃ  káº¿t quáº£ `health()` **khÃ´ng Ä‘Æ°á»£c** chá»©a:

- API key, token, password, secret, connection string.
- ToÃ n bá»™ ná»™i dung bÃ i viáº¿t / dá»¯ liá»‡u nháº¡y cáº£m khÃ¡ch hÃ ng (chá»‰ ID/ref, count, flag boolean).

`health()` chá»‰ tráº£ `{ok: bool, message: string}` mÃ´ táº£ tráº¡ng thÃ¡i káº¿t ná»‘i, khÃ´ng echo láº¡i credential Ä‘ang dÃ¹ng Ä‘á»ƒ kiá»ƒm tra. Log liÃªn quan tuÃ¢n [web-app-logging](../.cursor/rules/web-app-logging.mdc) â€” khÃ´ng log token/password qua `RuntimeLogger`.

## Enforcement

- `ExtensionSdkFoundationTest::test_extension_discovery_does_not_eval_or_include_arbitrary_code`
- `ExtensionArchitectureFreezeTest`
- `config/seo_architecture.php` â†’ `extension_id_pattern`
