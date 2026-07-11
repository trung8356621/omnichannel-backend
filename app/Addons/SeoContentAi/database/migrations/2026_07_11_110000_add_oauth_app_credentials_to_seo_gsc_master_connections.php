<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'mysql';

    public function up(): void
    {
        Schema::connection($this->connection)->table('seo_gsc_master_connections', function (Blueprint $table): void {
            $table->string('oauth_client_id')->nullable()->after('account_email');
            $table->text('oauth_client_secret')->nullable()->after('oauth_client_id');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->table('seo_gsc_master_connections', function (Blueprint $table): void {
            $table->dropColumn(['oauth_client_id', 'oauth_client_secret']);
        });
    }
};
