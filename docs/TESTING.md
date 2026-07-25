# Testing — PHPUnit discovery & conventions

## Framework

Project dùng **PHPUnit 11** thuần (`phpunit/phpunit` trong `require-dev`).

Pest **chưa** được cài (`pestphp/pest` không có trong `composer.json`). `allow-plugins.pestphp/pest-plugin` chỉ là chỗ dành sẵn — không có nghĩa suite đang chạy Pest.

Không dùng `php artisan optimize:clear` để “sửa” lỗi **No tests found**. Đó là lỗi discovery/config, không phải cache config.

## Cấu trúc & convention

### Core

| Loại | Path | Namespace | Base class |
|------|------|-----------|------------|
| Unit (Laravel) | `tests/Unit/...SomethingTest.php` | `Tests\Unit\...` | `Tests\TestCase` |
| Unit (thuần) | `tests/Unit/...SomethingTest.php` | `Tests\Unit\...` | `PHPUnit\Framework\TestCase` |
| Feature | `tests/Feature/...SomethingTest.php` | `Tests\Feature\...` | `Tests\TestCase` |

### Addon SeoContentAi

| Loại | Path | Namespace |
|------|------|-----------|
| Unit | `app/Addons/SeoContentAi/tests/Unit/...Test.php` | `App\Addons\SeoContentAi\Tests\Unit\...` |
| Feature | `app/Addons/SeoContentAi/tests/Feature/...Test.php` | `App\Addons\SeoContentAi\Tests\Feature\...` |

Folder vật lý là `tests/` (chữ thường). Composer map trong **`autoload`** (không chỉ autoload-dev):

```json
"App\\Addons\\SeoContentAi\\Tests\\": "app/Addons/SeoContentAi/tests/"
```

để PSR-4 khớp trên Linux (case-sensitive), kể cả khi ai đó dump autoload trên server.

### Quy tắc bắt buộc

1. Tên file kết thúc bằng `Test.php`.
2. Tên class = tên file (không extension).
3. Namespace khớp đường dẫn theo map Composer (`autoload` / `autoload-dev`).
4. Một file = một class test (helper đặt `tests/Support` hoặc `.../tests/Support`, **không** dùng hậu tố `Test.php`).
5. Method: `test_...` / `testSomething` hoặc attribute `#[Test]` (`PHPUnit\Framework\Attributes\Test`).
6. Không đặt `*Test.php` ngoài directory đã khai báo trong `phpunit.xml`.

## phpunit.xml

Testsuites hiện tại:

- `tests/Unit`
- `tests/Feature`
- `app/Addons/SeoContentAi/tests/Unit`
- `app/Addons/SeoContentAi/tests/Feature`

Mỗi suite dùng `suffix="Test.php"`.

## Commands

```bash
# Audit discovery (ổn định nhất — không phụ thuộc Collision)
composer test:doctor
php scripts/test-doctor.php
# hoặc (khi đã deploy command):
php artisan test:doctor

# Toàn bộ suite qua Composer → PHPUnit trực tiếp
composer test
php scripts/run-phpunit.php

# Theo file (ổn định trên server)
php scripts/run-phpunit.php app/Addons/SeoContentAi/tests/Unit/ArticlePipelineRerunServiceTest.php
composer test -- app/Addons/SeoContentAi/tests/Unit/ArticlePipelineRerunServiceTest.php

# Khi require-dev đủ (có Collision):
php artisan test app/Addons/SeoContentAi/tests/Unit/ArticlePipelineRerunServiceTest.php
php artisan test --filter='App\\Addons\\SeoContentAi\\Tests\\Unit\\ArticlePipelineRerunServiceTest'

# CI: doctor rồi suite
COMPOSER_ALLOW_SUPERUSER=1 composer test:ci
```

**Không** chạy `php artisan test:ci` — đó không phải Artisan command. Dùng `composer test:ci`.

### Lỗi: `There are no commands defined in the "test" namespace`

Nguyên nhân thường gặp:

1. Server cài `composer install --no-dev` → thiếu `nunomaduro/collision` + `phpunit/phpunit` → không có `php artisan test` / `test:doctor`.
2. Chưa deploy `TestDoctorCommand` / `scripts/*` / `composer.json` mới.
3. Gõ nhầm `php artisan test:ci` thay vì `composer test:ci`.

Sửa trên server (root):

```bash
cd /path/to/app
COMPOSER_ALLOW_SUPERUSER=1 composer install
COMPOSER_ALLOW_SUPERUSER=1 composer dump-autoload
php scripts/test-doctor.php
php scripts/run-phpunit.php app/Addons/SeoContentAi/tests/Unit/ArticlePipelineRerunServiceTest.php
COMPOSER_ALLOW_SUPERUSER=1 composer test:ci
```

`--filter` theo **tên class/method PHPUnit**, không phải Pest description (project không dùng Pest).

Liệt kê test runner discover:

```bash
./vendor/bin/phpunit --list-tests
./vendor/bin/phpunit --list-tests-xml phpunit-list.xml
```

## Root cause lịch sử: “No tests found”

`phpunit.xml` trước đây **chỉ** khai báo `tests/Unit` + `tests/Feature`.

Hầu hết test SEO nằm ở `app/Addons/SeoContentAi/tests/`. Vì vậy:

- `php artisan test --filter=PromptExecutionOrchestrationTest` → **No tests found**
- `php artisan test app/Addons/SeoContentAi/tests/Unit/PromptExecutionOrchestrationTest.php` → chạy được (path tường minh)

Đã sửa bằng cách thêm suite SeoContentAi vào `phpunit.xml` + autoload-dev map + `test:doctor`.

## Phân loại lỗi

| Loại | Ý nghĩa | Hành động |
|------|---------|-----------|
| Discovery | File không vào suite / sai tên / sai namespace | `test:doctor` + sửa convention/config |
| Bootstrap | Class not found, autoload, syntax | `composer dump-autoload`, sửa file |
| Nghiệp vụ | Assertion fail / DB / skip | Sửa production hoặc fixture — **không** xóa/skip test để xanh giả |

## Thêm addon tests mới

1. Đặt dưới `app/Addons/{Addon}/tests/Unit|Feature/`.
2. Namespace `App\Addons\{Addon}\Tests\...`.
3. Thêm PSR-4 vào `composer.json` `autoload-dev` nếu chưa có.
4. Thêm `<directory suffix="Test.php">...</directory>` vào `phpunit.xml`.
5. `composer dump-autoload`
6. `php artisan test:doctor`
