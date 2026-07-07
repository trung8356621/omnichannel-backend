<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\ExternalPlugin\WordPressPluginUploadPathResolver;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class WordPressPluginUploadPathResolverTest extends TestCase
{
    public function test_resolves_relative_path_on_local_disk(): void
    {
        Storage::fake('local');

        $relative = 'tmp/wp-plugin-uploads/omi-seo-ai-bridge-1.0.48.zip';
        Storage::disk('local')->put($relative, 'zip-binary');

        $absolute = app(WordPressPluginUploadPathResolver::class)->resolve($relative);

        $this->assertNotNull($absolute);
        $this->assertTrue(is_file($absolute));
        $this->assertSame('zip-binary', file_get_contents($absolute));
    }

    public function test_resolves_first_item_from_upload_array(): void
    {
        Storage::fake('local');

        $relative = 'tmp/wp-plugin-uploads/plugin.zip';
        Storage::disk('local')->put($relative, 'zip');

        $absolute = app(WordPressPluginUploadPathResolver::class)->resolve([$relative]);

        $this->assertNotNull($absolute);
        $this->assertTrue(is_file($absolute));
    }

    public function test_resolves_livewire_tmp_basename_fallback(): void
    {
        Storage::fake('local');

        $basename = 'uploaded-plugin.zip';
        Storage::disk('local')->put('livewire-tmp/'.$basename, 'zip');

        $absolute = app(WordPressPluginUploadPathResolver::class)->resolve([$basename]);

        $this->assertNotNull($absolute);
        $this->assertTrue(is_file($absolute));
    }

    public function test_resolves_basename_by_scanning_upload_directory(): void
    {
        Storage::fake('local');

        $basename = 'omi-seo-ai-bridge-1.0.49.zip';
        Storage::disk('local')->put('tmp/wp-plugin-uploads/'.$basename, 'zip');

        $absolute = app(WordPressPluginUploadPathResolver::class)->resolve($basename);

        $this->assertNotNull($absolute);
        $this->assertTrue(is_file($absolute));
    }

    public function test_resolves_path_from_associative_array_key(): void
    {
        Storage::fake('local');

        $relative = 'tmp/wp-plugin-uploads/omi-seo-ai-bridge-1.0.48.zip';
        Storage::disk('local')->put($relative, 'zip-binary');

        $absolute = app(WordPressPluginUploadPathResolver::class)->resolve([
            $relative => 'omi-seo-ai-bridge-1.0.48.zip',
        ]);

        $this->assertNotNull($absolute);
        $this->assertTrue(is_file($absolute));
    }

    public function test_resolves_legacy_livewire_tmp_outside_private_disk_root(): void
    {
        $basename = 'legacy-upload.zip';
        $legacyDir = storage_path('app/livewire-tmp');
        if (! is_dir($legacyDir)) {
            mkdir($legacyDir, 0755, true);
        }

        $legacyPath = $legacyDir.DIRECTORY_SEPARATOR.$basename;
        file_put_contents($legacyPath, 'zip');

        try {
            $absolute = app(WordPressPluginUploadPathResolver::class)->resolve($basename);

            $this->assertNotNull($absolute);
            $this->assertTrue(is_file($absolute));
        } finally {
            if (is_file($legacyPath)) {
                unlink($legacyPath);
            }
        }
    }
}
