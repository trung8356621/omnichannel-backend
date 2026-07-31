> Status: Historical
> Not canonical
> Archived on: 2026-08-01
> Superseded by: ../../modules/AGENT_WORKSPACE.md
> Purpose: implementation history only
# Agent Evaluation

Offline planning evaluation (Phase 6) + builtin installer (Phase 7 gap close).

## Commands

```text
php artisan agent:evaluations:install-builtin
php artisan agent:evaluate --dataset=core-routing --dry-run
```

## Builtin datasets

Installed idempotently by `BuiltinAgentEvaluationDatasetInstaller`:

- core-routing (â‰¥15 cases)
- core-planning
- core-security
- core-execution-boundary
- core-knowledge-grounding
- core-automation-safety
- **core-capability-coverage** (P0 wiring fixtures)

Does not overwrite manager-cloned/custom datasets (non-builtin description/source).

Dry-run may score fixtures without provider invocation; must not return `dataset_not_found` after install.

Doctor: `php artisan agent:v1:doctor --fix-safe`
