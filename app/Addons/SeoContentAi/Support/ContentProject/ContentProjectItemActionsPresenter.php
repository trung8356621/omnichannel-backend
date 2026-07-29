<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Support\ContentProject;

/**
 * Presentation-only action visibility for Project Items menu.
 * Does not change CommandBus eligibility — only hides UI noise.
 *
 * @phpstan-type ActionFlags array{
 *     open_article: bool,
 *     generate: bool,
 *     regen_outline: bool,
 *     regen_article: bool,
 *     regen_image: bool,
 *     retry_failed_step: bool,
 *     improve_note: bool,
 *     start_review: bool,
 *     approve: bool,
 *     schedule: bool,
 *     unschedule: bool,
 *     publish_now: bool,
 *     retry_publish: bool,
 *     skip: bool,
 *     cancel: bool,
 *     view_details: bool,
 *     has_content: bool,
 *     has_review: bool,
 *     has_publishing: bool
 * }
 */
final class ContentProjectItemActionsPresenter
{
    /**
     * @param  array<string, mixed>  $row
     * @return ActionFlags
     */
    public static function forRow(array $row): array
    {
        $lifecycle = strtolower((string) ($row['lifecycle'] ?? ''));
        $queue = strtolower((string) ($row['queue_status'] ?? 'none'));
        $genKey = strtolower((string) (($row['generation_badge']['key'] ?? '')));
        $canGenerate = (bool) ($row['can_generate'] ?? false);
        $canRegen = (bool) ($row['can_regen'] ?? false);
        $isImprove = (bool) ($row['is_improve'] ?? false);
        $hasArticle = ! empty($row['article_edit_url']);
        $isScheduled = (bool) ($row['is_scheduled'] ?? false);
        $hasGenerated = in_array($genKey, ['success', 'generated'], true)
            || in_array($lifecycle, ['review', 'approved', 'waiting_publish', 'published'], true);

        $openArticle = $hasArticle;
        $generate = $canGenerate;
        $regenOutline = $canRegen;
        $regenArticle = $canRegen;
        $regenImage = $canRegen && $hasArticle;
        $retryFailed = $genKey === 'failed' && $canRegen;
        $improveNote = $isImprove;

        $startReview = $hasGenerated && in_array($lifecycle, ['draft', 'generating'], true);
        $approve = $lifecycle === 'review';

        $schedule = in_array($lifecycle, ['approved', 'waiting_publish'], true);
        $unschedule = $isScheduled;
        $publishNow = in_array($lifecycle, ['approved', 'waiting_publish'], true);
        $retryPublish = $queue === 'failed' || $lifecycle === 'failed';
        $skip = in_array($queue, ['waiting', 'processing', 'retrying'], true);
        $cancel = in_array($queue, ['waiting', 'processing', 'retrying'], true);

        $hasContent = $openArticle || $generate || $regenOutline || $regenArticle || $regenImage || $retryFailed || $improveNote;
        $hasReview = $startReview || $approve;
        $hasPublishing = $schedule || $unschedule || $publishNow || $retryPublish || $skip || $cancel;

        return [
            'open_article' => $openArticle,
            'generate' => $generate,
            'regen_outline' => $regenOutline,
            'regen_article' => $regenArticle,
            'regen_image' => $regenImage,
            'retry_failed_step' => $retryFailed,
            'improve_note' => $improveNote,
            'start_review' => $startReview,
            'approve' => $approve,
            'schedule' => $schedule,
            'unschedule' => $unschedule,
            'publish_now' => $publishNow,
            'retry_publish' => $retryPublish,
            'skip' => $skip,
            'cancel' => $cancel,
            'view_details' => true,
            'has_content' => $hasContent,
            'has_review' => $hasReview,
            'has_publishing' => $hasPublishing,
        ];
    }
}
