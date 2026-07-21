<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection('omi_seo_ai');
        if (! $schema->hasTable('automation_rules')) {
            return;
        }

        $schema->table('automation_rules', function (Blueprint $table) use ($schema): void {
            if (! $schema->hasColumn('automation_rules', 'classification')) {
                $table->string('classification', 32)->default('production')->after('code');
            }
        });
    }

    public function down(): void
    {
        $schema = Schema::connection('omi_seo_ai');
        if (! $schema->hasTable('automation_rules')) {
            return;
        }

        $schema->table('automation_rules', function (Blueprint $table) use ($schema): void {
            if ($schema->hasColumn('automation_rules', 'classification')) {
                $table->dropColumn('classification');
            }
        });
    }
};
