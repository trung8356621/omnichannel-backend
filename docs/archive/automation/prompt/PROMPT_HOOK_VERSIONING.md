> Status: Historical
> Not canonical
> Archived on: 2026-08-01
> Superseded by: ../../modules/AUTOMATION.md
> Purpose: implementation history only
# Prompt Hook Versioning

- SemVer: `MAJOR.MINOR.PATCH`
- Experimental pin: `0.1.0` (no `latest`)
- Caller must pass version explicitly
- `PromptHookPromotionGate` + `PromptHookModeTransitionPolicy` â€” evaluate only; never flips mode/version

## Title / meta â†’ 1.0.0 checklist (still experimental)

- [ ] Input contract stable
- [ ] Output contract stable
- [ ] Locale verified
- [ ] Redaction verified
- [ ] Disable policy tested
- [ ] UI parity verified
- [ ] Provider adapter verified
- [ ] Sample threshold reached (30 default)
- [ ] No unresolved mismatch
- [ ] Cost within threshold
- [ ] Manual review recorded in `PROMPT_HOOK_PHASE5D1_ROLLOUT_REPORT.md`

**Do not** change version in code without hosting samples + human approval.
