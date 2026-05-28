<?php

use App\Addons\SeoContentAi\Models\ArticleMeta;
use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Models\SeoMedia;
use App\Addons\SeoContentAi\Services\SeoMediaPathAllocator;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('seo:media-flatten-paths {--dry-run}', function () {
    $dryRun = (bool) $this->option('dry-run');
    $disk = Storage::disk('public');
    $allocator = app(SeoMediaPathAllocator::class);

    $updated = 0;
    $missing = 0;
    $skipped = 0;
    $replacements = [];

    SeoMedia::query()
        ->where('path', 'like', 'uploads/seo_media/%/%')
        ->orderBy('id')
        ->chunkById(200, function ($rows) use (
            $dryRun,
            $disk,
            $allocator,
            &$updated,
            &$missing,
            &$skipped,
            &$replacements,
        ): void {
            foreach ($rows as $media) {
                $oldPath = ltrim(str_replace('\\', '/', (string) ($media->path ?? '')), '/');
                if ($oldPath === '' || ! preg_match('#^uploads/seo_media/[^/]+/.+#', $oldPath)) {
                    $skipped++;
                    continue;
                }
                $extension = strtolower((string) pathinfo($oldPath, PATHINFO_EXTENSION));
                if ($extension === 'jpeg') {
                    $extension = 'jpg';
                }
                if ($extension === '') {
                    $extension = 'jpg';
                }

                $slugSeed = trim((string) ($media->slug ?? ''));
                if ($slugSeed === '') {
                    $slugSeed = (string) pathinfo($oldPath, PATHINFO_FILENAME);
                }

                $allocated = $allocator->allocate($slugSeed, $extension, $oldPath);
                $newPath = (string) ($allocated['relative_path'] ?? '');
                if ($newPath === '' || $newPath === $oldPath) {
                    $skipped++;
                    continue;
                }

                if (! $disk->exists($oldPath)) {
                    $missing++;
                    continue;
                }

                $oldUrl = '/storage/' . $oldPath;
                $newUrl = '/storage/' . $newPath;

                if (! $dryRun) {
                    $disk->move($oldPath, $newPath);
                    $media->update([
                        'slug' => (string) ($allocated['slug'] ?? $slugSeed),
                        'filename' => (string) ($allocated['filename'] ?? basename($newPath)),
                        'path' => $newPath,
                        'url' => $newUrl,
                    ]);
                }

                $replacements[$oldUrl] = $newUrl;
                $updated++;
            }
        });

    $articleReplaced = 0;
    $metaReplaced = 0;

    foreach ($replacements as $oldUrl => $newUrl) {
        SeoArticle::query()
            ->where('body', 'like', '%' . $oldUrl . '%')
            ->orderBy('id')
            ->chunkById(100, function ($articles) use ($oldUrl, $newUrl, $dryRun, &$articleReplaced): void {
                foreach ($articles as $article) {
                    $body = (string) ($article->body ?? '');
                    $next = str_replace($oldUrl, $newUrl, $body);
                    if ($next === $body) {
                        continue;
                    }

                    if (! $dryRun) {
                        $article->update(['body' => $next]);
                    }
                    $articleReplaced++;
                }
            });

        ArticleMeta::query()
            ->where('meta_value', 'like', '%' . $oldUrl . '%')
            ->orderBy('id')
            ->chunkById(100, function ($metas) use ($oldUrl, $newUrl, $dryRun, &$metaReplaced): void {
                foreach ($metas as $meta) {
                    $value = (string) ($meta->meta_value ?? '');
                    $next = str_replace($oldUrl, $newUrl, $value);
                    if ($next === $value) {
                        continue;
                    }

                    if (! $dryRun) {
                        $meta->update(['meta_value' => $next]);
                    }
                    $metaReplaced++;
                }
            });
    }

    $deletedDirs = 0;
    foreach ($disk->directories(SeoMediaPathAllocator::BASE_DIR) as $dir) {
        $normalized = ltrim(str_replace('\\', '/', (string) $dir), '/');
        if ($normalized === SeoMediaPathAllocator::BASE_DIR . '/wp-staging') {
            continue;
        }
        if (str_starts_with($normalized, SeoMediaPathAllocator::BASE_DIR . '/wp-staging/')) {
            continue;
        }

        $hasFiles = $disk->files($normalized) !== [];
        $hasDirs = $disk->directories($normalized) !== [];
        if ($hasFiles || $hasDirs) {
            continue;
        }

        if (! $dryRun) {
            $disk->deleteDirectory($normalized);
        }
        $deletedDirs++;
    }

    $this->info(($dryRun ? '[DRY RUN] ' : '') . 'Flatten seo_media completed.');
    $this->line("Moved media rows: {$updated}");
    $this->line("Missing source files: {$missing}");
    $this->line("Skipped rows: {$skipped}");
    $this->line("Article body replacements: {$articleReplaced}");
    $this->line("Article meta replacements: {$metaReplaced}");
    $this->line("Deleted empty dirs: {$deletedDirs}");
})->purpose('Flatten seo_media paths and sync DB URLs');
