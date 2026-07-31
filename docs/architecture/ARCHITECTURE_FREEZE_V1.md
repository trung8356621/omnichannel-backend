# Architecture Freeze v1.0 — Extension Cutover

> Ref: [ARCHITECTURE_DECISIONS.md](ARCHITECTURE_DECISIONS.md) (ADR-001..ADR-017) · Index: [../README.md](../README.md)

**Freeze date:** 2026-07-27

**SDK version:** 1 (`App\Addons\SeoContentAi\Extension\SdkVersion::MAJOR`)

Từ ngày freeze, các public contract dưới đây coi là **khóa (frozen)**. Thay đổi breaking bắt buộc có ADR mới supersede ADR-017 + bump SDK major.

## Public contracts (frozen)

| Contract | Namespace |
|---|---|
| Publisher | `App\Addons\SeoContentAi\Services\ContentProject\Application\Publishing\ContentPublisher`, `App\Addons\SeoContentAi\Extension\Contracts\PublisherDriver` |
| AI Text Provider | `App\Addons\SeoContentAi\Extension\Contracts\AiTextProviderInterface` |
| AI Image Provider | `App\Addons\SeoContentAi\Extension\Contracts\AiImageProviderInterface` |
| Pipeline Definition | `App\Addons\SeoContentAi\Extension\Contracts\PipelineDefinitionInterface` |
| SEO Provider | `App\Addons\SeoContentAi\Extension\Contracts\SeoProviderDriver` (+ `SeoProviderInterface` nếu tồn tại) |
| Capability | `App\Addons\SeoContentAi\Extension\Contracts\CapabilityContributor` |
| Extension Events v1 | `App\Addons\SeoContentAi\Extension\ExtensionEvents` (mọi hằng số `*.v1`) |

## Allowed without ADR

Thay đổi sau đây **không** cần ADR mới, được coi là bảo trì bình thường:

- Thêm builtin extension mới (Publisher/AI Provider/Pipeline/SEO Provider) tuân thủ contract hiện có, không sửa contract.
- Thêm capability mới (tên mới, không trùng/đổi capability đã publish).
- Thêm field **additive** (optional, có default) vào payload event `.v1` hiện có, miễn không đổi ý nghĩa field cũ.
- Sửa lỗi (bugfix) hành vi implementation bên trong builtin extension, miễn giữ nguyên contract signature và kết quả hợp đồng (idempotency, at-least-once, health shape).
- Thêm test, doc, refactor nội bộ không đổi public surface.
- Thêm config key mới trong `config/seo_architecture.php`, `config/extension_sdk.php` không đổi nghĩa key cũ.

## Requires ADR

Thay đổi sau đây **bắt buộc** ADR mới (supersede rõ ràng ADR liên quan) trước khi merge:

- Đổi signature method trên bất kỳ contract nào trong bảng Public contracts.
- Đổi tên hoặc xóa capability đã publish (vi phạm ADR-015).
- Đổi ý nghĩa hoặc xóa field trong payload event `.v1` đã publish (breaking) — phải phát hành `.v2` (vi phạm ADR-016 nếu làm tại chỗ).
- Cho phép Extension đăng ký capability trùng prefix `content_project.` (vi phạm ADR-015).
- Cho phép `Application/Handlers` hoặc Agent layer import trực tiếp class dưới `Extension\Builtin\*` (vi phạm ADR-012).
- Thêm lifecycle hook mới cho `ExtensionProvider` ngoài `register()`/`boot()` (vi phạm ADR-013).
- Đổi chủ sở hữu lịch publish (SaaS → nguồn khác) hoặc cho phép Manual Sync ghi `scheduled_publish_at` (vi phạm ADR-007/ADR-008).
- Đổi hành vi Archive để không còn destroy AI Workspace, hoặc Restore khôi phục lại workspace cũ (vi phạm ADR-005/ADR-006).
- Bỏ prefix `cp_`/`cpi_` cho public reference hoặc cho phép Agent nhận/trả numeric ID thô (vi phạm ADR-010).
- Nới lỏng tenant guard fail-closed thành fail-open trong bất kỳ trường hợp nào (vi phạm ADR-011).
- Bump `SdkVersion::MAJOR`.

## Enforcement

- `app/Addons/SeoContentAi/tests/Unit/ExtensionArchitectureFreezeTest.php` — boundary test chính.
- `app/Addons/SeoContentAi/tests/Unit/ExtensionCutoverCapabilityAndEventsTest.php`
- `app/Addons/SeoContentAi/tests/Unit/ExtensionCutoverAiPipelineTest.php`
- `app/Addons/SeoContentAi/tests/Unit/ExtensionSdkFoundationTest.php`
- `app/Addons/SeoContentAi/tests/Unit/ContentProjectCommandBusCutoverTest.php`
- `app/Addons/SeoContentAi/config/seo_architecture.php` — machine-readable freeze config (`sdk_version`, `forbidden_dependency_rules`, `event_versions`, `public_reference_prefixes`).

## Related docs

- [ARCHITECTURE_DECISIONS.md](ARCHITECTURE_DECISIONS.md)
- [../modules/EXTENSION_SDK.md](../modules/EXTENSION_SDK.md)
- [../contracts/EXTENSION_AND_REGISTRY_CONTRACTS.md](../contracts/EXTENSION_AND_REGISTRY_CONTRACTS.md)
- [../README.md](../README.md)
