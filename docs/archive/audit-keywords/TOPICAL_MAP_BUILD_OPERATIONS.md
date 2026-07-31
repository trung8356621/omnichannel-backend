> Status: Historical
> Not canonical
> Archived on: 2026-08-01
> Superseded by: ../../modules/SEO_AUDIT_AND_KEYWORDS.md
> Purpose: implementation history only
# Topical Map Build Operations

Operation type conceptual: `keyword_topical_map_build`

## Stages

preparing_clusters â†’ building_topics â†’ assigning_clusters â†’ validating_hierarchy â†’ calculating_coverage â†’ suggesting_internal_links â†’ detecting_conflicts â†’ finalizing

## Lock

- Key: `keyword-topical-map-build:{workspace_ref}`
- Owner token + TTL (`topical_map.lock_ttl_seconds`, default 900)
- Refresh supported; no forceRelease
- Blocked when keyword analysis running â†’ `topical_map.keyword_analysis_running`
- Concurrent build â†’ `topical_map.already_building`
- Archived workspace blocked at handler (`keyword.workspace_archived`)

## Result codes

- `topical_map.build_started`
- `topical_map.build_completed`
- `topical_map.build_partially_completed`
- `topical_map.build_failed`
- `topical_map.no_approved_clusters`
- `topical_map.hierarchy_invalid`
- `topical_map.build_cancelled`

## Eligibility

Default: `status=approved`, not excluded, has primary keyword, no unresolved critical structural conflict.

Option `include_reviewed_clusters=false` by default; if true â†’ warning, not convert-ready.

## Quotas

`max_topics_per_workspace`, `max_clusters_per_map_build`, `max_link_suggestions`, `map_build_operations_per_hour`
