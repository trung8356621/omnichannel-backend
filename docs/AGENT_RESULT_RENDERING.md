# Agent Result Rendering (Phase 2)

Contract: `AgentResultRenderer` — input chỉ `AgentExecutionResult` payload. **Không** query business models.

## Registry order

1. `AgentErrorRenderer` (failures)
2. `ContentProjectResultRenderer`
3. `KeywordResultRenderer`
4. `SerpResultRenderer`
5. `GenericAgentResultRenderer`

## Output shape

title, summary, metrics, badges, warnings, links, suggested_skills, operation_reference, details

Links dùng ref/type (DeepLink builders ở UI) — không hard-code URL rải rác trong renderer.

Error categories: `AgentErrorCategory` (+ `retryable()`).
