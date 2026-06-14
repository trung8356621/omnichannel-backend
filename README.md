# Omnichannel Backend (Laravel)

Backend quản lý đa kênh: **site khách hàng**, **gói dịch vụ / subscription**, **ví & thanh toán**, và hệ thống **addon** mở rộng (WordPress Headless, SEO AI, …). Admin chạy trên **Filament v3** tại đường dẫn `/admin`.

---

## Kiến trúc cốt lõi (core)

| Thành phần | Vai trò |
|------------|--------|
| **Models chính** | `Site`, `SiteService`, `SiteMeta`, `Service`, `ServicePlan`, `Subscription`, `User`, `Wallet`, `Transaction`, `Order`, `Invoice`, `UsageLog`, `TaskJob`, `WpOption` — lưu trong **database mặc định** (`mysql`). |
| **Site ↔ Service** | Một site có nhiều bản ghi `site_services` (dịch vụ đã kích hoạt trên site), mỗi bản ghi có `settings` (JSON) tùy addon. |
| **Service (addon)** | Bảng `services` mô tả từng addon: `slug`, `addon_namespace` (class ServiceProvider), `is_active`, `config` (toàn bộ `addon.json`). |
| **AddonManager** | Quét thư mục `app/Addons/*/addon.json`, đồng bộ/`updateOrCreate` vào bảng `services` theo `slug`. |
| **AppServiceProvider** | Sau khi app boot (`$this->app->booted(...)`), nếu có bảng `services`, sẽ register mọi addon `is_active = true` bằng `$this->app->register($service->addon_namespace)`. |
| **AdminPanelProvider (Filament)** | Đăng ký panel `path('admin')`. Với mỗi service active, map `slug` → PascalCase (vd. `wp-headless` → `WpHeadless`), rồi auto **`discoverPages` / `discoverResources`** và **`loadViewsFrom`** (namespace view = `slug`). Riêng `seo-content-ai` được bỏ qua ở `/admin` vì dùng panel riêng `/seo`. |

**Lưu ý:** Addon có thể dùng **database riêng** (SEO AI: `omi_seo_ai`, WP Headless: `omi_wp_headless`). Cấu hình nằm trong `app/Addons/{Addon}/addon.json` (object `database`) và tùy chọn `database.local.php` (gitignore, chứa password trên hosting) — **không cần** thêm biến `.env` core. Provider gọi `RegistersAddonDatabase::registerAddonDatabase()` khi boot.

---

## Database schema

### Database chính (Laravel default)

Các bảng dưới đây lấy từ migrations trong `database/migrations/` (tóm tắt cột chính).

| Bảng | Mô tả ngắn |
|------|------------|
| **users** | `name`, `email`, `password`, `role` (`admin` \| `owner` \| `staff`), `status`, `parent_id` (staff thuộc owner), `google_id`, `avatar`, soft delete. |
| **sites** | `user_id`, `subscription_id`, `domain` (unique), `ssl`, `status`, soft delete. (Cột `url` đã bỏ.) |
| **site_meta** | Meta kỹ thuật theo site: `meta_key`, `meta_value`. |
| **services** | Danh mục addon: `name`, `slug`, `addon_namespace`, `db_connection` (legacy/default), `is_active`, `config` (JSON metadata từ `addon.json`). |
| **site_services** | Gán dịch vụ cho site: `site_id`, `service_id`, `status`, `settings` (JSON). |
| **service_plans** | Gói bán kèm service: `name`, `price`, `duration_days`, `limits` (JSON), … |
| **subscriptions** | User đăng ký plan: `user_id`, `plan_id`, `starts_at`, `ends_at`, `status`, soft delete. |
| **usage_logs** | Hạn mức theo subscription: `metric_key`, `current_usage`, `limit_value`. |
| **wallets** | Một ví / user: `balance`, `currency`. |
| **transactions** | Biến động ví: `type`, `amount`, balances, `status`, `reference_id`. |
| **orders** | Đơn mua gói: `plan_id`, `amount`, `status`, `payment_method`, `metadata`. |
| **invoices** | Hóa đơn gắn `order_id`, `invoice_number`, `total_amount`, `pdf_path`. |
| **task_jobs** | Theo dõi job nặng theo site: `task_type`, `status`, `progress_percent`, `error_log`, … |
| **wp_options** | Key–value kiểu WordPress (`option_name`, `option_value`, `autoload`). |
| **personal_access_tokens**, **cache**, **jobs**, **sessions**, … | Chuẩn Laravel / Sanctum. |

### Database addon WP Headless (connection `wp_headless`)

Migrations nằm trong `app/Addons/WpHeadless/database/migrations/`, chạy trên connection `wp_headless` (tên DB đọc từ `addon.json` → key `database`, vd. `omi_wp_headless`).

| Bảng | Mô tả ngắn |
|------|------------|
| **wp_headless_sites** | `id` = `sites.id` bên DB chính; `type` (theme/builder), … |
| **wp_headless_styles** | CSS theo site / post type: `style_type` (file \| inline \| font), `url` / `content`, … |
| **wp_headless_templates** | Template HTML theo `type` (header, footer, …), metadata `classes`, … (cột `template` đã qua nhiều migration — xem file migration cụ thể). |
| **wp_headless_styles_optimized** | CSS đã tối ưu theo class thực dùng: `post_type`, `chunk_index`, `content`, `size`, … |

Chi tiết cột sau các migration alter: xem trực tiếp các file trong `database/migrations/` và `app/Addons/WpHeadless/database/migrations/`.

---

## Cách kết nối / tạo addon mới

1. **Tạo thư mục** `app/Addons/{PascalCaseName}/`  
   Tên thư mục phải khớp quy ước từ **slug** trong `addon.json`:  
   `wp-headless` → `ucwords(str_replace(['-','_'], ' ', slug))` không khoảng → `WpHeadless`.

2. **Thêm `addon.json`** tại root thư mục addon, tối thiểu:
   - `name`, `slug`, `provider` (class FQCN của `ServiceProvider`, vd. `App\Addons\MyAddon\MyAddonServiceProvider`)
   - Tùy chọn: `database` — object `{ connection, name, host, port, username }` trong `addon.json`; password đặt trong `database.local.php` (copy từ `database.local.php.example`, file này gitignore). Legacy: `"database": "ten_db"` (chuỗi) clone user/pass từ connection `mysql` core.

3. **ServiceProvider**:
   - Trong `register()` / `boot()`: route, config, observer, v.v.
   - Nếu dùng DB riêng: `use RegistersAddonDatabase` và trong `boot()` gọi  
     `$this->registerAddonDatabase(__DIR__, 'ten_connection', __DIR__.'/database/migrations');`  
     Các model/migration addon nên set `$connection = 'ten_connection'` (thống nhất với WP Headless: `wp_headless`).

4. **Đồng bộ vào DB**  
   Vào Filament **Hệ thống → Quản lý Service** (hoặc gọi `AddonManager::discover()`) để cập nhật bảng `services` từ `addon.json`.

5. **Kích hoạt**  
   Trên cùng trang quản lý, bật `is_active`.  
   - Nếu `addon.json` có `database`: MySQL phải **đã tạo schema** đó trước; nếu chưa có, UI sẽ chặn bật addon.  
   - Sau đó chạy migration (toàn app; migrations addon được load khi provider đã register — xem Laravel docs về `loadMigrationsFrom`).

6. **Filament**  
   Đặt page tại `Filament/Pages`, resource tại `Filament/Resources`, view tại `resources/views` — panel sẽ auto-discover khi service active.  
   File **`Settings.php`** cùng namespace với provider (đổi tên class cuối thành `Settings`) với method `getDefaults()` để `SiteService` hydrate default `settings`.

7. **Đăng ký provider trong `config/app.php`?**  
   Không bắt buộc: provider được **đăng ký động** qua `addon_namespace` khi `is_active` (trong `AppServiceProvider`).

---

## Menu Filament (`/admin`)

Đường dẫn panel: **`/admin`**. Nhóm và nhãn sidebar (theo code hiện tại):

| Nhóm / vị trí | Mục menu | Ghi chú |
|---------------|----------|---------|
| *(mặc định / không nhóm)* | **Dashboard** | Trang chủ Filament. |
| *(không nhóm)* | **Site Management** | Thực tế là resource **Site** (`SiteResource` — CRUD site; nhãn navigation custom). |
| **Site Management** | **Activated Services** | `SiteServiceResource` — gán dịch vụ & settings cho từng site. |
| **Site Management** | **WP Headless** | Chỉ khi addon `wp-headless` **active**; URL: `/admin/wp-headless/manage`. Chỉ role **`admin`**. |
| **Hệ thống** | **Quản lý Service** | `ManageServices` — bật/tắt addon, sync `addon.json`; chỉ **`admin`**. |
| *(mặc định)* | **Users** | `UserResource` (hoặc nhãn plural của model) — query scoped theo role. |

**Trang Filament không hiện trên menu** (vẫn truy cập được khi biết URL / redirect):

- `/admin/wp-headless/connect` — flow kết nối WP (`WpHeadlessConnect`, CSRF có exception trong `bootstrap/app.php`).
- `/admin/wp-headless/site` — chi tiết/sync site headless (`WpHeadlessSitePage`, cần đăng nhập).

**Panel tách riêng:** SEO Content AI chạy panel riêng tại `/seo` (provider: `App\Addons\SeoContentAi\Providers\SeoPanelProvider`), không nằm trong navigation `/admin`.

---

## Tham chiếu nhanh file quan trọng

- `app/Services/AddonManager.php` — quét và sync `services`
- `app/Addons/RegistersAddonDatabase.php` — đăng ký DB addon
- `app/Providers/AppServiceProvider.php` — `register()` provider theo `is_active`
- `app/Providers/Filament/AdminPanelProvider.php` — discover Filament + views theo addon active
- `app/Filament/Pages/ManageServices.php` — UI kích hoạt addon
- `app/Addons/WpHeadless/WpHeadlessServiceProvider.php` — ví dụ đầy đủ: DB, routes web/API, commands, observers

---

*Framework: Laravel 12, PHP ^8.2, Filament ^3.2.*
