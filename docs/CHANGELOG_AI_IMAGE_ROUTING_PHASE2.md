# CHANGELOG — AI Image Routing Phase 2

**Date:** 2026-07-15  
**Scope:** `app/Addons/SeoContentAi/` (+ docs)

---

## Behavior mới

1. **Editor workflow thật:** `source=workflow` chạy full graph qua `EditorWorkflowExecutionService` + `TaskWorkflowTestRunner::run()`. BC `extract_last_prompt_bc` khi full graph không trả media.
2. **Typography pipeline:** `image_typography` đi `TypographyPipelineService` — parser complexity, N candidate, Vision validation, chọn winner, cleanup temp.
3. **Routing policy:** `ImageRoutingStrategy::executionPolicy()` trả candidate count, resolution, validation threshold.
4. **Settings:** Typography Quality Controls (bật validation, mức Nhanh/Cân bằng/Nghiêm + advanced candidate/threshold/fallback).
5. **History:** thêm `validation_model`, `workflow_execution_mode`, `candidate_count`, `winner_score`, `validation_passed`.

---

## Files chính (mới)

| File | Vai trò |
|---|---|
| `Support/ImageRoutingExecutionPolicy.php` | DTO policy typography |
| `Support/TypographyValidationLevel.php` | fast / balanced / strict |
| `Support/TypographyScoringConfig.php` | Trọng số chấm điểm |
| `Services/TypographyComplexityParser.php` | Parse visible text blocks |
| `Services/TypographyTemporaryStorageService.php` | Candidate tạm |
| `Services/TypographyCandidateGenerationService.php` | Sinh N candidate |
| `Services/TypographyValidationService.php` | Vision + scoring |
| `Services/TypographyPipelineService.php` | Orchestrator typography |
| `Services/EditorWorkflowExecutionService.php` | Full workflow từ Editor |
| `tests/Unit/TypographyComplexityParserTest.php` | Parser + scoring |

## Files sửa

| File | Thay đổi |
|---|---|
| `Support/TypographyComplexity.php` | Blocks, score, summary |
| `Support/ImageRoutingStrategy.php` | `executionPolicy()` |
| `Services/MediaGenerationService.php` | Delegate typography pipeline |
| `Services/PromptRunnerService.php` | Snapshot validation/workflow metadata |
| `Services/ArticleEditorMediaAiService.php` | Metadata workflow + tool typography |
| `Jobs/GenerateMediaJob.php` | Nhánh workflow vs prompt |
| `Services/SeoCreateArticleSettingsService.php` | Keys typography quality |
| `Filament/Pages/SeoSettingsWorkflows.php` | UI Typography Quality |
| `Services/ArticlePromptRunHistoryService.php` | Hiển thị metadata Phase 2 |
| Lang `vi`/`en` filament.php | Labels typography settings |

---

## Settings keys mới

| Key | Ý nghĩa |
|---|---|
| `typography_validation_enabled` | bool |
| `typography_validation_level` | `fast` \| `balanced` \| `strict` |
| `typography_max_candidates` | 1–3 (advanced) |
| `typography_pass_threshold` | 0–1 (advanced) |
| `typography_allow_general_image_fallback` | bool advanced |

---

## Manual verification

```text
php artisan optimize:clear
php artisan queue:restart
php artisan test app/Addons/SeoContentAi/tests/Unit/TypographyComplexityParserTest.php
php artisan test app/Addons/SeoContentAi/tests/Unit/ImageRoutingStrategyTest.php
npm run build
```
