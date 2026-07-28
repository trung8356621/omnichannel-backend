# Keyword Cannibalization Detection

`KeywordCannibalizationService::detect()` — Phase 2 **persist** vào `seo_keyword_cannibalization_issues` theo `fingerprint`.

## Issue types

| Code | Meaning |
|---|---|
| `c1_same_keyword_multi_article` | Same keyword → multiple articles |
| `c2_cluster_multi_article` | Same cluster → multiple article targets |
| `c3_multi_cluster_same_article` | (reserved / future) |
| `c4_planned_vs_existing` | (reserved) |
| `c5_near_primary_conflict` | (reserved) |
| `c6_manual_mapping_conflict` | (reserved) |

## Status

`open` → `reviewed` / `ignored` / `resolved` / `stale`

Re-analysis marks unseen open issues as `stale`; fingerprint match refreshes without duplicate rows.

## Risk

| Distinct articles | risk |
|---|---|
| 2 | low |
| 3 | medium |
| 4 | high |
| ≥5 | critical |

Multi-keyword sharing one article is **not** automatically cannibalization.

## Review command

`ReviewCannibalizationIssueCommand` → `keyword_intelligence.review_cannibalization`

Public ref: `kci_*`
