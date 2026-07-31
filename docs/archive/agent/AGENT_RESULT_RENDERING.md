> Status: Historical
> Not canonical
> Archived on: 2026-08-01
> Superseded by: ../../modules/AGENT_WORKSPACE.md
> Purpose: implementation history only
# Agent Result Rendering (Phase 2)

Contract: `AgentResultRenderer` â€” input chá»‰ `AgentExecutionResult` payload. **KhÃ´ng** query business models.

## Registry order

1. `AgentErrorRenderer` (failures)
2. `ContentProjectResultRenderer`
3. `KeywordResultRenderer`
4. `SerpResultRenderer`
5. `GenericAgentResultRenderer`

## Output shape

title, summary, metrics, badges, warnings, links, suggested_skills, operation_reference, details

Links dÃ¹ng ref/type (DeepLink builders á»Ÿ UI) â€” khÃ´ng hard-code URL ráº£i rÃ¡c trong renderer.

Error categories: `AgentErrorCategory` (+ `retryable()`).
