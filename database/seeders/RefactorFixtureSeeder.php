<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Service;
use App\Models\SeoDatabaseConnection;
use App\Models\Site;
use App\Models\SiteMeta;
use App\Models\SiteService;
use App\Models\User;
use App\Services\AddonManager;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Compact integration fixture for Phase 2 refactor — not a production dump.
 */
final class RefactorFixtureSeeder extends Seeder
{
    public function run(): void
    {
        AddonManager::discover();

        $admin = User::query()->updateOrCreate(
            ['email' => 'admin@refactor.test'],
            [
                'name' => 'Refactor Admin',
                'password' => Hash::make('password'),
                'role' => User::ROLE_ADMIN,
            ],
        );

        $site = Site::query()->updateOrCreate(
            ['domain' => 'demo-wp.refactor.test'],
            [
                'user_id' => $admin->id,
                'status' => 'active',
            ],
        );

        if (Schema::hasTable('site_meta')) {
            SiteMeta::query()->updateOrCreate(
                ['site_id' => $site->id, 'meta_key' => 'primary_url'],
                ['meta_value' => 'https://demo-wp.refactor.test'],
            );
        }

        $seoService = Service::query()->where('slug', 'seo-content-ai')->first();
        if ($seoService !== null) {
            $seoService->is_active = true;
            $seoService->save();

            SiteService::query()->updateOrCreate(
                [
                    'site_id' => $site->id,
                    'service_id' => $seoService->id,
                ],
                [
                    'settings' => [
                        'READ_TOKEN' => 'refactor-read-token',
                        'WRITE_TOKEN' => 'refactor-write-token',
                        'WP_URL' => 'https://demo-wp.refactor.test',
                    ],
                    'status' => 'active',
                ],
            );
        }

        // Activate peer addon service rows when present (capability registration).
        foreach (config('addons.peer_slugs', []) as $slug) {
            Service::query()->where('slug', $slug)->update(['is_active' => true]);
        }

        if (Schema::hasTable('seo_database_connections')) {
            $connection = SeoDatabaseConnection::query()->updateOrCreate(
                ['database' => (string) config('seo-content-ai.legacy_shared_database', 'omi_seo_ai')],
                [
                    'name' => 'Refactor SEO DB',
                    'type' => 'auto',
                    'is_active' => true,
                    'hash_id' => Str::lower(Str::random(32)),
                    'host' => (string) config('database.connections.mysql.host', '127.0.0.1'),
                    'port' => (int) config('database.connections.mysql.port', 3306),
                    'username' => (string) config('database.connections.mysql.username', 'root'),
                ],
            );

            if (method_exists($connection, 'users')) {
                $connection->users()->syncWithoutDetaching([$admin->id]);
            }
            if (method_exists($connection, 'sites')) {
                $connection->sites()->syncWithoutDetaching([$site->id]);
            }
        }

        $this->seedSeoBusinessFixture((int) $admin->id, (int) $site->id);
    }

    private function seedSeoBusinessFixture(int $userId, int $siteId): void
    {
        $conn = (string) config('seo-content-ai.connection', 'omi_seo_ai');
        if (! $this->connectionReady($conn)) {
            $this->command?->warn('omi_seo_ai not ready — skipped SEO business fixture rows.');

            return;
        }

        $keywordId = 0;
        if (Schema::connection($conn)->hasTable('keywords')) {
            $keywordAttrs = [
                'updated_at' => now(),
            ];
            if (Schema::connection($conn)->hasColumn('keywords', 'type')) {
                $keywordAttrs['type'] = 'normal';
            }
            if (Schema::connection($conn)->hasColumn('keywords', 'user_id')) {
                $keywordAttrs['user_id'] = $userId;
            }
            if (Schema::connection($conn)->hasColumn('keywords', 'site_id')) {
                $keywordAttrs['site_id'] = $siteId;
            }
            $lookup = ['phrase' => 'refactor canonical keyword'];
            if (Schema::connection($conn)->hasColumn('keywords', 'site_id')) {
                $lookup['site_id'] = $siteId;
            }
            if (! DB::connection($conn)->table('keywords')->where($lookup)->exists()) {
                $keywordAttrs['created_at'] = now();
            }

            DB::connection($conn)->table('keywords')->updateOrInsert($lookup, $keywordAttrs);
            $keywordId = (int) DB::connection($conn)->table('keywords')->where($lookup)->value('id');

            if ($keywordId > 0 && Schema::connection($conn)->hasTable('keyword_meta')) {
                DB::connection($conn)->table('keyword_meta')->updateOrInsert(
                    ['keyword_id' => $keywordId, 'meta_key' => 'site_id'],
                    ['meta_value' => (string) $siteId, 'updated_at' => now(), 'created_at' => now()],
                );
            }
        }

        $articleId = 0;
        if (Schema::connection($conn)->hasTable('articles')) {
            $articleAttrs = [
                'user_id' => $userId,
                'title' => 'Refactor Demo Article',
                'updated_at' => now(),
            ];
            $bodyCol = Schema::connection($conn)->hasColumn('articles', 'body') ? 'body' : 'content';
            if (Schema::connection($conn)->hasColumn('articles', $bodyCol)) {
                $articleAttrs[$bodyCol] = '<p>Phase 2 fixture content.</p>';
            }
            if (Schema::connection($conn)->hasColumn('articles', 'status')) {
                $articleAttrs['status'] = 'draft';
            }
            if (! DB::connection($conn)->table('articles')->where('site_id', $siteId)->where('slug', 'refactor-demo-article')->exists()) {
                $articleAttrs['created_at'] = now();
            }

            DB::connection($conn)->table('articles')->updateOrInsert(
                ['site_id' => $siteId, 'slug' => 'refactor-demo-article'],
                $articleAttrs,
            );
            $articleId = (int) DB::connection($conn)->table('articles')
                ->where('site_id', $siteId)
                ->where('slug', 'refactor-demo-article')
                ->value('id');
        }

        // Product-shaped article (commerce content remains Content-owned).
        if (Schema::connection($conn)->hasTable('articles')) {
            $productAttrs = [
                'user_id' => $userId,
                'title' => 'Refactor Demo Product',
                'updated_at' => now(),
            ];
            $bodyCol = Schema::connection($conn)->hasColumn('articles', 'body') ? 'body' : 'content';
            if (Schema::connection($conn)->hasColumn('articles', $bodyCol)) {
                $productAttrs[$bodyCol] = '<p>Product fixture.</p>';
            }
            if (Schema::connection($conn)->hasColumn('articles', 'status')) {
                $productAttrs['status'] = 'draft';
            }
            if (Schema::connection($conn)->hasColumn('articles', 'type')) {
                $productAttrs['type'] = 'product';
            }
            if (! DB::connection($conn)->table('articles')->where('site_id', $siteId)->where('slug', 'refactor-demo-product')->exists()) {
                $productAttrs['created_at'] = now();
            }
            DB::connection($conn)->table('articles')->updateOrInsert(
                ['site_id' => $siteId, 'slug' => 'refactor-demo-product'],
                $productAttrs,
            );
        }

        if ($articleId > 0 && Schema::connection($conn)->hasTable('seo_media')) {
            $mediaLookup = ['path' => 'fixtures/featured-demo.jpg'];
            $mediaAttrs = ['updated_at' => now()];
            foreach ([
                'filename' => 'featured-demo.jpg',
                'slug' => 'featured-demo',
                'url' => '/storage/fixtures/featured-demo.jpg',
                'source' => 'local',
                'user_id' => $userId,
                'site_id' => $siteId,
                'disk' => 'public',
                'mime' => 'image/jpeg',
                'status' => 'ready',
            ] as $col => $val) {
                if (Schema::connection($conn)->hasColumn('seo_media', $col)) {
                    $mediaAttrs[$col] = $val;
                }
            }
            if (! DB::connection($conn)->table('seo_media')->where($mediaLookup)->exists()) {
                $mediaAttrs['created_at'] = now();
            }
            DB::connection($conn)->table('seo_media')->updateOrInsert($mediaLookup, $mediaAttrs);
        }

        if ($keywordId > 0 && Schema::connection($conn)->hasTable('seo_keywords') && Schema::connection($conn)->hasTable('seo_keyword_workspaces')) {
            DB::connection($conn)->table('seo_keyword_workspaces')->updateOrInsert(
                ['site_id' => $siteId, 'name' => 'Refactor Workspace'],
                [
                    'public_ref' => 'ref-ws-1',
                    'status' => 'active',
                    'keyword_count' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
            $workspaceId = (int) DB::connection($conn)->table('seo_keyword_workspaces')
                ->where('site_id', $siteId)
                ->where('name', 'Refactor Workspace')
                ->value('id');

            if ($workspaceId > 0) {
                $row = [
                    'public_ref' => 'ref-kw-1',
                    'workspace_id' => $workspaceId,
                    'keyword' => 'refactor canonical keyword',
                    'normalized_keyword' => 'refactor canonical keyword',
                    'source' => 'manual',
                    'search_volume' => 100,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
                if (Schema::connection($conn)->hasColumn('seo_keywords', 'keyword_id')) {
                    $row['keyword_id'] = $keywordId;
                }
                DB::connection($conn)->table('seo_keywords')->updateOrInsert(
                    ['workspace_id' => $workspaceId, 'normalized_keyword' => 'refactor canonical keyword'],
                    $row,
                );
            }
        }

        if (Schema::connection($conn)->hasTable('seo_projects')) {
            $projectAttrs = [
                'user_id' => $userId,
                'updated_at' => now(),
            ];
            if (Schema::connection($conn)->hasColumn('seo_projects', 'status')) {
                $projectAttrs['status'] = 'active';
            }
            if (Schema::connection($conn)->hasColumn('seo_projects', 'month')) {
                $projectAttrs['month'] = now()->startOfMonth()->toDateString();
            }
            if (! DB::connection($conn)->table('seo_projects')->where('site_id', $siteId)->where('name', 'Refactor Content Project')->exists()) {
                $projectAttrs['created_at'] = now();
            }
            DB::connection($conn)->table('seo_projects')->updateOrInsert(
                ['site_id' => $siteId, 'name' => 'Refactor Content Project'],
                $projectAttrs,
            );
        }

        if ($keywordId > 0 && Schema::connection($conn)->hasTable('keyword_rank_snapshots')) {
            DB::connection($conn)->table('keyword_rank_snapshots')->updateOrInsert(
                [
                    'site_id' => $siteId,
                    'keyword_id' => $keywordId,
                    'checked_at' => now(),
                ],
                [
                    'provider' => 'fixture',
                    'position' => 7,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
        }

        $this->command?->info("Refactor fixture ready: user={$userId} site={$siteId} article={$articleId} keyword={$keywordId}");
    }

    private function connectionReady(string $connection): bool
    {
        try {
            DB::connection($connection)->getPdo();

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
