> Status: Historical
> Not canonical
> Archived on: 2026-08-01
> Superseded by: ../../modules/AGENT_WORKSPACE.md
> Purpose: implementation history only
# Agent Chat Templates (Phase 1)

Shortcut cards trong Agent Workspace â€” map nhanh sang skill hoáº·c prefill composer.

## AgentChatTemplate fields

| Field | Type | MÃ´ táº£ |
|-------|------|-------|
| `key` | string | Stable id, vd. `create_project_month` |
| `title` | string | Card title |
| `description` | string | Subtitle |
| `prompt_template` | string | NL template; placeholders `{{var}}` |
| `skill_key` | string\|null | Náº¿u set â†’ **direct skill open**, khÃ´ng qua AI |
| `variables` | list\<TemplateVariable\> | `{key, label, type, required?, default?}` |
| `category` | string | Grouping trong UI |
| `icon` | string | Heroicon |
| `sort_order` | int | Display order |
| `is_featured` | bool | Show in featured row |

DTO: `Dtos/AgentChatTemplate.php`

Methods:

- `render($values)` â€” substitute `{{key}}`
- `missingVariables($values)` â€” required check
- `hasUnresolvedPlaceholders($rendered)` â€” guard incomplete render

## Registry

`AgentChatTemplateRegistry` â€” boot tá»« `Templates/BuiltinChatTemplateCatalog.php`

Empty state Agent Workspace page render **featured templates** (7 cards builtin). Click card â†’ `openTemplate()` â†’ skill form náº¿u cÃ³ `skill_key`, khÃ´ng gá»i AI router, khÃ´ng auto execute.

UI: `AgentWorkspacePage::openTemplate()` â€” náº¿u `skill_key` set â†’ `openSkill()`; else prefill `composerText`.

## Skill mapping without AI

Khi `skill_key !== null`:

1. Template click **khÃ´ng** gá»i AI intent router
2. Má»Ÿ skill form trá»±c tiáº¿p vá»›i prefill tá»« context (`AgentSkillInputResolver`)
3. User preview/confirm theo skill `confirmation_policy`

Intent source = `SOURCE_TEMPLATE` (`AgentIntentRouter`).

## Builtin templates (shipped)

| Key | Title | skill_key | Category |
|-----|-------|-----------|----------|
| `create_project_month` | Táº¡o project má»›i | `content_project.create` | content_project |
| `create_project_from_map` | Táº¡o project tá»« Topical Map | `keyword.preview_project` | keyword_intelligence |
| `analyze_keywords_pending` | PhÃ¢n tÃ­ch tá»« khÃ³a | `keyword.analyze` | keyword_intelligence |
| `check_project_status` | Kiá»ƒm tra project | `content_project.status` | content_project |
| `rerun_images` | Cháº¡y láº¡i áº£nh | `content_project.rerun` | content_project |
| `schedule_approved` | LÃªn lá»‹ch Ä‘Äƒng | `content_project.schedule` | content_project |
| `daily_report` | BÃ¡o cÃ¡o hÃ´m nay | `operations.daily_report` | operations |

Template cÃ³ variables: `create_project_month` â€” `{{month}}`, `{{site_name}}`.

## Related

- [AGENT_WORKSPACE.md](AGENT_WORKSPACE.md)
- [AGENT_SLASH_COMMANDS.md](AGENT_SLASH_COMMANDS.md)
