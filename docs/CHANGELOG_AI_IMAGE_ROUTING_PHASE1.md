# CHANGELOG — AI Image Routing Phase 1

**Date:** 2026-07-15  
**Scope:** `app/Addons/SeoContentAi/` (+ docs)

---

## Behavior cũ

- Prompt form chọn **Model đại diện** (`model_category`) — image path gần như không dùng.
- Editor Settings: task tạo ảnh + gallery prompt + video prompt; fallback ngầm `gallery → task → legacy prompt`.
- Render ảnh thật: `image_model_priority` + `ImageModelInputLengthPolicy` qua `GoogleAiModelRegistry::imageModelsToTry`.
- Song song còn `AiModelRouterService` / `AiModelCategory` → `raw_model_used` dễ nhầm với model sinh ảnh.
- History ưu tiên `step.ai_model` (planner) hơn model render.
- Không có tool Typography riêng; không có Rendering Preference cho khách.

---

## Behavior mới

1. **Một cửa routing ảnh:** `ImageRoutingStrategy` (tool + RenderingPreference + capability + length policy cho ảnh thường + product exclude Imagen).
2. **Prompt:** bỏ UI Model đại diện; thêm tool `image_typography`.
3. **Settings:** Rendering Preference (`cost_first` / `balanced` / `quality_first`); mỗi slot Editor (ảnh bài / gallery / typography / video) chỉ **Prompt | Workflow**.
4. **Capability:** sync ghi `capabilities.resolved`; Model Status nhóm Text / Image / Image Typography / Video / Unknown; Unknown không route mặc định (admin có thể Enable).
5. **History:** `render_model` + `planner_model`; Media AI không lấy planner làm model ảnh.
6. **Workflow Phase 1:** vẫn extract prompt cuối (`resolveImagePromptForTask` / `resolveVideoPromptForTask`) — chưa execute full graph.

---

## Files chính

### Support (mới)

| File | Vai trò |
|---|---|
| `Support/ImageToolType.php` | Enum tool + helpers |
| `Support/ImageCapability.php` | Capability tối thiểu Phase 1 |
| `Support/ImageCapabilityResolver.php` | Resolve capability / merge sync |
| `Support/RenderingPreference.php` | Preference khách |
| `Support/TypographyComplexity.php` | Hook Phase 2 |
| `Support/ImageRoutingStrategy.php` | Entry duy nhất list model thử |

### Runtime / Settings / UI

| File | Thay đổi |
|---|---|
| `Services/GeminiMediaGenerationService.php` | Gọi strategy; return `{url, usage, model_used}` |
| `Services/MediaGenerationService.php` | Pipeline image/image_typography; bỏ preferred routed model |
| `Services/PromptRunnerService.php` | Image bypass category router; snapshot render/planner |
| `Services/AiModelRouterService.php` | Text category từ preference; sync `resolved`; overview grouped; toggle Unknown |
| `Support/AiModelCategory.php` | Deprecate image / bỏ tin `model_category` prompt |
| `Support/GoogleAiModelRegistry.php` | `imageModelsToTry` → wrapper strategy (BC tests) |
| `Services/SeoCreateArticleSettingsService.php` | Keys source/preference/unknown; migrate-on-load |
| `Filament/Pages/SeoSettingsWorkflows.php` | Preference + Prompt\|Workflow slots |
| `Filament/Pages/SeoSettingsOverview.php` + blade | Model Status theo capability + advanced |
| `Filament/Resources/PromptResource.php` (+ Create/Edit) | Bỏ model_category UI; tool Typography |
| `Services/ArticleEditorMediaAiService.php` | Resolve theo source đã migrate |
| `Services/EditorImageTaskResolverService.php` | + `resolveVideoPrompt` |
| `Services/TaskWorkflowTestRunner.php` | Image pipeline tools; `resolveVideoPromptForTask` |
| `Services/ArticlePromptRunHistoryService.php` + history blades | Render vs planner |
| `Services/SeoPromptSettingsOptionsService.php` | Options image / typography |
| Lang `vi`/`en` filament.php | Labels mới |
| `tests/Unit/ImageRoutingStrategyTest.php` | Unit strategy/capability/tool |

---

## Settings keys mới

| Key | Ý nghĩa |
|---|---|
| `rendering_preference` | `cost_first` \| `balanced` \| `quality_first` |
| `create_image_source` | `prompt` \| `workflow` |
| `create_product_gallery_source` | idem |
| `create_typography_image_source` | idem |
| `create_video_source` | idem |
| `create_product_gallery_image_task_id` | Workflow gallery |
| `create_typography_image_prompt_id` / `_task_id` | Typography slot |
| `create_video_task_id` | Workflow video (khác alias cũ `getCreateVideoTaskId`) |
| `admin_enabled_unknown_image_models` | list slug Unknown admin bật |

Giữ: `create_image_task_id`, `create_image_prompt_id` (legacy), `image_model_priority`, `create_video_prompt_id`, gallery prompt id.

---

## Backward compatibility

- Cột `prompts.model_category` **không xóa**; form không ghi đè khi save (unset).
- Prompt `tools=image` cũ vẫn image pipeline.
- Site chỉ có `create_image_task_id` → migrate source=`workflow`.
- Site chỉ có legacy `create_image_prompt_id` → source=`prompt`.
- Gallery/video cũ prompt-only → source=`prompt`.
- `ImageModelInputLengthPolicy` giữ nguyên cho **image + balanced**.
- `GoogleAiModelRegistry::imageModelsToTry` vẫn có (deprecated wrapper).
- Snapshot `raw_model_used` = alias `render_model` khi image.
- Text path vẫn dùng category router, nhưng category lấy từ **RenderingPreference**, không từ prompt.

---

## Deprecated paths

- `AiModelCategory::resolveForPrompt` điều khiển image rendering — **không** còn trên image path.
- `Prompt.model_category` / UI “Lựa chọn Model đại diện”.
- Fallback chuỗi `gallery → workflow → legacy` sau khi source đã xác định.
- Truyền `$routedModel` category vào `executeImage` làm preferred.

---

## Tests

### Unit đã thêm (chưa chạy local — remote-first)

- `ImageRoutingStrategyTest` — tool helpers, capability map, typography filter, product exclude Imagen, balanced length policy, typography ignore length, unknown admin enable.

### Manual verification

```text
Manual verification:

1. Sync AI models → Overview nhóm Text/Image/Image Typography/Video; Advanced thấy raw + capabilities.resolved
2. Prompt form: không còn Model đại diện; có Image (Typography)
3. Settings → Rendering Preference + 4 slot Prompt|Workflow
4. Site cũ chỉ task_id / chỉ legacy prompt_id: Editor «Tạo ảnh» vẫn chạy
5. Generate ảnh Editor: History hiện render model (Nano Banana/Imagen), không gemini-3-flash-preview nếu đó chỉ planner
6. Prompt image_typography: không route Imagen (thiếu typography_supported)
7. Preference Cost first / Quality first: đổi thứ tự Flash/Pro trên ảnh thường
```

---

## Phase 2 (chưa làm)

- Full workflow execute từ Editor
- TypographyComplexity parser + OCR candidates
- Trang AI Settings tách khỏi Workflows
- Xóa cột DB legacy
