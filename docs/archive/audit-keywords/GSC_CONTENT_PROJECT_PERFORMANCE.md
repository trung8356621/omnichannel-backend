> Status: Historical
> Not canonical
> Archived on: 2026-08-01
> Superseded by: ../../modules/SEO_AUDIT_AND_KEYWORDS.md
> Purpose: implementation history only
# GSC Content Project Performance

## Performance states (`GscProjectItemPerformanceState`)

Derived by `GscProjectItemPerformanceDeriver` from current vs baseline GSC aggregates:

| State | Condition |
|-------|-----------|
| `not_published` | `published === false` |
| `awaiting_data` | impressions = 0 |
| `needs_review` | explicit flag |
| `new` | baseline zero |
| `decaying` | clicks drop â‰¥ threshold (default 30%) |
| `growing` | clicks or impressions up |
| `winning` | position â‰¤ 5, CTR â‰¥ 5% |
| `underperforming` | impressions â‰¥ 100, CTR < 2% |
| `stable` | flat period |
| `unknown` | fallback |

## Conversion preview

- `GscContentProjectPreviewBuilder` â€” `improve_description` / `rewrite_brief` only
- **Never** `gallery_description`
- Preview command: `PreviewCreateContentProjectFromGscOpportunitiesCommand` (no create)
- Create command: `CreateContentProjectFromGscOpportunitiesCommand` â†’ `GscOpportunityContentProjectConverter` â†’ `CreateContentProjectCommand` via CommandBus (confirmation token when required)
- Item type: improve path; **no** auto rewrite/publish from GSC services

GSC services do **not** mutate topical map or call `ApproveTopicalMap`.
