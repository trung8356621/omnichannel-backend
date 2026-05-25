<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    protected $connection = 'omi_seo_ai';

    public function up(): void
    {
        if (Schema::connection($this->connection)->hasTable('seo_media')
            && ! Schema::connection($this->connection)->hasColumn('seo_media', 'ai_generator')) {
            Schema::connection($this->connection)->table('seo_media', function (Blueprint $table) {
                $table->string('ai_generator', 120)->nullable()->after('source')->index();
            });
        }

        if (! Schema::connection($this->connection)->hasTable('seo_generated_images')
            || ! Schema::connection($this->connection)->hasTable('seo_media')) {
            return;
        }

        DB::connection($this->connection)
            ->table('seo_generated_images')
            ->orderBy('id')
            ->chunkById(200, function ($rows): void {
                foreach ($rows as $row) {
                    $url = (string) ($row->url ?? '');
                    if ($url === '') {
                        continue;
                    }

                    $slug = trim((string) ($row->slug ?? ''));
                    if ($slug === '') {
                        $slug = 'legacy-ai-' . (int) $row->id;
                    }

                    $filename = basename((string) parse_url($url, PHP_URL_PATH));
                    if ($filename === '' || ! str_contains($filename, '.')) {
                        $filename = $slug . '.png';
                    }

                    $exists = DB::connection($this->connection)
                        ->table('seo_media')
                        ->where('site_id', (int) $row->site_id)
                        ->where('slug', $slug)
                        ->where('url', $url)
                        ->exists();

                    if ($exists) {
                        continue;
                    }

                    DB::connection($this->connection)
                        ->table('seo_media')
                        ->insert([
                            'site_id' => (int) $row->site_id > 0 ? (int) $row->site_id : null,
                            'article_id' => isset($row->article_id) ? (int) $row->article_id : null,
                            'filename' => Str::limit($filename, 255, ''),
                            'slug' => Str::limit($slug, 255, ''),
                            'path' => '',
                            'url' => $url,
                            'source' => 'ai_prompt',
                            'ai_generator' => (string) ($row->source ?? 'legacy_ai'),
                            'wp_attachment_id' => isset($row->wp_attachment_id) ? (int) $row->wp_attachment_id : null,
                            'created_at' => $row->created_at ?? now(),
                            'updated_at' => $row->updated_at ?? now(),
                        ]);
                }
            });

        Schema::connection($this->connection)->dropIfExists('seo_generated_images');
    }

    public function down(): void
    {
        if (! Schema::connection($this->connection)->hasTable('seo_generated_images')) {
            Schema::connection($this->connection)->create('seo_generated_images', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('site_id')->index();
                $table->foreignId('article_id')
                    ->nullable()
                    ->constrained('articles')
                    ->nullOnDelete();
                $table->string('slug', 255)->index();
                $table->text('url');
                $table->string('alt', 500)->nullable();
                $table->string('title', 500)->nullable();
                $table->string('source', 64)->default('ai');
                $table->unsignedBigInteger('wp_attachment_id')->nullable()->index();
                $table->timestamps();
            });
        }

        if (Schema::connection($this->connection)->hasTable('seo_media')
            && Schema::connection($this->connection)->hasColumn('seo_media', 'ai_generator')) {
            Schema::connection($this->connection)->table('seo_media', function (Blueprint $table) {
                $table->dropColumn('ai_generator');
            });
        }
    }
};

