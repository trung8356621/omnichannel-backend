# Agent Workspace Phase 4 Handoff — Scoped Memory & Knowledge Grounding

## 1. Inspect findings

| Item | Finding |
|------|---------|
| Conversation context/summary | Phase 3 `summary*` + `context_summary` giữ ephemeral — **không** thay bằng knowledge table. |
| `AgentPlanningContextAssembler` | Inject optional `AgentGroundingContextProvider`; section `grounded_knowledge`. |
| Budget | Thêm priority `grounded_knowledge` (82). |
| Sanitizer / UntrustedMarker | Reuse Phase 3; knowledge content wrap UNTRUSTED_DATA. |
| Upload/extractor | Phase 4 chỉ text/md/json qua payload; unsupported → clear error. Không crawl URL. |
| FULLTEXT | Optional trên `seo_agent_knowledge_items`; fallback LIKE keyword. |
| hash_id | `aknow_` / `amem_` + ULID. |
| Scope | Server resolve từ `AgentWorkspaceContext`; browser không override site. |
| Conflicts | **Compute-on-read** — không tạo bảng `seo_agent_knowledge_conflicts` (documented). |

## 2. Architecture

```
UI → ApplicationService → AgentKnowledgeOrchestrator
  → SourceRegistry + Sanitizer + Chunker + Repository + Index

Planning:
Assembler → GroundingContextProvider → KnowledgeRetriever → Index.search
```

AI vẫn chỉ đề xuất. Phase 2 vẫn execution boundary.

## 3–13. Scope / types / ingestion / retrieval / proposals / conflicts / versioning / citations / grounding

- Scopes: conversation → workspace → project → site → user_preference (specificity ranking).
- Types/sources/trust/status theo spec Phase 4.
- Ingest transactional; index fail → không claim searchable.
- Memory proposal: deterministic extract → UI approve; **no auto-persist**.
- Correction = new version + supersede old; forget = soft delete + remove index; **không** xóa business source.
- Citations `[K#]` server-authored; fabricated rejected.
- Grounding failure → empty package + warning; planning continues.

## 14–16. UI / files / migration

- Knowledge panel tab; memory proposal + conflict cards.
- Skills: `/knowledge` `/add-knowledge` `/search-knowledge` `/review-memory` `/forget-memory` `/verify-knowledge`
- Migration: `2026_07_28_230000_phase4_agent_knowledge.php`

## 17–20. Tests / freeze / limitations / Phase 5

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
