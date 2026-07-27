# Content Project Agent Planner

## Role

Planner **lập kế hoạch**, không thực thi business.

```
Objective → PlanGenerator → CanonicalPlanValidator → Persist Plan/Steps
                                                      ↓
User confirm → PlanApplicationService.start → PlanExecutor (1 step/job)
                                                      ↓
                                         AgentGateway.execute(capability)
```

Executor **chỉ** gọi `ContentProjectAgentGateway`. Không gọi CommandBus / Handler / SeoProjectRun / WordPress.

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
- `RuleBasedContentProjectPlanGenerator` — template registry, **không bịa keyword** (`constraints.item_seed`)
- `LlmContentProjectPlanGenerator` — stub (chưa cấu hình LLM)

Safety nằm ở `ContentProjectCanonicalPlanValidator` + policy, không nằm trong prompt.

Limits (config `planner.*`): max_steps=20, max_write=15, max_publish=1, max_archive=1.

## Templates

`generate_new_content_project`, `generate_only`, `review_existing`, `schedule_approved`, `publish_due_check` (readiness only), `restore_and_rebuild` (restore + generate tách step).

## Internal steps

- `wait_operation` — poll `content_project.get_operation` qua Gateway, interval ≥ `poll_min_seconds`
- `wait_condition` — whitelist `ContentProjectAgentConditionRegistry`

Không expose thành public write capability.

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
