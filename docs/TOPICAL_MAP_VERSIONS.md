# Topical Map Versions

Table: `seo_topical_map_versions`

## Status

`draft` → `reviewed` → `approved` → (old approved) `superseded`

## When version created

- Build draft map (snapshot of candidate hierarchy)
- User approve map
- User explicit `SaveTopicalMapVersion`
- Prepare convert (uses approved version; may save if needed)

Not on every drag/move.

## Snapshot (compact)

```json
{
  "workspace_ref": "kww_...",
  "version": 3,
  "topics": [{"topic_ref": "kwt_...", "parent_ref": null, "type": "pillar", "name": "SEO"}],
  "assignments": [{"cluster_ref": "kwc_...", "topic_ref": "kwt_...", "relationship": "primary"}],
  "summary": {"topic_count": 8, "cluster_count": 24, "coverage_score": 63}
}
```

No full models, article body, or raw AI output.

## Diff

`TopicalMapVersionDiffService` — topics added/removed/moved/renamed, clusters attached/detached/moved, coverage/gap/blocking deltas.

Phase 3: **no restore**.
