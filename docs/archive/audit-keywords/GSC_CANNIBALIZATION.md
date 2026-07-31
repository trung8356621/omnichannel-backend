> Status: Historical
> Not canonical
> Archived on: 2026-08-01
> Superseded by: ../../modules/SEO_AUDIT_AND_KEYWORDS.md
> Purpose: implementation history only
# GSC Cannibalization

Path: `Services/GscIntelligence/GscQueryCannibalizationDetector.php`

Detects multiple pages competing for the same normalized query.

## Issue types (`GscCannibalizationType`)

- `competing_pages` â€” â‰¥2 pages each with min impressions
- `alternating_page` â€” leadership alternates between top pages
- `expected_multi_page` â€” navigational queries (e.g. `trang chá»§`, `login`) â€” **not** treated as critical conflict

## Rules

- `auto_consolidate` always **false** â€” suggestions only
- Config: `seo-content-ai.gsc_intelligence.cannibalization.min_competing_pages` (default 2), `min_impressions_per_page` (default 10)

Content action when cannibalization present: `GscContentAction::Differentiate`.

Distinct from Keyword Intelligence cannibalization (`KeywordCannibalizationService`) â€” GSC layer is queryÃ—page URL competition from Search Console facts.
