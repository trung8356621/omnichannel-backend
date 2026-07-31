> Status: Historical
> Not canonical
> Archived on: 2026-08-01
> Superseded by: ../../modules/SEO_AUDIT_AND_KEYWORDS.md
> Purpose: implementation history only
# Keyword Clustering

`Services/KeywordIntelligence/KeywordClusterService.php`

## Strategies (Phase 2)

- `strict` â€” intent + full normalized key
- `balanced` (default) â€” intent + core tokens
- `broad` â€” intent + main entity

Suggested page type (SEO page shape, not Content Project item type):

`article` | `landing_page` | `comparison` | `local_landing` | `unknown`

## Protection

- Manual `cluster_id` / approved|reviewed clusters skipped when `recluster_draft_only=true`
- Manual primary via `ClusterPrimaryKeywordSelector`
- Validator: `KeywordClusterValidator` (`valid` / `needs_split` / `needs_review` / `invalid`)

## Mutations (CommandBus)

- `MergeKeywordClustersCommand` â€” preview + confirmation token when approved/mixed
- `SplitKeywordClusterCommand`
- `MoveKeywordsToClusterCommand` â€” Agent cannot use `force_reviewed_mismatch`

Phase 2 analysis **does not** auto-build Topical Map (Phase 3).

## Phase 4 â€” SERP overlap validation (additive)

`SerpClusterValidationService` gá»£i Ã½ keep/split/outlier tá»« URL overlap â€” **khÃ´ng** auto-merge/split approved clusters. Agent/user apply qua `ValidateClusterWithSerpCommand`. Xem [SERP_CLUSTER_VALIDATION.md](SERP_CLUSTER_VALIDATION.md).
