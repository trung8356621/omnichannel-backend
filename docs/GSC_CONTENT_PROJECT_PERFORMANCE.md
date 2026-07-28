# GSC Content Project Performance

## Performance states (`GscProjectItemPerformanceState`)

Derived by `GscProjectItemPerformanceDeriver` from current vs baseline GSC aggregates:

| State | Condition |
|-------|-----------|
| `not_published` | `published === false` |
| `awaiting_data` | impressions = 0 |
| `needs_review` | explicit flag |
| `new` | baseline zero |
| `decaying` | clicks drop ≥ threshold (default 30%) |
| `growing` | clicks or impressions up |
| `winning` | position ≤ 5, CTR ≥ 5% |
| `underperforming` | impressions ≥ 100, CTR < 2% |
| `stable` | flat period |
| `unknown` | fallback |

## Conversion preview

- `GscContentProjectPreviewBuilder` — `improve_description` / `rewrite_brief` only
- **Never** `gallery_description`
- Preview command: `PreviewCreateContentProjectFromGscOpportunitiesCommand` (no create)
- Create command: `CreateContentProjectFromGscOpportunitiesCommand` → `GscOpportunityContentProjectConverter` → `CreateContentProjectCommand` via CommandBus (confirmation token when required)
- Item type: improve path; **no** auto rewrite/publish from GSC services

GSC services do **not** mutate topical map or call `ApproveTopicalMap`.
