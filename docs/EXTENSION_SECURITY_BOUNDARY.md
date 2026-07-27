# Extension Security Boundary

> Ref: [EXTENSION_SDK.md](EXTENSION_SDK.md) · [ARCHITECTURE_DECISIONS.md](ARCHITECTURE_DECISIONS.md) · [ARCHITECTURE_FREEZE_V1.md](ARCHITECTURE_FREEZE_V1.md)

## No marketplace / download / eval

Không có marketplace, không auto-download, không remote code execution. `ExtensionDiscovery` chỉ:

- Glob `plugin.json` trên đĩa local (`Extension/Builtin/*`, `Extensions/{id}/*`).
- `class_exists($providerClass)` trước khi `$app->make()`.
- Không `eval(`, không `include`/`require` động theo path lấy từ dữ liệu ngoài.

## Whitelist discovery

Extension chỉ được nạp nếu:

1. Thư mục nằm trong `Extension/Builtin/` (built-in, ship cùng addon) hoặc `Extensions/{id}/` (`config('seo-content-ai.extension_sdk.extensions_path')`, mặc định `app/Addons/SeoContentAi/Extensions`).
2. Có `plugin.json` hợp lệ (`ExtensionManifest::fromFile`), `sdk` tương thích (`ExtensionCompatibilityChecker`).
3. Provider class tồn tại (`class_exists`) — không tồn tại → ghi `status: error`, không crash discovery.
4. Trạng thái `enabled` theo `ExtensionStateStore` (DB `seo_extension_states`, connection `omi_seo_ai`) — nguồn sự thật là DB/cache, không phải `manifest.enabled`.

Không có cơ chế nạp extension từ URL, upload zip runtime, hay Git remote.

## Extension id pattern

`extension_id` phải khớp:

```
/^[a-z0-9][a-z0-9._-]*$/
```

(xem `config/seo_architecture.php` → `extension_id_pattern`). Không khoảng trắng, không ký tự hoa, không path traversal (`..`, `/`).

## Settings namespace

Mọi setting/metadata do extension ghi phải nằm trong namespace riêng:

```
extensions.{id}.*
```

Ví dụ: `extensions.wordpress.default_status`. Extension **không** được đọc/ghi key setting ngoài namespace của chính nó (không đụng `seo_project_agent.*`, `seo_content_ai.*` core settings).

## Event isolation — after-commit, try/catch riêng từng listener

`ContentProjectDomainEvents::dispatchAfterCommit()` chỉ bridge sang `ExtensionEventBus` **sau khi DB commit** (hoặc ngay nếu không có transaction). `ExtensionEventBus::dispatch()` bọc từng listener trong `try { … } catch (Throwable) { … }` riêng biệt:

- Listener lỗi không rollback transaction domain (đã commit rồi).
- Listener lỗi không chặn listener khác chạy tiếp.
- Domain path chính (`event($event)` nội bộ Laravel) không phụ thuộc kết quả extension bus.

## No credentials in events / health

Payload event (`ExtensionEventEnvelope::make(...)`) và kết quả `health()` **không được** chứa:

- API key, token, password, secret, connection string.
- Toàn bộ nội dung bài viết / dữ liệu nhạy cảm khách hàng (chỉ ID/ref, count, flag boolean).

`health()` chỉ trả `{ok: bool, message: string}` mô tả trạng thái kết nối, không echo lại credential đang dùng để kiểm tra. Log liên quan tuân [web-app-logging](../.cursor/rules/web-app-logging.mdc) — không log token/password qua `RuntimeLogger`.

## Enforcement

- `ExtensionSdkFoundationTest::test_extension_discovery_does_not_eval_or_include_arbitrary_code`
- `ExtensionArchitectureFreezeTest`
- `config/seo_architecture.php` → `extension_id_pattern`
