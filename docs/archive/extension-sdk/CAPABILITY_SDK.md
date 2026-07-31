> Status: Historical
> Not canonical
> Archived on: 2026-08-01
> Superseded by: ../../modules/EXTENSION_SDK.md
> Purpose: implementation history only
# Capability SDK

> **Extension Cutover v1.0 hoÃ n táº¥t.** `CanonicalCapabilityRegistry` (merge core `ContentProjectCapabilityRegistry` + `ExtensionCapabilityRegistry`) lÃ  Ä‘Æ°á»ng canonical mÃ  `ContentProjectAgentGateway` inject â€” khÃ´ng pháº£i scaffold song song. Gateway khÃ´ng cÃ²n inject `ExtensionCapabilityRegistry`/`ContentProjectCapabilityRegistry` trá»±c tiáº¿p. Xem [ARCHITECTURE_DECISIONS.md](ARCHITECTURE_DECISIONS.md) ADR-003, ADR-015.

## Contract

`CapabilityContributor::capabilities(): list<array{name,description,input_schema,risk_level}>`

Register:

```php
$ctx->capabilities()->contribute($extensionId, $this);
```

Stored in `ExtensionCapabilityRegistry` â€” Agent/MCP cÃ³ thá»ƒ list sau khi merge policy.

## Content Project capabilities

Core CP capabilities náº±m á»Ÿ `ContentProjectCapabilityRegistry` (CommandBus). `CanonicalCapabilityRegistry` merge core + extension, report `conflicts()`, vÃ  biáº¿t `isAgentWriteExposed()`.

Plugin **khÃ´ng** Ä‘Æ°á»£c Ä‘Äƒng kÃ½ capability trÃ¹ng prefix `content_project.` (protected â€” xem `config/seo_architecture.php` â†’ `core_capabilities_protected_prefix`), vÃ  **khÃ´ng** Ä‘Æ°á»£c inject internal commands (`process_scheduled_publish`, stop/resume).

Plugin caps vÃ­ dá»¥: `seo.audit`, `gsc.sync`, `social.publish` â€” Ä‘Äƒng kÃ½ extension registry, tá»± Ä‘á»™ng merge vÃ o `CanonicalCapabilityRegistry`.

## Agent

Agent (`ContentProjectAgentGateway`) chá»‰ inject `CanonicalCapabilityRegistry`, chá»‰ tháº¥y capability Ä‘Ã£ expose qua registry Ä‘Ã£ validate â€” khÃ´ng tháº¥y class Handler, khÃ´ng inject raw `ExtensionCapabilityRegistry`.
