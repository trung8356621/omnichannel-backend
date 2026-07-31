> Status: Historical
> Not canonical
> Archived on: 2026-08-01
> Superseded by: ../../modules/CONTENT_PROJECTS.md
> Purpose: implementation history only
# Content Project Agent Planner

## Role

Planner **láº­p káº¿ hoáº¡ch**, khÃ´ng thá»±c thi business.

```
Objective â†’ PlanGenerator â†’ CanonicalPlanValidator â†’ Persist Plan/Steps
                                                      â†“
User confirm â†’ PlanApplicationService.start â†’ PlanExecutor (1 step/job)
                                                      â†“
                                         AgentGateway.execute(capability)
```

Executor **chá»‰** gá»i `ContentProjectAgentGateway`. KhÃ´ng gá»i CommandBus / Handler / SeoProjectRun / WordPress.

## Schema

| Table | Ref prefix |
|-------|------------|
| `seo_content_project_agent_plans` | `apl_` |
| `seo_content_project_agent_plan_steps` | `aps_` |
| `seo_content_project_automation_policies` | `apy_` |
| `seo_content_project_agent_approvals` | `apv_` |

Migration: `2026_07_27_150000_create_content_project_agent_planner_tables.php`

## Generation

- Interface: `ContentProjectPlanGenerator`
- `RuleBasedContentProjectPlanGenerator` â€” template registry, **khÃ´ng bá»‹a keyword** (`constraints.item_seed`)
- `LlmContentProjectPlanGenerator` â€” stub (chÆ°a cáº¥u hÃ¬nh LLM)

Safety náº±m á»Ÿ `ContentProjectCanonicalPlanValidator` + policy, khÃ´ng náº±m trong prompt.

Limits (config `planner.*`): max_steps=20, max_write=15, max_publish=1, max_archive=1.

## Templates

`generate_new_content_project`, `generate_only`, `review_existing`, `schedule_approved`, `publish_due_check` (readiness only), `restore_and_rebuild` (restore + generate tÃ¡ch step).

## Internal steps

- `wait_operation` â€” poll `content_project.get_operation` qua Gateway, interval â‰¥ `poll_min_seconds`
- `wait_condition` â€” whitelist `ContentProjectAgentConditionRegistry`

KhÃ´ng expose thÃ nh public write capability.

## Idempotency

Step key: `plan:{plan_ref}:step:{step_ref}`

## Key classes

| Class | Role |
|-------|------|
| `ContentProjectAgentPlanner` | Create/persist draft |
| `ContentProjectAgentPlanApplicationService` | confirm/start/pause/resume/cancel/retry |
| `ContentProjectAgentPlanExecutor` | One step per invoke |
| `ContentProjectAgentPlanGateway` | MCP plan tools boundary |
| `AgentPlanDraftValidator` | Lightweight draft checks |

## Related

- [CONTENT_PROJECT_AUTOMATION_POLICY.md](CONTENT_PROJECT_AUTOMATION_POLICY.md)
- [CONTENT_PROJECT_AGENT_APPROVALS.md](CONTENT_PROJECT_AGENT_APPROVALS.md)
- [CONTENT_PROJECT_AGENT_PLAN_LIFECYCLE.md](CONTENT_PROJECT_AGENT_PLAN_LIFECYCLE.md)
- [CONTENT_PROJECT_AGENT_GATEWAY.md](CONTENT_PROJECT_AGENT_GATEWAY.md)
