# Agent Pack Import / Export

`AgentPackImportExportService`

Export: declarative-only (manifest/skills/templates/translations/evaluations/docs/checksums). Strip secrets/history/user data.

Import JSON/ZIP:

- Max 1 MiB / 64 entries
- Reject traversal, symlink, nested archive, PHP/exec extensions
- No extract to public path; no PHP load
- Imported pack `trust=imported_unverified`, **disabled** until validate + gate + explicit enable
