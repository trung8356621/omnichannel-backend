> Status: Historical
> Not canonical
> Archived on: 2026-08-01
> Superseded by: ../../modules/SEO_AUDIT_AND_KEYWORDS.md
> Purpose: implementation history only
# GSC Opportunity Engine

Path: `Services/GscIntelligence/GscOpportunityDetectionService.php`

Config namespace: `seo-content-ai.gsc_intelligence.opportunity.*`  
(source file: `app/Addons/SeoContentAi/config/gsc_intelligence.php`)

## Types (`GscOpportunityType`)

| Type | Trigger (service defaults / config) |
|------|-------------------------------------|
| `high_impression_low_ctr` | impressions â‰¥ `min_impressions` (100), CTR gap â‰¥ `low_ctr_gap_min` (0.02) vs `GscExpectedCtrModel` |
| `near_page_one` | position â‰¤ `near_page_one_max_position` (15), impressions â‰¥ min |
| `content_decay` | clicks drop â‰¥ `decay_clicks_drop_pct` (0.30) vs baseline |
| `impression_growth` | impressions growth â‰¥ `min_impressions_growth_pct` (0.25) |
| `unmapped_query` | no `keyword_ref`, impressions â‰¥ min |

## Maturity (`GscOpportunityMaturity`)

From `first_seen_date` + `opportunity.maturity.new_days` (14) / `early_days` (60).

## Fingerprint dedup

SHA-256 over algorithm + type + normalized_query + keyword_ref â€” duplicate detect calls skip seen fingerprints (`resetFingerprints()` between batches).

## Expected CTR

`GscExpectedCtrModel` â€” position bands; **no ML**.

## Content actions

`GscContentActionRecommendationService` â€” rewrite requires reviewed evidence; improve path khÃ´ng dÃ¹ng `gallery_description`.

## Persistence

In-run detect during sync returns opportunity arrays only. Durable `seo_gsc_opportunities` rows via CommandBus `DetectGscOpportunitiesCommand` (+ approve/reject/ignore/resolve).

Commands: `DetectGscOpportunitiesCommand`, `ApproveGscOpportunityCommand`, `RejectGscOpportunityCommand`, `IgnoreGscOpportunityCommand`, `ResolveGscOpportunityCommand`.
