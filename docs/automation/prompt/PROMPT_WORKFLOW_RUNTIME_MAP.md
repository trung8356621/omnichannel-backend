# Prompt Workflow Runtime Map

**Phase:** 5A  
**Updated:** 2026-07-18

Canonical shape:

```text
Caller
→ Workflow (SeoTask graph | single Prompt | PromptHook)
→ Step (prompt | filter | action | user_input | …)
→ Prompt builder (PromptRunner compile / Hook assembler)
→ Provider/model (AiModelRouter / ImageRouting / Claude|Gemini)
→ Parser / normalizer
→ Domain write (Action node | Business Action | UI apply)
```

---

## 1. Project publish / rewrite

```text
SeoProjectWorkflowRunService / ListArticles / Keyword flows
→ CreateArticlesFromTaskService
   → TaskTestInputResolver (variables + _seo_project_task_id)
   → TaskWorkflowTestRunner::run(SeoTask, TaskTestContext)
      → prompt nodes → PromptRunnerService::run
      → filter nodes → WorkflowParserService
      → action save_article → PromptTestPublishService
           → (Phase 4B) content/seo_meta bridges → optional Business Actions
   → article.create bridge (draft) when new
```

**Side effects:** Eloquent article/meta, scoring/analyze (legacy path), sync flags — **không** WP outbound từ publish local.

## 2. Editor single-prompt AI

```text
EditArticle / Controllers
→ ArticleHeadingAiGenerateService | Faq* | FeaturedSnippet | QuickTranslate
→ PromptRunnerService::run(SeoPrompt, variables)
→ UI applies result (or meta write in service)
```

## 3. PromptHook (title / meta)

```text
React (articleTitlePromptHook / ArticleGoogleSerpPreview)
→ POST /api/seo/prompt-hooks/{hookKey}/execute
→ PromptHookExecuteController
→ PromptHookExecutionService
   → Registry + InputResolver + SettingsResolver
   → PromptHookPromptAssembler (compilePrompt + locale template)
   → PromptRunnerService
   → PromptHookOutputNormalizer
→ UI sets field (title/meta) — user save riêng
```

**Note:** ExecutionService có `attachPromptResultToArticle` (PromptResult link) — logging/link, không phải domain body write; vẫn cần rà soát boundary 5B.

## 4. Media / image

```text
GenerateMediaJob | MediaGenerationService | ImageGenerationChainService
→ PromptRunner (image tool) | EditorWorkflowExecutionService
→ GeminiMediaGenerationService + ImageRoutingStrategy
→ PromptMediaStorage / SeoMedia
```

## 5. Keyword discovery (bypass PromptRunner)

```text
AiKeywordDiscovery / SeoProjectKeywordAiGenerator
→ (hardcoded or PromptRunner)
→ json_decode + fence strip
→ keyword domain services / UI
```

## 6. Provider routing

```text
PromptRunnerService
→ tool type text|image*
→ text: AiModelRouterService → Gemini | AiExecutionService::executeClaude
→ image: MediaGenerationService → Gemini image models
```

Secrets: `ApiConnection.api_key` — **không** trong JSON hook.

## 7. Concern split (target)

| Layer | Owns |
|---|---|
| Prompt Hook | request render, response validate |
| Prompt Workflow | step order, previous_outputs, branch/retry |
| Business Action | domain mutation |
| UI | collect input, display, apply to form |

Hiện tại nhiều caller **trộn** prompt + domain write trong cùng service — migration tách dần.
