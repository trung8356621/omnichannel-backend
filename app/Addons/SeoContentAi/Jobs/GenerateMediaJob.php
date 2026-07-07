<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Jobs;

use App\Addons\SeoContentAi\Exceptions\PromptRunException;
use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Models\SeoMedia;
use App\Addons\SeoContentAi\Models\SeoPrompt;
use App\Addons\SeoContentAi\Services\ArticleEditorMediaAiService;
use App\Addons\SeoContentAi\Services\ArticleEditorReadinessService;
use App\Addons\SeoContentAi\Services\ArticleMediaLocalService;
use App\Addons\SeoContentAi\Services\PromptMediaStorageService;
use App\Addons\SeoContentAi\Services\PromptPostProcessingApplyService;
use App\Addons\SeoContentAi\Services\PromptResultLinkService;
use App\Addons\SeoContentAi\Services\PromptRunnerService;
use App\Addons\SeoContentAi\Services\SeoDatabaseConnectionService;
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

    /** Must exceed GeminiMediaGenerationService HTTP budget × số model dự phòng. */
    public int $timeout = 360;

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
        /** Editor jobs need one image — not the full text→image chain (can run 10+ minutes). */
        protected bool $runFullDependentChain = false,
    ) {}

    public function handle(
        PromptRunnerService $promptRunner,
        PromptMediaStorageService $promptMediaStorage,
        PromptPostProcessingApplyService $postProcessing,
        PromptResultLinkService $promptResultLinks,
        SeoDatabaseConnectionService $databaseConnection,
        ArticleEditorReadinessService $editorReadiness,
    ): void {
        $databaseConnection->bootstrapLegacySharedConnection();

        $media = SeoMedia::query()->find($this->seoMediaId);
        if ($media instanceof SeoMedia && (int) ($media->site_id ?? 0) > 0) {
            $databaseConnection->bootstrapSeoDatabaseConnection((int) $media->site_id);
            $media = SeoMedia::query()->find($this->seoMediaId);
        }

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
                fn () => $promptRunner->run(
                    $prompt,
                    $this->variables,
                    isTaskMode: false,
                    runFullDependentChain: $this->runFullDependentChain,
                ),
            );
            $output = trim((string) ($promptResult->output_text ?? ''));
            $finalUrl = trim((string) (explode("\n", $output, 2)[0] ?? ''));

            $articleId = (int) ($media->firstArticleId() ?? 0);
            if ($articleId > 0) {
                try {
                    $promptResultLinks->linkPromptResult(
                        promptResultId: (int) $promptResult->id,
                        articleId: $articleId,
                        source: 'editor_media_generation',
                        meta: [
                            'tool_type' => $this->toolType,
                            'seo_media_id' => (int) $media->id,
                            'editor_block_id' => (string) ($media->editor_block_id ?? ''),
                        ],
                    );
                } catch (Throwable $linkException) {
                    // Link lịch sử prompt không được làm hỏng luồng tạo ảnh.
                    logger()->warning(
                        "GenerateMediaJob linkPromptResult failed [media_id={$this->seoMediaId}]: {$linkException->getMessage()}",
                    );
                }
            }

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

            $media = $media->fresh();
            if ($this->toolType === 'image' && $media instanceof SeoMedia) {
                try {
                    $postResult = $postProcessing->applyIfConfigured($media, $prompt);
                    if ($postResult->applied && count($postResult->pieces) > 0) {
                        $variables = is_array($media->prompt_variables) ? $media->prompt_variables : [];
                        $variables['post_processing_piece_ids'] = array_values(array_map(
                            static fn (SeoMedia $piece): int => (int) $piece->id,
                            $postResult->pieces,
                        ));
                        $media->update(['prompt_variables' => $variables]);
                    }
                } catch (Throwable $postProcessingException) {
                    logger()->warning(
                        "GenerateMediaJob post-processing failed [media_id={$this->seoMediaId}]: {$postProcessingException->getMessage()}",
                    );
                }
            }

            $media = $media->fresh();
            if ($this->toolType === 'image' && $media instanceof SeoMedia) {
                $this->persistProductGalleryLinkIfNeeded($media);
            }

            if ($articleId > 0 && $media instanceof SeoMedia) {
                $article = SeoArticle::query()->find($articleId);
                if ($article instanceof SeoArticle) {
                    $editorReadiness->evaluate($article);
                }
            }
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

        $rawMessage = trim((string) $exception->getMessage());

        $message = match (true) {
            $exception instanceof TimeoutExceededException => 'Job AI bị timeout khi xử lý (quá lâu). Chạy queue worker với --timeout=360 rồi bấm Thử lại.',
            str_contains($rawMessage, 'attempted too many times') => 'Job AI bị hủy giữa chừng (queue worker timeout). Khởi động lại worker với --timeout=360 rồi bấm Thử lại.',
            str_contains(strtolower($rawMessage), 'curl error 28')
                || str_contains(strtolower($rawMessage), 'timed out') => 'Gemini API không phản hồi kịp. Thử lại sau hoặc đổi model Imagen 4 trong Cấu hình AI.',
            default => $rawMessage,
        };

        if ($message === '') {
            $message = 'Job AI thất bại. Vui lòng bấm Thử lại.';
        }

        $media->update([
            'status' => 'failed',
            'error_message' => mb_substr($message, 0, 1000),
        ]);
    }

    private function persistProductGalleryLinkIfNeeded(SeoMedia $media): void
    {
        if (trim((string) ($media->editor_block_id ?? '')) !== ArticleEditorMediaAiService::PRODUCT_GALLERY_EDITOR_BLOCK_ID) {
            return;
        }

        $articleId = (int) ($media->firstArticleId() ?? 0);
        if ($articleId <= 0) {
            return;
        }

        $article = SeoArticle::query()->find($articleId);
        if (! $article instanceof SeoArticle) {
            return;
        }

        try {
            app(ArticleMediaLocalService::class)->appendGeneratedImageToProductAlbum($article, $media);
        } catch (Throwable $exception) {
            logger()->warning(
                "GenerateMediaJob product gallery link failed [media_id={$this->seoMediaId}]: {$exception->getMessage()}",
            );
        }
    }
}
