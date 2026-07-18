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

Registered (v01): title, meta, outline, **content.generate**, **content.rewrite**, faq, keyword.discovery — all **experimental@0.1.0**.

## Vertical slice status — `article.outline.generate@0.1.0`

- Status: **experimental** (not stable)
- Output: `markdown_sections` — Task1 outline / Task2 vocabulary / Total from one provider call
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

## Vertical slice status — article content generate/rewrite @0.1.0

| Hook | Label | Template | Output | editor | explicit bind | hosting | stable |
|---|---|---|---|---|---|---|---|
| `article.content.generate@0.1.0` | Viết bài viết | `legacy_prompt_content` | markdown | yes | yes | no | no |
| `article.content.rewrite@0.1.0` | Viết lại bài viết | `legacy_prompt_content` | markdown | yes | yes | no | no |

Domain save: Workflow / `article.content.update` only — Hook Runtime never saves Article.

Editor dropdown reads **RuntimeRegistry** (not Phase1-only list).
