> Status: Historical
> Not canonical
> Archived on: 2026-08-01
> Superseded by: ../../modules/AGENT_WORKSPACE.md
> Purpose: implementation history only
# Agent Pack Manifest

Schema version `1`. Key `vendor.pack-name`. Semver required.

Fields: schema_version, key, name, version, description, provider, sdk_constraint, agent_workspace_constraint, type, skills, templates, translations, evaluation_datasets, permissions, dependencies, conflicts, metadata.

Validator: `AgentPackManifestValidator`. Reject executable fields, traversal, unsupported schema, SDK/workspace mismatch, core namespace takeover (non-builtin).
