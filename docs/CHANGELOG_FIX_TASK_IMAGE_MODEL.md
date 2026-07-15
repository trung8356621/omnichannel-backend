# CHANGELOG — Fix Task test image model vs Test Prompt

**Date:** 2026-07-15  
**Scope:** `TaskWorkflowTestRunner`, `TestTask`

---

## Bug

- **Test Prompt** (`tools=image|image_typography`): `runWithCompiledPrompt` → `ImageRoutingStrategy` → `imagen-4.0-generate-001` (OK).
- **Test Task** cùng prompt: `PromptRunner::run(..., runFullDependentChain: true)` → nếu có `sub_task`, bước cha chạy **text** `GEMINI_FLASH` (`gemini-3-flash-preview`), không đi image pipeline như Test Prompt → model sai / lỗi.

Node workflow `aiModel` (category Flash) còn bị truyền `modelOverride` và hiện trên UI dù image path không dùng.

---

## Fix

| File | Thay đổi |
|---|---|
| `Services/TaskWorkflowTestRunner.php` | Image tool: `runFullDependentChain=false` + bỏ `modelOverride` category → `runDirectImagePreview` / image pipeline như Test Prompt |
| `Services/TaskWorkflowTestRunner.php` | `runSingleStep`: không gắn text model override lên image node |
| `Filament/.../TestTask.php` | Label/options ưu tiên `render_model`; dropdown image dùng priority list |

---

## Manual verification

```text
Test Prompt image → imagen / Nano Banana (như trước)
Test Task cùng prompt image → cùng model family, output URL ảnh
Bước text blueprint vẫn dùng Flash (đúng)
```
