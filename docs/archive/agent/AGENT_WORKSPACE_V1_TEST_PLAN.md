> Status: Historical
> Not canonical
> Archived on: 2026-08-01
> Superseded by: ../modules/AGENT_WORKSPACE.md
> Purpose: implementation history only
<!--
Status: Historical
Not canonical
Superseded by: docs/AGENT_WORKSPACE.md + docs/AGENT_WORKSPACE_V1_FREEZE.md
-->
# Agent Workspace v1 â€” Manual Test Plan (tomorrow)

Non-destructive first. Manager only for Operations/Packs/Doctor.

## Order

1. `php artisan migrate` + `agent:evaluations:install-builtin`
2. `php artisan agent:capabilities:audit --sync`
3. `php artisan agent:v1:doctor --fix-safe --sync`
4. `php artisan agent:evaluate --dataset=core-routing --dry-run`
5. `php artisan agent:evaluate --dataset=core-capability-coverage --dry-run`
6. Open `/seo/{hash}/agent` â€” empty state + templates
7. Slash `/help`, `/list-projects`, `/site-health` (reads)
8. `/create-project` â€” preview only, cancel (no confirm execute if not ready)
9. With project context: `/project-status`, `/list-project-items`
10. Write preview: `/add-project-items` â†’ Cancel
11. Confirmation path: `/archive-project` â†’ Cancel (must require confirm)
12. Knowledge tab list/search
13. Automations list (no create save unless intentional)
14. Operations â†’ Run v1 readiness check
15. Packs list (manager)
16. Cross-site: try foreign `project_ref` in form â€” must fail closed
17. NL: â€œKiá»ƒm tra sá»©c khá»e siteâ€, â€œBáº¯t Ä‘áº§u duyá»‡tâ€, â€œDá»«ng quÃ¡ trÃ¬nh Ä‘ang cháº¡yâ€
18. Trace after any preview (if ops emits)

## Checklist statuses

not tested | passed | failed | skipped

Do not automate destructive publish/archive in smoke.
