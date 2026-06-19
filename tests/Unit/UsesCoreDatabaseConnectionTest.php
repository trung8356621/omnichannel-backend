<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Site;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

final class UsesCoreDatabaseConnectionTest extends TestCase
{
    public function test_site_model_uses_core_connection_not_default(): void
    {
        Config::set('database.default', 'omi_seo_ai');
        Config::set('database.core_connection', 'mysql');

        $this->assertSame('mysql', (new Site)->getConnectionName());
    }
}
