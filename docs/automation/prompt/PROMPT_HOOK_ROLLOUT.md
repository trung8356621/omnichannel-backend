# Prompt Hook Rollout

## Modes (per hook — never one global mode)

| Mode | Behavior |
|---|---|
| `legacy` | Production default — existing caller path |
| `shadow` | Legacy SoT; parity without second AI call |
| `hook` | Runtime + `PromptRunnerProviderAdapter` |

## Transition policy

`legacy` → `shadow` → `hook`. Rollback any → `legacy`.  
`legacy` → `hook` **forbidden** (must shadow first).  
Stable version auto-promote: **never**.

## Status ladder

`code ready` → `deployed` → `shadow enabled` → `sample threshold reached` → `gate passed` → `hook enabled` → `stable version` (manual only; title/meta not in 5D1)

## Thresholds (config)

| Hook | Samples |
|---|---|
| outline / faq / keyword | 20 |
| title / meta | 30 |

## Hosting

- Runbook: `PROMPT_HOOK_PHASE5C_HOSTING_VALIDATION.md`
- Fill-in report: `PROMPT_HOOK_PHASE5D1_ROLLOUT_REPORT.md`
- Commands: `seo:prompt-hooks:status`, `seo:prompt-hooks:parity-report`, `seo:prompt-hooks:clear-cache`

Live AI shadow: default **false**. Multi-worker blocked without durable budget store.
