# Prompt Hook Migration Plan

**Phase:** 5D1 — hosting rollout support + single-hook stabilization tooling. Repo defaults **legacy**.

## Modes

| Mode | Behavior |
|---|---|
| `legacy` | Existing caller path — **repo/production default** |
| `shadow` | Legacy SoT; no second AI call; parity samples |
| `hook` | Runtime + production adapter; typed fail; no silent fallback |

## Status per hook (5D1 code)

| Hook | Status |
|---|---|
| `article.outline.generate@0.1.0` | experimental; `markdown_sections` + `legacy_prompt_content`; code ready / production-adapter-ready (mode legacy until hosting) |
| `article.content.generate@0.1.0` | experimental; markdown + `legacy_prompt_content`; editor selectable; explicit binding (mode legacy) |
| `article.content.rewrite@0.1.0` | experimental; markdown + `legacy_prompt_content`; editor selectable; explicit binding (mode legacy) |
| `article.faq.generate@0.1.0` | code ready / production-adapter-ready (mode legacy) |
| `keyword.discovery.structured@0.1.0` | code ready / production-adapter-ready (mode legacy) |
| `article.title_suggestion@0.1.0` | code ready; experimental; not stable |
| `article.meta_description_suggestion@0.1.0` | code ready; experimental; not stable |

Ladder: code ready → deployed → shadow enabled → sample threshold reached → gate passed → hook enabled → stable version

## Flags

All `PROMPT_HOOK_MIGRATION_*=legacy` in repo. Do not commit shadow/hook defaults.

## Order

1. Spec + fixtures (5A)
2. Runtime core (5B)
3. Production adapter + attach Action (5C)
4. Hosting shadow → gate → hook (5D1 tooling; hosting fills report)
5. Multistep / image / WP — later / blocked

## Rollback

Mode=`legacy` + optimize/config/cache clear + `seo:prompt-hooks:clear-cache` + `queue:restart`.

## Retry ownership

PromptRunner / AiModelRouter own provider retries. Hook output validation (including `markdown_sections` missing section) classifies failure only — no second retry loop in Hook Runtime.

## Non-goals

Workflow Graph Engine · multistep article · image/video · WP · live shadow · durable budget DB · auto stable version.
