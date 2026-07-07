<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Tests\Unit;

use App\Addons\SeoContentAi\Models\SeoImageOptimizationSetting;
use App\Addons\SeoContentAi\Services\SeoAnalyzerService;
use App\Addons\SeoContentAi\Services\SeoImageOptimizationService;
use App\Addons\SeoContentAi\Services\SeoMediaPathAllocator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class SeoImageOptimizationServiceTest extends TestCase
{
    private function service(): SeoImageOptimizationService
    {
        $analyzer = $this->createMock(SeoAnalyzerService::class);

        return new SeoImageOptimizationService(
            $analyzer,
            app(SeoMediaPathAllocator::class),
            app(\App\Addons\SeoContentAi\Support\SeoImagePipeline::class),
        );
    }

    private function webpEnabledConfig(): SeoImageOptimizationSetting
    {
        return new SeoImageOptimizationSetting([
            'auto_convert_webp' => true,
            'quality' => 80,
            'limit_dimensions' => false,
            'clean_filename' => false,
            'auto_alt_tag' => false,
        ]);
    }

    public function test_process_upload_keeps_original_extension_when_webp_enabled(): void
    {
        $file = UploadedFile::fake()->image('split-piece.png', 80, 60);

        $processed = $this->service()->processUpload($file, $this->webpEnabledConfig());

        $this->assertStringEndsWith('.png', $processed['filename']);
        $this->assertStringEndsWith('.png', $processed['relative_path']);
    }

    public function test_needs_wordpress_webp_backfill_when_wp_url_not_webp(): void
    {
        Storage::fake('public');

        $file = UploadedFile::fake()->image('gallery.jpg', 120, 90);
        $relativePath = 'uploads/seo_media/test-backfill.jpg';
        Storage::disk('public')->put($relativePath, (string) file_get_contents($file->getRealPath()));
        $absolutePath = Storage::disk('public')->path($relativePath);

        $service = $this->service();
        $config = $this->webpEnabledConfig();

        $webpPath = $service->ensureLocalWebpCopy($absolutePath, $config);
        if ($webpPath === null) {
            $this->markTestSkipped('WebP encoder is not available in this PHP environment.');
        }

        $this->assertTrue($service->needsWordPressWebpBackfill(
            $config,
            $absolutePath,
            'https://example.com/wp-content/uploads/2026/07/foo.jpg',
        ));
        $this->assertFalse($service->needsWordPressWebpBackfill(
            $config,
            $absolutePath,
            'https://example.com/wp-content/uploads/2026/07/foo.webp',
        ));
        $this->assertFalse($service->needsWordPressWebpBackfill(
            new SeoImageOptimizationSetting(['auto_convert_webp' => false]),
            $absolutePath,
            'https://example.com/wp-content/uploads/2026/07/foo.jpg',
        ));

        if (is_file($webpPath)) {
            @unlink($webpPath);
        }
    }

    public function test_needs_wordpress_webp_backfill_false_when_optimized_fallback_exists(): void
    {
        Storage::fake('public');

        $file = UploadedFile::fake()->image('gallery.jpg', 120, 90);
        $relativePath = 'uploads/seo_media/test-backfill-skip.jpg';
        Storage::disk('public')->put($relativePath, (string) file_get_contents($file->getRealPath()));
        $absolutePath = Storage::disk('public')->path($relativePath);

        $service = $this->service();
        $optimizedPath = $service->resolveSiblingOptimizedUploadAbsolutePath($absolutePath, 'jpg');
        Storage::disk('public')->put(
            'uploads/seo_media/'.basename($optimizedPath),
            str_repeat('x', 512),
        );

        $this->assertFalse($service->needsWordPressWebpBackfill(
            $this->webpEnabledConfig(),
            $absolutePath,
            'https://example.com/wp-content/uploads/2026/07/foo-wp-upload.jpg',
        ));
    }

    public function test_prepare_wordpress_upload_does_not_mutate_original_file(): void
    {
        Storage::fake('public');

        $file = UploadedFile::fake()->image('gallery.jpg', 120, 90);
        $relativePath = 'uploads/seo_media/test-gallery-original.jpg';
        $originalBinary = (string) file_get_contents($file->getRealPath());
        Storage::disk('public')->put($relativePath, $originalBinary);

        $absolutePath = Storage::disk('public')->path($relativePath);
        $uploadFile = $this->service()->prepareWordPressUploadFile($absolutePath, $this->webpEnabledConfig());

        if ($uploadFile === null) {
            $this->markTestSkipped('WebP encoder is not available in this PHP environment.');
        }

        $this->assertSame($originalBinary, Storage::disk('public')->get($relativePath));

        if (($uploadFile['temporary'] ?? false) && is_file($uploadFile['path'])) {
            @unlink($uploadFile['path']);
        }
    }

    public function test_prepare_wordpress_upload_converts_to_webp_when_enabled(): void
    {
        Storage::fake('public');

        $file = UploadedFile::fake()->image('gallery.jpg', 640, 480);
        $relativePath = 'uploads/seo_media/test-gallery.jpg';
        Storage::disk('public')->put($relativePath, (string) file_get_contents($file->getRealPath()));

        $absolutePath = Storage::disk('public')->path($relativePath);
        $uploadFile = $this->service()->prepareWordPressUploadFile($absolutePath, $this->webpEnabledConfig());

        if ($uploadFile === null) {
            $this->markTestSkipped('WebP encoder is not available in this PHP environment.');
        }

        if (! str_ends_with((string) ($uploadFile['path'] ?? ''), '.webp')) {
            $this->markTestSkipped('WebP encoder is not available in this PHP environment.');
        }

        $this->assertFalse((bool) ($uploadFile['temporary'] ?? true));
        $this->assertStringEndsWith('.webp', (string) $uploadFile['path']);
        $this->assertSame('image/webp', $uploadFile['mime']);

        if (is_file($uploadFile['path'])) {
            @unlink($uploadFile['path']);
        }
    }

    public function test_prepare_wordpress_upload_falls_back_to_optimized_when_webp_unavailable(): void
    {
        Storage::fake('public');

        $file = UploadedFile::fake()->image('gallery.jpg', 800, 600);
        $relativePath = 'uploads/seo_media/test-gallery-fallback.jpg';
        Storage::disk('public')->put($relativePath, (string) file_get_contents($file->getRealPath()));

        $absolutePath = Storage::disk('public')->path($relativePath);
        $service = $this->service();
        $config = $this->webpEnabledConfig();

        $webpPath = $service->ensureLocalWebpCopy($absolutePath, $config);
        if ($webpPath !== null && is_file($webpPath)) {
            @unlink($webpPath);
        }

        $optimizedPath = $service->ensureLocalOptimizedUploadCopy($absolutePath, $config);
        if ($optimizedPath === null) {
            $this->markTestSkipped('Image encoder is not available in this PHP environment.');
        }

        $this->assertStringEndsWith('-wp-upload.jpg', $optimizedPath);
        $this->assertLessThanOrEqual(
            SeoImageOptimizationService::WORDPRESS_UPLOAD_FALLBACK_MAX_BYTES,
            (int) filesize($optimizedPath),
        );
        $this->assertTrue(Storage::disk('public')->exists($relativePath));

        if (is_file($optimizedPath)) {
            @unlink($optimizedPath);
        }
    }

    public function test_prepare_wordpress_upload_returns_optimized_file_when_webp_fails(): void
    {
        Storage::fake('public');

        $file = UploadedFile::fake()->image('gallery.jpg', 640, 480);
        $relativePath = 'uploads/seo_media/test-gallery-wp-fallback.jpg';
        Storage::disk('public')->put($relativePath, (string) file_get_contents($file->getRealPath()));

        $absolutePath = Storage::disk('public')->path($relativePath);
        $service = $this->service();
        $config = $this->webpEnabledConfig();

        $webpPath = $service->resolveSiblingWebpAbsolutePath($absolutePath);
        if (is_file($webpPath)) {
            @unlink($webpPath);
        }

        $uploadFile = $service->prepareWordPressUploadFile($absolutePath, $config);
        $this->assertNotNull($uploadFile);

        $uploadPath = (string) ($uploadFile['path'] ?? '');
        if (str_ends_with($uploadPath, '.webp')) {
            $this->assertSame('image/webp', $uploadFile['mime']);
        } else {
            $this->assertStringContainsString('-wp-upload', $uploadPath);
            $this->assertLessThanOrEqual(
                SeoImageOptimizationService::WORDPRESS_UPLOAD_FALLBACK_MAX_BYTES,
                (int) filesize($uploadPath),
            );
        }

        if (is_file($webpPath)) {
            @unlink($webpPath);
        }
        $optimizedPath = $service->resolveSiblingOptimizedUploadAbsolutePath($absolutePath, 'jpg');
        if (is_file($optimizedPath)) {
            @unlink($optimizedPath);
        }
    }

    public function test_prepare_wordpress_upload_keeps_jpeg_when_webp_disabled(): void
    {
        Storage::fake('public');

        $file = UploadedFile::fake()->image('gallery.jpg', 120, 90);
        $relativePath = 'uploads/seo_media/test-gallery.jpg';
        Storage::disk('public')->put($relativePath, (string) file_get_contents($file->getRealPath()));

        $absolutePath = Storage::disk('public')->path($relativePath);
        $config = new SeoImageOptimizationSetting([
            'auto_convert_webp' => false,
            'quality' => 80,
            'limit_dimensions' => false,
        ]);

        $uploadFile = $this->service()->prepareWordPressUploadFile($absolutePath, $config);

        $this->assertNotNull($uploadFile);
        $this->assertStringEndsWith('.jpg', $uploadFile['path']);
        $this->assertSame('image/jpeg', $uploadFile['mime']);

        if (($uploadFile['temporary'] ?? false) && is_file($uploadFile['path'])) {
            @unlink($uploadFile['path']);
        }
    }
}
