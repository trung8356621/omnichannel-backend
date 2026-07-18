# Prompt Hook Boundaries

**Phase:** 5A — locked principles

## Layers

| Layer | Allowed | Forbidden |
|---|---|---|
| **Prompt Hook** | Build AI request; validate AI response; normalize output; declare schemas | Eloquent `save()`; WP sync; Business Action call; PHP in template; API keys in JSON |
| **Prompt Workflow** | Order hooks/steps; pass `previous_outputs`; branch/retry; map ports | Hide domain writes inside prompt nodes |
| **Business Action** | Domain mutation (`article.*`, `keyword.*`, …) | Embed prompt templates; call provider directly (prefer hook) |
| **UI** | Collect input; show result; apply to form fields | Silent domain persist without user/action path |

## Hard rules

1. Hook **không** gọi Eloquent save trên Article/Keyword/Task.  
2. Hook **không** sync WordPress.  
3. Template **không** chứa PHP executable / `eval`.  
4. JSON hook **không** chứa `api_key` / secrets.  
5. Domain write sau AI = Workflow action node **hoặc** caller → Business Action.  
6. Không silent accept invalid output.  
7. Không silent fallback legacy sau khi provider đã charge/cost (mode `hook`).

## Current violations / debt (document, không fix 5A)

| Item | Issue |
|---|---|
| `PromptHookExecutionService::attachPromptResultToArticle` | Ghi link PromptResult — cần class là audit vs domain |
| Nhiều `*GeneratorService` | PromptRunner + parse + đôi khi persist meta trong cùng class |
| `TaskWorkflowTestRunner` action nodes | Đúng chỗ domain — nhưng action lẫn WP review |
| `AiKeywordDiscoveryService` | Prompt hardcoded + parse ngoài Hook contract |

## Import boundary (static)

Hook Spec / Manifest / Normalizer / Spec validators **không** import:

- `Filament\\`
- `WordPressArticleSyncService`
- `ArticleEditorSyncOrchestrator`
- WP queue jobs

Entity resolvers **được** đọc Eloquent (authorized) → trả **array context** only.
