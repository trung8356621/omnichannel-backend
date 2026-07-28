# Keyword Clustering

`Services/KeywordIntelligence/KeywordClusterService.php`

## Strategies (Phase 2)

- `strict` — intent + full normalized key
- `balanced` (default) — intent + core tokens
- `broad` — intent + main entity

Suggested page type (SEO page shape, not Content Project item type):

`article` | `landing_page` | `comparison` | `local_landing` | `unknown`

## Protection

- Manual `cluster_id` / approved|reviewed clusters skipped when `recluster_draft_only=true`
- Manual primary via `ClusterPrimaryKeywordSelector`
- Validator: `KeywordClusterValidator` (`valid` / `needs_split` / `needs_review` / `invalid`)

## Mutations (CommandBus)

- `MergeKeywordClustersCommand` — preview + confirmation token when approved/mixed
- `SplitKeywordClusterCommand`
- `MoveKeywordsToClusterCommand` — Agent cannot use `force_reviewed_mismatch`

Phase 2 analysis **does not** auto-build Topical Map (Phase 3).

## Phase 4 — SERP overlap validation (additive)

`SerpClusterValidationService` gợi ý keep/split/outlier từ URL overlap — **không** auto-merge/split approved clusters. Agent/user apply qua `ValidateClusterWithSerpCommand`. Xem [SERP_CLUSTER_VALIDATION.md](SERP_CLUSTER_VALIDATION.md).
