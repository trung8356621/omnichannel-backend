> Status: Historical
> Not canonical
> Archived on: 2026-08-01
> Superseded by: ../../modules/SEO_AUDIT_AND_KEYWORDS.md
> Purpose: implementation history only
# Keyword Cannibalization Detection

`KeywordCannibalizationService::detect()` â€” Phase 2 **persist** vÃ o `seo_keyword_cannibalization_issues` theo `fingerprint`.

## Issue types

| Code | Meaning |
|---|---|
| `c1_same_keyword_multi_article` | Same keyword â†’ multiple articles |
| `c2_cluster_multi_article` | Same cluster â†’ multiple article targets |
| `c3_multi_cluster_same_article` | (reserved / future) |
| `c4_planned_vs_existing` | (reserved) |
| `c5_near_primary_conflict` | (reserved) |
| `c6_manual_mapping_conflict` | (reserved) |

## Status

`open` â†’ `reviewed` / `ignored` / `resolved` / `stale`

Re-analysis marks unseen open issues as `stale`; fingerprint match refreshes without duplicate rows.

## Risk

| Distinct articles | risk |
|---|---|
| 2 | low |
| 3 | medium |
| 4 | high |
| â‰¥5 | critical |

Multi-keyword sharing one article is **not** automatically cannibalization.

## Review command

`ReviewCannibalizationIssueCommand` â†’ `keyword_intelligence.review_cannibalization`

Public ref: `kci_*`
