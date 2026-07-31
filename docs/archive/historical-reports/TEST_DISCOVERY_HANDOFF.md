> Status: Historical
> Not canonical
> Archived on: 2026-08-01
> Superseded by: ../../operations/TESTING.md
> Purpose: implementation history only
# Test discovery handoff

## Root cause

`phpunit.xml` chá»‰ khai bÃ¡o `tests/Unit` + `tests/Feature`. ~237 test SEO á»Ÿ `app/Addons/SeoContentAi/tests/` **khÃ´ng** vÃ o default suite â†’ `php artisan test --filter=ClassName` bÃ¡o **No tests found**. Cháº¡y theo path file thÃ¬ OK.

ThÃªm: namespace `App\Addons\SeoContentAi\Tests\*` vs folder `tests/` (lowercase) â€” cáº§n PSR-4 map riÃªng trÃªn Linux.

## Fix shipped

1. `phpunit.xml` â€” suites `SeoContentAiUnit` + `SeoContentAiFeature` (`suffix="Test.php"`).
2. `composer.json` `autoload-dev` â€” `App\Addons\SeoContentAi\Tests\` â†’ `app/Addons/SeoContentAi/tests/`.
3. `php artisan test:doctor` + `App\Services\Testing\TestDiscoveryAuditService`.
4. Composer scripts: `test:doctor`, `test:ci`.
5. Docs: `docs/TESTING.md`.

## KhÃ´ng lÃ m

- KhÃ´ng Ä‘á»•i sang Pest (chÆ°a cÃ i).
- KhÃ´ng dÃ¹ng `optimize:clear` lÃ m â€œfixâ€ discovery.
- KhÃ´ng xÃ³a/skip test nghiá»‡p vá»¥ Ä‘á»ƒ xanh giáº£.

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

TrÃªn server root: `COMPOSER_ALLOW_SUPERUSER=1 composer install` (khÃ´ng `--no-dev`). KhÃ´ng gÃµ `php artisan test:ci`.
