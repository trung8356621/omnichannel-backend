> Status: Historical
> Not canonical
> Archived on: 2026-08-01
> Superseded by: ../../modules/AGENT_WORKSPACE.md
> Purpose: implementation history only
# Agent Context Budget

`AgentContextBudgetManager` fits prompt sections under model context limit.

## Sections (priority highâ†’low keep)

1. current_message  
2. system_policy  
3. working_context  
4. skill_catalog  
5. grounded_knowledge (Phase 4)  
6. user_corrections  
7. execution_summaries  
8. summary  
9. recent_messages  

Grounded knowledge must not consume full context (`max_context_ratio` / token budget on retriever).

## Rules

- Drop whole sections / trim arrays from end.
- Never truncate JSON mid-object.
- Estimate: char length / 4 when tokenizer unavailable; method stored in diagnostics.
