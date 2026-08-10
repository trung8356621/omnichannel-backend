# Post-Refactor Manual Checklist (USER verification)

> Status: **USER verification** — not an automated gate  
> Last verified: 2026-08-10  
> Architecture: [ADDON_ARCHITECTURE.md](ADDON_ARCHITECTURE.md) · Handoff: [NEW_AGENT_HANDOFF.md](NEW_AGENT_HANDOFF.md)

## Closure note

**Architecture refactor is CLOSED.**  
Manual browser regression and real WordPress E2E **must not** trigger another architecture redesign wave.

These items are **USER** / external environment checks. Automated architecture gates may PASS while this list remains open.

| Debt | Status |
|------|--------|
| Manual browser regression | **PENDING** (this checklist) — local `.env` may point at empty `*_test` after `refactor:migrate-fresh`; for imported-data UI use `omi_channel` / `omi_seo_ai` (protected) or re-import into `*_test` |
| Real WP E2E | **PENDING** — requires a real WP site/runtime (not available in default agent environment) |

Do **not** invent false PASS claims for either item.

---

## USER checklist (browser)

Mark each item after live UI verification:

- [ ] Content title / slug / blocks — save and reload  
- [ ] Featured image — set / clear  
- [ ] Gallery  
- [ ] Product multi-select  
- [ ] SEO focus / scoring  
- [ ] WP sync / resync  
- [ ] Existing thumbnail  
- [ ] Slug sync  
- [ ] Publishing — schedule / publish / retry  
- [ ] Content Project — create / rewrite / archive / restore  
- [ ] Same-user session  
- [ ] Disconnect / reconnect  
- [ ] Mount / remount  

---

## External WP E2E

When a WP site + bridge tokens are available, run real sync/publish flows against that site. Until then, keep status **PENDING** and rely on WP contract harness / unit tests only for automated coverage.
