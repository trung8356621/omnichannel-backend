<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Core\Database\DestructiveMigrationGuard;
use PHPUnit\Framework\TestCase;

final class DestructiveMigrationGuardTest extends TestCase
{
    private DestructiveMigrationGuard $guard;

    protected function setUp(): void
    {
        parent::setUp();
        $this->guard = new DestructiveMigrationGuard([
            'disposable_database_patterns' => ['*_test', '*_testing', 'test_*', 'phpunit_*', 'pest_*'],
            'protected_database_names' => [
                'omi_channel',
                'omi_seo_ai',
                'omi_channel_real',
                'omi_seo_ai_real',
            ],
            'protected_database_patterns' => ['*_real', '*_prod', '*_production', 'production', 'prod'],
        ]);
    }

    public function test_blocks_protected_omi_channel_even_with_confirm_flag(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(DestructiveMigrationGuard::BLOCK_PREFIX);

        $this->guard->assertMayDestroy(
            ['omi_channel', 'omi_seo_ai'],
            true,
            'local',
        );
    }

    public function test_allows_testing_env_for_disposable_names(): void
    {
        $names = $this->guard->assertMayDestroy(
            ['omi_channel_test', 'omi_seo_ai_test'],
            false,
            'testing',
        );

        self::assertSame(['omi_channel_test', 'omi_seo_ai_test'], $names);
    }

    public function test_allows_disposable_pattern_outside_testing(): void
    {
        $names = $this->guard->assertMayDestroy(
            ['omi_channel_test', 'omi_seo_ai_test'],
            false,
            'local',
        );

        self::assertSame(['omi_channel_test', 'omi_seo_ai_test'], $names);
    }

    public function test_blocks_real_names_without_flag(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(DestructiveMigrationGuard::BLOCK_PREFIX);

        $this->guard->assertMayDestroy(['omi_channel'], false, 'local');
    }

    public function test_is_protected_and_disposable_helpers(): void
    {
        self::assertTrue($this->guard->isProtected('omi_channel'));
        self::assertTrue($this->guard->isProtected('omi_seo_ai'));
        self::assertTrue($this->guard->isProtected('omi_channel_real'));
        self::assertFalse($this->guard->isDisposable('omi_channel'));
        self::assertTrue($this->guard->isDisposable('omi_channel_test'));
        self::assertFalse($this->guard->isDisposable('omi_seo_ai_real'));
    }
}
