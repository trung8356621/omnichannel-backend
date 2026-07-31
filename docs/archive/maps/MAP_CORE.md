> Status: Historical
> Not canonical
> Archived on: 2026-08-01
> Superseded by: ../../architecture/SYSTEM_OVERVIEW.md
> Purpose: implementation history only
# Core System â€” Báº£n Ä‘á»“ Há»‡ thá»‘ng

[â† Quay láº¡i FEATURE_MAP_FULL.md](FEATURE_MAP_FULL.md) Â· [SUPER_MAP_INDEX.md](SUPER_MAP_INDEX.md)

> **NgÃ y kháº£o sÃ¡t:** 06/07/2026
> **Pháº¡m vi:** Core application (`app/`, `routes/`, `config/`, `bootstrap/`)
> **Má»¥c Ä‘Ã­ch:** Liá»‡t kÃª táº¥t cáº£ Controller, Middleware, Services, Models, Filament resources, Routes, Auth, Console cá»§a Core.

---

## 1. Routes tá»•ng quan

| File | NhÃ³m | Sá»‘ routes | Ghi chÃº |
|------|------|-----------|---------|
| `routes/web.php` | Web (default) | 7 | Google Auth, Plugin update, WP redirect |
| `routes/api.php` | API (default) | 8 | User info, Plugin update/check, SEO plugin |
| `routes/auth.php` | Auth (Breeze/Sanctum) | 7 | Login, Register, Reset password, Email verify |
| `routes/console.php` | Artisan Commands | 2 | `inspire`, `seo:media-flatten-paths` |
| `routes/channels.php` | â€” | 0 | **KhÃ´ng tá»“n táº¡i** |

---

## 2. Core Controllers (`app/Http/Controllers/`)

| # | Controller | Methods | Chá»©c nÄƒng |
|---|-----------|---------|-----------|
| 1 | `Controller` (abstract) | â€” | Base class rá»—ng |
| 2 | `Admin\DashboardController` | 3 | Dashboard admin: index (thá»‘ng kÃª), users, services |
| 3 | `Api\ApiController` | 1 | `checkNpm()` â€” kiá»ƒm tra Ä‘Æ°á»ng dáº«n npm |
| 4 | `Api\ExternalPluginUpdateController` | 8 | Quáº£n lÃ½ cáº­p nháº­t WordPress plugin |
| 5 | `Auth\AuthenticatedSessionController` | 2 | Login/Logout |
| 6 | `Auth\RegisteredUserController` | 1 | Register user (role=owner, status=normal) |
| 7 | `Auth\GoogleController` | 2 | Google OAuth login (Socialite) |
| 8 | `Auth\PasswordResetLinkController` | 1 | Gá»­i link reset password |
| 9 | `Auth\NewPasswordController` | 1 | Reset password |
| 10 | `Auth\VerifyEmailController` | 1 | Verify email (__invoke) |
| 11 | `Auth\EmailVerificationNotificationController` | 1 | Gá»­i láº¡i email verification |

---

## 3. Core Middleware

| Middleware | Alias | Chá»©c nÄƒng |
|-----------|-------|-----------|
| `AdminMiddleware` | `admin` | Chá»‰ cho phÃ©p role=admin |
| `EnsureEmailIsVerified` | `verified` | 409 JSON náº¿u email chÆ°a verify |
| `SetDynamicSeoDatabaseByHash` | (none) | Bootstrap `omi_seo_ai` tá»« hash/session/header |
| `RedirectStaffFromAdminPanel` | (none) | Staff redirect khá»i /admin |

---

## 4. Core Models (`app/Models/`)

| Model | Table | Connection | Ghi chÃº |
|-------|-------|-----------|---------|
| `User` | `users` | core (mysql) | Roles, SEO roles, status, meta EAV |
| `Site` | `sites` | core | Domain, SSL, subscription |
| `SiteMeta` | `site_meta` | core | EAV cho site |
| `SiteService` | `site_services` | default | Service binding (site/user level) |
| `Service` | `services` | default | Addon registry |
| `ServicePlan` | `service_plans` | default | Pricing plans |
| `Subscription` | `subscriptions` | default | User subscriptions |
| `Order` | `orders` | default | Placeholder (rá»—ng) |
| `Invoice` | `invoices` | default | PDF invoices |
| `Wallet` | `wallets` | default | User wallets |
| `Transaction` | `transactions` | default | Wallet transactions |
| `UsageLog` | `usage_logs` | default | Usage metrics |
| `TaskJob` | `task_jobs` | default | Background jobs |
| `UserMeta` | `user_meta` | core | User EAV meta |
| `TeamMessage` | `team_messages` | core | Team chat messages (core) |
| `SeoDatabaseConnection` | `seo_database_connections` | core | SEO DB credential store |
| `ApiConnection` | `api_connections` | core | AI API connections |
| `WpOption` | `wp_options` | core | Key-value store |

---

## 5. Core Services (`app/Services/`)

| Service | Chá»©c nÄƒng |
|---------|-----------|
| `AddonManager` | KhÃ¡m phÃ¡ addon tá»« filesystem, sync vÃ o DB services |
| `SeoEngineService` | PhÃ¢n tÃ­ch SEO HTML (heading, length, image ratio, wiki trust, FAQ schema, keyword) |
| `SiteServiceBindingService` | Quáº£n lÃ½ rÃ ng buá»™c SiteService (site-bound/user-bound) |
| `ExternalPluginManifest` | DTO cho WordPress plugin manifest |
| `ExternalPluginRegistry` | Registry Ä‘á»c tá»« services table + addon.json |
| `WordPressPluginZipInspector` | TrÃ­ch xuáº¥t version tá»« ZIP plugin |
| `WordPressPluginReleaseService` | CRUD release lifecycle (upload, publish, list, delete) |

---

## 6. Core Filament Resources (`app/Filament/Resources/`)

| Resource | Pages | MÃ´ táº£ |
|----------|-------|-------|
| `UserResource` | List, Create, Edit | Quáº£n lÃ½ users |
| `SiteResource` | List, Create, Edit | Quáº£n lÃ½ sites |
| `SiteServiceResource` | List, Create, Edit, View | Quáº£n lÃ½ service binding |
| `SeoDatabaseConnectionResource` | List, Create, Edit | CRUD + Run migrations |

---

## 7. Core Filament Providers

| Provider | Panel | Middleware Ä‘áº·c biá»‡t |
|----------|-------|---------------------|
| `AdminPanelProvider` | `/admin` | `RedirectStaffFromAdminPanel`, auto-discover addon; **`maxContentWidth(MaxWidth::Full)`** â€” content full chiá»u rá»™ng sau sidebar (Users, Automation Flows, â€¦) |
| `SeoToolsPanelProvider` | `/tools` | KhÃ´ng auth â€” chá»‰ 1 trang `SeoTools` |

---

## 8. Console & Scheduled Tasks

- **KhÃ´ng cÃ³** Laravel Scheduler (khÃ´ng `schedule()` trong codebase)
- **1 console command**: `seo:media-flatten-paths` (flatten seo_media paths tá»« dáº¡ng hash â†’ pháº³ng)

---

## 9. Auth System

| Chá»©c nÄƒng | Controller | Route | Ghi chÃº |
|-----------|-----------|-------|---------|
| **Google OAuth** | `GoogleController` | `auth/google` | Socialite, lÆ°u `return_url`, redirect theo role |
| **Registration** | `RegisteredUserController` | `POST /register` | role=owner, status=normal |
| **Login** | `AuthenticatedSessionController` | `POST /login` | Sanctum SPA |
| **Logout** | `AuthenticatedSessionController` | `POST /logout` | Invalidate session + token |
| **Forgot Password** | `PasswordResetLinkController` | `POST /forgot-password` | Gá»­i email reset link |
| **Reset Password** | `NewPasswordController` | `POST /reset-password` | Token + email + password |
| **Email Verify** | `VerifyEmailController` | `GET /verify-email/{id}/{hash}` | Signed route, throttle 6/1 |
| **Resend Verify** | `EmailVerificationNotificationController` | `POST /email/verification-notification` | Throttle 6/1 |

---

## 10. Core Config & Bootstrap

| File | Má»¥c Ä‘Ã­ch |
|------|----------|
| `config/addons.php` | `skip_slugs` config (máº·c Ä‘á»‹nh: `wp-headless`) |
| `bootstrap/app.php` | CSRF exceptions cho `admin/wp-headless/connect/*`, Sanctuum stateful API, middleware aliases |

---

## 11. Plugin Distribution System

Há»‡ thá»‘ng phÃ¢n phá»‘i WordPress plugin (omi-seo-ai-bridge) qua Laravel:

### Routes

| Method | Path | Controller | MÃ´ táº£ |
|--------|------|-----------|-------|
| GET | `/wp-plugin-release` | Redirect â†’ `/admin/wp-plugin-release` | Redirect admin |
| GET | `/storage/plugins/{package_prefix}/info.json` | `ExternalPluginUpdateController@infoJsonByPackagePrefix` | Info JSON theo package prefix |
| GET | `/wp-plugin-release/download/{slug}/{version}` | `ExternalPluginUpdateController@downloadForPanel` | Download khÃ´ng cáº§n signature |
| GET | `/seo/wp-plugin/download/{version}` | `ExternalPluginUpdateController@legacyDownloadForPanel` | Legacy download |
| GET | `/api/plugins/{slug}/update-check` | `ExternalPluginUpdateController@checkUpdate` | Update check API |
| GET | `/api/plugins/{slug}/info.json` | `ExternalPluginUpdateController@infoJson` | Info JSON API |
| GET | `/api/plugins/{slug}/download/{version}` | `ExternalPluginUpdateController@download` | Download API (signed URL) |
| GET | `/api/seo/plugin/update-check` | `ExternalPluginUpdateController@legacyCheckUpdate` | Legacy update check |
| GET | `/api/seo/plugin/info.json` | Closure â†’ slug cá»©ng `omi-seo-ai-bridge` | Legacy info |
| GET | `/api/seo/plugin/download/{version}` | `ExternalPluginUpdateController@legacyDownload` | Legacy download |

### Services

| Service | Vai trÃ² |
|---------|---------|
| `ExternalPluginRegistry` | Äá»c manifest tá»« `services.config.external_plugins` + `addon.json` |
| `ExternalPluginManifest` | DTO: slug, label, platform, packagePrefix, metadataOptionKey |
| `WordPressPluginReleaseService` | CRUD releases: publish, list, delete, metadata (lÆ°u WpOption) |
| `WordPressPluginZipInspector` | Parse Version header tá»« PHP file trong ZIP |

---

## HÆ°á»›ng dáº«n prompt

```
Core routes: routes/{web,api,auth,console}.php
Core controllers: app/Http/Controllers/{Admin,Api,Auth}/
Core middleware: app/Http/Middleware/{AdminMiddleware,EnsureEmailIsVerified,...}
Core models: app/Models/{User,Site,SiteService,...,WpOption}
Core services: app/Services/{AddonManager,SeoEngineService,SiteServiceBindingService,ExternalPlugin/*}
Core Filament: app/Filament/Resources/{UserResource,SiteResource,SiteServiceResource,SeoDatabaseConnectionResource}
Core providers: app/Providers/{AppServiceProvider,Filament/{AdminPanelProvider,SeoToolsPanelProvider}}
Plugin distribution: services/ExternalPlugin/{Registry,Manifest,ReleaseService,ZipInspector}
```
