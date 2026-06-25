# Codex Project Instructions

## Communication

- Respond in Vietnamese unless the user asks for another language.
- Keep answers concise, direct, and implementation-focused.
- Before changing code, inspect the relevant implementation, nearby tests, and the closest README.
- When documentation and code disagree, treat the current code, manifests, and dependency files as authoritative. Mention meaningful discrepancies.
- Do not replace an entire file when a focused patch is sufficient.

## Project Context

- This is an omnichannel SaaS backend built with Laravel 12, PHP 8.2+, Filament 3, React, Vite, Tailwind CSS, and MySQL.
- The core application manages sites, services, subscriptions, wallets, payments, orders, invoices, jobs, and frontend proxies.
- Filament's core admin panel is available under `/admin`.
- Features that can be isolated must be implemented as addons under `app/Addons/{PascalCaseName}/`.
- Read `README.md` for the core architecture.
- When working in SEO Content AI, also read `app/Addons/SeoContentAi/README.md`.

## Addon Architecture

- Each addon should own its metadata, provider, routes, migrations, models, services, Filament code, views, and frontend assets.
- Keep addon-specific changes inside the addon unless integration with the core application genuinely requires a root-level change.
- Do not register an addon statically in `config/app.php`; active addons are registered dynamically from the `services` table.
- Use `addon.json` as the source of truth for addon slug, provider, version, and optional static database config.
- Use `App\Addons\RegistersAddonDatabase` for addons with a dedicated database declared in `addon.json` (not SEO Content AI).
- Slugs listed in `config/addons.php` → `skip_slugs` (default includes `wp-headless`) are ignored by `AddonManager::discover()` and `AppServiceProvider` even if still active in `services`.
- Place addon migrations in `app/Addons/{AddonName}/database/migrations/`.
- Avoid foreign-key constraints across databases. Represent cross-database references as scalar IDs and enforce them at the application layer.
- Do not edit `bootstrap/app.php` for ordinary addon behavior. Changes there are limited to true application-level middleware or narrowly scoped CSRF exceptions.

## PHP And Laravel

- Add `declare(strict_types=1);` to new PHP files.
- Use parameter types, return types, typed properties, enums, constructor property promotion, match expressions, and nullsafe access where appropriate.
- Follow existing local patterns before introducing a new abstraction.
- Prefer early returns over deeply nested conditionals.
- Use constructor or method injection for application dependencies. Avoid resolving dependencies through `app()` in business logic.
- Put validation in Form Request classes when handling conventional HTTP controller input.
- Keep controllers thin. Put non-trivial business behavior in focused Service or Action classes.
- Use Eloquent casts for JSON, booleans, enums, dates, and structured values.
- Prevent N+1 queries with eager loading when relationships are accessed in loops or serializers.
- Wrap multi-write financial operations involving wallets, transactions, orders, or invoices in `DB::transaction()`.
- Preserve authorization and tenant boundaries when changing queries.

## Filament

- Organize large forms with `Section`, `Grid`, `Fieldset`, tabs, or existing project components.
- Define access explicitly through policies, `canAccess()`, query scoping, or the established access-control helpers.
- Non-admin users must only see records within their permitted owner/site/domain scope.
- Use Filament notifications for user-facing action results.
- Place addon pages and resources under the addon's `Filament/` directory so its panel/provider can discover them.
- Do not use Livewire only to open/close modals, drawers, dropdowns, or other pure UI containers. Toggle those states with JavaScript/Alpine, and call Livewire only when loading data or executing server-side actions.

## Databases

- Core models use the default `mysql` connection.
- Never assume every addon uses the same secondary connection; inspect its provider and configuration source.
- SEO Content AI uses runtime connection name `omi_seo_ai`. Credentials come from core table `seo_database_connections` (Filament: SEO Database Connections), bootstrapped by `SeoDatabaseConnectionService` — not from `addon.json` or core `.env` DB vars.
- SEO Content AI models and migrations must consistently target `omi_seo_ai`.
- Other addons may use `addon.json` + optional `database.local.php` via `RegistersAddonDatabase`.

## SEO Content AI

- The addon lives in `app/Addons/SeoContentAi/` and its Filament panel is mounted at `/seo`.
- Keep business logic in `Services/`, persistence in `Models/`, HTTP endpoints in `Http/Controllers/`, and Filament UI in `Filament/`.
- Preserve the WordPress bridge contracts and token-based authentication when changing sync, media, webhook, or plugin endpoints.
- Use the `seo-content-ai::` namespace for addon views and translations.
- Add or update focused tests under `app/Addons/SeoContentAi/tests/Unit/` for service and parser behavior.
- After changing addon JavaScript or CSS, verify the relevant Vite entry and run the frontend build.

## React And Vite

- Follow the existing addon-local frontend layout. SEO Content AI assets live under `app/Addons/SeoContentAi/resources/`.
- Register new entry points in `vite.config.js` only when a separate bundle is required.
- Pass server data to React through explicit serialized props or the existing page bootstrap mechanism.
- Reuse the project's icon library and existing UI patterns; use `lucide-react` where that is the established component convention.
- Do not duplicate state between Livewire and React without defining which side owns it.

## Security And Integrations

- Validate and authorize every state-changing endpoint, including addon APIs and WordPress callbacks.
- Do not log tokens, passwords, API keys, authorization headers, or full sensitive payloads.
- Keep CSRF exclusions narrow and route-specific.
- Preserve `WpHeadlessCors` or the addon-specific middleware for external WordPress requests where currently required.
- Read WordPress credentials from the established site/domain settings or metadata storage; do not hard-code them.

## Verification

- Run the smallest relevant test set first.
- For PHP changes, run focused tests such as:
  - `php artisan test --filter=<TestName>`
  - `php artisan test app/Addons/SeoContentAi/tests`
- Run Laravel Pint on changed PHP files when practical.
- For JavaScript or CSS changes, run the relevant frontend build or checks, normally `npm run build`.
- For migration changes, inspect both `up()` and `down()` behavior and verify the intended database connection.
- Report commands that could not be run and the reason.

## Troubleshooting Addon Removal

For class-not-found failures after removing an addon or package:

1. Check `bootstrap/providers.php` and remove only stale provider entries.
2. Clear generated PHP files under `bootstrap/cache/` without deleting `.gitignore`.
3. Run `composer dump-autoload -o`.
4. Run `php artisan optimize:clear`.

Do not delete caches or providers blindly; first identify the stale class reference.
