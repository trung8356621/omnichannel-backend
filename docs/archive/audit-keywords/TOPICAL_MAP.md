> Status: Historical
> Not canonical
> Archived on: 2026-08-01
> Superseded by: ../../modules/SEO_AUDIT_AND_KEYWORDS.md
> Purpose: implementation history only
# Topical Map (Phase 3)

Topic â‰  Cluster â‰  Keyword.

- **Topic** = structural subject grouping (`seo_topics`)
- **Cluster** = candidate target page (`seo_keyword_clusters`)
- **Keyword** = search query (`seo_ki_keywords`)

## Flow

Approved clusters â†’ `BuildTopicalMap` (draft) â†’ review/resolve conflicts â†’ `ApproveTopicalMap` â†’ immutable version â†’ `PreviewContentProjectFromTopicalMap` â†’ `CreateContentProjectFromTopicalMap` â†’ traceability links.

## Rules

- Builder **does not** re-analyze keywords.
- Build creates **draft** map version â€” never auto-approve.
- Conversion default source = **approved** map version only.
- Covered clusters excluded by default.
- Rewrite/improve require evidence + article target; improve needs description.
- No gallery_description. No auto schedule/publish.
- After conversion: no live sync map â†’ project.
- Content Project archive does **not** delete topical planning data.

## Agent Workspace

Topical Map flows trong Agent UI: slash `/build-topical-map`, `/approve-topical-map`, `/preview-project`, `/create-project-from-map`; template `create_project_from_map`. Docs: [AGENT_SLASH_COMMANDS.md](AGENT_SLASH_COMMANDS.md), [AGENT_CHAT_TEMPLATES.md](AGENT_CHAT_TEMPLATES.md).

## Phase 4 â€” SERP boundary (additive)

SERP Intelligence services **do not** call `ApproveTopicalMap` or mutate approved map versions. SERP evidence is advisory input only. See [SERP_INTELLIGENCE.md](SERP_INTELLIGENCE.md).

## Phase 5 â€” GSC boundary (additive)

GSC Intelligence services **do not** call `ApproveTopicalMap` or mutate approved map versions. GSC metrics/opportunities are advisory for topical planning. See [GSC_INTELLIGENCE.md](GSC_INTELLIGENCE.md).

## Modes

| Mode | Depth default | Behavior |
|------|---------------|----------|
| conservative | 3 | Fewer pillars, high confidence only |
| balanced | 4 | Default â€” entity + intent + funnel |
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
