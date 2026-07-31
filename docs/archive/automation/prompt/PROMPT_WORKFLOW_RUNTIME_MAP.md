> Status: Historical
> Not canonical
> Archived on: 2026-08-01
> Superseded by: ../../modules/AUTOMATION.md
> Purpose: implementation history only
# Prompt Workflow Runtime Map

**Phase:** 5A  
**Updated:** 2026-07-18

Canonical shape:

```text
Caller
â†’ Workflow (SeoTask graph | single Prompt | PromptHook)
â†’ Step (prompt | filter | action | user_input | â€¦)
â†’ Prompt builder (PromptRunner compile / Hook assembler)
â†’ Provider/model (AiModelRouter / ImageRouting / Claude|Gemini)
â†’ Parser / normalizer
â†’ Domain write (Action node | Business Action | UI apply)
```

---

## 1. Project publish / rewrite

```text
SeoProjectWorkflowRunService / ListArticles / Keyword flows
â†’ CreateArticlesFromTaskService
   â†’ TaskTestInputResolver (variables + _seo_project_task_id)
   â†’ TaskWorkflowTestRunner::run(SeoTask, TaskTestContext)
      â†’ prompt nodes â†’ PromptRunnerService::run
      â†’ filter nodes â†’ WorkflowParserService
      â†’ action save_article â†’ PromptTestPublishService
           â†’ (Phase 4B) content/seo_meta bridges â†’ optional Business Actions
   â†’ article.create bridge (draft) when new
```

**Side effects:** Eloquent article/meta, scoring/analyze (legacy path), sync flags â€” **khÃ´ng** WP outbound tá»« publish local.

## 2. Editor single-prompt AI

```text
EditArticle / Controllers
â†’ ArticleHeadingAiGenerateService | Faq* | FeaturedSnippet | QuickTranslate
â†’ PromptRunnerService::run(SeoPrompt, variables)
â†’ UI applies result (or meta write in service)
```

## 3. PromptHook (title / meta)

```text
React (articleTitlePromptHook / ArticleGoogleSerpPreview)
â†’ POST /api/seo/prompt-hooks/{hookKey}/execute
â†’ PromptHookExecuteController
â†’ PromptHookExecutionService
   â†’ Registry + InputResolver + SettingsResolver
   â†’ PromptHookPromptAssembler (compilePrompt + locale template)
   â†’ PromptRunnerService
   â†’ PromptHookOutputNormalizer
â†’ UI sets field (title/meta) â€” user save riÃªng
```

**Note:** ExecutionService cÃ³ `attachPromptResultToArticle` (PromptResult link) â€” logging/link, khÃ´ng pháº£i domain body write; váº«n cáº§n rÃ  soÃ¡t boundary 5B.

## 4. Media / image

```text
GenerateMediaJob | MediaGenerationService | ImageGenerationChainService
â†’ PromptRunner (image tool) | EditorWorkflowExecutionService
â†’ GeminiMediaGenerationService + ImageRoutingStrategy
â†’ PromptMediaStorage / SeoMedia
```

## 5. Keyword discovery (bypass PromptRunner)

```text
AiKeywordDiscovery / SeoProjectKeywordAiGenerator
â†’ (hardcoded or PromptRunner)
â†’ json_decode + fence strip
â†’ keyword domain services / UI
```

## 6. Provider routing

```text
PromptRunnerService
â†’ tool type text|image*
â†’ text: AiModelRouterService â†’ Gemini | AiExecutionService::executeClaude
â†’ image: MediaGenerationService â†’ Gemini image models
```

Secrets: `ApiConnection.api_key` â€” **khÃ´ng** trong JSON hook.

## 7. Concern split (target)

| Layer | Owns |
|---|---|
| Prompt Hook | request render, response validate |
| Prompt Workflow | step order, previous_outputs, branch/retry |
| Business Action | domain mutation |
| UI | collect input, display, apply to form |

Hiá»‡n táº¡i nhiá»u caller **trá»™n** prompt + domain write trong cÃ¹ng service â€” migration tÃ¡ch dáº§n.
