<?php

declare(strict_types=1);

use Omnichannel\Addons\AiPrompt\Support\ApiConnectionProviders;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'mysql';

    public function up(): void
    {
        if (! Schema::connection($this->connection)->hasColumn('api_connections', 'connection_type')) {
            Schema::connection($this->connection)->table('api_connections', function (Blueprint $table): void {
                $table->string('connection_type', 8)->default('ai')->after('provider');
                $table->index('connection_type');
            });
        }

        $this->backfillConnectionTypes();
    }

    public function down(): void
    {
        if (Schema::connection($this->connection)->hasColumn('api_connections', 'connection_type')) {
            Schema::connection($this->connection)->table('api_connections', function (Blueprint $table): void {
                $table->dropIndex(['connection_type']);
                $table->dropColumn('connection_type');
            });
        }
    }

    private function backfillConnectionTypes(): void
    {
        $aiProviders = [ApiConnectionProviders::GEMINI, ApiConnectionProviders::CLAUDE];

        DB::connection($this->connection)
            ->table('api_connections')
            ->whereIn('provider', $aiProviders)
            ->update(['connection_type' => 'ai']);

        DB::connection($this->connection)
            ->table('api_connections')
            ->whereNotIn('provider', $aiProviders)
            ->update(['connection_type' => 'seo']);
    }
};
