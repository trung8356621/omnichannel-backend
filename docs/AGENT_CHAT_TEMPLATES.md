# Agent Chat Templates (Phase 1)

Shortcut cards trong Agent Workspace — map nhanh sang skill hoặc prefill composer.

## AgentChatTemplate fields

| Field | Type | Mô tả |
|-------|------|-------|
| `key` | string | Stable id, vd. `create_project_month` |
| `title` | string | Card title |
| `description` | string | Subtitle |
| `prompt_template` | string | NL template; placeholders `{{var}}` |
| `skill_key` | string\|null | Nếu set → **direct skill open**, không qua AI |
| `variables` | list\<TemplateVariable\> | `{key, label, type, required?, default?}` |
| `category` | string | Grouping trong UI |
| `icon` | string | Heroicon |
| `sort_order` | int | Display order |
| `is_featured` | bool | Show in featured row |

DTO: `Dtos/AgentChatTemplate.php`

Methods:

- `render($values)` — substitute `{{key}}`
- `missingVariables($values)` — required check
- `hasUnresolvedPlaceholders($rendered)` — guard incomplete render

## Registry

`AgentChatTemplateRegistry` — boot từ `Templates/BuiltinChatTemplateCatalog.php`

Empty state Agent Workspace page render **featured templates** (7 cards builtin). Click card → `openTemplate()` → skill form nếu có `skill_key`, không gọi AI router, không auto execute.

UI: `AgentWorkspacePage::openTemplate()` — nếu `skill_key` set → `openSkill()`; else prefill `composerText`.

## Skill mapping without AI

Khi `skill_key !== null`:

1. Template click **không** gọi AI intent router
2. Mở skill form trực tiếp với prefill từ context (`AgentSkillInputResolver`)
3. User preview/confirm theo skill `confirmation_policy`

Intent source = `SOURCE_TEMPLATE` (`AgentIntentRouter`).

## Builtin templates (shipped)

| Key | Title | skill_key | Category |
|-----|-------|-----------|----------|
| `create_project_month` | Tạo project mới | `content_project.create` | content_project |
| `create_project_from_map` | Tạo project từ Topical Map | `keyword.preview_project` | keyword_intelligence |
| `analyze_keywords_pending` | Phân tích từ khóa | `keyword.analyze` | keyword_intelligence |
| `check_project_status` | Kiểm tra project | `content_project.status` | content_project |
| `rerun_images` | Chạy lại ảnh | `content_project.rerun` | content_project |
| `schedule_approved` | Lên lịch đăng | `content_project.schedule` | content_project |
| `daily_report` | Báo cáo hôm nay | `operations.daily_report` | operations |

Template có variables: `create_project_month` — `{{month}}`, `{{site_name}}`.

## Related

- [AGENT_WORKSPACE.md](AGENT_WORKSPACE.md)
- [AGENT_SLASH_COMMANDS.md](AGENT_SLASH_COMMANDS.md)
