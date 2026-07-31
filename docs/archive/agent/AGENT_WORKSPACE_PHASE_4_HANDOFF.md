> Status: Historical
> Not canonical
> Archived on: 2026-08-01
> Superseded by: ../modules/AGENT_WORKSPACE.md
> Purpose: implementation history only
<!--
Status: Historical
Not canonical
Superseded by: docs/AGENT_WORKSPACE.md + docs/AGENT_WORKSPACE_V1_FREEZE.md
-->
# Agent Workspace Phase 4 Handoff â€” Scoped Memory & Knowledge Grounding

## 1. Inspect findings

| Item | Finding |
|------|---------|
| Conversation context/summary | Phase 3 `summary*` + `context_summary` giá»¯ ephemeral â€” **khÃ´ng** thay báº±ng knowledge table. |
| `AgentPlanningContextAssembler` | Inject optional `AgentGroundingContextProvider`; section `grounded_knowledge`. |
| Budget | ThÃªm priority `grounded_knowledge` (82). |
| Sanitizer / UntrustedMarker | Reuse Phase 3; knowledge content wrap UNTRUSTED_DATA. |
| Upload/extractor | Phase 4 chá»‰ text/md/json qua payload; unsupported â†’ clear error. KhÃ´ng crawl URL. |
| FULLTEXT | Optional trÃªn `seo_agent_knowledge_items`; fallback LIKE keyword. |
| hash_id | `aknow_` / `amem_` + ULID. |
| Scope | Server resolve tá»« `AgentWorkspaceContext`; browser khÃ´ng override site. |
| Conflicts | **Compute-on-read** â€” khÃ´ng táº¡o báº£ng `seo_agent_knowledge_conflicts` (documented). |

## 2. Architecture

```
UI â†’ ApplicationService â†’ AgentKnowledgeOrchestrator
  â†’ SourceRegistry + Sanitizer + Chunker + Repository + Index

Planning:
Assembler â†’ GroundingContextProvider â†’ KnowledgeRetriever â†’ Index.search
```

AI váº«n chá»‰ Ä‘á» xuáº¥t. Phase 2 váº«n execution boundary.

## 3â€“13. Scope / types / ingestion / retrieval / proposals / conflicts / versioning / citations / grounding

- Scopes: conversation â†’ workspace â†’ project â†’ site â†’ user_preference (specificity ranking).
- Types/sources/trust/status theo spec Phase 4.
- Ingest transactional; index fail â†’ khÃ´ng claim searchable.
- Memory proposal: deterministic extract â†’ UI approve; **no auto-persist**.
- Correction = new version + supersede old; forget = soft delete + remove index; **khÃ´ng** xÃ³a business source.
- Citations `[K#]` server-authored; fabricated rejected.
- Grounding failure â†’ empty package + warning; planning continues.

## 14â€“16. UI / files / migration

- Knowledge panel tab; memory proposal + conflict cards.
- Skills: `/knowledge` `/add-knowledge` `/search-knowledge` `/review-memory` `/forget-memory` `/verify-knowledge`
- Migration: `2026_07_28_230000_phase4_agent_knowledge.php`

## 17â€“20. Tests / freeze / limitations / Phase 5

```text
Manual verification:

$PHP_BIN vendor/bin/phpunit --filter=AgentKnowledge
$PHP_BIN vendor/bin/phpunit --filter=AgentMemory
$PHP_BIN vendor/bin/phpunit --filter=AgentGrounding
$PHP_BIN vendor/bin/phpunit --filter=AgentKnowledgeSecurity
$PHP_BIN vendor/bin/phpunit --filter=AgentPlanning
$PHP_BIN vendor/bin/phpunit --filter=AgentExecution
$PHP_BIN vendor/bin/phpunit --filter=ExtensionArchitectureFreezeTest

php artisan migrate
php artisan optimize:clear
```

| Freeze | Result |
|--------|--------|
| CommandBus / handlers / Gateway / ExecutionOrchestrator bypassed | No |
| Planning architecture rewritten | No (inject grounding only) |
| Business tables for memory | No |
| Cross-site / autonomous memory / AI auto-confirm | No |
| External vector dependency | No |

**Limitations:** no vector index; no remote crawl; no scheduler freshness; conflicts not persisted; upload text-only.

**Phase 5 candidates (DO NOT implement):** autonomous memory loop, vector DB, cross-site memory, scheduled reverify, URL crawler.
