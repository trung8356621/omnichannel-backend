<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Jobs;

use App\Addons\SeoContentAi\Exceptions\PromptRunException;
use App\Addons\SeoContentAi\Models\SeoMedia;
use App\Addons\SeoContentAi\Models\SeoPrompt;
use App\Addons\SeoContentAi\Services\PromptMediaStorageService;
use App\Addons\SeoContentAi\Services\PromptRunnerService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Str;
use Throwable;

class GenerateMediaJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 240;

    public int $tries = 1;

    public bool $failOnTimeout = true;

    /**
     * @param  array<string, string>  $variables
     */
    public function __construct(
        protected int $seoMediaId,
        protected int $promptId,
        protected array $variables,
        protected string $toolType = 'image',
    ) {}

    public function handle(PromptRunnerService $promptRunner, PromptMediaStorageService $promptMediaStorage): void
    {
        $media = SeoMedia::query()->find($this->seoMediaId);
        $prompt = SeoPrompt::query()->where('is_active', true)->find($this->promptId);

        if (! $media instanceof SeoMedia || ! $prompt instanceof SeoPrompt) {
            return;
        }

        if ((int) ($media->prompt_id ?? 0) <= 0 || ! is_array($media->prompt_variables) || $media->prompt_variables === []) {
            $media->update([
                'status' => 'failed',
                'error_message' => 'Job AI không hợp lệ (thiếu prompt). Xóa hoặc tạo ảnh mới.',
            ]);

            return;
        }

        try {
            $promptResult = $promptMediaStorage->usingTargetMedia(
                $media,
                fn () => $promptRunner->run($prompt, $this->variables, isTaskMode: false),
            );
            $output = trim((string) ($promptResult->output_text ?? ''));
            $finalUrl = trim((string) (explode("\n", $output, 2)[0] ?? ''));

            if ($finalUrl === '') {
                throw new PromptRunException('Không nhận được URL kết quả từ AI.');
            }

            $urlPath = parse_url($finalUrl, PHP_URL_PATH);
            $urlPath = is_string($urlPath) ? $urlPath : '';
            $isStoragePath = Str::startsWith($urlPath, '/storage/');
            $resolvedPath = $isStoragePath
                ? ltrim(substr($urlPath, strlen('/storage/')), '/')
                : (string) $media->path;
            $resolvedFilename = basename($urlPath !== '' ? $urlPath : $finalUrl);

            $media->update([
                'url' => $finalUrl,
                'path' => $resolvedPath !== '' ? $resolvedPath : (string) $media->path,
                'filename' => $resolvedFilename !== '' ? $resolvedFilename : (string) $media->filename,
                'status' => 'completed',
                'error_message' => null,
            ]);
        } catch (Throwable $exception) {
            $media->update([
                'status' => 'failed',
                'error_message' => mb_substr($exception->getMessage(), 0, 1000),
            ]);

            logger()->error(
                "GenerateMediaJob failed [media_id={$this->seoMediaId}, tool={$this->toolType}]: {$exception->getMessage()}",
            );

            throw $exception;
        }
    }

    public function failed(Throwable $exception): void
    {
        $media = SeoMedia::query()->find($this->seoMediaId);
        if (! $media instanceof SeoMedia) {
            return;
        }

        $message = $exception instanceof TimeoutExceededException
            ? 'Job AI bị timeout khi xử lý (quá lâu). Vui lòng bấm Thử lại.'
            : trim((string) $exception->getMessage());

        if ($message === '') {
            $message = 'Job AI thất bại. Vui lòng bấm Thử lại.';
        }

        $media->update([
            'status' => 'failed',
            'error_message' => mb_substr($message, 0, 1000),
        ]);
    }
}
