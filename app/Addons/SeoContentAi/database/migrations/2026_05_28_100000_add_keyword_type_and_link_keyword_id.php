<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'omi_seo_ai';

    public function up(): void
    {
        Schema::connection($this->connection)->table('keywords', function (Blueprint $table) {
            $table->string('type', 50)
                ->default('focus')
                ->comment('focus: Từ khóa SEO, internal: Anchor text internal link')
                ->after('phrase');
            $table->text('target_url')
                ->nullable()
                ->comment('URL đích mặc định nếu là internal link')
                ->after('difficulty');
            $table->index(['site_id', 'type']);
        });

        Schema::connection($this->connection)->table('article_keyword', function (Blueprint $table) {
            $table->boolean('is_main')
                ->default(false)
                ->after('weight')
                ->comment('Từ khóa chính của bài viết');
        });

        Schema::connection($this->connection)->table('seo_article_links', function (Blueprint $table) {
            $table->unsignedBigInteger('keyword_id')
                ->nullable()
                ->index()
                ->after('article_id');

            $table->foreign('keyword_id')
                ->references('id')
                ->on('keywords')
                ->nullOnDelete();
        });

        DB::connection($this->connection)->table('keywords')->update(['type' => 'focus']);

        DB::connection($this->connection)->table('article_keyword')
            ->where(function ($query): void {
                $query->where('weight', '>=', 1)
                    ->orWhereNull('weight');
            })
            ->update(['is_main' => true]);
    }

    public function down(): void
    {
        Schema::connection($this->connection)->table('seo_article_links', function (Blueprint $table) {
            $table->dropForeign(['keyword_id']);
            $table->dropColumn('keyword_id');
        });

        Schema::connection($this->connection)->table('article_keyword', function (Blueprint $table) {
            $table->dropColumn('is_main');
        });

        Schema::connection($this->connection)->table('keywords', function (Blueprint $table) {
            $table->dropIndex(['site_id', 'type']);
            $table->dropColumn(['type', 'target_url']);
        });
    }
};
