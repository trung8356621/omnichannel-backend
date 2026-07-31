> Status: Historical
> Not canonical
> Archived on: 2026-08-01
> Superseded by: ../../modules/SEO_AUDIT_AND_KEYWORDS.md
> Purpose: implementation history only
# SERP Content Gaps

Analyzer: `SerpContentGapAnalyzer` â€” version `CONTENT_GAP_ANALYZER_VERSION = 1.0.0`.

Compares **own page evidence** vs **competitor evidence** arrays (headings, FAQ count, schema, media, word count).

## Gap types (`SerpContentGapType`)

| Type | Signal |
|------|--------|
| `missing_question` | Competitors have FAQ, own has none |
| `missing_schema` | Competitors use schema, own empty |
| `missing_media` | Competitors media-rich, own sparse |
| `weak_coverage` | Competitors deep content, own shallow |
| `missing_comparison` | Comparison title/schema patterns |
| `missing_heading` | H2 section on â‰¥2 competitors, missing on own |

## Rules

- Requires `min_frequency` (default 0.3) across competitor set
- **Single heading on one competitor does not create strong gap** â€” section gaps need â‰¥2 competitor hits + frequency threshold
- Output filtered by `min_confidence` (default 0.45)

## Commands

`ReviewSerpContentGapCommand`, `AcceptSerpContentGapCommand`, `IgnoreSerpContentGapCommand`, `ResolveSerpContentGapCommand`.

## Content Project preview

`SerpEvidenceContentProjectPreviewAdapter` merges `serp_evidence` into preview items â€” no `gallery_description`.
