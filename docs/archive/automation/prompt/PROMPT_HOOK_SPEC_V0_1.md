> Status: Historical
> Not canonical
> Archived on: 2026-08-01
> Superseded by: ../../modules/AUTOMATION.md
> Purpose: implementation history only
# Prompt Hook Specification v0.1

**Status:** proposed (Phase 5A)  
**Runtime today:** Manifest schema_version 1 (`PromptHookManifestLoader`) â€” **khÃ´ng** thay production loader trong 5A.  
**Validator:** `PromptHooks/Spec/PromptHookSpecV01Validator.php`  
**Fixtures:** `docs/automation/prompt/fixtures/*.v0.1.json`

---

## 1. Goals

KhÃ³a contract cho Hook Engine tÆ°Æ¡ng lai dá»±a trÃªn audit tháº­t (SeoTask + PromptRunner + Phase 1 hooks).

## 2. Discovery

| Question | Decision (v0.1) |
|---|---|
| Discover á»Ÿ Ä‘Ã¢u? | Directory `resources/prompt-hooks/*.json` (+ optional `docs/automation/prompt/fixtures` cho spec-only) |
| JSON hay PHP? | **JSON manifest** lÃ  SoT; PHP chá»‰ loader/validator/resolvers |
| PHP má»Ÿ rá»™ng JSON? | CÃ³ â€” PHP `PromptHookEntityResolver*` + optional PHP normalizer steps Ä‘Äƒng kÃ½ theo tÃªn |
| Versioning | `key` + `version` (semver string hoáº·c int); mismatch â†’ fail rÃµ, khÃ´ng silent |
| Override site/team/locale | Settings merge: defaults â† manifest â† site option â† caller; locale `mode`: site\|article\|fixed\|caller |
| Merge settings | `PromptHookSettingsMerger` â€” drop secret keys |
| Validate input | Hook runtime **trÆ°á»›c** provider call (`PromptHookInputResolver` pattern) |
| Render template | Locale keys (`lang/`) hoáº·c inline system/user; engine mustache `{{var}}`; **khÃ´ng** PHP eval |
| Previous-step | Workflow truyá»n `previous_outputs` object â€” hook khÃ´ng query graph |
| Model settings allowed | temperature, max_tokens, top_p, timeout_ms only |
| Secrets | Resolve tá»« `ApiConnection` / prompt binding â€” **cáº¥m** trong JSON |
| Output validate | schema type + normalize steps + not_empty / json_decode |
| Retry | Workflow owns multi-step retry; Hook may declare `retry.max` for empty/invalid **before** domain write |
| Hook â†’ Business Action? | **KhÃ´ng** trá»±c tiáº¿p. Workflow/caller gá»i Action sau hook success |
| Hook ghi DB? | **KhÃ´ng** domain entity. Optional: PromptResult audit link (cáº§n policy 5B) |
| Logging | KhÃ´ng lÆ°u full prompt/output máº·c Ä‘á»‹nh; redact secrets |
| Disabled / missing / version mismatch | Fail vá»›i error code rÃµ â€” khÃ´ng fallback legacy sau khi Ä‘Ã£ gá»i provider cÃ³ cost |

## 3. Canonical envelope (caller â†’ hook)

```json
{
  "context": {
    "site_id": 1,
    "article_id": 10,
    "locale": "vi",
    "actor_id": 3,
    "correlation_id": "uuid"
  },
  "input": {},
  "previous_outputs": {},
  "settings": {}
}
```

KhÃ´ng truyá»n Eloquent model.

## 4. Spec shape

```json
{
  "spec_version": "0.1",
  "key": "article.outline.generate",
  "version": "0.1.0",
  "name": "...",
  "description": "...",
  "enabled": true,
  "model": {
    "provider": "prompt_connection",
    "name": "configured",
    "settings": {}
  },
  "locale": { "mode": "site", "fallback": "en" },
  "input_schema": {},
  "output_schema": { "type": "markdown" },
  "template": { "system": "...", "user": "..." },
  "validation": {},
  "retry": {},
  "logging": { "store_full_prompt": false, "redact_sensitive": true },
  "permissions": {},
  "metadata": {
    "prompt_concern": "...",
    "workflow_concern": "...",
    "domain_action_concern": "...",
    "ui_concern": "..."
  },
  "side_effects": []
}
```

## 5. Output types

`text` | `markdown` | `markdown_sections` | `html` | `json` | `structured_object` | `list` | `score` | `classification`

### `markdown_sections` (exceptional)

One provider response containing multiple explicitly marked Markdown sections (section / task markers). Markers are plain delimiters â€” never execute code (not WordPress shortcodes).

Currently used only by `article.outline.generate@0.1.0` (experimental). Do not migrate other prompts into this type unless they intentionally return multiple task sections from one AI call.

Contract fields per section: `key`, `label`, `task`, `content_type`, `start_marker`, `end_marker`, `required`, `output_port`, `validation`, `normalize`.

Template source may be `legacy_prompt_content`: Prompt DB markdown = template SoT; Hook JSON = I/O contract SoT.

## 6. Known output failure modes (audit)

| Failure | Policy |
|---|---|
| JSON trong markdown fence | `strip_markdown_fence` rá»“i validate JSON |
| Missing field | reject |
| Wrong type | reject |
| Truncated | retry chá»‰ náº¿u hook.retry cho phÃ©p + chÆ°a domain write |
| Empty | reject (`not_empty`) |
| Provider refusal | surface error |
| Invalid HTML | reject / sanitize á»Ÿ domain layer â€” khÃ´ng silent accept |
| Unexpected language | log; optional locale validator later |
| Previous-step markers leaked | reject when `reject_previous_step_markers` |

## 7. Relation to Phase 1 manifests

Phase 1 JSON (`schema_version: 1`) váº«n production. Spec v0.1 lÃ  **siÃªu táº­p / Ä‘á» xuáº¥t** â€” 5B cÃ³ thá»ƒ migrate loader hoáº·c dual-read.

## 8. Blockers trÆ°á»›c engine code

1. PromptResult attach trong HookExecution â€” domain/audit boundary.  
2. SeoTask graph vs Hook â€” Workflow engine scope.  
3. Shadow AI double-call budget.  
4. Structured output provider differences (Gemini/Claude).  
5. Large `previous_outputs` size limits.  
6. EXPERIMENTAL hooks: khÃ³a versioning + site override trÆ°á»›c promote â€œstableâ€.
