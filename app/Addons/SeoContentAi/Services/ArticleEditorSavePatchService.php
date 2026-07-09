<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services;

use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Models\SeoProjectTask;
use App\Addons\SeoContentAi\Support\ArticleEditorSaveContext;
use App\Addons\SeoContentAi\Support\SeoDisplayTimezone;
use Illuminate\Support\Carbon;

/**
 * JSON patch trả về client sau REST save — không cần Livewire refresh.
 */
final class ArticleEditorSavePatchService
{
    public function __construct(
        private readonly ArticleWordPressSyncFlagService $syncFlags,
        private readonly SeoArticleRevisionService $revisions,
        private readonly ArticleScheduleReconcileService $scheduleReconcile,
    ) {}

    /**
     * @param  array<string, mixed>|null  $seoAnalysis
     * @return array<string, mixed>
     */
    public function build(SeoArticle $article, ArticleEditorSaveContext $context, ?array $seoAnalysis = null): array
    {
        $article = $article->fresh() ?? $article;
        $article->loadMissing('articleMetas');

        $status = (string) ($article->status ?? 'draft');
        $publishedAt = $article->published_at instanceof Carbon
            ? $article->published_at->copy()->timezone(SeoDisplayTimezone::name())
            : null;
        $updatedAt = $article->updated_at instanceof Carbon
            ? $article->updated_at->copy()->timezone(SeoDisplayTimezone::name())
            : SeoDisplayTimezone::now();

        $postType = SeoProjectTask::normalizePostType(
            (string) ($article->type ?? ArticlePostTypeResolver::resolve($article)),
        );

        $publishWhenLabel = '';
        if ($status === 'scheduled' && $publishedAt instanceof Carbon) {
            $publishWhenLabel = $this->formatScheduleLabel($publishedAt);
        }

        $publishedAtSidebarLabel = null;
        if ($this->scheduleReconcile->shouldShowPublishedAtLabel($status, $article->published_at)) {
            $publishedAtSidebarLabel = $publishedAt instanceof Carbon
                ? $this->formatScheduleLabel($publishedAt)
                : null;
        }

        return [
            'article' => [
                'id' => (int) $article->id,
                'title' => (string) ($article->title ?? ''),
                'slug' => (string) ($article->slug ?? ''),
                'status' => $status,
                'post_type' => $postType,
                'visibility' => $context->visibility,
                'updated_at' => $updatedAt->toIso8601String(),
                'updated_at_label' => $updatedAt->format('d/m/Y H:i'),
                'published_at' => $publishedAt?->toIso8601String(),
                'seo_score' => $article->seo_score !== null ? (float) $article->seo_score : null,
            ],
            'publish_box' => [
                'status' => $status,
                'post_type' => $postType,
                'visibility' => $context->visibility,
                'publish_day' => $context->publishDay,
                'publish_month' => $context->publishMonth,
                'publish_year' => $context->publishYear,
                'publish_hour' => $context->publishHour,
                'publish_minute' => $context->publishMinute,
                'publish_when_label' => $publishWhenLabel,
                'published_at_sidebar_label' => $publishedAtSidebarLabel,
                'show_publish_schedule_row' => $this->scheduleReconcile->shouldShowScheduleLabel($status),
                'status_label' => $this->statusLabel($status),
                'saved_at_label' => 'Đã lưu lúc '.$updatedAt->format('H:i:s'),
            ],
            'flags' => [
                'local_edit_pending' => $this->syncFlags->hasLocalEditPending($article),
                'wp_data_out_of_sync' => $this->syncFlags->hasDataOutOfSync($article),
                'body_media_sync_pending' => $this->syncFlags->hasBodyMediaSyncPending($article),
            ],
            'revision_count' => $this->revisions->countForArticle((int) $article->id),
            'seo_analysis' => is_array($seoAnalysis) ? $seoAnalysis : null,
            'seo_analysis_pending' => ! is_array($seoAnalysis) || ! array_key_exists('violations', $seoAnalysis),
        ];
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'published' => 'Published',
            'scheduled' => 'Scheduled',
            'private' => 'Private',
            default => 'Draft',
        };
    }

    private function formatScheduleLabel(Carbon $dt): string
    {
        return SeoDisplayTimezone::formatScheduleLabel($dt);
    }
}
