> Status: Historical
> Not canonical
> Archived on: 2026-08-01
> Superseded by: ../../modules/AGENT_WORKSPACE.md
> Purpose: implementation history only
# Agent Knowledge Security

Knowledge input is untrusted.

- HTML/script strip
- Secret pattern reject (API key, password, private key, bearer)
- Size/chunk limits
- Upload type allowlist (txt/md/json text payload)
- No remote URL ingestion
- No arbitrary SQL
- Soft delete / disable audited
- Soft-fail grounding

See also `AGENT_PROMPT_SECURITY.md`.
