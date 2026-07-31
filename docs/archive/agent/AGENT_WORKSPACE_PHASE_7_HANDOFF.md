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
# Agent Workspace Phase 7 â€” Extensible Skill Packs & Declarative Skill Studio

## 1. Inspect findings

- Extension SDK: `ExtensionDiscovery` + `ExtensionManifest` + `SdkVersion::MAJOR` â€” reuse discovery style; do **not** create second plugin framework.
- Skills: `AgentSkillRegistry` + `BuiltinSkillCatalog` (presentation only); conflicts fail closed; Phase 7 merges enabled pack skills with isolate-on-error / no last-wins.
- Templates: `AgentChatTemplateRegistry` + builtin catalog; pack templates map to `title` / `prompt_template` / `skill_key`.
- Capabilities: `CanonicalCapabilityRegistry` is authority (`confirmation_requirement`, `risk_level`, `input_schema`). Pack manifest capability metadata not trusted.
- Confirmation: rank `none < preview < confirm < destructive`; binder rejects downgrade vs canonical.
- Planning catalog: pack compile emits `planning_catalog` metadata; runtime still via `AgentSkillCatalogPresenter` over registry skills.
- Automation eligibility: pack `automation_metadata` compiled; cannot elevate safety.
- Phase 6: `AgentQualityGateService`, `AgentGovernancePolicyService`, `AgentPolicyViolationDetector`, `AgentEvaluationRunner` (`dataset_not_found` when key missing).
- Import patterns: Automation import/export style â€” ZIP fail-closed (traversal/symlink/exec/nested).
- Semver / hash IDs: pack `vendor.pack-name` + semver; hash ids `apack_` / `aprev_`.
- Cache: pack-scoped keys `agent_pack:{build}:{scope}:{key}:{revision}` â€” no global flush.
- UI tabs: Chat / Knowledge / Automations / Operations / **Packs** (manager) / Diagnostics.
- i18n: `seo-content-ai::filament.agent_workspace.*`.
- Permissions: manager via `SeoAccessControl::canAccessManagerFeatures` + `AgentGovernancePolicyService::canAccessDiagnostics`.
- Freeze: `ExtensionArchitectureFreezeTest` untouched; pack code must not call `ContentProjectCommandBus`.

## 2. Architecture

```
Pack (builtin|extension|custom|imported)
  â†’ AgentPackDiscovery / Loader / Registry
  â†’ validate â†’ compile â†’ gate â†’ explicit enable
  â†’ AgentSkillRegistry (+ templates)
  â†’ Agent Workspace / Planning catalog
  â†’ AgentExecutionOrchestrator â†’ AgentGateway â†’ Canonical capability

Pack management slash skills:
  â†’ ApplicationService â†’ AgentPackOrchestrator (local agent.pack.*)
```

No Pack â†’ CommandBus / handlers / Eloquent business / SQL / shell / eval / PHP upload.

## 3. Pack types

| Type | Source | Trust | Uninstall |
|------|--------|-------|-----------|
| builtin | code | builtin | no |
| extension | Extension SDK | trusted_extension | via extension lifecycle |
| custom | Skill Studio DB | admin_created | soft delete |
| imported | JSON/ZIP | imported_unverified | soft delete; disabled until enable |

## 4. Manifest

Canonical fields in `AgentPackManifestValidator` / `AgentPackConstants::SCHEMA_VERSION=1`.
Key: `vendor.pack-name`. Reject traversal, URL, unsupported schema, SDK/workspace mismatch, core namespace takeover (non-builtin).

## 5. Declarative skills / templates

Skills bind via `AgentPackCapabilityBinder`. Templates open skill + prefill + NL draft only; server resolves allowed variables; no execute/confirm/hidden instructions/site override.

## 6. Capability binding

Resolver = `CanonicalCapabilityRegistry::get`. Reject unknown / internal / unexposed / mode mismatch / confirmation downgrade / automation safety elevate. Core skill override forbidden. Slash/alias conflicts fail closed.

## 7. Safe schemas / mappings

`AgentPackSafeSchemaValidator` â€” subset types; pattern presets only; reference resolvers allowlisted.
`AgentPackSafeMappingValidator` â€” `$input.*` / context refs / `$actor.id` / `$execution.*`; transformers allowlisted; output `$result.*` only; no secrets/config/env.

## 8. Registry / discovery

`AgentPackRegistry`, `AgentPackDiscoveryService`, `AgentPackLoader`, `AgentPackStateService`.
States: discovered|installed|enabled|disabled|incompatible|unhealthy|quarantined|removed.
One invalid pack isolated.

## 9. Compatibility / dependencies / conflicts

`AgentPackCompatibilityService` â€” missing dep, cycle, active conflict; deterministic topo order.

## 10. Compiler / cache

`AgentPackCompiler` â€” zero partial on failure. `AgentPackCache` scoped invalidation.

## 11. Revision / activation / rollback

`seo_agent_pack_revisions` immutable active. Enable: gate + explicit approval + atomic active swap. Failure keeps previous. Rollback rechecks compatibility; no business data rollback.

## 12. Skill Studio

Manager Packs panel + slash skills. Preview via `AgentPackOrchestrator::previewSkill` â€” `executed=false`, `capability_executed=false`. No PHP editor.

## 13. Import / export / security

`AgentPackImportExportService` â€” declarative JSON/ZIP; size/entry limits; reject PHP/symlink/traversal/nested archive; strip secrets; imported disabled + `imported_unverified`.

## 14. Governance / evaluation

Before enable: validate â†’ compat â†’ bind â†’ quality gate â†’ explicit approval. No auto-enable / auto-promotion. Pack datasets: `pack:{pack_key}:{dataset_key}`.

## 15. Builtin datasets

`BuiltinAgentEvaluationDatasetInstaller` + `agent:evaluations:install-builtin`.
Keys: core-routing, core-planning, core-security, core-execution-boundary, core-knowledge-grounding, core-automation-safety.
Idempotent; skip non-builtin clones.

## 16. UI

Packs tab (manager): list + detail tabs overview/skills/templates/compatibility/evaluations/revisions/diagnostics; enable/disable with confirm.

## 17. Files created

- Migration `2026_07_28_260000_phase7_agent_packs.php`
- Models `SeoAgentPack*`
- `Services/AgentWorkspace/Packs/**`
- `Skills/PackSkills.php`
- `Console/InstallBuiltinAgentEvaluationsCommand.php`
- `BuiltinAgentEvaluationDatasetInstaller.php`
- Views `packs-panel.blade.php`
- Tests `AgentPackPhase7Test.php`
- Docs: this handoff + AGENT_PACKS*.md

## 18. Files modified

- `AgentSkillRegistry`, `AgentChatTemplateRegistry`, `BuiltinSkillCatalog`
- `AgentWorkspaceApplicationService`, `AgentWorkspacePage`, agent-workspace blade
- `AgentObservabilityCatalog` (pack.* events)
- `SeoContentAiServiceProvider`
- lang en/vi filament
- `SUPER_MAP_INDEX.md`, `AGENT_WORKSPACE.md`, `AGENT_WORKSPACE_SECURITY.md`, `AGENT_EVALUATION.md`

## 19. Migration

`omi_seo_ai`: `seo_agent_packs`, `seo_agent_pack_revisions`, `seo_agent_pack_skills`, `seo_agent_pack_templates`. No site installations table (global pack enablement).

## 20. Commands

```text
php artisan migrate
php artisan optimize:clear
php artisan agent:evaluations:install-builtin
php artisan agent:evaluate --dataset=core-routing --dry-run
```

## 21. Tests / results

Filters: AgentPack, AgentPackManifest, AgentPackCompatibility, AgentPackImport, AgentPackEvaluation, BuiltinAgentDataset (+ Phase 1â€“6 regression filters). Local PHPUnit not run (remote-first).

## 22. Freeze verification

| Check | Result |
|---|---|
| CommandBus modified | No |
| Existing handlers modified | No |
| AgentGateway modified | No |
| Execution boundary bypassed | No |
| Planning rewritten | No |
| Capability Registry authority bypassed | No |
| Extension SDK replaced | No |
| Executable uploaded code | No |
| Internal capability exposed | No |
| Confirmation downgraded | No |
| AI auto-enable | No |
| Evaluation executes business action | No |

## 23. Known limitations

- Extension packs currently bridge metadata only (empty skills until extension declares declarative agent_pack payload).
- Skill Studio JSON editor is slash + Packs panel; full form builder UI minimal (preview/detail JSON).
- Pack evaluation datasets from manifest are referenced/namespaced; seed into eval tables is manual/per enable flow.
- Gate summary on enable may use fixture rates until pack dataset run wired end-to-end.

## 24. Phase 8 candidates (DO NOT IMPLEMENT)

- Public marketplace / PKI signing
- Live pack store
- Executable sandboxed runners
- Cross-site pack installation tenancy matrix
- Auto-enable after green gates
