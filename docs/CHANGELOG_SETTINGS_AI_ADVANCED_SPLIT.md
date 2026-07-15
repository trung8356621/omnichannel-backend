# CHANGELOG — Settings: tách AI Advanced khỏi Editor / Workflows

**Date:** 2026-07-15  
**Scope:** Settings UI + wiring model priority / typography fallback  
**Không đổi:** workflow execution graph, PromptRunner chain execution (chỉ Settings + routing helpers).

---

## Mục tiêu

- Editor / Workflows Settings chỉ còn Prompt | Workflow (nghiệp vụ).
- AI Advanced chứa toàn bộ model routing / validation.
- Không mất dữ liệu settings cũ.
- Loại Gemini legacy (< 3) khỏi priority runtime.
- Sửa bug General Image Fallback typography.

---

## Files di chuyển / mới

| File | Vai trò |
|---|---|
| `Filament/Pages/SeoSettingsAiAdvanced.php` | **Mới** — trang AI Advanced |
| `resources/views/filament/pages/seo-settings-ai-advanced.blade.php` | **Mới** — view |
| `Filament/Pages/SeoSettingsWorkflows.php` | Bỏ model UI; chỉ Editor Media + task prompts |
| `Support/SeoSettingsMenu.php` | Thêm menu **AI Advanced** |
| `Services/SeoCreateArticleSettingsService.php` | Keys mới + partial merge save + typography/video priority |
| `Services/SeoImageModelPriorityOptionsService.php` | Ẩn Gemini < 3 khỏi dropdown; label Legacy nếu cần |
| `Support/ImageRoutingStrategy.php` | `generalImageFallbackPriorityList` |
| `Services/GeminiMediaGenerationService.php` | Typography dùng `executionPolicy` + `modelsOverride` |
| `Services/TypographyPipelineService.php` | Typography priority + fallback list + validation model |
| `Services/TypographyCandidateGenerationService.php` | Truyền `modelsOverride` từ policy |
| `lang/vi|en/filament.php` | `settings_ai_advanced.*` + intro Workflows |
| `tests/Unit/ImageRoutingStrategyTest.php` | Test fallback General Image Priority |

---

## Migration setting (không DB migration)

Cùng `wp_options` key `seo_create_article_task` (OPTION_KEY).

| Key | Hành vi |
|---|---|
| `image_model_priority` | Giữ nguyên; chuyển UI sang AI Advanced |
| `rendering_preference` | Giữ nguyên; UI sang AI Advanced |
| `typography_validation_*` / `typography_allow_general_image_fallback` | Giữ nguyên; UI sang AI Advanced |
| `typography_model_priority` | **Mới** — trống → đọc `image_model_priority` (migrate-on-load) |
| `video_model_priority` | **Mới** — default Veo list nếu trống |
| `typography_validation_model` | **Mới** — optional preferred vision model |

`saveSettings()` đổi sang **partial merge**: lưu Workflows không ghi đè Advanced; lưu Advanced không xóa Prompt|Workflow.

---

## Backward compatibility

- Site cũ chỉ có `image_model_priority` → Typography Priority tự dùng list đó.
- Gemini 2.5 còn trong wp_options → load priority **lọc bỏ**; list rỗng → default Gemini 3.x / Imagen.
- Không yêu cầu user cấu hình lại.
- Không crash khi setting cũ còn slug legacy.

---

## Legacy model cleanup

- `GeminiModelVersionPolicy::MIN_MAJOR_VERSION = 3` (đã có).
- `normalizeImageModelPriorityList` / dropdown Advanced: bỏ slug không `isEligibleForAutoRouting`.
- `labelForSlug`: nếu còn slug legacy hiển thị `· Legacy` (không đưa vào select runtime).

---

## Bug fallback đã sửa

**Triệu chứng:** bật General Image Fallback vẫn throw kiểu «Không có model typography…».

**Nguyên nhân:**
1. `SeoSettingsWorkflows::saveSettings` không gửi / ghi đè keys validation → fallback luôn `false`.
2. `GeminiMediaGenerationService` / candidate gọi lại `modelsToTry(ImageTypography)` bỏ qua list đã fallback trong `executionPolicy`.

**Sửa tại:**
- `SeoCreateArticleSettingsService::saveSettings` — partial merge
- `ImageRoutingStrategy::executionPolicy` — `generalImageFallbackPriorityList`
- `GeminiMediaGenerationService::generateImage` — `modelsOverride` + typography `executionPolicy`
- `TypographyPipelineService` / `TypographyCandidateGenerationService` — truyền `policy->models`

Fallback tắt → mới throw thiếu typography model.

---

## Manual verification

```text
# Mở Settings → Workflows: chỉ Prompt|Workflow cho Ảnh / Typography / Gallery / Video
# Mở Settings → AI Advanced: Preference + 3 priority lists + Validation
# Bật General Image Fallback, priority typography chỉ Imagen → vẫn render bằng General Image
# wp_options còn gemini-2.5-* trong priority → load form / runtime không crash, dùng default 3.x
```
