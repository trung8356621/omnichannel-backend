# Core System — Bản đồ Hệ thống

[← Quay lại FEATURE_MAP_FULL.md](FEATURE_MAP_FULL.md) · [SUPER_MAP_INDEX.md](SUPER_MAP_INDEX.md)

> **Ngày khảo sát:** 06/07/2026
> **Phạm vi:** Core application (`app/`, `routes/`, `config/`, `bootstrap/`)
> **Mục đích:** Liệt kê tất cả Controller, Middleware, Services, Models, Filament resources, Routes, Auth, Console của Core.

---

## 1. Routes tổng quan

| File | Nhóm | Số routes | Ghi chú |
|------|------|-----------|---------|
| `routes/web.php` | Web (default) | 7 | Google Auth, Plugin update, WP redirect |
| `routes/api.php` | API (default) | 8 | User info, Plugin update/check, SEO plugin |
| `routes/auth.php` | Auth (Breeze/Sanctum) | 7 | Login, Register, Reset password, Email verify |
| `routes/console.php` | Artisan Commands | 2 | `inspire`, `seo:media-flatten-paths` |
| `routes/channels.php` | — | 0 | **Không tồn tại** |

---

## 2. Core Controllers (`app/Http/Controllers/`)

| # | Controller | Methods | Chức năng |
|---|-----------|---------|-----------|
| 1 | `Controller` (abstract) | — | Base class rỗng |
| 2 | `Admin\DashboardController` | 3 | Dashboard admin: index (thống kê), users, services |
| 3 | `Api\ApiController` | 1 | `checkNpm()` — kiểm tra đường dẫn npm |
| 4 | `Api\ExternalPluginUpdateController` | 8 | Quản lý cập nhật WordPress plugin |
| 5 | `Auth\AuthenticatedSessionController` | 2 | Login/Logout |
| 6 | `Auth\RegisteredUserController` | 1 | Register user (role=owner, status=normal) |
| 7 | `Auth\GoogleController` | 2 | Google OAuth login (Socialite) |
| 8 | `Auth\PasswordResetLinkController` | 1 | Gửi link reset password |
| 9 | `Auth\NewPasswordController` | 1 | Reset password |
| 10 | `Auth\VerifyEmailController` | 1 | Verify email (__invoke) |
| 11 | `Auth\EmailVerificationNotificationController` | 1 | Gửi lại email verification |

---

## 3. Core Middleware

| Middleware | Alias | Chức năng |
|-----------|-------|-----------|
| `AdminMiddleware` | `admin` | Chỉ cho phép role=admin |
| `EnsureEmailIsVerified` | `verified` | 409 JSON nếu email chưa verify |
| `SetDynamicSeoDatabaseByHash` | (none) | Bootstrap `omi_seo_ai` từ hash/session/header |
| `RedirectStaffFromAdminPanel` | (none) | Staff redirect khỏi /admin |

---

## 4. Core Models (`app/Models/`)

| Model | Table | Connection | Ghi chú |
|-------|-------|-----------|---------|
| `User` | `users` | core (mysql) | Roles, SEO roles, status, meta EAV |
| `Site` | `sites` | core | Domain, SSL, subscription |
| `SiteMeta` | `site_meta` | core | EAV cho site |
| `SiteService` | `site_services` | default | Service binding (site/user level) |
| `Service` | `services` | default | Addon registry |
| `ServicePlan` | `service_plans` | default | Pricing plans |
| `Subscription` | `subscriptions` | default | User subscriptions |
| `Order` | `orders` | default | Placeholder (rỗng) |
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

| Service | Chức năng |
|---------|-----------|
| `AddonManager` | Khám phá addon từ filesystem, sync vào DB services |
| `SeoEngineService` | Phân tích SEO HTML (heading, length, image ratio, wiki trust, FAQ schema, keyword) |
| `SiteServiceBindingService` | Quản lý ràng buộc SiteService (site-bound/user-bound) |
| `ExternalPluginManifest` | DTO cho WordPress plugin manifest |
| `ExternalPluginRegistry` | Registry đọc từ services table + addon.json |
| `WordPressPluginZipInspector` | Trích xuất version từ ZIP plugin |
| `WordPressPluginReleaseService` | CRUD release lifecycle (upload, publish, list, delete) |

---

## 6. Core Filament Resources (`app/Filament/Resources/`)

| Resource | Pages | Mô tả |
|----------|-------|-------|
| `UserResource` | List, Create, Edit | Quản lý users |
| `SiteResource` | List, Create, Edit | Quản lý sites |
| `SiteServiceResource` | List, Create, Edit, View | Quản lý service binding |
| `SeoDatabaseConnectionResource` | List, Create, Edit | CRUD + Run migrations |

---

## 7. Core Filament Providers

| Provider | Panel | Middleware đặc biệt |
|----------|-------|---------------------|
| `AdminPanelProvider` | `/admin` | `RedirectStaffFromAdminPanel`, auto-discover addon; **`maxContentWidth(MaxWidth::Full)`** — content full chiều rộng sau sidebar (Users, Automation Flows, …) |
| `SeoToolsPanelProvider` | `/tools` | Không auth — chỉ 1 trang `SeoTools` |

---

## 8. Console & Scheduled Tasks

- **Không có** Laravel Scheduler (không `schedule()` trong codebase)
- **1 console command**: `seo:media-flatten-paths` (flatten seo_media paths từ dạng hash → phẳng)

---

## 9. Auth System

| Chức năng | Controller | Route | Ghi chú |
|-----------|-----------|-------|---------|
| **Google OAuth** | `GoogleController` | `auth/google` | Socialite, lưu `return_url`, redirect theo role |
| **Registration** | `RegisteredUserController` | `POST /register` | role=owner, status=normal |
| **Login** | `AuthenticatedSessionController` | `POST /login` | Sanctum SPA |
| **Logout** | `AuthenticatedSessionController` | `POST /logout` | Invalidate session + token |
| **Forgot Password** | `PasswordResetLinkController` | `POST /forgot-password` | Gửi email reset link |
| **Reset Password** | `NewPasswordController` | `POST /reset-password` | Token + email + password |
| **Email Verify** | `VerifyEmailController` | `GET /verify-email/{id}/{hash}` | Signed route, throttle 6/1 |
| **Resend Verify** | `EmailVerificationNotificationController` | `POST /email/verification-notification` | Throttle 6/1 |

---

## 10. Core Config & Bootstrap

| File | Mục đích |
|------|----------|
| `config/addons.php` | `skip_slugs` config (mặc định: `wp-headless`) |
| `bootstrap/app.php` | CSRF exceptions cho `admin/wp-headless/connect/*`, Sanctuum stateful API, middleware aliases |

---

## 11. Plugin Distribution System

Hệ thống phân phối WordPress plugin (omi-seo-ai-bridge) qua Laravel:

### Routes

| Method | Path | Controller | Mô tả |
|--------|------|-----------|-------|
| GET | `/wp-plugin-release` | Redirect → `/admin/wp-plugin-release` | Redirect admin |
| GET | `/storage/plugins/{package_prefix}/info.json` | `ExternalPluginUpdateController@infoJsonByPackagePrefix` | Info JSON theo package prefix |
| GET | `/wp-plugin-release/download/{slug}/{version}` | `ExternalPluginUpdateController@downloadForPanel` | Download không cần signature |
| GET | `/seo/wp-plugin/download/{version}` | `ExternalPluginUpdateController@legacyDownloadForPanel` | Legacy download |
| GET | `/api/plugins/{slug}/update-check` | `ExternalPluginUpdateController@checkUpdate` | Update check API |
| GET | `/api/plugins/{slug}/info.json` | `ExternalPluginUpdateController@infoJson` | Info JSON API |
| GET | `/api/plugins/{slug}/download/{version}` | `ExternalPluginUpdateController@download` | Download API (signed URL) |
| GET | `/api/seo/plugin/update-check` | `ExternalPluginUpdateController@legacyCheckUpdate` | Legacy update check |
| GET | `/api/seo/plugin/info.json` | Closure → slug cứng `omi-seo-ai-bridge` | Legacy info |
| GET | `/api/seo/plugin/download/{version}` | `ExternalPluginUpdateController@legacyDownload` | Legacy download |

### Services

| Service | Vai trò |
|---------|---------|
| `ExternalPluginRegistry` | Đọc manifest từ `services.config.external_plugins` + `addon.json` |
| `ExternalPluginManifest` | DTO: slug, label, platform, packagePrefix, metadataOptionKey |
| `WordPressPluginReleaseService` | CRUD releases: publish, list, delete, metadata (lưu WpOption) |
| `WordPressPluginZipInspector` | Parse Version header từ PHP file trong ZIP |

---

## Hướng dẫn prompt

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
