# SERP Cluster Validation

Service: `SerpClusterValidationService` — **suggestions only**, no cluster DB mutation.

Uses `SerpOverlapService::compare()` — score + band, never merges clusters.

## Actions (`SerpClusterValidationAction`)

| Action | Trigger |
|--------|---------|
| `keep_cluster` | High average overlap |
| `split_cluster` | Avg overlap &lt; `split_overlap_max` |
| `remove_outlier` | Member avg overlap ≤ `outlier_overlap_max` |
| `resample_serp` | Insufficient SERP coverage (`min_valid` not met) |
| `review_keyword` | Moderate overlap / manual review |

## Overlap bands

`SerpOverlapBand`: low → moderate → high → very_high (configurable thresholds).

Position-weighted scoring optional (`overlap.position_weighted`).

## Integration with Keyword Clustering

- Approved clusters **not** auto-split by SERP — user/agent applies via `ValidateClusterWithSerpCommand` / review UI.
- SERP layer does not call `MergeKeywordClustersCommand` or mutate approved topical map.

## Config keys

`seo-content-ai.serp_intelligence.cluster_validation.outlier_overlap_max` (default 0.2)

`seo-content-ai.serp_intelligence.cluster_validation.split_overlap_max` (default 0.25)

`seo-content-ai.serp_intelligence.overlap.min_valid` (default 5)
