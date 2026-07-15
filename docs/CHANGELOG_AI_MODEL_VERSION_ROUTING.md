# CHANGELOG — Gemini Model Version Routing

**Date:** 2026-07-15  
**Scope:** `app/Addons/SeoContentAi/Support/`, `Services/AiModelRouterService.php`, image routing wired services

---

## Behavior mới

1. **Auto-routing gate:** Gemini/Imagen chỉ eligible khi `major >= 3` (`GeminiModelVersionPolicy::MIN_MAJOR_VERSION`).
2. **Model Status UI:** Record 2.x vẫn hiển thị; `routing_status=disabled`, `disabled_reason=legacy_version`.
3. **Unavailable retry:** Lỗi provider → `markModelUnavailableForAutoRouting()` + thử model kế trong priority.
4. **Vision typography:** `VisionValidationModelRouter` — text models có `image_input`, version ≥ 3, multi-model failover.
5. **Logs/history:** Tách `render_model`, `validation_model`, `planner_model` trong metadata.

---

## Files chính

| File | Vai trò |
|------|---------|
| `Support/GeminiModelVersionPolicy.php` | Version gate, routing decision, filter/prefer stable |
| `Support/VisionValidationModelRouter.php` | Vision candidate list + primary resolve |
| `Support/ImageCapability.php` | Thêm `ImageInput` cho Vision |
| `Support/ImageRoutingStrategy.php` | Version filter + typography stricter rules |
| `Support/GoogleAiModelRegistry.php` | Default priority 3.x only |
| `Services/AiModelRouterService.php` | `routing_status` overview, unavailable mark, planner failover |
| `Services/GeminiMediaGenerationService.php` | Render retry + `render_model` log |
| `Services/TypographyValidationService.php` | Multi-model Vision retry |
| `Services/SeoCreateArticleSettingsService.php` | Normalize image priority list |
| `resources/views/filament/pages/seo-settings-overview.blade.php` | Hiển thị routing disabled + reason |

## Tests

| Test | Phạm vi |
|------|---------|
| `tests/Unit/GeminiModelVersionPolicyTest.php` | Version gate, filter, unavailable |
| `tests/Unit/ImageRoutingStrategyTest.php` | 3.x models, legacy excluded, vision router |
| `tests/Unit/GoogleAiModelRegistryImagePriorityTest.php` | Default priority 3.x |
