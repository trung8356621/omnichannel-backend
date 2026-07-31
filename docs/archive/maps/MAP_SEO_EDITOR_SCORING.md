> Status: Historical
> Not canonical
> Archived on: 2026-08-01
> Superseded by: ../../modules/ARTICLE_EDITOR.md
> Purpose: implementation history only
# SeoContentAi â€” SEO Scoring (Rules + Violations)

[â† Quay láº¡i MAP_SEO_EDITOR](MAP_SEO_EDITOR.md) Â· [Audit filters](MAP_SEO_AUDIT.md) Â· [Báº£n Ä‘á»“ tá»•ng](SUPER_MAP_INDEX.md)

---

## 1. Tá»•ng quan

Há»‡ thá»‘ng cháº¥m SEO dÃ¹ng kiáº¿n trÃºc **deduction-based**:

| ThÃ nh pháº§n | Vai trÃ² |
|------------|---------|
| `SeoScoringRulesRegistry` | Danh sÃ¡ch rules cá»‘ Ä‘á»‹nh trong code (`key`, `deduction`, `locale_key`) |
| `SeoScoringEngine` | PhÃ¢n tÃ­ch HTML â†’ `list<string>` violation keys |
| `SeoScoringCalculator` | `score = max(0, 100 - sum(deductions))` |
| `article_meta.seo_rule_violations` | JSON array pháº³ng cÃ¡c `rule_key` vi pháº¡m |
| `articles.seo_score` | Cache denormalized khi persist (sort/filter SQL) |

**Äiá»ƒm hiá»ƒn thá»‹** luÃ´n tÃ­nh Ä‘á»™ng tá»« `violations` + rules hiá»‡n táº¡i â€” Ä‘á»•i deduction trong code cáº­p nháº­t Ä‘iá»ƒm bÃ i cÅ© mÃ  khÃ´ng cáº§n re-analyze job.

---

## 2. Luá»“ng React Editor (client-side)

```mermaid
flowchart TB
    Bootstrap["edit-article.blade.php<br/>seo_scoring_rules + seo_rule_messages"]
    Editor["SeoArticleEditor.jsx"]
    Analyzer["seoAnalyzer.js â†’ computeViolations()"]
    Calc["seoScoreCalculator.js â†’ scoreFromViolations()"]
    Panel["SeoScorePanel.jsx"]
    Save["articleEditorApi.js â†’ POST save<br/>violations only"]

    Bootstrap --> Editor
    Editor --> Analyzer
    Analyzer --> Calc
    Calc --> Panel
    Editor --> Save
    Save --> Job["AnalyzeArticleSeoJob"]
    Job --> Meta["article_meta.seo_rule_violations"]
```

### Bootstrap JSON (`#seo-article-editor-settings`, `#seo-article-initial-seo`)

| Key | MÃ´ táº£ |
|-----|--------|
| `seo_scoring_rules` | `[{key, deduction, locale_key}, ...]` |
| `seo_rule_messages` / `seo_scoring_messages` | Map `seo_rules.*` â†’ text Ä‘Ã£ dá»‹ch |
| `featured_snippet_thresholds` | NgÆ°á»¡ng báº£ng FS cho client analyzer |
| `violations` / `analysis.violations` | Violations Ä‘Ã£ lÆ°u cá»§a bÃ i |

### Files React

| File | Vai trÃ² |
|------|---------|
| [`seoAnalyzer.js`](../app/Addons/SeoContentAi/resources/js/utils/seoAnalyzer.js) | `computeSeoAnalysis()` â€” mirror PHP checkers |
| [`seoScoreCalculator.js`](../app/Addons/SeoContentAi/resources/js/utils/seoScoreCalculator.js) | `scoreFromViolations()`, `formatViolationLine()` |
| [`SeoScorePanel.jsx`](../app/Addons/SeoContentAi/resources/js/components/SeoScorePanel.jsx) | Ring score + list `-{deduction}Ä‘: {message}` |
| [`SeoArticleEditor.jsx`](../app/Addons/SeoContentAi/resources/js/components/SeoArticleEditor.jsx) | Debounce analyze on content change; tab badge score |

### Payload save

`buildSeoAnalysisPayload()` chá»‰ gá»­i:

```json
{
  "violations": ["h2_missing", "faq_missing"],
  "extracted_links": { "internal": [], "external": [] }
}
```

KhÃ´ng gá»­i `score`, `breakdown`, `good`, `errors` cá»‘ Ä‘á»‹nh.

---

## 3. Backend

| File | Vai trÃ² |
|------|---------|
| [`SeoScoringRulesRegistry.php`](../app/Addons/SeoContentAi/Support/SeoScoringRulesRegistry.php) | Rules + i18n keys |
| [`SeoScoringEngine.php`](../app/Addons/SeoContentAi/Services/SeoScoringEngine.php) | Core analyzers + Featured Snippet tiers |
| [`SeoScoringCalculator.php`](../app/Addons/SeoContentAi/Services/SeoScoringCalculator.php) | TÃ­nh Ä‘iá»ƒm tá»« violations |
| [`SeoRuleViolationsResolver.php`](../app/Addons/SeoContentAi/Support/SeoRuleViolationsResolver.php) | Äá»c meta má»›i + convert legacy |
| [`SeoAnalyzerService.php`](../app/Addons/SeoContentAi/Services/SeoAnalyzerService.php) | Persist `seo_rule_violations` |
| [`SeoEngineService.php`](../app/Services/SeoEngineService.php) | Wrapper tÆ°Æ¡ng thÃ­ch audit/API |

### Rules (tÃ³m táº¯t)

| key | deduction |
|-----|-----------|
| `missing_focus_keyword` | 100 |
| `h2_missing` | 20 |
| `content_length_low` | 15 |
| `image_ratio_*` | 5â€“15 |
| `image_alt_missing` | 5 |
| `wiki_trust_missing` | 15 |
| `faq_missing` | 10 |
| `keyword_missing_in_*` | 3â€“4 |
| `featured_snippet_*` | 4â€“10 |

i18n: [`lang/en/seo_rules.php`](../lang/en/seo_rules.php), [`lang/vi/seo_rules.php`](../lang/vi/seo_rules.php).

---

## 4. Legacy compat

`SeoRuleViolationsResolver` Ä‘á»c lazy:

1. `seo_rule_violations` (format má»›i â€” array pháº³ng)
2. Fallback `seo_rank_math_score` object â†’ map `reason_keys` / breakdown
3. Fallback `seo_scoring_details` â†’ FAQ + snippet tier keys

BÃ i Ä‘Æ°á»£c save/analyze láº¡i sáº½ ghi format má»›i.

---

## 5. Audit (`ArticlesOptimal`)

Audit **chá»‰ Ä‘á»c cache** (`seo_rule_violations`, `seo_score`). Populate cache qua `AnalyzeArticleSeoJob` (xem [MAP_SEO_AUDIT.md](MAP_SEO_AUDIT.md)).

Filters map sang violation keys:

| Filter | Violation key |
|--------|---------------|
| Thin content | `content_length_low` |
| Poor image | `image_ratio_*`, `image_alt_missing` |
| Missing H2 | `h2_missing` |
| Missing FAQ | `faq_missing` |
| Low score | `articles.seo_score < 60` (aggregate, khÃ´ng pháº£i rule key) |
