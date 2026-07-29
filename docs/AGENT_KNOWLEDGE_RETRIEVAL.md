# Agent Knowledge Retrieval

`DatabaseAgentKnowledgeIndex` + `DefaultAgentKnowledgeRetriever`.

## Ranking

Scope specificity, keyword relevance, trust, priority, freshness.

## Output

`AgentGroundedContextPackage` — facts/rules/preferences/conflicts/warnings/citations/omitted/diagnostics.

No Eloquent models to planner. Fail closed on missing site / cross-site.
