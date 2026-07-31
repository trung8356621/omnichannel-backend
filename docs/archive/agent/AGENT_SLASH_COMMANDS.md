> Status: Historical
> Not canonical
> Archived on: 2026-08-01
> Superseded by: ../../modules/AGENT_WORKSPACE.md
> Purpose: implementation history only
# Agent Slash Commands (Phase 1)

Slash commands lÃ  entry point chÃ­nh cho Agent Skills trong composer.

## UX

1. GÃµ `/` trong composer Agent Workspace â†’ má»Ÿ **slash palette client-side** (`window.AgentCommandCatalog` / `resources/js/agent/command-catalog.js`). Filter **khÃ´ng** gá»i Livewire/backend.
2. Chá»n command â†’ insert **template** + Tab/Shift+Tab nháº£y placeholder; arg `project`/`member` má»›i request suggest (cache `_argCache` trong page session).
3. Gá»­i CLI â†’ `AgentCliCommandCatalog` + `AgentCliCommandParser` map sang skill/inputs â†’ `selectSkill` / CommandBus path hiá»‡n cÃ³ â€” **khÃ´ng** LLM parse deterministic commands.
4. Skill `confirmation_policy=none` (read) â†’ **auto-execute** sau preview; chá»‰ policy `preview`/`confirm` má»›i há»i Yes/No.

Backend catalog SoT: `Services/AgentWorkspace/Cli/AgentCliCommandCatalog.php` (+ `toFrontendCatalog()`). FE mirror + optional `localStorage` key `agent.command-catalog.v1`.

Keyboard: Arrow Up/Down, Enter, Escape (Alpine). Popup Quick Assistant **khÃ´ng** host slash palette Agent.

### CLI UX catalog (curated slash palette â€” khÃ´ng dump CanonicalCapabilityRegistry)

Dropdown `/` = **user-facing CLI skills** (`AgentCliCommandCatalog` + FE mirror). Gate: `AgentCliCapabilityGate` (capability exists / agent-exposed / scope / context). KhÃ´ng hiá»‡n raw key kiá»ƒu `content_project.approve` trá»« khi cÃ³ slash intentionally designed.

| Group | CLI | Capability / type |
|-------|-----|-------------------|
| Core | `/help` `/new-chat` `/context` | local_ui (`agent.help` / `agent.new_chat` / meta) |
| Site | `/site-list` `/site-switch` `/site-info` | local_ui (stable `--site-id` / `--domain`) |
| Site | `/site-health` | `content_project.get_site_health` (`--refresh` â†’ `site.refresh_snapshot`) |
| Site | `/site-sync` `/site-sync-keywords` `/site-sync-links` `/site-refresh-snapshot` | `site.sync` / `site.sync_keywords` / `site.sync_links` / `site.refresh_snapshot` |
| Project | `/project-list` â€¦ `/project-archive` | `content_project.list_projects` / `get_status` / `create` / `update` / `generate` / `start_review` / `archive` |
| Member | `/member-list` `/member-available` | local_ui; arg á»•n Ä‘á»‹nh `--member-id` (hoáº·c email); khÃ´ng dÃ¹ng tÃªn hiá»ƒn thá»‹ |
| Keyword | `/keyword-suggest` `/keyword-add-to-project` | `keyword_intelligence.analyze_workspace` / `content_project.add_items` |
| Audit | `/audit-list` `/audit-keyword-suggest` `/audit-add-to-project` | `seo_audit.list` (site-level) + local stub keyword-suggest + `content_project.add_items` |
| Operation | `/daily-report` `/operation-status` | `content_project.get_daily_report` / `get_operation` |

SoT mapping: `AgentCliCommandCatalog.php`. Skill registry slash table bÃªn dÆ°á»›i = skill-layer aliases (váº«n tá»“n táº¡i); palette Agent Æ°u tiÃªn curated CLI á»Ÿ trÃªn.

## Aliases

- Alias Ä‘Äƒng kÃ½ trÃªn `AgentSkillDefinition.aliases`
- Resolve cÃ¹ng index vá»›i canonical slash (`AgentSkillRegistry::resolveSlashCommand`)
- Intent source: `SOURCE_SLASH` vs `SOURCE_ALIAS` (`AgentIntentRouter`)

VÃ­ dá»¥:

| Canonical | Aliases |
|-----------|---------|
| `/create-project` | `/new-project`, `/tao-project` |
| `/start-review` | `/review-project` |

## Conflict: `agent.skill_command_conflict`

Boot-time registry reject trÃ¹ng slash hoáº·c alias (case-insensitive, normalized):

- Pattern: `^/[a-z0-9]+(?:-[a-z0-9]+)*$`
- Duplicate skill key â†’ `agent.skill_conflict`
- Duplicate command/alias â†’ `agent.skill_command_conflict`
- Invalid format â†’ `agent.slash_command_conflict`

## Meta commands (khÃ´ng qua Gateway business)

| Slash | Skill key | Capability |
|-------|-----------|------------|
| `/help` | `general.help` | `agent.help` |
| `/new-chat` | `general.new_chat` | `agent.new_chat` |

## Shipped command catalog

| Slash | Name | Capability | Confirmation | Notes |
|-------|------|------------|--------------|-------|
| `/help` | Trá»£ giÃºp | `agent.help` | none | Meta; list skills by category |
| `/new-chat` | Chat má»›i | `agent.new_chat` | none | Meta; táº¡o conversation |
| `/site-health` | Kiá»ƒm tra sá»©c khá»e site | `content_project.get_site_health` | none | Scope: read |
| `/daily-report` | BÃ¡o cÃ¡o hÃ´m nay | `content_project.get_daily_report` | none | Scope: read; featured |
| `/operation-status` | Kiá»ƒm tra operation | `content_project.get_operation` | none | Form: `operation_ref` |
| `/list-projects` | Danh sÃ¡ch Content Project | `content_project.list_projects` | none | |
| `/create-project` | Táº¡o Content Project | `content_project.create` | preview | Aliases: `/new-project`, `/tao-project`; featured |
| `/project-status` | Tráº¡ng thÃ¡i project | `content_project.get_status` | none | Requires `project_ref`; featured |
| `/add-project-items` | ThÃªm bÃ i vÃ o project | `content_project.add_items` | preview | Requires `project_ref`; featured |
| `/update-project-item` | Cáº­p nháº­t bÃ i trong project | `content_project.update_item` | none | Form: `item_ref` |
| `/generate-articles` | Cháº¡y táº¡o bÃ i | `content_project.generate` | preview | Scope: generate; requires `project_ref`; featured |
| `/rerun-content` | Cháº¡y láº¡i má»™t bÆ°á»›c | `content_project.rerun` | preview | Steps: image/outline/article; featured |
| `/start-review` | Báº¯t Ä‘áº§u duyá»‡t | `content_project.start_review` | preview | Alias: `/review-project` |
| `/approve-items` | Duyá»‡t bÃ i | `content_project.approve` | preview | Scope: review |
| `/schedule-content` | LÃªn lá»‹ch Ä‘Äƒng | `content_project.schedule` | preview | Featured |
| `/auto-schedule` | Tá»± Ä‘á»™ng lÃªn lá»‹ch | `content_project.auto_schedule` | preview | |
| `/unschedule-content` | Há»§y lá»‹ch Ä‘Äƒng | `content_project.unschedule` | confirm | |
| `/publish-now` | ÄÆ°a vÃ o hÃ ng Ä‘á»£i Ä‘Äƒng | `content_project.publish_now` | confirm | Featured; publishing queue only |
| `/retry-publish` | Thá»­ Ä‘Äƒng láº¡i | `content_project.retry_publish` | confirm | |
| `/archive-project` | LÆ°u trá»¯ project | `content_project.archive` | confirm | Destroy workspace preview |
| `/restore-project` | KhÃ´i phá»¥c project | `content_project.restore` | confirm | |
| `/publishing-queue` | HÃ ng Ä‘á»£i Ä‘Äƒng | `content_project.get_publishing_queue` | none | Featured |
| `/list-keyword-workspaces` | Danh sÃ¡ch Keyword Workspace | `keyword_intelligence.list_workspaces` | none | |
| `/import-keywords` | Nháº­p tá»« khÃ³a | `keyword_intelligence.import_keywords` | preview | Requires `workspace_ref`; featured |
| `/analyze-keywords` | PhÃ¢n tÃ­ch tá»« khÃ³a | `keyword_intelligence.analyze_workspace` | preview | Strategy strict/balanced/broad; featured |
| `/list-keyword-clusters` | Danh sÃ¡ch cluster | `keyword_intelligence.list_clusters` | none | Requires `workspace_ref` |
| `/merge-clusters` | Gá»™p cluster | `keyword_intelligence.merge_clusters` | confirm | |
| `/split-cluster` | TÃ¡ch cluster | `keyword_intelligence.split_cluster` | preview | |
| `/build-topical-map` | XÃ¢y Topical Map | `keyword_intelligence.build_topical_map` | preview | Draft only; featured |
| `/approve-topical-map` | Duyá»‡t Topical Map | `keyword_intelligence.approve_topical_map` | confirm | |
| `/preview-project` | Xem trÆ°á»›c project tá»« káº¿ hoáº¡ch | `keyword_intelligence.preview_content_project` | preview | Featured |
| `/create-project-from-map` | Táº¡o project tá»« Topical Map | `keyword_intelligence.create_content_project` | confirm | Requires `workspace_ref` |
| `/create-serp-queries` | Táº¡o SERP queries | `serp_intelligence.create_queries` | preview | |
| `/import-serp` | Import SERP thá»§ cÃ´ng | `serp_intelligence.import_snapshot` | preview | Featured; no provider required |
| `/collect-serp` | Thu tháº­p SERP | `serp_intelligence.collect` | preview | Provider `serp` required; featured |
| `/validate-cluster-serp` | Validate cluster SERP | `serp_intelligence.validate_cluster` | preview | |
| `/list-content-gaps` | Content gaps | `serp_intelligence.list_content_gaps` | none | Read |

Source of truth: `Services/AgentWorkspace/Skills/*.php`

## Related

- [AGENT_SKILLS.md](AGENT_SKILLS.md)
- [AGENT_WORKSPACE.md](AGENT_WORKSPACE.md)
- [CONTENT_PROJECT_AGENT_APPROVALS.md](CONTENT_PROJECT_AGENT_APPROVALS.md)
