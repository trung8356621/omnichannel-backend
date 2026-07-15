# Audit: Logic chọn model sinh ảnh (Editor / Workflow / Product gallery)

**Ngày quét:** 2026-07-14  
**Phạm vi:** `app/Addons/SeoContentAi/` (+ liên quan frontend React editor)  
**Không sửa code.** Kết luận chỉ dựa trên source đã truy được.

---

## A. Luồng thực thi hiện tại

### A1. Prompt trực tiếp / “Tạo ảnh” từ Editor

```text
SeoArticleEditor.jsx
  requestGenerateArticleImage()
    → callEditArticleLivewire('generateArticleImageFromEditor', …)
EditArticle::generateArticleImageFromEditor()
  → ArticleEditorMediaAiService::generateImage()
      → resolveEditorImagePrompt($target)   // chọn SeoPrompt
      → buildVariables / filterVariablesForPrompt
      → createPlaceholderMedia (seo_media: prompt_id, prompt_variables, status=processing)
      → GenerateMediaJob::dispatch(mediaId, promptId, variables, 'image')
           // runFullDependentChain = false (mặc định)
GenerateMediaJob::handle()
  → PromptRunnerService::run(prompt, variables, isTaskMode:false, runFullDependentChain:false)
      ├─ tools=image + có sub_task  → runDirectImagePreview()
      │     → MediaGenerationService::executeImage()   // KHÔNG qua chuỗi planner→sub
      └─ tools=image + không sub_task → executeWithModelRouting()
            → callProvider() → MediaGenerationService::executeImage()
MediaGenerationService::executeImage()
  → đo độ dài compiled prompt (UTF-8, trim)
  → GeminiMediaGenerationService::generateImage(..., preferredModel=null, inputLength=…)
       → GoogleAiModelRegistry::imageModelsToTry(priority từ settings + reorder theo length)
       → thử lần lượt: Nano Banana / Imagen API
       → storeBinaryMedia(..., $model)  // ghi seo_media.ai_generator = model thật
```

**Frontend payload (Livewire):**  
`selectionText`, `selectionHtml`, `userBrief`, `activeBlockId`, `target`, `loaiSanPhamCategoryArticleId`, `loaiSanPhamCustom`  

**Không gửi:** `prompt_id`, `workflow_id`, `model_category`, `tool`, `image_type`, `typography_mode`.  
Prompt/workflow lấy 100% từ Settings phía server.

**Files:**
- `resources/js/components/SeoArticleEditor.jsx` → `requestGenerateArticleImage`
- `resources/js/components/GenerateImageModal.jsx` (UI brief / preview)
- `Filament/Resources/ArticleResource/Pages/EditArticle.php` → `generateArticleImageFromEditor`, `previewGenerateArticleImagePrompt`
- `Services/ArticleEditorMediaAiService.php` → `generateImage`, `resolveEditorImagePrompt`
- `Jobs/GenerateMediaJob.php`
- `Services/PromptRunnerService.php` → `run`, `runDirectImagePreview`, `callProvider`
- `Services/MediaGenerationService.php` → `executeImage`
- `Services/GeminiMediaGenerationService.php` → `generateImage`

### A2. “Workflow tạo ảnh” (Settings `create_image_task_id`)

**Quan trọng:** Editor **không chạy toàn bộ graph workflow**.  
Settings lưu `SeoTask` id → resolver **chỉ lấy SeoPrompt tools=image cuối cùng** trong task graph.

```text
SEO → Settings → Workflows → «Quy trình tạo ảnh» (create_image_task_id)
  → EditorImageTaskResolverService::resolveImagePrompt($taskId)
  → TaskWorkflowTestRunner::resolveImagePromptForTask($task)
       // duyệt ordered nodes, giữ lại prompt tools=image cuối cùng
  → tiếp tục đúng luồng A1 với SeoPrompt đó
```

**Files:**
- `Services/SeoCreateArticleSettingsService.php` → `KEY_CREATE_IMAGE = create_image_task_id`
- `Services/EditorImageTaskResolverService.php` → `resolveImagePrompt`
- `Services/TaskWorkflowTestRunner.php` → `resolveImagePromptForTask` (khoảng dòng 2199)

Fallback nếu không có task: `create_image_prompt_id` (legacy) qua `getLegacyCreateImagePromptId()`.

Chuỗi task→sub_task **đầy đủ** (`ImageGenerationChainService` / `runFullDependentChain=true`) chỉ chạy khi gọi PromptRunner với chain bật (vd. trang Test Prompt / Test Task) — **không** phải job Editor mặc định (`GenerateMediaJob` luôn `runFullDependentChain=false`).

### A3. Product gallery

```text
target = 'product-gallery'
  → resolveEditorImagePrompt:
       1) create_product_gallery_image_prompt_id (nếu có) → SeoPrompt image
       2) else cùng luồng A2 (task / legacy)
  → GenerateMediaJob giống A1
  → MediaGenerationService::isProductImageContext() = true
       → excludeImagen=true (chỉ Nano Banana, bỏ Imagen)
```

**Files:** `ArticleEditorMediaAiService::resolveEditorImagePrompt`, `MediaGenerationService::isProductImageContext`.

### A4. Infographic / typography

- **Không** tìm thấy field `typography_mode`, `image_type=infographic`, hay tool riêng.
- “Infographic” chỉ là **nhãn mô tả** trong bảng Settings (`ImageModelInputLengthPolicy::routingTableRows` + lang `image_model_rule_infographic`).
- Thực tế: prompt render dài (>1000 ký tự) → ưu tiên tier **Pro** trong danh sách image models.

---

## B. Bảng quyết định model thực tế

| Trường hợp | File/hàm quyết định | Input dùng để quyết định | Model kết quả | Có fallback không |
|---|---|---|---|---|
| **Render ảnh (Editor / gallery / job)** | `GeminiMediaGenerationService::generateImage` + `GoogleAiModelRegistry::imageModelsToTry` + `ImageModelInputLengthPolicy::reorderModels` | (1) `SeoCreateArticleSettingsService::getImageModelPriority()` (2) `mb_strlen(trim(compiled_prompt))` (3) `excludeImagen` nếu product context | Model **image** đầu tiên trong list sau reorder thành công (vd. `gemini-2.5-flash-image` / `gemini-2.5-pro-image` / `imagen-4.0-…` / slug user cấu hình) | Có: lần lượt toàn bộ list; retry khi `isRetryable` |
| Tier Flash vs Pro theo độ dài | `ImageModelInputLengthPolicy::preferredTier` / `reorderModels` | `inputLength <= 1000` → Flash trước; `> 1000` → Pro trước | Chỉ **sắp lại thứ tự**, không đổi danh sách | Tier còn lại vẫn thử sau |
| Category `prompts.model_category` / `AiModelRouterService` khi tools=image | `AiModelCategory::resolveForPrompt` → luôn `imagen_pro` nếu `toolType===image` | `prompts.model_category` bị **bỏ qua** khi tools=image | Category router: `imagen_pro` | Failover trong `seo_ai_models` theo category |
| **Router raw model khi gọi image pipeline** | `PromptRunnerService::executeWithModelRouting` → `AiModelRouterService::executeWithFailover` | Category `imagen_pro` / DB `seo_ai_models` | Trả `rawModel` vào `callProvider` | Có (quota/rate-limit) |
| **Ghi đè preferred model vào API ảnh** | `MediaGenerationService::executeImage` | Tham số `$routedModel` | **Không dùng** — luôn truyền `preferredModel: null` vào GeminiMediaGeneration | N/A — dead path |
| Prompt không có sub_task, tools=image | `PromptRunnerService::run` → `executeWithModelRouting` → `executeImage` | Như trên | Render = priority+length; log `raw_model_used` = model **router**, không phải model API ảnh | Router failover ≠ image list failover |
| Prompt có sub_task, Editor job | `runDirectImagePreview` | compile **toàn bộ** parts rồi `executeImage` | Render = priority+length | **Không** ghi `raw_model_used` |
| Chuỗi đầy đủ (Test / `runFullDependentChain=true`) | `resolveStepToolType` + `resolveStepCategory` | Parent `task` → tool text, category ép `GEMINI_FLASH`; `sub_task` → image, `IMAGEN_PRO` | Planner: text flash (thường `gemini-3-flash-preview`); Render: priority+length | Text: GeminiModelCatalog; Image: priority list |
| Product gallery / product context | `isProductImageContext` | `post_type=product` hoặc biến loai/gallery | Loại Imagen khỏi list | Chỉ Nano Banana (+ thứ tự Flash/Pro theo length) |
| Truncate prompt rất dài | `buildImageGenerationInput` + `LONG_INPUT_CHARS=5000` | length > 5000 | Cắt còn 8000 + note; tier vẫn Pro | Không đổi model |

Default priority (khi Settings trống):  
`SeoCreateArticleSettingsService::defaultImageModelPriority()` =  
`gemini-2.5-flash-image`, `gemini-2.5-pro-image`, `imagen-4.0-generate-001`.

---

## C. Rule theo độ dài prompt

| Câu hỏi | Kết quả quét |
|---|---|
| Đã implement thật? | **Có.** `Support/ImageModelInputLengthPolicy.php` + gọi từ `GoogleAiModelRegistry::imageModelsToTry` khi `$inputLength !== null` + `MediaGenerationService::executeImage` luôn truyền length. |
| Ngưỡng thực thi | **Một ngưỡng duy nhất:** `FLASH_MAX_CHARS = 1000`. `≤1000` → tier flash trước; `>1000` → tier pro trước. `LONG_INPUT_CHARS = 5000` chỉ để **truncate**, không đổi tier thêm. |
| Đếm gì? | `measureCompiledPromptLength` = `mb_strlen(trim($compiledPrompt))` — prompt **đã render/substitute biến**, trước khi gắn wrapper `"Generate exactly ONE image…"`. Không đếm token API. Không đếm “blueprint” riêng. |
| Bảng Settings 0–300 / 301–1000 / 1001–2500 / … | `routingTableRows()` — **chỉ UI Placeholder** trong `SeoSettingsWorkflows`. Code **không** đọc các khoảng 300/2500. Tất cả khoảng ≤1000 = Flash; >1000 = Pro. |
| HelperText ↔ backend | Hint i18n (`image_model_priority_hint`) khớp ngưỡng 1000 + đo rendered UTF-8 + fallback theo list. Bảng chi tiết “infographic 2501–5000” = **mô tả UX**, không phải branch riêng. |
| Production có gọi? | **Có**, mọi `executeImage` từ Editor job. Unit: `tests/Unit/ImageModelInputLengthPolicyTest.php`. |
| Có ghi đè sau đó? | Sau reorder, `GeminiMediaGenerationService` thử tuần tự; model **thật** = first success. `$routedModel` từ AiModelRouter **không** đẩy lên đầu list (bị null). |

---

## D. Logic cạnh tranh / thứ tự ưu tiên thực tế

### D1. Chọn *SeoPrompt* (trước khi nghĩ tới model)

1. `target=product-gallery` + có `create_product_gallery_image_prompt_id` → prompt gallery  
2. Else `create_image_task_id` → **prompt image cuối** trong workflow task  
3. Else `create_image_prompt_id` (legacy)  
4. Thiếu hết → exception Settings

Workflow step **không** gắn model riêng per-node trên Editor path; chỉ chọn prompt.

### D2. Chọn *model render ảnh* (thứ tự thật)

1. List từ Settings `image_model_priority` (lọc bỏ slug text)  
2. `ImageModelInputLengthPolicy::reorderModels` (Flash/Pro theo length)  
3. Nếu product context: bỏ Imagen  
4. Thử API tuần tự đến khi có ảnh  
5. Ghi `seo_media.ai_generator` = slug model thành công  

### D3. Layer “cạnh tranh” / lệch nhau

| Layer | Vai trò thực | Có ảnh hưởng render Editor? |
|---|---|---|
| Settings `image_model_priority` | List + thứ tự trong tier | **Có** |
| `ImageModelInputLengthPolicy` | Reorder Flash/Pro | **Có** |
| `prompts.model_category` | Category cho text / router | **Không** khi tools=image (`resolveForPrompt` ép `imagen_pro`) |
| `AiModelRouterService` / `seo_ai_models` | Pick raw model + failover category | Truyền vào `executeImage` rồi **bị bỏ** (`preferredModel=null`) |
| `AiModelRouterService::fallbackRawModelName(GEMINI_FLASH)` → `gemini-3-flash-preview` | Text planner / text tool | **Không** render ảnh Editor; dễ lọt vào **history** nếu nhầm planner vs media |
| Prompt `ai_model` cột legacy | Còn migration/schema cũ | Không thấy dùng trong image pipeline hiện tại |
| MCP (Cursor) | Tool IDE | **Không** có trong addon SEO; không tham gia routing runtime |
| Docs UI bảng multi-range | Mô tả | Không điều khiển code |

**Thứ tự ưu tiên thực tế (render):**  
`Settings priority list` → `reorder theo length` → `exclude Imagen (product)` → `first successful API call`  

**Không** có: `prompt.model` → `workflow.step.model` → `global override` theo nghĩa ghi đè slug render (global chỉ là list priority).

---

## E. Planner model vs Render model

| Khái niệm | Khi nào chạy | Model | Log |
|---|---|---|---|
| **Planner (text)** | Chỉ khi `runFullDependentChain=true` + prompt có `sub_task`: step `role=task` ép `GEMINI_FLASH` (`resolveStepCategory`) | Thường `gemini-3-flash-preview` (fallback category flash) | `prompt_results.input_snapshot.raw_model_used` = text model |
| **Render (image)** | `executeImage` / Gemini native hoặc Imagen | Flash-image / Pro-image / Imagen theo B | `seo_media.ai_generator` = model thật; `raw_model_used` **thường sai hoặc trống** |
| Editor mặc định | `GenerateMediaJob` `runFullDependentChain=false` | **Không chạy planner riêng**; nếu có sub_task → `runDirectImagePreview` gộp toàn bộ parts thành 1 image prompt | `runDirectImagePreview` **không** ghi `raw_model_used` |

### Vì sao history hay hiện `gemini-3-flash-preview`

1. Đó là **text Flash** (`GoogleAiModelRegistry` CATEGORY_TEXT; `AiModelRouterService::fallbackRawModelName` cho `GEMINI_FLASH`).  
2. **Không** nằm trong image priority defaults.  
3. UI lịch sử (`ArticlePromptRunHistoryService`, field `model`) lấy `step['ai_model']` hoặc `snapshot['raw_model_used']` — **không** đọc `seo_media.ai_generator`.  
4. Với image path qua router: `raw_model_used` = model **category imagen_pro từ DB**, không phải model đã generate ảnh.  
5. Với `runDirectImagePreview` (Editor + prompt có sub_task): `raw_model_used` **không được set** → UI có thể trống hoặc lấy nhầm step khác.

**Render model thật được lưu ở:** `seo_media.ai_generator` (qua `PromptMediaStorageService::storeBinaryMedia`).

**Chỗ thiếu / sai log:**
- `MediaGenerationService::executeImage` không return model đã dùng; discard `$routedModel`.  
- `PromptRunnerService::runDirectImagePreview` không merge `raw_model_used`.  
- History không map `ai_generator` / không tách `planner_model` vs `render_model`.

---

## F. Kết luận

**Có nhiều logic cạnh tranh, cần gom lại.**

Chi tiết:
- **Routing render thật** tồn tại và **đang chạy** production (length + settings priority) — không phải “chỉ default cứng”.  
- Song song tồn tại **AiModelRouter category path** và **UI bảng multi-range** gây hiểu nhầm / log sai.  
- Editor “workflow” = **extract prompt**, không execute full task graph.  
- Không có routing riêng typography/infographic ngoài proxy độ dài prompt.

Không chọn “chỉ dùng model mặc định” vì Settings priority + reorder length điều khiển list thực tế.

---

## G. Đề xuất thay đổi tối thiểu (chưa code)

| # | Đề xuất | File chính | Migration? | Rủi ro BC |
|---|---|---|---|---|
| 1 | **Giữ** `ImageModelInputLengthPolicy` + `image_model_priority` làm nguồn render duy nhất | `ImageModelInputLengthPolicy.php`, `GoogleAiModelRegistry.php`, `SeoCreateArticleSettingsService.php` | Không | Thấp |
| 2 | **Sửa** `MediaGenerationService::executeImage`: trả về model thành công; bỏ hoặc dùng thật `$routedModel` | `MediaGenerationService.php`, `GeminiMediaGenerationService.php` | Không | Trung bình nếu ai đang phụ thuộc `raw_model_used` sai |
| 3 | **Ghi** `raw_model_used` = model ảnh thật (và tách `planner_model` khi chain) | `PromptRunnerService.php` (`run`, `runDirectImagePreview`) | Không bắt buộc; optional cột JSON | Thấp nếu chỉ sửa snapshot |
| 4 | **History** ưu tiên `seo_media.ai_generator` cho Media AI | `ArticlePromptRunHistoryService.php` | Không | Thấp |
| 5 | **Settings UI:** hoặc đơn giản bảng về 2 dòng (≤1000 Flash / >1000 Pro), hoặc implement đủ range trong policy | `SeoSettingsWorkflows.php`, lang, `ImageModelInputLengthPolicy::routingTableRows` | Không | UX only nếu chỉ sửa bảng |
| 6 | **Bỏ / disable** truyền category router vào image `preferred` cho đến khi có quyết định unify | `PromptRunnerService::callProvider` | Không | Thấp (hiện đã bỏ) |
| 7 | Document rõ Editor không chạy full workflow; chỉ lấy image prompt cuối | docs / helperText Settings | Không | Không |
| 8 | MCP | Không cần nối | — | — |

**Không cần migration** cho việc làm đúng log + gom routing, trừ khi muốn cột riêng `render_model` / `planner_model` trên `prompt_results` (hiện `input_snapshot` JSON đủ chứa).

---

## Phụ lục — Symbol chính

| Symbol | Path |
|---|---|
| `ImageModelInputLengthPolicy` | `app/Addons/SeoContentAi/Support/ImageModelInputLengthPolicy.php` |
| `GoogleAiModelRegistry::imageModelsToTry` | `app/Addons/SeoContentAi/Support/GoogleAiModelRegistry.php` |
| `GeminiMediaGenerationService::generateImage` | `app/Addons/SeoContentAi/Services/GeminiMediaGenerationService.php` |
| `MediaGenerationService::executeImage` | `app/Addons/SeoContentAi/Services/MediaGenerationService.php` |
| `ArticleEditorMediaAiService::resolveEditorImagePrompt` | `app/Addons/SeoContentAi/Services/ArticleEditorMediaAiService.php` |
| `EditorImageTaskResolverService` | `app/Addons/SeoContentAi/Services/EditorImageTaskResolverService.php` |
| `TaskWorkflowTestRunner::resolveImagePromptForTask` | `app/Addons/SeoContentAi/Services/TaskWorkflowTestRunner.php` |
| `GenerateMediaJob` | `app/Addons/SeoContentAi/Jobs/GenerateMediaJob.php` |
| `PromptRunnerService::run` / `runDirectImagePreview` / `resolveStepCategory` | `app/Addons/SeoContentAi/Services/PromptRunnerService.php` |
| `AiModelRouterService::executeWithFailover` | `app/Addons/SeoContentAi/Services/AiModelRouterService.php` |
| `AiModelCategory::resolveForPrompt` | `app/Addons/SeoContentAi/Support/AiModelCategory.php` |
| `SeoCreateArticleSettingsService` keys | `KEY_CREATE_IMAGE`, `KEY_CREATE_PRODUCT_GALLERY_IMAGE`, `KEY_IMAGE_MODEL_PRIORITY`, `KEY_LEGACY_CREATE_IMAGE_PROMPT` |
| `SeoSettingsWorkflows` | `app/Addons/SeoContentAi/Filament/Pages/SeoSettingsWorkflows.php` |
| `ImageGenerationChainService` | `app/Addons/SeoContentAi/Services/ImageGenerationChainService.php` |
| Test length policy | `app/Addons/SeoContentAi/tests/Unit/ImageModelInputLengthPolicyTest.php` |

---

*Hết audit. Không có thay đổi runtime từ tài liệu này.*
