# Test discovery handoff

## Root cause

`phpunit.xml` chỉ khai báo `tests/Unit` + `tests/Feature`. ~237 test SEO ở `app/Addons/SeoContentAi/tests/` **không** vào default suite → `php artisan test --filter=ClassName` báo **No tests found**. Chạy theo path file thì OK.

Thêm: namespace `App\Addons\SeoContentAi\Tests\*` vs folder `tests/` (lowercase) — cần PSR-4 map riêng trên Linux.

## Fix shipped

1. `phpunit.xml` — suites `SeoContentAiUnit` + `SeoContentAiFeature` (`suffix="Test.php"`).
2. `composer.json` `autoload-dev` — `App\Addons\SeoContentAi\Tests\` → `app/Addons/SeoContentAi/tests/`.
3. `php artisan test:doctor` + `App\Services\Testing\TestDiscoveryAuditService`.
4. Composer scripts: `test:doctor`, `test:ci`.
5. Docs: `docs/TESTING.md`.

## Không làm

- Không đổi sang Pest (chưa cài).
- Không dùng `optimize:clear` làm “fix” discovery.
- Không xóa/skip test nghiệp vụ để xanh giả.

## Verify

```text
composer dump-autoload
php artisan test:doctor
./vendor/bin/phpunit --list-tests
php artisan test app/Addons/SeoContentAi/tests/Unit/PromptExecutionOrchestrationTest.php
php artisan test app/Addons/SeoContentAi/tests/Unit/ArticlePipelineRerunServiceTest.php
composer test:ci
```

`composer test:ci` = `disableProcessTimeout` + `scripts/test-doctor.php` + `scripts/run-phpunit.php`.

Trên server root: `COMPOSER_ALLOW_SUPERUSER=1 composer install` (không `--no-dev`). Không gõ `php artisan test:ci`.
