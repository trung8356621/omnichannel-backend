# Agent Workspace v1 — Gap Report

## Fixed in this sweep (P0)

| Feature | Gap before | Fix |
|---------|------------|-----|
| stop_execution | Command existed, no capability/skill | Registry + factory + skill `/stop-execution` |
| resume_execution | Same | Registry + factory + skill `/resume-execution` |
| move_schedule | Capability+factory, no skill | Skill `/move-schedule` |
| skip_publish / cancel_publish | Capability+factory, no skill | Skills |
| update project | Capability+factory, no skill | Skill `/update-project` |
| list_items / timeline | Read gateway, no skill | Skills |
| Routing phrases | Thin | Expanded VI/EN deterministic rules |
| Builtin datasets | core-routing thin; no coverage set | ≥15 routing cases + `core-capability-coverage` |
| Doctor / audit | Missing | `agent:v1:doctor`, `agent:capabilities:audit` |

## Remaining business gaps (do not fake)

| Area | Status | Notes |
|------|--------|-------|
| GSC Agent skills | business_partial / missing skills | Capabilities exist; Agent skills not fully curated (P1/P2) |
| SEO Audit Agent skills | business_missing / partial | Need existing public contracts before wiring |
| Article helpers (title/meta/FAQ/…) | business_missing | No invent |
| Media/image Agent surface | partial | Only when safe public contracts |
| Prompt-hook config reads | partial | Manager diagnostics only |
| Extension health Agent skill | partial | Extension SDK health exists; thin Agent skill optional |

## Critical rule

Write capability is either **fully Phase-2 wired** or marked **missing** — never half-wired.
