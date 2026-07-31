> Status: Historical
> Not canonical
> Archived on: 2026-08-01
> Superseded by: ../../modules/AUTOMATION.md
> Purpose: implementation history only
# Prompt Workflow Normalization Report

**Phase:** 5A  
**Updated:** 2026-07-18  
**Meaning of normalize:** Spec fixtures + concern split â€” **khÃ´ng** Ä‘á»•i SeoTask DB / production callers.

## Representative set (normalized as fixtures)

| Fixture | Covers | Classification |
|---|---|---|
| `article.outline.generate.v0.1.json` | Outline generation | NORMALIZE_NOW |
| `article.vocabulary.extract.v0.1.json` | Semantic vocabulary | NORMALIZE_NOW |
| `article.content.generate.v0.1.json` | Full article | MIGRATE_LATER |
| `article.seo_meta.generate.v0.1.json` | SEO meta | EXPERIMENTAL |
| `article.title.suggest.v0.1.json` | Title suggest | EXPERIMENTAL |
| `article.content.rewrite.v0.1.json` | Rewrite | MIGRATE_LATER |
| `article.workflow.multistep.v0.1.json` | Multi-step orchestration | KEEP_AS_IS |
| `keyword.discovery.structured.v0.1.json` | Structured JSON | NORMALIZE_NOW |
| `article.locale.aware.v0.1.json` | Locale-specific | NORMALIZE_NOW |
| `article.step.with_previous.v0.1.json` | Previous-step output | KEEP_AS_IS |
| `article.publish.after_ai.v0.1.json` | Domain side effect after AI | KEEP_AS_IS (boundary) |

Path: `docs/automation/prompt/fixtures/`.

## Contract patterns observed

1. **Variables bag** `array<string,string>` â€” khÃ´ng DTO.  
2. **Eloquent Ä‘Ã´i khi** lá»t vÃ o service (article model) trÆ°á»›c compile â€” Spec cáº¥m vÃ o hook.  
3. **Previous outputs** qua `nodeOutputs[port]` â€” ad hoc string.  
4. **Filters** vá»«a parse vá»«a side-effect state.meta.  
5. **Actions** trá»™n local persist + WP review.  
6. **Hooks Phase 1** gáº§n Spec nháº¥t (schema + normalize + settings).  
7. **Locale** = English language **name** trong `{{language}}`, khÃ´ng pháº£i slug.  
8. **Output** markdown-first; JSON thÆ°á»ng bá»‹ fence.

## Inconsistencies

| Topic | Detail |
|---|---|
| Dual runtimes | SeoTask graph vs PromptHook vs hardcoded services |
| Meta path | Hook UI vs markdown import meta trong PromptTestPublish |
| Scoring | analyze sync vs scoring queue (Phase 4B action) |
| Tone defaults | Hardcoded VI trong SeoPromptSettingsService |
| Image source | prompt \| workflow dual binding |
| Markers | `[STARTâ€¦END]` dá»… leak vÃ o model output |
| Experimental vs Active | UI Active nhÆ°ng inventory Ä‘Ã¡nh EXPERIMENTAL cho engine lock |

## What changed in code (5A)

- Spec helpers: `PromptHooks/Spec/*`  
- Fixtures + docs under `docs/automation/prompt/`  
- Unit tests Phase 5A  
- **KhÃ´ng** Ä‘á»•i production workflow behavior

## Production behavior

Unchanged (default legacy paths). EXPERIMENTAL hooks giá»¯ runtime hiá»‡n táº¡i.
