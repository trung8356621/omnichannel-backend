> Status: Historical
> Not canonical
> Archived on: 2026-08-01
> Superseded by: ../../modules/CONTENT_PROJECTS.md
> Purpose: implementation history only
# Content Project Agent Workflows

## Agent Workspace UI (Phase 1)

Filament **Agent Workspace** (`/seo/{connection_hash}/agent`) expose cÃ¹ng capabilities qua slash skills + form preview/confirm â€” khÃ´ng duplicate CommandBus handlers.

- Overview: [AGENT_WORKSPACE.md](AGENT_WORKSPACE.md)
- Slash catalog: [AGENT_SLASH_COMMANDS.md](AGENT_SLASH_COMMANDS.md)
- Security/scopes: [AGENT_WORKSPACE_SECURITY.md](AGENT_WORKSPACE_SECURITY.md)

Flow UI: `AgentWorkspaceApplicationService` â†’ `AgentGateway` â†’ `ContentProjectAgentGateway` â†’ `CanonicalCapabilityRegistry` â†’ `ContentProjectCommandBus`.

## E2E Create â†’ Generate

```
content_project.create
  â†’ content_project.add_items
  â†’ content_project.generate
  â†’ content_project.get_operation   (poll â‰¥ poll_min_seconds)
  â†’ content_project.get_status
  â†’ content_project.start_review
  â†’ content_project.approve
  â†’ content_project.auto_schedule (dry_run preview)
  â†’ content_project.auto_schedule (confirm)
```

Táº¥t cáº£ dÃ¹ng public refs + tenant/site context + idempotency_key.

### Create input (tá»‘i thiá»ƒu)

`site_ref` (tá»« context), `name`, `project_type`, `pipeline`, `language`, `timezone`, publishing strategy.

### Add items

`keyword`, `title?`, `description?`, `content_type` âˆˆ {`write_new`,`rewrite`,`improve`}, `article_ref?`, `scheduled_publish_at?`.

`improve` = sá»­a theo yÃªu cáº§u, khÃ´ng rerun full prompt stack. Item description â‰  Product `gallery_description`.

## Generate / Review (business only)

Agent tháº¥y **Generate Article** / **Review Article** operations â€” khÃ´ng tháº¥y tá»«ng prompt step (outline/image/auditâ€¦) trá»« khi capability prompt-level Ä‘Æ°á»£c báº­t riÃªng.

## Schedule

Modes hiá»‡n cÃ³: `explicit`, `interval`, `per_day`, `random_windows`.

Preview trÆ°á»›c bulk: sample `item_ref` + `planned_time` + timezone; stats `first_publish_at`, `last_publish_at`, `items_per_day`, `conflicts`.

Publishing = SaaS queue. **KhÃ´ng** táº¡o WP future post.

## Publish Now

1. dry_run / thiáº¿u token â†’ preview
2. User confirm
3. execute + `confirmation_token`
4. poll `get_operation` / `get_publishing_queue`

## Archive (Destroy Workspace)

Dry-run báº¯t buá»™c. Preview pháº£i nÃªu rÃµ destroy:

- AI Workspace, Prompt History, Execution, local media, SaaS revisions

Result: `workspace_destroyed=true`, counts dá»n (khÃ´ng list tá»«ng record).

Session clear workspace context; giá»¯ project_ref + archive result tá»‘i giáº£n.

## Restore

Confirmation. Chá»‰ clear archived metadata / má»Ÿ business project.

`workspace_reused=false`, `requires_new_generation_context=true`.

KhÃ´ng phá»¥c há»“i AI workspace / prompt / execution / media / publish process cÅ©.

## Plan (preview only)

`ContentProjectAgentPlan` â€” data only, max steps tá»« config. Má»—i step váº«n qua Gateway; publish/archive confirmation riÃªng. KhÃ´ng auto-execute toÃ n plan. KhÃ´ng thÃªm capability ngoÃ i registry.

## NL adapter

`ContentProjectNaturalLanguageAdapter` â†’ capability + structured input + missing_fields. KhÃ´ng Ä‘oÃ¡n site / ngÃ y Ä‘Äƒng / sá»‘ bÃ i / archive target.

## Example MCP sequence

```json
{"name":"content_project.create","arguments":{"name":"Cafe Q3","attributes":{"name":"Cafe Q3"}}}
{"name":"content_project.add_items","arguments":{"project_ref":"cpj_...","items":[{"keyword":"cafe da lat","content_type":"write_new"}]}}
{"name":"content_project.generate","arguments":{"project_ref":"cpj_...","item_refs":["cpi_..."],"idempotency_key":"gen-1"}}
{"name":"content_project.get_operation","arguments":{"operation_ref":"..."}}
```

## Phase 4 â€” SERP capabilities (additive)

Future agent flow may include read-only SERP steps before convert:

```
serp_intelligence.import_snapshot (preview)
serp_intelligence.validate_cluster
serp_intelligence.apply_intent_suggestion  (blocked when manual intent locked)
```

Manual keyword intent (`field_sources.intent=manual`) wins â€” reconciler never auto-overwrites. See [SERP_INTENT_EVIDENCE.md](SERP_INTENT_EVIDENCE.md).

## Phase 5 â€” GSC Intelligence (additive)

Future agent flow may include GSC sync/import and opportunity review:

```
gsc_intelligence.import_performance_data (preview)
gsc_intelligence.detect_opportunities
gsc_intelligence.preview_create_content_project
gsc_intelligence.create_content_project_from_opportunities
```

Handlers under `Services/GscIntelligence/Application/Handlers/` must not import `Google\Client`. See [GSC_INTELLIGENCE.md](GSC_INTELLIGENCE.md).
