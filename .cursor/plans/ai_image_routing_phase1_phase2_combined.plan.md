---
name: AI Image Routing Phase1 + Phase2
 overview: "Hoàn thiện kiến trúc AI Image Routing đã làm ở Phase 1 và triển khai Phase 2 cho typography: chạy workflow thật theo lựa chọn Settings, phân tích TypographyComplexity, sinh nhiều candidate, kiểm tra exact text bằng Vision, chấm điểm và giữ ảnh tốt nhất; vẫn giữ backward compatibility và không tạo routing song song."
todos:
  - id: verify-phase1
    content: Đối chiếu CHANGELOG Phase 1 với source thực tế, sửa regression nhỏ trước khi mở Phase 2
    status: pending
  - id: workflow-runtime
    content: Cho Editor chạy đúng Prompt trực tiếp hoặc full Workflow theo source đã chọn; giữ chế độ extract-last-prompt làm BC fallback
    status: pending
  - id: typography-parser
    content: Hoàn thiện TypographyComplexity parser từ compiled prompt/blueprint và chuẩn hóa exact text blocks
    status: pending
  - id: typography-routing
    content: Mở rộng ImageRoutingStrategy dùng TypographyComplexity + RenderingPreference để chọn model/candidate count/resolution
    status: pending
  - id: candidate-generation
    content: Sinh nhiều candidate cho image_typography, lưu tạm và không ghi media chính thức trước khi chọn ảnh thắng
    status: pending
  - id: validation-scoring
    content: Tạo TypographyValidationService dùng Vision/OCR một lần mỗi candidate, so exact text và chấm điểm
    status: pending
  - id: winner-lifecycle
    content: Giữ candidate tốt nhất, xóa candidate lỗi/tạm, ghi render_model và validation metadata vào history
    status: pending
  - id: settings-ui
    content: Bổ sung Typography Quality Controls trong AI Settings, không lộ raw model ở UI mặc định
    status: pending
  - id: tests-observability
    content: Thêm unit/integration/manual tests, metrics và changelog Phase 2
    status: pending
isProject: false
---

# AI Image Routing — Combined Phase 1 + Phase 2

## Tài liệu phải đọc trước

- `AUDIT_IMAGE_MODEL_ROUTING.md`
- `MAP_SEO_SETTINGS.md`
- `CHANGELOG_AI_IMAGE_ROUTING_PHASE1.md`

`CHANGELOG_AI_IMAGE_ROUTING_PHASE1.md` mô tả những gì Cursor cho rằng đã triển khai. Không được mặc định changelog đồng nghĩa code đã đúng; phải đối chiếu source trước khi mở rộng.

---

# Mục tiêu tổng thể

1. Duy trì **một cửa routing ảnh** qua `ImageRoutingStrategy`.
2. Prompt chỉ khai báo tool và nội dung; không chọn model đại diện.
3. Settings là nơi duy nhất chọn:
   - Rendering Preference;
   - nguồn chạy `Prompt | Workflow` cho từng slot.
4. `Prompt` chạy trực tiếp để tiết kiệm.
5. `Workflow` phải có khả năng chạy full graph khi người dùng đã chọn workflow.
6. `image_typography` có pipeline riêng:
   - phân tích độ phức tạp;
   - sinh nhiều candidate khi cần;
   - kiểm tra chữ;
   - giữ ảnh tốt nhất.
7. History phải phân biệt rõ:
   - planner model;
   - render model;
   - validation model.
8. Không tạo một routing mới song song với Phase 1.

---

# Phạm vi và nguyên tắc

## Nguyên tắc bắt buộc

- Không dựa hoàn toàn vào tổng độ dài prompt cho typography.
- Không để Prompt, Workflow step, Settings và legacy router cùng cạnh tranh quyết định model.
- Không rải `if image_typography` ở nhiều service.
- Không dùng OCR lặp vô hạn; số lần validation phải có giới hạn rõ.
- Không ghi candidate lỗi thành media chính thức của bài viết.
- Không phá dữ liệu cũ.
- Không xóa schema legacy trong lần này.

## Không làm

- Không thêm provider mới trong kế hoạch này.
- Không làm editor đồ họa/vector overlay.
- Không cam kết typography 100% chính xác.
- Không tự động tăng candidate vô hạn theo retry.
- Không thay đổi toàn bộ workflow engine ngoài phạm vi chạy từ Editor.

---

# Phase 0 — Verify Phase 1 trước khi mở Phase 2

## 0.1 Đối chiếu code với CHANGELOG

Xác minh source thật sự đã có và đang được gọi production:

- `ImageToolType`
- `ImageCapability`
- `ImageCapabilityResolver`
- `RenderingPreference`
- `TypographyComplexity`
- `ImageRoutingStrategy`
- `render_model` / `planner_model`
- Prompt tool `image_typography`
- Settings `Prompt | Workflow`
- `capabilities.resolved`
- Unknown model không route mặc định

## 0.2 Chặn Phase 2 nếu còn lỗi nền

Không triển khai candidate/validation nếu còn một trong các lỗi:

- image path vẫn đi qua `AiModelCategory` để chọn render model;
- `GeminiMediaGenerationService` không trả model thật;
- history vẫn lấy planner model làm render model;
- `image_typography` chưa đi qua image pipeline;
- source `Prompt | Workflow` chưa được resolve nhất quán;
- `ImageRoutingStrategy` chưa là entry duy nhất.

## 0.3 Regression cần sửa ngay nếu phát hiện

- Changelog ghi có nhưng code không dùng.
- UI Settings hiển thị khác logic backend.
- Unknown model lọt vào default priority.
- Capability của model text bị gắn `image_generation` chỉ vì tên có chữ image.

---

# Phase 1 — Baseline architecture cần giữ

Phase 1 được xem là nền tảng, không viết lại nếu đang đúng.

## 1.1 Tool

```text
default
image
image_typography
video
```

## 1.2 Capability tối thiểu

```text
text_generation
image_generation
general_image
typography_supported
typography_recommended
video_generation
unknown
```

`product` là context, không phải capability.

## 1.3 Rendering Preference

```text
cost_first
balanced
quality_first
```

Preference chỉ điều chỉnh mức chi phí/chất lượng và thứ tự ưu tiên. Không chứa logic typography riêng.

## 1.4 Routing duy nhất

Mọi image pipeline phải đi qua:

```text
ImageRoutingStrategy
```

`ImageModelInputLengthPolicy` chỉ tiếp tục áp dụng trực tiếp cho ảnh thường ở mode `balanced`.

## 1.5 History

Snapshot chuẩn:

```text
planner_model
render_model
validation_model
raw_model_used   // alias BC: render_model với image run
```

Không được suy luận render model từ planner step.

---

# Phase 2A — Prompt trực tiếp và Full Workflow từ Editor

## 2A.1 Hành vi theo Settings

Mỗi slot Editor có:

```text
source = prompt | workflow
```

### Nếu source = prompt

```text
Editor
→ resolve prompt được chọn
→ compile variables
→ ImageRoutingStrategy
→ render
```

Đây là đường chạy rẻ và nhanh.

### Nếu source = workflow

```text
Editor
→ resolve workflow được chọn
→ execute full graph
→ planner / transform / image / validation steps theo workflow
→ trả media cuối
```

Không tiếp tục giả vờ chạy workflow nhưng chỉ extract prompt cuối.

## 2A.2 Backward compatibility

Giữ `extract last image prompt` làm chế độ BC nội bộ, không phải lựa chọn mặc định mới.

Cách xử lý:

- workflow cũ chưa tương thích full execution → fallback có log cảnh báo;
- workflow mới → full execution;
- không fallback im lặng;
- history phải ghi `workflow_execution_mode`:
  - `full_graph`
  - `extract_last_prompt_bc`

## 2A.3 Contract kết quả workflow

Full workflow từ Editor phải trả object thống nhất:

```php
[
    'media_id' => int|null,
    'url' => string|null,
    'planner_model' => string|null,
    'render_model' => string|null,
    'validation_model' => string|null,
    'usage' => array,
    'workflow_execution_mode' => string,
    'metadata' => array,
]
```

Không để mỗi runner trả shape khác nhau.

## 2A.4 Guardrails

- Chỉ một image output cuối được gắn vào bài viết.
- Intermediate image không xuất hiện trong Media Library chính thức trừ khi workflow yêu cầu rõ.
- Job phải idempotent theo media/job id.
- Retry job không tạo hàng loạt media trùng.

---

# Phase 2B — TypographyComplexity parser

## 2B.1 Mục tiêu

Biến `TypographyComplexity` từ stub thành dữ liệu thật để router và validator dùng chung.

## 2B.2 Input ưu tiên

Thứ tự nguồn:

1. Blueprint có cấu trúc/JSON nếu có.
2. Markdown blueprint đã compile.
3. Compiled prompt cuối.
4. User brief như fallback thấp nhất.

Không parse instruction chung thành visible text nếu nội dung đó không được yêu cầu xuất hiện trong ảnh.

## 2B.3 Output chuẩn

```php
TypographyComplexity {
    language: string,
    exact_text_required: bool,
    visible_text_blocks: array,
    visible_text_chars: int,
    text_block_count: int,
    max_text_block_length: int,
    title_count: int,
    label_count: int,
    paragraph_count: int,
    layout_type: string|null,
    panel_count: int,
    node_count: int,
    relation_count: int,
    visual_density: string,
    complexity_score: float,
}
```

## 2B.4 Visible text blocks

Mỗi block phải có:

```php
[
    'id' => 'title|panel_1|node_2_label',
    'text' => 'Chuỗi chính xác',
    'required' => true,
    'weight' => 1.0,
    'type' => 'title|label|body|cta|number',
]
```

Validation phải so trên danh sách này, không so toàn bộ prompt.

## 2B.5 Complexity score

Không dùng một công thức bí mật rải rác. Tập trung trong parser/service và có test.

Tín hiệu tối thiểu:

- số ký tự hiển thị;
- số block;
- block dài nhất;
- paragraph count;
- node/panel count;
- layout mindmap/flowchart/table/process;
- exact text required;
- ngôn ngữ có dấu/Unicode.

Score chỉ phục vụ routing và candidate policy, không dùng để quảng cáo độ chính xác.

---

# Phase 2C — Typography routing policy

## 2C.1 Mọi quyết định vẫn qua `ImageRoutingStrategy`

Không tạo `TypographyModelRouter` độc lập.

`ImageRoutingStrategy` nhận `TypographyComplexity` và trả thêm execution policy:

```php
[
    'models' => [...],
    'candidate_count' => 1|2|3,
    'resolution' => '1K|2K|4K',
    'validation_required' => bool,
    'minimum_score' => float,
    'max_render_attempts' => int,
]
```

## 2C.2 Policy khởi đầu

### Cost first

- Typography nhẹ → Flash Image trước.
- Candidate mặc định: 1.
- Validation chỉ khi `exact_text_required=true`.
- Tối đa 1 lần regenerate bổ sung.

### Balanced

- Nhẹ → Flash Image trước, Pro fallback.
- Trung bình/nặng → Pro trước.
- Candidate: 2 với exact text; 1 nếu typography rất nhẹ.
- Mặc định 2K.

### Quality first

- Pro/typography recommended trước.
- Candidate: 3 với exact text.
- Mặc định 2K; 4K chỉ khi output yêu cầu lớn hoặc chữ rất dày.
- Tối đa 1 vòng regenerate sau validation.

## 2C.3 Không hard-code theo tên model trong router

Router dựa trên:

- capability;
- tier/quality metadata;
- provider availability;
- admin-enabled status;
- rendering preference.

Registry nội bộ có thể map model đã biết, nhưng logic chính không dùng chuỗi `str_contains('pro')` khắp nơi.

## 2C.4 Empty route behavior

Nếu không có model `typography_supported`:

- không tự rơi về text-only model;
- có thể fallback sang `image_generation` general model chỉ khi admin bật setting rõ;
- phải log warning và hiển thị cảnh báo chất lượng;
- không fail im lặng.

---

# Phase 2D — Candidate generation

## 2D.1 Service mới

Tạo service tập trung, ví dụ:

```text
TypographyCandidateGenerationService
```

Nhiệm vụ:

1. nhận compiled prompt + routing policy;
2. sinh N candidate có giới hạn;
3. lưu candidate vào temporary storage;
4. trả metadata đầy đủ;
5. không gắn candidate vào article/media library chính thức trước khi chọn winner.

## 2D.2 Candidate metadata

```php
[
    'candidate_id' => string,
    'temporary_path' => string,
    'model_used' => string,
    'provider' => string,
    'attempt' => int,
    'usage' => array,
    'resolution' => string,
    'generation_error' => string|null,
]
```

## 2D.3 Retry

Phân biệt rõ:

- provider retry: lỗi mạng/quota tạm thời;
- candidate generation: chủ động sinh thêm ảnh;
- validation retry: sinh lại do chữ sai.

Không dùng chung một counter.

## 2D.4 Giới hạn chi phí

Hard cap mặc định:

```text
candidate_count <= 3
validation_regeneration_rounds <= 1
total_rendered_images_per_request <= 4
```

Admin có thể thay đổi sau, nhưng Phase 2 không mở tùy chọn vô hạn.

---

# Phase 2E — Exact text validation và scoring

## 2E.1 Service mới

Tạo:

```text
TypographyValidationService
```

Input:

- candidate image;
- `visible_text_blocks`;
- language;
- layout metadata nếu có.

Output:

```php
[
    'score' => float,
    'passed' => bool,
    'detected_blocks' => array,
    'missing_blocks' => array,
    'mismatched_blocks' => array,
    'extra_text' => array,
    'validation_model' => string,
    'raw_response' => array|null,
]
```

## 2E.2 Vision trước, OCR library là fallback

Ưu tiên một Vision request có cấu trúc để:

- đọc text;
- giữ Unicode tiếng Việt;
- map text theo block;
- phát hiện chữ thừa.

Không lặp OCR nhiều lần trên cùng candidate.

Nếu Vision không khả dụng, OCR local chỉ là fallback và phải đánh dấu confidence thấp hơn.

## 2E.3 Chuẩn hóa so sánh

Hai chế độ:

### Strict exact text

Dùng cho title, label, CTA, số liệu bắt buộc:

- giữ dấu tiếng Việt;
- giữ chữ/số;
- không chấp nhận paraphrase;
- có thể bỏ qua khác biệt whitespace không có ý nghĩa.

### Normalized comparison

Chỉ cho block không strict:

- normalize Unicode;
- normalize whitespace;
- không bỏ dấu;
- không tự dịch.

## 2E.4 Scoring gợi ý

```text
Exact required text accuracy: 70%
Missing required blocks: penalty mạnh
Extra invented text: 15%
Layout/visual compliance: 10%
Readability/confidence: 5%
```

Trọng số phải nằm trong một config/class và có unit test.

Không để Vision model tự trả một điểm duy nhất rồi tin tuyệt đối; service phải tự tính score từ kết quả có cấu trúc.

## 2E.5 Pass threshold

Default ban đầu:

```text
quality_first: 0.95
balanced: 0.90
cost_first: 0.85
```

Threshold là setting nâng cao; UI mặc định chỉ diễn giải bằng ngôn ngữ dễ hiểu.

---

# Phase 2F — Winner selection và lifecycle

## 2F.1 Chọn winner

Thứ tự:

1. candidate passed threshold;
2. score cao nhất;
3. nếu hòa, ưu tiên ít missing text hơn;
4. nếu vẫn hòa, ưu tiên chi phí thấp hơn hoặc candidate đầu tiên theo preference.

## 2F.2 Không candidate nào đạt

- Nếu chưa dùng regeneration round → sinh lại một vòng theo feedback validation rút gọn.
- Nếu vẫn không đạt → giữ candidate điểm cao nhất nhưng gắn trạng thái `validation_warning`.
- UI phải báo rõ ảnh chưa đạt exact text, không nói thành công tuyệt đối.

## 2F.3 Cleanup

Sau khi chọn winner:

- chuyển winner từ temporary storage sang media storage chính thức;
- xóa candidate thua;
- xóa temporary artifacts khi job fail/timeout;
- scheduled cleanup cho orphan candidates cũ.

## 2F.4 Metadata lưu lại

Trong media/history snapshot:

```text
render_model
validation_model
candidate_count
winner_score
validation_passed
missing_text_count
mismatched_text_count
workflow_execution_mode
typography_complexity_summary
```

Không lưu raw Vision response quá lớn trong DB chính; có thể lưu debug log có retention ngắn.

---

# Phase 2G — Settings và UI

## 2G.1 Vị trí

Theo `MAP_SEO_SETTINGS.md`, đặt các lựa chọn chiến lược ở khu vực AI/Model Settings. Khu vực Editor chỉ chọn Prompt hoặc Workflow.

## 2G.2 UI mặc định cho khách

### Rendering Preference

```text
Tiết kiệm
Cân bằng
Ưu tiên chất lượng
```

### Typography Quality Controls

Chỉ hiển thị tối thiểu:

```text
Kiểm tra chữ sau khi tạo: Bật/Tắt
Mức kiểm tra: Nhanh / Cân bằng / Nghiêm ngặt
```

Không bắt khách chọn candidate count, threshold hoặc raw validation model ở UI mặc định.

## 2G.3 Advanced/admin

Có thể cấu hình:

- candidate count tối đa;
- pass threshold;
- validation model group;
- fallback general image;
- temporary retention;
- admin-enabled unknown models.

## 2G.4 Model Status

Nhóm:

```text
Text
Image
Image Typography
Video
Unknown
```

Mỗi model typography hiển thị rõ:

- Supported;
- Recommended;
- Unknown/experimental.

Không gắn nhãn “chính xác tuyệt đối”.

---

# Phase 2H — Full workflow integration chi tiết

## 2H.1 Workflow step roles

Workflow có thể gồm:

```text
planner text step
blueprint transform step
image render step
validation step optional
post-processing step
```

Không bắt buộc mọi workflow có đủ các bước.

## 2H.2 Render step tool

Render step hợp lệ:

```text
image
image_typography
```

Nếu workflow slot Typography nhưng render step chỉ là `image`, phải cảnh báo hoặc normalize theo cấu hình rõ; không âm thầm đổi tool.

## 2H.3 Validation step

Hai cách hợp lệ:

1. Workflow tự có validation step.
2. System typography pipeline tự validate sau render.

Không chạy validation hai lần. Cần metadata:

```text
validation_owner = workflow | system | none
```

## 2H.4 Output contract

Workflow runner phải đánh dấu step output nào là final media. Không mặc định “image step cuối cùng” nếu graph có nhiều nhánh.

Nếu workflow cũ không có final output marker, mới dùng BC resolver.

---

# Phase 2I — Observability và History

## 2I.1 History card

Hiển thị:

```text
Nguồn: Prompt hoặc Workflow
Workflow mode: Full graph hoặc BC extract
Planner model
Render model
Validation model
Số candidate
Điểm ảnh chọn
Validation passed/warning
```

## 2I.2 Metrics

Ghi metric tối thiểu:

- success rate theo render model;
- validation pass rate;
- average candidate count;
- average cost/usage;
- retry rate;
- OCR/Vision failure rate;
- winner thường đến từ candidate thứ mấy.

## 2I.3 Không lộ dữ liệu quá mức

Không ghi toàn bộ prompt chứa dữ liệu nhạy cảm vào metric aggregation. Chỉ lưu length/complexity summary và IDs cần thiết.

---

# Files dự kiến

## Support

- `Support/ImageToolType.php`
- `Support/ImageCapability.php`
- `Support/ImageCapabilityResolver.php`
- `Support/RenderingPreference.php`
- `Support/TypographyComplexity.php`
- `Support/ImageRoutingStrategy.php`
- `Support/TypographyValidationPolicy.php` hoặc config tương đương

## Services

- `Services/TypographyComplexityParser.php`
- `Services/TypographyCandidateGenerationService.php`
- `Services/TypographyValidationService.php`
- `Services/TypographyWinnerSelector.php`
- `Services/MediaGenerationService.php`
- `Services/GeminiMediaGenerationService.php`
- `Services/PromptRunnerService.php`
- `Services/ArticleEditorMediaAiService.php`
- `Services/EditorImageTaskResolverService.php`
- `Services/TaskWorkflowTestRunner.php` hoặc workflow runner thực tế
- `Services/ArticlePromptRunHistoryService.php`

## Jobs / storage

- `Jobs/GenerateMediaJob.php`
- job cleanup candidate nếu kiến trúc hiện tại cần
- temporary media storage abstraction hiện có hoặc mới tối thiểu

## Settings / UI

- Theo vị trí thật trong `MAP_SEO_SETTINGS.md`
- `SeoSettingsOverview`
- `SeoSettingsWorkflows` chỉ giữ phần Editor source nếu đúng map hiện tại
- Prompt Resource
- History blades/components
- lang vi/en

## Tests

- `TypographyComplexityParserTest`
- `ImageRoutingStrategyTypographyTest`
- `TypographyValidationServiceTest`
- `TypographyWinnerSelectorTest`
- workflow full execution integration test
- GenerateMediaJob idempotency test
- backward compatibility tests

---

# Thứ tự triển khai đề xuất

## Milestone 1 — Verify và ổn định Phase 1

1. Đối chiếu changelog với source.
2. Chạy unit test hiện có.
3. Sửa routing/history/source regressions nếu có.
4. Chưa thêm candidate generation.

## Milestone 2 — Full Workflow from Editor

1. Chuẩn hóa result contract.
2. Thêm full graph execution theo source=`workflow`.
3. Giữ BC extract mode có log.
4. Thêm integration test.

## Milestone 3 — Typography complexity và routing

1. Parser visible text blocks.
2. Complexity score.
3. Mở rộng routing policy.
4. Test cost/balanced/quality.

## Milestone 4 — Candidate + validation

1. Temporary candidate storage.
2. N candidate có hard cap.
3. Vision validation một lần/candidate.
4. Scoring và winner selector.
5. Cleanup.

## Milestone 5 — Settings, History, Metrics

1. Typography quality controls.
2. History chi tiết.
3. Metrics.
4. Changelog và docs.

Không merge Milestone 4 nếu Milestone 2 và 3 chưa ổn.

---

# Test bắt buộc

## Phase 1 regression

- Prompt cũ `tools=image` vẫn chạy.
- Prompt `image_typography` đi đúng image pipeline.
- Rendering Preference default là balanced.
- Unknown không route mặc định.
- History hiện render model thật.

## Full workflow

- source=`prompt` không chạy workflow.
- source=`workflow` chạy full graph.
- workflow cũ fallback BC có log.
- retry job không tạo media trùng.
- final output đúng node được đánh dấu.

## Typography parser

- Blueprint JSON.
- Markdown blueprint.
- Prompt tiếng Việt có dấu.
- Title/label/body được tách đúng.
- Instruction không bị tính nhầm là visible text.

## Routing

- Cost first typography nhẹ → Flash trước.
- Balanced typography nặng → Pro trước.
- Quality first → typography recommended trước.
- Image thường balanced vẫn giữ LengthPolicy cũ.
- Không có typography model → cảnh báo/fallback đúng setting.

## Validation

- Đúng toàn bộ chữ → pass.
- Sai dấu → mismatch.
- Thiếu label → penalty mạnh.
- Thêm chữ bịa → extra text penalty.
- Unicode normalize không làm mất dấu.
- Vision fail → fallback/failed state đúng.

## Candidate lifecycle

- Chỉ winner được lưu chính thức.
- Candidate thua được xóa.
- Job fail có cleanup.
- Không vượt hard cap.
- History lưu candidate count và score.

---

# Backward compatibility

- Giữ `prompts.model_category` trong DB nhưng không dùng cho image routing.
- Giữ legacy settings keys và migrate-on-load.
- Giữ `GoogleAiModelRegistry::imageModelsToTry` như wrapper nếu caller cũ còn dùng.
- Giữ `raw_model_used` alias cho history cũ.
- Workflow cũ chưa full-compatible dùng BC mode có cảnh báo.
- Không migration DB bắt buộc trừ khi source thật cho thấy JSON hiện tại không đủ.

---

# Changelog yêu cầu

Sau triển khai, tạo:

```text
docs/CHANGELOG_AI_IMAGE_ROUTING_PHASE2.md
```

Bắt buộc ghi:

- file sửa;
- behavior trước/sau;
- model routing policy;
- candidate limits;
- validation scoring;
- workflow BC behavior;
- settings keys mới;
- tests đã chạy và kết quả;
- điểm chưa làm.

Không ghi “đã test” nếu chưa thực sự chạy.

---

# Definition of Done

Chỉ xem là hoàn thành khi:

1. Prompt trực tiếp và Workflow có hành vi khác nhau đúng như Settings.
2. Workflow source chạy full graph hoặc BC fallback có cảnh báo rõ.
3. `image_typography` không dùng prompt length làm sole router.
4. Candidate generation có hard cap.
5. Mỗi candidate chỉ validation có giới hạn.
6. Winner được chọn bằng score có cấu trúc.
7. Candidate thua được cleanup.
8. History hiện planner/render/validation model đúng.
9. Không còn image routing song song qua `AiModelCategory`.
10. Unit/integration tests chính đã chạy và được ghi thật trong changelog.
