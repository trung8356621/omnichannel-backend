> Status: Historical
> Not canonical
> Archived on: 2026-08-01
> Superseded by: ../../modules/SEO_AUDIT_AND_KEYWORDS.md
> Purpose: implementation history only
# SERP Intent Evidence

Service: `SerpIntentEvidenceService` â€” version `INTENT_EVIDENCE_VERSION = 1.0.0`.

## Input

Organic results + SERP features (PAA, local pack, featured snippet). **KhÃ´ng** dÃ¹ng keyword token rules.

Signals:

- `SerpResultClassifier` â†’ `SerpResultType`
- `SerpPageTypeClassifier` â†’ `SerpPageType`

## Output

```php
[
  'observed_primary_intent' => string,  // KeywordSearchIntent value
  'secondary_intents' => list<string>,
  'dominant_page_types' => list<string>,
  'feature_distribution' => array<string, int>,
  'confidence' => float,
  'reason_codes' => list<string>,
  'version' => '1.0.0',
]
```

## Reconciliation

`KeywordSerpIntentReconciler`:

- `field_sources.intent = manual` â†’ manual wins, never overwritten by SERP
- Low SERP confidence â†’ `InsufficientEvidence`, falls back to classifier/cluster
- Compatible pairs â†’ `Mixed`

Codes: `SerpIntentReconciliationCode` (`serp.intent_consistent`, `serp.intent_mismatch`, â€¦).

## Typical patterns

| SERP shape | Likely intent |
|------------|---------------|
| Service/pricing pages | commercial / local |
| Article/blog dominance | informational |
| &lt; 2 weak results | low confidence (~0.2) |
