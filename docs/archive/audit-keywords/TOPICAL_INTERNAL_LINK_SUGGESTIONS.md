> Status: Historical
> Not canonical
> Archived on: 2026-08-01
> Superseded by: ../../modules/SEO_AUDIT_AND_KEYWORDS.md
> Purpose: implementation history only
# Topical Internal Link Suggestions

Table: `seo_topical_link_suggestions`

Service: `TopicalInternalLinkSuggestionService` â€” **suggestions only**, never mutates articles.

## Types

pillar_to_cluster, cluster_to_pillar, sibling_related, comparison_to_entity, faq_to_parent, existing_to_planned

## Constraints

Skip when: same article, cross-site/tenant, weak relationship, target excluded, critical cannibalization unresolved, low mapping confidence.

Anchor: normalize leading/trailing punctuation; prefer primary keyword; no stuffing; store candidate only.
