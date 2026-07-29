# Agent Slash Commands (Phase 1)

Slash commands là entry point chính cho Agent Skills trong composer.

## UX

1. Gõ `/` trong composer Agent Workspace → mở **slash palette** (`showPalette`, filter `paletteQuery`)
2. Chọn skill → mở **skill form** (`activeSkillKey`, `skillFormSchema`, prefill từ context) — **không execute ngay**
3. **Preview** (nếu `confirmation_policy` = `preview` hoặc capability dry_run) → message type `preview`
4. **Confirm & Execute** → `AgentWorkspaceApplicationService::execute()` + optional `confirmation_token`

Palette search: slash, alias, name, description, category, skill key (`AgentSkillRegistry::search`). Keyboard: Arrow Up/Down, Enter, Escape (Alpine trên composer).

Popup Quick Assistant **không** host slash palette Agent — chỉ launcher sang page.

## Aliases

- Alias đăng ký trên `AgentSkillDefinition.aliases`
- Resolve cùng index với canonical slash (`AgentSkillRegistry::resolveSlashCommand`)
- Intent source: `SOURCE_SLASH` vs `SOURCE_ALIAS` (`AgentIntentRouter`)

Ví dụ:

| Canonical | Aliases |
|-----------|---------|
| `/create-project` | `/new-project`, `/tao-project` |
| `/start-review` | `/review-project` |

## Conflict: `agent.skill_command_conflict`

Boot-time registry reject trùng slash hoặc alias (case-insensitive, normalized):

- Pattern: `^/[a-z0-9]+(?:-[a-z0-9]+)*$`
- Duplicate skill key → `agent.skill_conflict`
- Duplicate command/alias → `agent.skill_command_conflict`
- Invalid format → `agent.slash_command_conflict`

## Meta commands (không qua Gateway business)

| Slash | Skill key | Capability |
|-------|-----------|------------|
| `/help` | `general.help` | `agent.help` |
| `/new-chat` | `general.new_chat` | `agent.new_chat` |

## Shipped command catalog

| Slash | Name | Capability | Confirmation | Notes |
|-------|------|------------|--------------|-------|
| `/help` | Trợ giúp | `agent.help` | none | Meta; list skills by category |
| `/new-chat` | Chat mới | `agent.new_chat` | none | Meta; tạo conversation |
| `/site-health` | Kiểm tra sức khỏe site | `content_project.get_site_health` | none | Scope: read |
| `/daily-report` | Báo cáo hôm nay | `content_project.get_daily_report` | none | Scope: read; featured |
| `/operation-status` | Kiểm tra operation | `content_project.get_operation` | none | Form: `operation_ref` |
| `/list-projects` | Danh sách Content Project | `content_project.list_projects` | none | |
| `/create-project` | Tạo Content Project | `content_project.create` | preview | Aliases: `/new-project`, `/tao-project`; featured |
| `/project-status` | Trạng thái project | `content_project.get_status` | none | Requires `project_ref`; featured |
| `/add-project-items` | Thêm bài vào project | `content_project.add_items` | preview | Requires `project_ref`; featured |
| `/update-project-item` | Cập nhật bài trong project | `content_project.update_item` | none | Form: `item_ref` |
| `/generate-articles` | Chạy tạo bài | `content_project.generate` | preview | Scope: generate; requires `project_ref`; featured |
| `/rerun-content` | Chạy lại một bước | `content_project.rerun` | preview | Steps: image/outline/article; featured |
| `/start-review` | Bắt đầu duyệt | `content_project.start_review` | preview | Alias: `/review-project` |
| `/approve-items` | Duyệt bài | `content_project.approve` | preview | Scope: review |
| `/schedule-content` | Lên lịch đăng | `content_project.schedule` | preview | Featured |
| `/auto-schedule` | Tự động lên lịch | `content_project.auto_schedule` | preview | |
| `/unschedule-content` | Hủy lịch đăng | `content_project.unschedule` | confirm | |
| `/publish-now` | Đưa vào hàng đợi đăng | `content_project.publish_now` | confirm | Featured; publishing queue only |
| `/retry-publish` | Thử đăng lại | `content_project.retry_publish` | confirm | |
| `/archive-project` | Lưu trữ project | `content_project.archive` | confirm | Destroy workspace preview |
| `/restore-project` | Khôi phục project | `content_project.restore` | confirm | |
| `/publishing-queue` | Hàng đợi đăng | `content_project.get_publishing_queue` | none | Featured |
| `/list-keyword-workspaces` | Danh sách Keyword Workspace | `keyword_intelligence.list_workspaces` | none | |
| `/import-keywords` | Nhập từ khóa | `keyword_intelligence.import_keywords` | preview | Requires `workspace_ref`; featured |
| `/analyze-keywords` | Phân tích từ khóa | `keyword_intelligence.analyze_workspace` | preview | Strategy strict/balanced/broad; featured |
| `/list-keyword-clusters` | Danh sách cluster | `keyword_intelligence.list_clusters` | none | Requires `workspace_ref` |
| `/merge-clusters` | Gộp cluster | `keyword_intelligence.merge_clusters` | confirm | |
| `/split-cluster` | Tách cluster | `keyword_intelligence.split_cluster` | preview | |
| `/build-topical-map` | Xây Topical Map | `keyword_intelligence.build_topical_map` | preview | Draft only; featured |
| `/approve-topical-map` | Duyệt Topical Map | `keyword_intelligence.approve_topical_map` | confirm | |
| `/preview-project` | Xem trước project từ kế hoạch | `keyword_intelligence.preview_content_project` | preview | Featured |
| `/create-project-from-map` | Tạo project từ Topical Map | `keyword_intelligence.create_content_project` | confirm | Requires `workspace_ref` |
| `/create-serp-queries` | Tạo SERP queries | `serp_intelligence.create_queries` | preview | |
| `/import-serp` | Import SERP thủ công | `serp_intelligence.import_snapshot` | preview | Featured; no provider required |
| `/collect-serp` | Thu thập SERP | `serp_intelligence.collect` | preview | Provider `serp` required; featured |
| `/validate-cluster-serp` | Validate cluster SERP | `serp_intelligence.validate_cluster` | preview | |
| `/list-content-gaps` | Content gaps | `serp_intelligence.list_content_gaps` | none | Read |

Source of truth: `Services/AgentWorkspace/Skills/*.php`

## Related

- [AGENT_SKILLS.md](AGENT_SKILLS.md)
- [AGENT_WORKSPACE.md](AGENT_WORKSPACE.md)
- [CONTENT_PROJECT_AGENT_APPROVALS.md](CONTENT_PROJECT_AGENT_APPROVALS.md)
