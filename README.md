# Omnichannel Backend (Laravel)

Multi-channel SaaS backend: sites, subscriptions, wallets, payments, and addon modules (SEO Content AI, …). Admin panel: Filament v3 at `/admin`.

## Documentation

**Canonical index:** [docs/README.md](docs/README.md)

Precedence: Architecture Freeze / ADR → `docs/modules/` → `docs/contracts/` → `docs/operations/` → audits → archive.

Do **not** treat archived docs, phase handoffs, or this landing page as architecture SoT.

## Quick orientation

| Surface | Path |
|---------|------|
| Core admin | `/admin` |
| SEO workspace | `/seo/{connection_hash}` |
| Addon code | `app/Addons/{AddonName}/` |
| SEO DB | Runtime `omi_seo_ai` via `seo_database_connections` |

## Verified commands (remote)

```bash
php artisan optimize:clear
php artisan route:list
php artisan schedule:list
$PHP_BIN vendor/bin/phpunit --filter=ArchitectureHardeningLockContractTest
$PHP_BIN vendor/bin/phpunit --filter=BackendRiskClosureContractTest
```

Local package/test installs are not the project verify path — use remote host `$PHP_BIN`.

## Compatibility stubs

- [SeoContentAi README](app/Addons/SeoContentAi/README_ADDON_SEOCONTENTAI.md) — superseded stub
- [docs/SUPER_MAP_INDEX.md](docs/SUPER_MAP_INDEX.md) — thin tooling pointer
