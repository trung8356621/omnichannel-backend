<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services;

use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Models\SeoMedia;
use App\Addons\SeoContentAi\Models\SeoPrompt;
use App\Addons\SeoContentAi\Support\PromptPostProcessing;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Laravel\Facades\Image;

final class PromptPostProcessingApplyService
{
    public function __construct(
        private readonly SeoImageOptimizationService $optimization,
        private readonly SeoMediaStorageService $mediaStorage,
        private readonly SeoMediaResizeService $mediaResize,
    ) {}

    public function applyIfConfigured(SeoMedia $media, SeoPrompt $prompt): PromptPostProcessingApplyResult
    {
        $config = PromptPostProcessing::fromPrompt($prompt);

        if (! PromptPostProcessing::isActive($config)) {
            return PromptPostProcessingApplyResult::skipped($media);
        }

        $media->refresh();
        $sourceMedia = $this->resolveSourceMedia($media);

        if ($config['split_enabled']) {
            if ($this->hasExistingSplitPieces($sourceMedia)) {
                $this->removePreviousSplitPieces($sourceMedia);
            }

            return $this->applySplit($sourceMedia, $prompt, $config);
        }

        if ($config['resize_enabled']) {
            return $this->applyResizeExistingPieces($sourceMedia, $config);
        }

        return PromptPostProcessingApplyResult::skipped($sourceMedia);
    }

    public function resolveSourceMedia(SeoMedia $media): SeoMedia
    {
        $variables = is_array($media->prompt_variables) ? $media->prompt_variables : [];
        $sourceId = (int) ($variables['post_processing_source_media_id'] ?? 0);

        if ($sourceId > 0 && $sourceId !== (int) $media->id) {
            $source = SeoMedia::query()->find($sourceId);
            if ($source instanceof SeoMedia) {
                return $source->fresh() ?? $source;
            }
        }

        if ($this->hasExistingSplitPieces($media)) {
            return $media->fresh() ?? $media;
        }

        return $media->fresh() ?? $media;
    }

    /**
     * @param  array{
     *     split_enabled: bool,
     *     split_rows: int,
     *     split_columns: int,
     *     resize_enabled: bool,
     *     resize_width: int|null,
     *     resize_height: int|null,
     * }  $config
     */
    private function applySplit(SeoMedia $media, SeoPrompt $prompt, array $config): PromptPostProcessingApplyResult
    {
        $absolutePath = $this->resolveAbsolutePath($media);
        if ($absolutePath === null) {
            return PromptPostProcessingApplyResult::failed($media, 'Không tìm thấy file ảnh để tách lưới.');
        }

        $rows = $config['split_rows'];
        $cols = $config['split_columns'];

        $sourceBinary = file_get_contents($absolutePath);
        if (! is_string($sourceBinary) || $sourceBinary === '') {
            return PromptPostProcessingApplyResult::failed($media, 'Không đọc được file ảnh để tách lưới.');
        }

        $source = Image::decodeBinary($sourceBinary);
        $origWidth = $source->width();
        $origHeight = $source->height();

        if ($origWidth < 2 || $origHeight < 2) {
            return PromptPostProcessingApplyResult::failed($media, 'Ảnh quá nhỏ để tách lưới.');
        }

        $baseSlug = trim((string) ($media->slug ?? ''));
        if ($baseSlug === '') {
            $baseSlug = Str::slug(pathinfo($absolutePath, PATHINFO_FILENAME) ?: 'image');
        }
        if ($baseSlug === '') {
            $baseSlug = 'image';
        }

        $extension = strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION) ?: 'jpg');
        if ($extension === 'jpeg') {
            $extension = 'jpg';
        }

        $siteId = (int) ($media->site_id ?? 0) > 0 ? (int) $media->site_id : null;
        $article = $this->resolveArticle($media);
        $cellWidth = intdiv($origWidth, $cols);
        $cellHeight = intdiv($origHeight, $rows);
        $pieces = [];

        for ($row = 0; $row < $rows; $row++) {
            for ($col = 0; $col < $cols; $col++) {
                $x = $col * $cellWidth;
                $y = $row * $cellHeight;
                $width = $col === $cols - 1 ? ($origWidth - $x) : $cellWidth;
                $height = $row === $rows - 1 ? ($origHeight - $y) : $cellHeight;

                if ($width < 1 || $height < 1) {
                    continue;
                }

                $pieceImage = Image::decodeBinary($sourceBinary)->crop($width, $height, $x, $y);
                $binary = (string) $pieceImage->encode();
                $slugSeed = sprintf('%s-%d-%d', $baseSlug, $row + 1, $col + 1);

                $saved = $this->storeNewPiece($binary, $extension, $siteId, $article, $slugSeed, $media);
                $pieces[] = $saved;
            }
        }

        if ($pieces === []) {
            return PromptPostProcessingApplyResult::failed($media, 'Không tạo được ảnh con từ lưới.');
        }

        if ($config['resize_enabled']) {
            $resizeResult = $this->resizePieces($pieces, $config);
            if ($resizeResult instanceof PromptPostProcessingApplyResult) {
                return $resizeResult;
            }
            $pieces = $resizeResult;
        }

        $this->persistSplitPiecesOnSource($media, $pieces);
        $this->appendPiecesToProductAlbum($media, $pieces);

        $message = sprintf(
            'Đã tách thành %d ảnh (%d×%d)%s.',
            count($pieces),
            $rows,
            $cols,
            $config['resize_enabled'] ? ' và resize' : '',
        );

        return PromptPostProcessingApplyResult::applied($pieces[0], $pieces, $message);
    }

    /**
     * @param  array{
     *     split_enabled: bool,
     *     split_rows: int,
     *     split_columns: int,
     *     resize_enabled: bool,
     *     resize_width: int|null,
     *     resize_height: int|null,
     * }  $config
     */
    private function applyResizeExistingPieces(SeoMedia $sourceMedia, array $config): PromptPostProcessingApplyResult
    {
        $pieces = $this->loadSplitPieces($sourceMedia);
        if ($pieces === []) {
            return PromptPostProcessingApplyResult::failed(
                $sourceMedia,
                'Bật Quick split trước — Quick resize chỉ áp dụng lên ảnh con sau khi tách lưới.',
            );
        }

        $resizeResult = $this->resizePieces($pieces, $config);
        if ($resizeResult instanceof PromptPostProcessingApplyResult) {
            return $resizeResult;
        }

        $pieces = $resizeResult;
        $this->persistSplitPiecesOnSource($sourceMedia, $pieces);

        return PromptPostProcessingApplyResult::applied(
            $pieces[0],
            $pieces,
            sprintf('Đã resize %d ảnh con.', count($pieces)),
        );
    }

    /**
     * @param  list<SeoMedia>  $pieces
     * @param  array{
     *     split_enabled: bool,
     *     split_rows: int,
     *     split_columns: int,
     *     resize_enabled: bool,
     *     resize_width: int|null,
     *     resize_height: int|null,
     * }  $config
     * @return list<SeoMedia>|PromptPostProcessingApplyResult
     */
    private function resizePieces(array $pieces, array $config): array|PromptPostProcessingApplyResult
    {
        if (! $config['resize_enabled']) {
            return $pieces;
        }

        $resized = [];

        foreach ($pieces as $piece) {
            $outcome = $this->maybeResizeMedia($piece, $config);
            if (! ($outcome['success'] ?? false)) {
                return PromptPostProcessingApplyResult::failed(
                    $piece,
                    (string) ($outcome['message'] ?? 'Không resize được ảnh con.'),
                );
            }

            $resized[] = $piece->fresh();
        }

        return $resized;
    }

    /**
     * @return list<SeoMedia>
     */
    private function loadSplitPieces(SeoMedia $sourceMedia): array
    {
        $variables = is_array($sourceMedia->prompt_variables) ? $sourceMedia->prompt_variables : [];
        $pieceIds = is_array($variables['post_processing_piece_ids'] ?? null)
            ? $variables['post_processing_piece_ids']
            : [];

        $ids = array_values(array_filter(array_map(
            static fn (mixed $id): int => (int) $id,
            $pieceIds,
        ), static fn (int $id): bool => $id > 0));

        if ($ids === []) {
            return [];
        }

        return SeoMedia::query()
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->get()
            ->all();
    }

    /**
     * @param  array{
     *     split_enabled: bool,
     *     split_rows: int,
     *     split_columns: int,
     *     resize_enabled: bool,
     *     resize_width: int|null,
     *     resize_height: int|null,
     * }  $config
     * @return array{success: bool, message: string}
     */
    private function maybeResizeMedia(SeoMedia $media, array $config): array
    {
        if (! $config['resize_enabled']) {
            return ['success' => true, 'message' => ''];
        }

        return $this->mediaResize->resizeLocal(
            $media,
            $config['resize_width'],
            $config['resize_height'],
        );
    }

    private function storeNewPiece(
        string $binary,
        string $extension,
        ?int $siteId,
        ?SeoArticle $article,
        string $slugSeed,
        SeoMedia $template,
    ): SeoMedia {
        $config = $this->optimization->resolveForSite($siteId);
        $processed = $this->optimization->processBinary($binary, $extension, $config, $article, $slugSeed);

        Storage::disk('public')->put($processed['relative_path'], $processed['binary']);

        $articleIds = SeoMedia::normalizeArticleIds($template->article_id);
        $promptVariables = is_array($template->prompt_variables) ? $template->prompt_variables : [];
        $promptVariables['post_processing_source_media_id'] = (int) $template->id;

        $media = SeoMedia::query()->create([
            'site_id' => $siteId,
            'article_id' => $articleIds !== [] ? $articleIds : null,
            'filename' => $processed['filename'],
            'slug' => $processed['slug'],
            'path' => $processed['relative_path'],
            'url' => $this->mediaStorage->urlForPath($processed['relative_path']),
            'source' => 'image_split',
            'alt_text' => $processed['alt_text'],
            'prompt_id' => (int) ($template->prompt_id ?? 0) > 0 ? (int) $template->prompt_id : null,
            'prompt_variables' => $promptVariables,
            'status' => 'completed',
        ]);

        if ($siteId !== null) {
            app(SeoWatermarkService::class)->applyToMediaIfEnabled($media);
        }

        return $media->fresh();
    }

    /**
     * @param  list<SeoMedia>  $pieces
     */
    private function appendPiecesToProductAlbum(SeoMedia $sourceMedia, array $pieces): void
    {
        $articleId = $sourceMedia->firstArticleId();
        if ($articleId === null) {
            return;
        }

        $editorBlockId = trim((string) ($sourceMedia->editor_block_id ?? ''));
        if ($editorBlockId !== '') {
            return;
        }

        $article = SeoArticle::query()->find($articleId);
        if (! $article instanceof SeoArticle || (string) ($article->type ?? '') !== 'product') {
            return;
        }

        $service = app(ArticleMediaLocalService::class);
        foreach ($pieces as $piece) {
            $service->appendProductAlbumLocal($article, (int) $piece->id, $piece->publicUrl());
        }
    }

    private function resolveArticle(SeoMedia $media): ?SeoArticle
    {
        $articleId = $media->firstArticleId();

        return $articleId !== null
            ? SeoArticle::query()->find($articleId)
            : null;
    }

    private function resolveAbsolutePath(SeoMedia $media): ?string
    {
        $relativePath = ltrim(str_replace('\\', '/', (string) $media->path), '/');
        if ($relativePath === '') {
            return null;
        }

        $disk = Storage::disk('public');
        if (! $disk->exists($relativePath)) {
            return null;
        }

        return $disk->path($relativePath);
    }

    /**
     * @param  list<SeoMedia>  $pieces
     */
    private function persistSplitPiecesOnSource(SeoMedia $sourceMedia, array $pieces): void
    {
        $variables = is_array($sourceMedia->prompt_variables) ? $sourceMedia->prompt_variables : [];
        $variables['post_processing_piece_ids'] = array_values(array_map(
            static fn (SeoMedia $piece): int => (int) $piece->id,
            $pieces,
        ));

        $sourceMedia->update(['prompt_variables' => $variables]);
    }

    private function hasExistingSplitPieces(SeoMedia $sourceMedia): bool
    {
        $variables = is_array($sourceMedia->prompt_variables) ? $sourceMedia->prompt_variables : [];
        $pieceIds = is_array($variables['post_processing_piece_ids'] ?? null)
            ? $variables['post_processing_piece_ids']
            : [];

        return array_values(array_filter(array_map(
            static fn (mixed $id): int => (int) $id,
            $pieceIds,
        ), static fn (int $id): bool => $id > 0)) !== [];
    }

    private function removePreviousSplitPieces(SeoMedia $sourceMedia): void
    {
        $variables = is_array($sourceMedia->prompt_variables) ? $sourceMedia->prompt_variables : [];
        $pieceIds = is_array($variables['post_processing_piece_ids'] ?? null)
            ? $variables['post_processing_piece_ids']
            : [];

        foreach ($pieceIds as $pieceId) {
            $pieceId = (int) $pieceId;
            if ($pieceId <= 0 || $pieceId === (int) $sourceMedia->id) {
                continue;
            }

            $piece = SeoMedia::query()->find($pieceId);
            if ($piece instanceof SeoMedia) {
                $this->deletePieceMedia($piece);
            }
        }

        unset($variables['post_processing_piece_ids']);
        $sourceMedia->update(['prompt_variables' => $variables]);
    }

    private function deletePieceMedia(SeoMedia $piece): void
    {
        $path = ltrim(str_replace('\\', '/', (string) $piece->path), '/');
        if ($path !== '' && Storage::disk('public')->exists($path)) {
            Storage::disk('public')->delete($path);
        }

        $piece->delete();
    }
}

final class PromptPostProcessingApplyResult
{
    /**
     * @param  list<SeoMedia>  $pieces
     */
    public function __construct(
        public readonly bool $applied,
        public readonly SeoMedia $primary,
        public readonly array $pieces = [],
        public readonly ?string $message = null,
    ) {}

    public static function skipped(SeoMedia $media): self
    {
        return new self(false, $media);
    }

    /**
     * @param  list<SeoMedia>  $pieces
     */
    public static function applied(SeoMedia $primary, array $pieces, ?string $message = null): self
    {
        return new self(true, $primary, $pieces, $message);
    }

    public static function failed(SeoMedia $media, string $message): self
    {
        return new self(false, $media, [], $message);
    }

    /**
     * @return list<string>
     */
    public function publicUrls(): array
    {
        if ($this->pieces === []) {
            return [$this->primary->publicUrl()];
        }

        return array_values(array_map(
            static fn (SeoMedia $media): string => $media->publicUrl(),
            $this->pieces,
        ));
    }
}
