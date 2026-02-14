<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('frontend_projects', function (Blueprint $table) {
            $table->string('router', 128)->nullable()->after('package_json_path')
                ->comment('Path dùng để proxy Laravel → Next.js (vd: wp-headless). URL: /frontend/{router}');
            $table->boolean('proxy_auto')->default(true)->after('router')
                ->comment('true = bật ép proxy tự động; false = tắt khi proxy thủ công');
            $table->unsignedSmallInteger('port')->nullable()->default(3000)->after('proxy_auto')
                ->comment('Port Next.js (tự nhận / cấu hình, mặc định 3000)');
        });
    }

    public function down(): void
    {
        Schema::table('frontend_projects', function (Blueprint $table) {
            $table->dropColumn(['router', 'proxy_auto', 'port']);
        });
    }
};
