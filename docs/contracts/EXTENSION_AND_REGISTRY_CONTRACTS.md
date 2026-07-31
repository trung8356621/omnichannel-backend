# Extension and Registry Contracts

> Status: Canonical  
> Owner: SeoContentAi  
> Last verified: 2026-08-01  
> Supersedes: contract slices of `docs/archive/extension-sdk/*` (EXTENSION / CAPABILITY / PIPELINE / PUBLISHER / AI_PROVIDER / SECURITY_BOUNDARY / BUILTIN_WORDPRESS)

Module UX/runtime: `docs/modules/EXTENSION_SDK.md`. Agent capability merge: `docs/contracts/AGENT_AND_MCP_CONTRACTS.md`.

---

## 1. Security boundary

### Allowed discovery

1. Paths only: `Extension/Builtin/*` and `Extensions/{id}/` (`config('seo-content-ai.extension_sdk.extensions_path')`).
2. Valid `plugin.json` via `ExtensionManifest::fromFile`; SDK major compatible (`ExtensionCompatibilityChecker`).
3. `class_exists($providerClass)` before `$app->make()` — missing class → `status: error`, no crash.
4. Enabled SoT: `ExtensionStateStore` / `seo_extension_states` — not `manifest.enabled`.

### Forbidden

- Marketplace, auto-download, zip upload, Git remote install.
- `eval`, dynamic `include`/`require` from external data.
- Path traversal / uppercase / spaces in `extension_id` (must match `extension_id_pattern`).

### Settings namespace

All extension-owned settings: `extensions.{id}.*`.  
Forbidden: read/write core keys outside own namespace (`seo_project_agent.*`, `seo_content_ai.*`, …).

### Events and health

- Bridge domain → `ExtensionEventBus` **after commit** (or immediately if no transaction).
- Each listener isolated `try/catch` — failure does not roll back domain or stop peers.
- Payload / `health()` must not contain secrets, tokens, passwords, connection strings, or full article bodies.
- `health()` shape: `{ok: bool, message: string}` only.

Logging: follow RuntimeLogger rules on HTTP; never log credentials.

---

## 2. Manifest and provider contract

### plugin.json (minimum)

```json
{
  "id": "wordpress",
  "name": "WordPress Publisher",
  "version": "1.0.0",
  "sdk": 1,
  "provider": "App\\...\\WordpressExtensionProvider",
  "providers": ["publisher"],
  "capabilities": [],
  "requires": []
}
```

### ExtensionProvider

```php
public function register(ExtensionContext $ctx): void; // inject drivers
public function boot(ExtensionContext $ctx): void;     // events / warm
```

Register only inside `register()`. Application code must not poke registries ad hoc outside provider lifecycle.

---

## 3. Publisher contract

### Interfaces

| Interface | Layer |
|-----------|--------|
| `PublisherDriver` | SDK registry — `publish|update|delete|find|health` |
| `ContentPublisher` | Application publishing — used by `PublisherResolver` |

### Resolve rules

```text
PublisherResolver::resolveForSiteId($siteId)
  → ContentPublisherRegistry by publisher_key / seo_platform
  → extension enabled + health
  → fail-closed (no silent WordPress fallback)
```

### Builtin WordPress rules

- Path: `Extension/Builtin/Wordpress/` (`id === 'wordpress'`).
- Registers **both** `ContentPublisherRegistry` and `PublisherRegistry`.
- Publish idempotent on `external_reference` / `wp_post_id`.
- **Forbidden:** Application Handlers import Builtin WordPress publisher class; Agent import `Extension\Builtin\*`; resurrect `Application/Publishing/WordPressContentPublisher`.

---

## 4. AI provider contract

| Surface | Methods |
|---------|---------|
| `AiTextProviderInterface` | `generate`, `health` |
| `AiImageProviderInterface` | `generateImage` |
| `AiProviderDriver` | `id`, `label`, `supportsChat|Image|Embedding|Moderation`, `health` |

Register: `$ctx->aiProviders()->register($id, $driver)`.

**Resolve:** `PromptRunnerService` → `AiProviderResolver::assertTextReady` only.  
Builtin extension id: `ai-providers`.  
Errors: `ai_provider.not_configured`, `ai_provider.not_registered`, `ai_provider.disabled`.

Forbidden: hard-code vendor clients inside Application prompt path.

---

## 5. Pipeline contract

| Surface | Role |
|---------|------|
| `PipelineDefinitionInterface` | `key`, `name`, `version`, `supportedContentTypes`, `steps`, `requiredCapabilities`, `validate` |
| `PipelineStepDriver` | `id`, `label`, `stage` ∈ `outline\|article\|translate\|review\|image\|seo_audit\|custom`, `health` |

Register: `$ctx->pipelines()->register($id, $driver)`.

**Resolve:** `PipelineResolver` — builtin id `content-pipelines`.  
Errors: `pipeline.not_configured`, `pipeline.not_registered`, `pipeline.disabled`.

Prompt Hook contributors (`PromptHookContributor` → `PromptHookExtensionRegistry`) are parallel — do not silently replace core definition loader.

---

## 6. Capability registration rules

### Contribution shape

`CapabilityContributor::capabilities(): list<array{name,description,input_schema,risk_level}>`

```php
$ctx->capabilities()->contribute($extensionId, $this);
```

Stored in `ExtensionCapabilityRegistry`; Agent/MCP see results only after **Canonical** merge.

### CanonicalCapabilityRegistry

1. Merge core `ContentProjectCapabilityRegistry` + enabled extension contributions.
2. Core-owned names (`content_project.*` / protected prefix in `config/seo_architecture.php`) **cannot** be overridden.
3. Name collisions (extension↔extension or extension↔core) → **excluded** + recorded in `conflicts()`.
4. Disabled extensions contribute nothing.
5. Exposure gates: `isAgentWriteExposed` / `isMcpWriteExposed` (MCP ⊆ Agent).

### Forbidden plugin caps

- Register under protected `content_project.` prefix.
- Inject internal commands (`process_scheduled_publish`, stop/resume, engine-only ops).
- Bypass Gateway — Agent must use `CanonicalCapabilityRegistry` only (never raw `ExtensionCapabilityRegistry` or Handler classes).

### Metadata (each capability)

`name`, `description`, `input_schema`, `required_permission`, `allowed_lifecycle_phases`, `handler`, `confirmation_requirement`, `risk_level` (`read|write|publish|destructive`), `idempotency_support`, `dry_run_support`, optional `mcp_exposed`.

Public I/O uses public refs only — see `AGENT_AND_MCP_CONTRACTS.md`.

---

## 7. Registry inventory (normative)

| Registry | Register API (via ExtensionContext) | Consumer |
|----------|-------------------------------------|----------|
| Publishers (driver) | `publishers()->register` | Health / Extensions UI |
| Content publishers | `contentPublishers()->register` | `PublisherResolver` |
| AI providers | `aiProviders()->register` | `AiProviderResolver` / PromptRunner |
| Pipelines | `pipelines()->register` | `PipelineResolver` |
| Capabilities | `capabilities()->contribute` | → Canonical → Gateway |
| Prompt hooks | PromptHook extension registry | Hook discovery (additive) |
| Media processors | MediaProcessorRegistry | Media pipeline |
| Workflow packs | WorkflowExtensionRegistry | Workflow packs |
| Extension registry | discovery | Installed list |
| SEO providers | SeoProviderRegistry | Settings / Performance Hub |

`ContentPlatformRegistry` is facade only — not a second SoT.

---

## 8. Enforcement

| Guard | Location |
|-------|----------|
| No eval/arbitrary include | `ExtensionSdkFoundationTest` |
| Handler/Agent import freeze | `ExtensionArchitectureFreezeTest` |
| Id pattern | `config/seo_architecture.php` → `extension_id_pattern` |
| Protected capability prefix | `core_capabilities_protected_prefix` |
| Unique jobs / architecture lock | `ArchitectureHardeningLockContractTest` (adjacent) |

---

## 9. Related documents

- `docs/modules/EXTENSION_SDK.md`
- `docs/modules/PROMPTS_AND_AI.md`
- `docs/contracts/AGENT_AND_MCP_CONTRACTS.md`
- `docs/modules/PUBLISHING.md`
)
