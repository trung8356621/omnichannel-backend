> Status: Historical
> Not canonical
> Archived on: 2026-08-01
> Superseded by: ../../modules/AUTOMATION.md
> Purpose: implementation history only
# Prompt Hook Boundaries

**Phase:** 5A â€” locked principles

## Layers

| Layer | Allowed | Forbidden |
|---|---|---|
| **Prompt Hook** | Build AI request; validate AI response; normalize output; declare schemas | Eloquent `save()`; WP sync; Business Action call; PHP in template; API keys in JSON |
| **Prompt Workflow** | Order hooks/steps; pass `previous_outputs`; branch/retry; map ports | Hide domain writes inside prompt nodes |
| **Business Action** | Domain mutation (`article.*`, `keyword.*`, â€¦) | Embed prompt templates; call provider directly (prefer hook) |
| **UI** | Collect input; show result; apply to form fields | Silent domain persist without user/action path |

## Hard rules

1. Hook **khÃ´ng** gá»i Eloquent save trÃªn Article/Keyword/Task.  
2. Hook **khÃ´ng** sync WordPress.  
3. Template **khÃ´ng** chá»©a PHP executable / `eval`.  
4. JSON hook **khÃ´ng** chá»©a `api_key` / secrets.  
5. Domain write sau AI = Workflow action node **hoáº·c** caller â†’ Business Action.  
6. KhÃ´ng silent accept invalid output.  
7. KhÃ´ng silent fallback legacy sau khi provider Ä‘Ã£ charge/cost (mode `hook`).

## Current violations / debt (document, khÃ´ng fix 5A)

| Item | Issue |
|---|---|
| `PromptHookExecutionService::attachPromptResultToArticle` | Ghi link PromptResult â€” cáº§n class lÃ  audit vs domain |
| Nhiá»u `*GeneratorService` | PromptRunner + parse + Ä‘Ã´i khi persist meta trong cÃ¹ng class |
| `TaskWorkflowTestRunner` action nodes | ÄÃºng chá»— domain â€” nhÆ°ng action láº«n WP review |
| `AiKeywordDiscoveryService` | Prompt hardcoded + parse ngoÃ i Hook contract |

## Import boundary (static)

Hook Spec / Manifest / Normalizer / Spec validators **khÃ´ng** import:

- `Filament\\`
- `WordPressArticleSyncService`
- `ArticleEditorSyncOrchestrator`
- WP queue jobs

Entity resolvers **Ä‘Æ°á»£c** Ä‘á»c Eloquent (authorized) â†’ tráº£ **array context** only.
