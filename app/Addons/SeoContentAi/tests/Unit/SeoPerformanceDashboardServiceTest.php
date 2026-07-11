<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Tests\Unit;

use App\Addons\SeoContentAi\Services\SeoPerformanceDashboardService;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class SeoPerformanceDashboardServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.connections.mysql' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
            ],
        ]);

        DB::purge('mysql');
        $this->ensureTables();

        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin-dashboard@test.local',
            'password' => 'secret',
            'role' => 'admin',
        ]);
        $this->actingAs($user);
    }

    public function test_build_gsc_state_does_not_include_rank_visibility_metrics(): void
    {
        $state = app(SeoPerformanceDashboardService::class)->buildGscState(null);

        $this->assertArrayHasKey('total_clicks', $state['kpis']);
        $this->assertArrayNotHasKey('visibility', $state['kpis']);
        $this->assertArrayNotHasKey('search_volume', $state['kpis']);
    }

    public function test_build_rank_state_does_not_include_gsc_queries(): void
    {
        $state = app(SeoPerformanceDashboardService::class)->buildRankState(null);

        $this->assertArrayHasKey('visibility', $state['kpis']);
        $this->assertArrayNotHasKey('queries', $state);
        $this->assertArrayNotHasKey('quick_wins', $state);
    }

    public function test_resolve_default_data_source_returns_gsc_when_no_providers(): void
    {
        $source = app(SeoPerformanceDashboardService::class)->resolveDefaultDataSource(null);

        $this->assertSame('gsc', $source);
    }

    private function ensureTables(): void
    {
        Schema::connection('mysql')->dropIfExists('users');
        Schema::connection('mysql')->dropIfExists('seo_dataforseo_connections');
        Schema::connection('mysql')->dropIfExists('seo_gsc_property_mappings');
        Schema::connection('mysql')->dropIfExists('seo_gsc_master_connections');

        Schema::connection('mysql')->create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->string('email')->unique();
            $table->string('password')->nullable();
            $table->string('role')->default('admin');
            $table->timestamps();
        });

        Schema::connection('mysql')->create('seo_gsc_master_connections', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('name');
            $table->string('status')->default('not_configured');
            $table->text('credentials')->nullable();
            $table->string('account_email')->nullable();
            $table->json('metadata')->nullable();
            $table->boolean('is_global')->default(false);
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });

        Schema::connection('mysql')->create('seo_gsc_property_mappings', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('gsc_connection_id')->index();
            $table->unsignedBigInteger('site_id')->index();
            $table->string('property_url');
            $table->string('property_type')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
        });

        Schema::connection('mysql')->create('seo_dataforseo_connections', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('login');
            $table->text('password');
            $table->string('default_location')->nullable();
            $table->string('default_language')->nullable();
            $table->decimal('balance', 12, 4)->nullable();
            $table->string('status')->default('not_configured');
            $table->boolean('is_global')->default(false);
            $table->json('metadata')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });
    }
}
