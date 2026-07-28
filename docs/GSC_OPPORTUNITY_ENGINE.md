# GSC Opportunity Engine

Path: `Services/GscIntelligence/GscOpportunityDetectionService.php`

Config namespace: `seo-content-ai.gsc_intelligence.opportunity.*`  
(source file: `app/Addons/SeoContentAi/config/gsc_intelligence.php`)

## Types (`GscOpportunityType`)

| Type | Trigger (service defaults / config) |
|------|-------------------------------------|
| `high_impression_low_ctr` | impressions ≥ `min_impressions` (100), CTR gap ≥ `low_ctr_gap_min` (0.02) vs `GscExpectedCtrModel` |
| `near_page_one` | position ≤ `near_page_one_max_position` (15), impressions ≥ min |
| `content_decay` | clicks drop ≥ `decay_clicks_drop_pct` (0.30) vs baseline |
| `impression_growth` | impressions growth ≥ `min_impressions_growth_pct` (0.25) |
| `unmapped_query` | no `keyword_ref`, impressions ≥ min |

## Maturity (`GscOpportunityMaturity`)

From `first_seen_date` + `opportunity.maturity.new_days` (14) / `early_days` (60).

## Fingerprint dedup

SHA-256 over algorithm + type + normalized_query + keyword_ref — duplicate detect calls skip seen fingerprints (`resetFingerprints()` between batches).

## Expected CTR

`GscExpectedCtrModel` — position bands; **no ML**.

## Content actions

`GscContentActionRecommendationService` — rewrite requires reviewed evidence; improve path không dùng `gallery_description`.

## Persistence

In-run detect during sync returns opportunity arrays only. Durable `seo_gsc_opportunities` rows via CommandBus `DetectGscOpportunitiesCommand` (+ approve/reject/ignore/resolve).

Commands: `DetectGscOpportunitiesCommand`, `ApproveGscOpportunityCommand`, `RejectGscOpportunityCommand`, `IgnoreGscOpportunityCommand`, `ResolveGscOpportunityCommand`.
