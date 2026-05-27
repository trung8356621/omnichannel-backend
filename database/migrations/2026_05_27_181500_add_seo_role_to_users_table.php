<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasColumn('users', 'seo_role')) {
            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->string('seo_role', 50)
                ->default('content_manager')
                ->after('role');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('users', 'seo_role')) {
            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('seo_role');
        });
    }
};

