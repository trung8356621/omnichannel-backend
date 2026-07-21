<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection('omi_seo_ai');

        if ($schema->hasTable('article_product_reviews')) {
            $schema->table('article_product_reviews', function (Blueprint $table) use ($schema): void {
                if (! $schema->hasColumn('article_product_reviews', 'synced_at')) {
                    $table->timestamp('synced_at')->nullable()->after('published_at');
                }
            });

            $map = [
                'draft' => 'pending',
                'pending_article' => 'pending',
                'pending_publish' => 'pending',
                'scheduled' => 'pending',
                'publishing' => 'syncing',
                'failed_dispatch' => 'failed',
            ];

            foreach ($map as $from => $to) {
                DB::connection('omi_seo_ai')
                    ->table('article_product_reviews')
                    ->where('status', $from)
                    ->update(['status' => $to]);
            }

            DB::connection('omi_seo_ai')
                ->table('article_product_reviews')
                ->where('status', 'published')
                ->update([
                    'status' => 'reviewed',
                    'synced_at' => DB::raw('COALESCE(synced_at, published_at, NOW())'),
                ]);
        }

        if ($schema->hasTable('automation_rules')) {
            $schema->table('automation_rules', function (Blueprint $table) use ($schema): void {
                if (! $schema->hasColumn('automation_rules', 'visibility')) {
                    $table->string('visibility', 16)->default('user')->after('classification');
                }
            });

            DB::connection('omi_seo_ai')
                ->table('automation_rules')
                ->where('classification', 'production')
                ->update(['classification' => 'business']);

            DB::connection('omi_seo_ai')
                ->table('automation_rules')
                ->whereIn('classification', ['experimental', 'manual-only'])
                ->update(['classification' => 'system', 'visibility' => 'admin']);
        }
    }

    public function down(): void
    {
        $schema = Schema::connection('omi_seo_ai');

        if ($schema->hasTable('article_product_reviews')
            && $schema->hasColumn('article_product_reviews', 'synced_at')
        ) {
            $schema->table('article_product_reviews', function (Blueprint $table): void {
                $table->dropColumn('synced_at');
            });
        }

        if ($schema->hasTable('automation_rules')
            && $schema->hasColumn('automation_rules', 'visibility')
        ) {
            $schema->table('automation_rules', function (Blueprint $table): void {
                $table->dropColumn('visibility');
            });
        }
    }
};
