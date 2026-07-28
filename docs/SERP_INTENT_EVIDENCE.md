# SERP Intent Evidence

Service: `SerpIntentEvidenceService` — version `INTENT_EVIDENCE_VERSION = 1.0.0`.

## Input

Organic results + SERP features (PAA, local pack, featured snippet). **Không** dùng keyword token rules.

Signals:

- `SerpResultClassifier` → `SerpResultType`
- `SerpPageTypeClassifier` → `SerpPageType`

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

- `field_sources.intent = manual` → manual wins, never overwritten by SERP
- Low SERP confidence → `InsufficientEvidence`, falls back to classifier/cluster
- Compatible pairs → `Mixed`

Codes: `SerpIntentReconciliationCode` (`serp.intent_consistent`, `serp.intent_mismatch`, …).

## Typical patterns

| SERP shape | Likely intent |
|------------|---------------|
| Service/pricing pages | commercial / local |
| Article/blog dominance | informational |
| &lt; 2 weak results | low confidence (~0.2) |
