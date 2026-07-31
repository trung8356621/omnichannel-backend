> Status: Historical
> Not canonical
> Archived on: 2026-08-01
> Superseded by: ../../modules/AUTOMATION.md
> Purpose: implementation history only
# Prompt Hook Registry

Resolve: **key + explicit SemVer** only.

```text
article.title_suggestion@0.1.0
```

| Status | Execute? |
|---|---|
| experimental | If `experimental_allowed` or allowlist |
| stable | Yes |
| deprecated | Yes + warning log |
| disabled | No (`HookDisabled`) |

No implicit latest. Experimental never auto-promotes to stable.

Registered (v01): title, meta, outline, faq, featured_snippet, **comment.generate**, **content.translate**, **product.gallery.generate**, content.generate, content.rewrite, keyword.discovery â€” experimental@0.1.0.

**Ownership (2026-07):** `settings_visible=true` â†’ Settings binding selector. `settings_visible=false` â†’ Task/editor only (no global Settings slot). Hook does **not** activate a Prompt; Settings/Task reference does.

| Hook | settings_visible |
|---|---|
| article.title_suggestion | true |
| article.meta_description_suggestion | true |
| article.faq.generate | true |
| article.featured_snippet.generate | true |
| article.outline.generate | true |
| article.content.translate | true |
| article.comment.generate | true |
| product.gallery.generate | true |
| keyword.discovery.structured | true |
| article.content.generate | false |
| article.content.rewrite | false |


## Vertical slice status â€” `article.outline.generate@0.1.0`

- Status: **experimental** (not stable)
- Output: `markdown_sections` â€” Task1 outline / Task2 vocabulary / Total from one provider call
- Template: `legacy_prompt_content` (Prompt DB markdown SoT; Hook JSON = contract)
- Parser: `MarkdownSectionsOutputParser` (definition-driven; no hook-key hardcode)
- Editor: selectable + section contract UI; markdown stays editable
- Explicit binding executable; global migration still **legacy**


| Flag | Value |
|---|---|
| defined | yes |
| registered | yes |
| generic caller wired | yes (Heading AI still legacy-default via migration flags) |
| editor selectable | yes (`PromptHookEditorCatalog`) |
| explicit-binding executable | yes (`PromptHookExplicitBindingExecutor`) |
| markdown_sections | yes |
| hosting tested | no |
| stable | no |

## Vertical slice status â€” article content generate/rewrite @0.1.0

| Hook | Label | Template | Output | editor | explicit bind | hosting | stable |
|---|---|---|---|---|---|---|---|
| `article.content.generate@0.1.0` | Viáº¿t bÃ i viáº¿t | `legacy_prompt_content` | markdown | yes | yes | no | no |
| `article.content.rewrite@0.1.0` | Viáº¿t láº¡i bÃ i viáº¿t | `legacy_prompt_content` | markdown | yes | yes | no | no |

Domain save: Workflow / `article.content.update` only â€” Hook Runtime never saves Article.

Editor dropdown reads **RuntimeRegistry** (not Phase1-only list).
