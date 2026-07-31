> Status: Historical
> Not canonical
> Archived on: 2026-08-01
> Superseded by: ../../modules/AGENT_WORKSPACE.md
> Purpose: implementation history only
# Agent Knowledge Retrieval

`DatabaseAgentKnowledgeIndex` + `DefaultAgentKnowledgeRetriever`.

## Ranking

Scope specificity, keyword relevance, trust, priority, freshness.

## Output

`AgentGroundedContextPackage` â€” facts/rules/preferences/conflicts/warnings/citations/omitted/diagnostics.

No Eloquent models to planner. Fail closed on missing site / cross-site.
