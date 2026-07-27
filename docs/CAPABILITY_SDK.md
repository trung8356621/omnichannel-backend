# Capability SDK

> **Extension Cutover v1.0 hoàn tất.** `CanonicalCapabilityRegistry` (merge core `ContentProjectCapabilityRegistry` + `ExtensionCapabilityRegistry`) là đường canonical mà `ContentProjectAgentGateway` inject — không phải scaffold song song. Gateway không còn inject `ExtensionCapabilityRegistry`/`ContentProjectCapabilityRegistry` trực tiếp. Xem [ARCHITECTURE_DECISIONS.md](ARCHITECTURE_DECISIONS.md) ADR-003, ADR-015.

## Contract

`CapabilityContributor::capabilities(): list<array{name,description,input_schema,risk_level}>`

Register:

```php
$ctx->capabilities()->contribute($extensionId, $this);
```

Stored in `ExtensionCapabilityRegistry` — Agent/MCP có thể list sau khi merge policy.

## Content Project capabilities

Core CP capabilities nằm ở `ContentProjectCapabilityRegistry` (CommandBus). `CanonicalCapabilityRegistry` merge core + extension, report `conflicts()`, và biết `isAgentWriteExposed()`.

Plugin **không** được đăng ký capability trùng prefix `content_project.` (protected — xem `config/seo_architecture.php` → `core_capabilities_protected_prefix`), và **không** được inject internal commands (`process_scheduled_publish`, stop/resume).

Plugin caps ví dụ: `seo.audit`, `gsc.sync`, `social.publish` — đăng ký extension registry, tự động merge vào `CanonicalCapabilityRegistry`.

## Agent

Agent (`ContentProjectAgentGateway`) chỉ inject `CanonicalCapabilityRegistry`, chỉ thấy capability đã expose qua registry đã validate — không thấy class Handler, không inject raw `ExtensionCapabilityRegistry`.
