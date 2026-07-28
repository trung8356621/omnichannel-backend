# SERP Provider Contract

Interface: `SerpIntelligenceProviderInterface`

## Required methods

- `key(): string` — stable provider id (`manual_import`, `fake_local`, …)
- `supports(SerpQueryRequest): bool`
- `collect(SerpQueryRequest): SerpProviderResult`
- `health(): array{healthy: bool, code?: string, message?: string, metadata?: array}`

## Registry

`SerpIntelligenceProviderRegistry` — register once per key; `SerpProviderResolver` fail-closed:

| Error | When |
|-------|------|
| `serp_provider.not_configured` | Missing provider key |
| `serp_provider.disabled` | Not in enabled list |
| `serp_provider.not_registered` | Unknown key |
| `serp_provider.incompatible` | `supports()` false |
| `serp_provider.unhealthy` | `health().healthy !== true` |

**No silent fallback** to another provider.

## Built-in providers

| Key | Class | Notes |
|-----|-------|-------|
| `manual_import` | `ManualImportSerpProvider` | JSON/CSV import, preview |
| `fake_local` | `FakeLocalSerpProvider` | Synthetic 10 results — dev/test |

Vendor rank providers (`SerpApiProvider`, …) live under `Providers/Serp/` for Performance Hub — **not** imported in `Application/Handlers`.

## Collect payload (manual import)

`SerpQueryRequest.options.import_payload` + `format` (`json`|`csv`).

Preview via `ManualImportSerpProvider::preview()` or `ImportSerpSnapshotCommand` with `preview=true`.
