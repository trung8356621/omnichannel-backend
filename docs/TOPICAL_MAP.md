# Topical Map (Phase 3)

Topic ≠ Cluster ≠ Keyword.

- **Topic** = structural subject grouping (`seo_topics`)
- **Cluster** = candidate target page (`seo_keyword_clusters`)
- **Keyword** = search query (`seo_ki_keywords`)

## Flow

Approved clusters → `BuildTopicalMap` (draft) → review/resolve conflicts → `ApproveTopicalMap` → immutable version → `PreviewContentProjectFromTopicalMap` → `CreateContentProjectFromTopicalMap` → traceability links.

## Rules

- Builder **does not** re-analyze keywords.
- Build creates **draft** map version — never auto-approve.
- Conversion default source = **approved** map version only.
- Covered clusters excluded by default.
- Rewrite/improve require evidence + article target; improve needs description.
- No gallery_description. No auto schedule/publish.
- After conversion: no live sync map → project.
- Content Project archive does **not** delete topical planning data.

## Phase 4 — SERP boundary (additive)

SERP Intelligence services **do not** call `ApproveTopicalMap` or mutate approved map versions. SERP evidence is advisory input only. See [SERP_INTELLIGENCE.md](SERP_INTELLIGENCE.md).

## Phase 5 — GSC boundary (additive)

GSC Intelligence services **do not** call `ApproveTopicalMap` or mutate approved map versions. GSC metrics/opportunities are advisory for topical planning. See [GSC_INTELLIGENCE.md](GSC_INTELLIGENCE.md).

## Modes

| Mode | Depth default | Behavior |
|------|---------------|----------|
| conservative | 3 | Fewer pillars, high confidence only |
| balanced | 4 | Default — entity + intent + funnel |
| expansive | 5 | More subtopic/faq groups |

## Key classes

- `TopicalMapBuilder::buildFromRequest(TopicalMapBuildRequest, workspace)`
- `TopicalMapHierarchyValidator`
- `TopicalCoverageService` (`authority_score_source=internal_proxy`)
- `TopicalInternalLinkSuggestionService` (suggestions only)
- `TopicalMapConflictDetector`
- `TopicalMapVersionDiffService`
- `KeywordTopicalMapMutationService` (CRUD topics, approve/save version)
- `KeywordTopicalMapToContentProjectConverter`
- Lock: `keyword-topical-map-build:{workspace_ref}` via `KeywordTopicalMapBuildLock`

## Commands (CommandBus)

`build_topical_map`, `cancel_topical_map_build`, `create_topic`, `update_topic`, `move_topic`, `delete_empty_topic`, `attach_cluster`, `detach_cluster`, `review_topical_map`, `approve_topical_map`, `save_map_version`, `preview_content_project`, `create_content_project`
