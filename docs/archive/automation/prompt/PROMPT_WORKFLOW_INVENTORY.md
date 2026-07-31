> Status: Historical
> Not canonical
> Archived on: 2026-08-01
> Superseded by: ../../modules/AUTOMATION.md
> Purpose: implementation history only
# Prompt Workflow Inventory â€” SeoContentAi

**Phase:** 5A  
**Updated:** 2026-07-18  
**Scope:** Audit only â€” khÃ´ng rewrite engine, khÃ´ng Ä‘á»•i production mode.

## Classification legend

| Class | Meaning |
|---|---|
| KEEP_AS_IS | á»”n / orchestration cáº§n giá»¯ |
| NORMALIZE_NOW | Contract rÃµ; fixture normalize trong 5A |
| MIGRATE_LATER | Cáº§n Hook/Workflow engine sau |
| DEPRECATED | Legacy alias / sáº¯p bá» |
| BLOCKED | WP / incomplete / Filament-bound |
| EXPERIMENTAL | Hook thá»­ nghiá»‡m â€” chÆ°a khÃ³a contract engine |

---

## A. Entry points (runtime hubs)

| Hub | Path | Role |
|---|---|---|
| `PromptRunnerService` | `Services/PromptRunnerService.php` | Compile + call AI (text/image) |
| `TaskWorkflowTestRunner` | `Services/TaskWorkflowTestRunner.php` | SeoTask graph orchestration |
| `EditorWorkflowExecutionService` | `Services/EditorWorkflowExecutionService.php` | Editor media prompt|workflow |
| `PromptHookExecutionService` | `PromptHooks/PromptHookExecutionService.php` | Manifest hooks (title/meta) |
| `GlobalAiChatService` | (chat) | Hardcoded system prompt â€” ngoÃ i PromptRunner |
| `AiKeywordDiscoveryService` | (discovery) | Hardcoded JSON system â€” ngoÃ i PromptRunner |

---

## B. Settings-bound workflows / prompts

Source: `SeoCreateArticleSettingsService` (`seo_create_article_task`).

| Binding key | Type | Classification | Reason |
|---|---|---|---|
| `publish_article_task_id` | SeoTask workflow | MIGRATE_LATER | Multi-step + domain persist; core production |
| `rewrite_article_task_id` | SeoTask workflow | MIGRATE_LATER | Rewrite modes; no new article |
| `post_review_task_id` | SeoTask workflow | BLOCKED | `post_comment_review` â†’ WP path |
| `create_image_*` prompt/task | prompt\|workflow | MIGRATE_LATER | Image routing complex |
| `create_typography_image_*` | prompt\|workflow | MIGRATE_LATER | Typography pipeline |
| `create_product_gallery_image_*` | prompt\|workflow | MIGRATE_LATER | Product-only skip rules |
| `create_video_*` | prompt\|workflow | BLOCKED | Video/Veo incomplete |
| `renew_faq_prompt_id` | single prompt | NORMALIZE_NOW | Clear I/O; editor FAQ |
| `project_keywords_prompt_id` | single prompt | NORMALIZE_NOW | JSON/list keywords |
| `featured_snippet_prompt_id` | single prompt | NORMALIZE_NOW | Structured table-ish |
| `outline_heading_regenerator_prompt_id` | single prompt | NORMALIZE_NOW | Outline/heading |
| `translate_article_prompt_id` | single prompt | KEEP_AS_IS | Stable translate path |
| `article_title_suggestion_prompt_id` | PromptHook | EXPERIMENTAL | Phase 1 hook UI |
| `article_meta_description_suggestion_prompt_id` | PromptHook | EXPERIMENTAL | Phase 1 hook UI |
| `create_image_prompt_id` (legacy) | prompt | DEPRECATED | Prefer task + source |

Tone/length: `SeoPromptSettingsService` (`seo_prompt_settings`) â€” KEEP_AS_IS (context inject).

---

## C. Direct PromptRunner callers (non-settings graph)

| Caller | Classification | Notes |
|---|---|---|
| `ArticleHeadingAiGenerateService` | NORMALIZE_NOW | Outline headings |
| `ArticleFaqGeneratorService` / `ArticleFaqEditorService` | NORMALIZE_NOW | FAQ text/JSON |
| `ArticleFeaturedSnippetGeneratorService` | NORMALIZE_NOW | Snippet |
| `ArticleQuickTranslateService` | KEEP_AS_IS | Editor translate |
| `SeoTextTranslateToolService` | KEEP_AS_IS | Tools |
| `SeoProjectKeywordAiGeneratorService` | NORMALIZE_NOW | Project keywords |
| `ImageGenerationChainService` / `MediaGenerationService` | MIGRATE_LATER | Image tools |
| `ArticleEditorMediaAiService` | MIGRATE_LATER | compile only + gen |
| `GenerateMediaJob` | MIGRATE_LATER | Queue |
| `PromptResource/TestPrompt` | KEEP_AS_IS | Dev UI |
| `EditArticle` (preview compile) | KEEP_AS_IS | Template preview |

---

## D. PromptHooks (manifest)

| Key | Classification | Status |
|---|---|---|
| `article.title_suggestion` | EXPERIMENTAL | Runtime Active UI; contract engine chÆ°a khÃ³a |
| `article.meta_description_suggestion` | EXPERIMENTAL | Runtime Active UI; contract engine chÆ°a khÃ³a |

Docs: `docs/prompt-hooks/`.

---

## E. Hardcoded / non-DB prompts

| Location | Classification |
|---|---|
| `GlobalAiChatService::SYSTEM_PROMPT` | KEEP_AS_IS |
| `AiKeywordDiscoveryService` system_instruction | NORMALIZE_NOW â†’ fixture `keyword.discovery.structured` |
| `MediaGenerationService` image prefix | KEEP_AS_IS |
| `SeoPromptSettingsService` default tone VI | KEEP_AS_IS (inconsistency note) |
| `lang/*/prompt_hooks.php` templates | EXPERIMENTAL (paired hooks) |

---

## F. Counts (logical families)

| Classification | Count (approx) |
|---|---:|
| KEEP_AS_IS | 8 |
| NORMALIZE_NOW | 10 |
| MIGRATE_LATER | 8 |
| DEPRECATED | 1 |
| BLOCKED | 2 |
| EXPERIMENTAL | 2 |
| **Total logical workflows/prompt families** | **~31** |

DB `prompts` / `seo_tasks` rows: dynamic (tenant data) â€” khÃ´ng Ä‘áº¿m cá»©ng; inventory theo **binding + caller family**.

---

## G. Output / parser inventory

| Parser | Used by |
|---|---|
| `WorkflowParserService` | outline / keywords / faq / score |
| `MarkdownOutlineParser` | PromptTestPublish |
| `MarkdownSemanticKeywordsParser` | publish vocabulary |
| `PromptPostProcessingApplyService` | image post |
| `PromptHookOutputNormalizer` | hooks |
| Ad-hoc `json_decode` + fence strip | keyword discovery, FAQ editor |

## H. Do not change (5A)

Article Editor save Â· WP sync Â· delete legacy prompts Â· full Workflow Builder Â· production AI calls.
