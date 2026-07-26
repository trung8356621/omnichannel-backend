<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\PromptOwnership;

use App\Addons\SeoContentAi\PromptHooks\Runtime\PromptHookCompositionPreviewService;
use Illuminate\Support\HtmlString;

/**
 * Segment / summary presenter for Prompt Edit «Prompt sau khi ghép».
 * Default: summary only. Expand: full compose via existing preview service (runtime SoT untouched).
 */
class PromptCompositionSummaryPresenter
{
    private const PROMPT_OWN_MAX_CHARS = 4000;

    public function __construct(
        private readonly PromptHookSummaryService $hookSummary,
        private readonly PromptHookCompositionPreviewService $compositionPreview,
    ) {}

    /**
     * @param  array<string, mixed>  $hookSettings
     */
    public function renderHtml(
        string $hookKey,
        string $hookVersion,
        string $markdownContent,
        array $hookSettings = [],
        bool $expanded = false,
    ): HtmlString {
        $hookKey = trim($hookKey);
        $markdownContent = (string) $markdownContent;

        if ($expanded) {
            $preview = $this->compositionPreview->preview(
                $hookKey,
                $hookVersion,
                $markdownContent,
                $hookSettings,
            );

            $full = $this->compositionPreview->formatPreviewHtml($preview);
            $collapseHint = '<p class="text-xs text-gray-500 dark:text-gray-400 mt-2">'
                .e($this->t(
                    'seo-content-ai::filament.prompt.composed_preview_expanded_hint',
                    'Showing full merged prompt. Turn off the toggle to return to summary.',
                ))
                .'</p>';

            return new HtmlString($this->pipelineChrome($hookKey).$full.$collapseHint);
        }

        if ($hookKey === '') {
            return new HtmlString($this->renderNoHook($markdownContent));
        }

        return new HtmlString($this->renderSummary($hookKey, $hookVersion, $markdownContent));
    }

    private function pipelineChrome(string $hookKey): string
    {
        $summary = $this->hookSummary->summarize($hookKey);
        $steps = $summary['pipeline'];
        $parts = [];
        foreach ($steps as $i => $step) {
            $parts[] = '<span class="font-medium">'.e((string) $step).'</span>';
            if ($i < count($steps) - 1) {
                $parts[] = '<span class="text-gray-400 mx-1">↓</span>';
            }
        }

        return '<div class="mb-3 rounded-md border border-dashed border-gray-300 dark:border-gray-600 bg-gray-50 dark:bg-gray-900/30 px-3 py-2 text-xs text-gray-600 dark:text-gray-300">'
            .'<div class="font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1">'
            .e($this->t('seo-content-ai::filament.prompt.composed_runtime_compose', 'Runtime compose'))
            .'</div>'
            .'<div class="flex flex-wrap items-center gap-y-1">'.implode('', $parts).'</div>'
            .'</div>';
    }

    private function renderNoHook(string $markdownContent): string
    {
        $body = trim($markdownContent);
        $html = $this->pipelineChrome('');
        $html .= '<div class="rounded-md border border-gray-200 dark:border-gray-700 p-3 space-y-2">'
            .'<p class="text-sm text-gray-700 dark:text-gray-200">'
            .e($this->t(
                'seo-content-ai::filament.prompt.composed_no_hook',
                'No Hook. Final prompt is the Prompt Markdown.',
            ))
            .'</p>';

        if ($body === '') {
            $html .= '<p class="text-sm text-gray-500">'.$this->t(
                'seo-content-ai::filament.prompt.composed_preview_empty',
                'No composed prompt yet.',
            ).'</p>';
        } else {
            $html .= '<div class="text-xs font-semibold uppercase tracking-wide text-gray-500">'
                .e($this->t('seo-content-ai::filament.prompt.composed_segment_prompt', 'Prompt content'))
                .'</div>'
                .'<pre class="seo-prompt-composed-preview max-h-[16rem] overflow-y-auto whitespace-pre-wrap text-sm font-mono rounded-md bg-gray-50 dark:bg-gray-900/40 p-3 border border-gray-200 dark:border-gray-700">'
                .e($this->truncate($body))
                .'</pre>';
        }

        return $html.'</div>';
    }

    private function renderSummary(string $hookKey, string $hookVersion, string $markdownContent): string
    {
        $summary = $this->hookSummary->summarize($hookKey, $hookVersion);
        $html = $this->pipelineChrome($hookKey);

        $own = trim($markdownContent);
        $html .= '<div class="space-y-3">';

        $html .= '<div class="rounded-md border border-gray-200 dark:border-gray-700 p-3 space-y-2">'
            .'<div class="text-xs font-semibold uppercase tracking-wide text-gray-500">'
            .e($this->t('seo-content-ai::filament.prompt.composed_segment_prompt_own', 'Prompt-specific content'))
            .'</div>';

        if ($own === '') {
            $html .= '<p class="text-sm text-gray-500">'
                .e($this->t(
                    'seo-content-ai::filament.prompt.composed_prompt_own_empty',
                    'No Prompt markdown content yet.',
                ))
                .'</p>';
        } else {
            $html .= '<pre class="seo-prompt-composed-preview max-h-[14rem] overflow-y-auto whitespace-pre-wrap text-sm font-mono rounded-md bg-gray-50 dark:bg-gray-900/40 p-3 border border-gray-200 dark:border-gray-700">'
                .e($this->truncate($own))
                .'</pre>';
        }
        $html .= '</div>';

        $html .= '<div class="rounded-md border border-dashed border-violet-300 dark:border-violet-700 bg-violet-50/40 dark:bg-violet-950/20 p-3 space-y-2">'
            .'<div class="text-xs font-semibold uppercase tracking-wide text-violet-700 dark:text-violet-300">'
            .e($this->t('seo-content-ai::filament.prompt.composed_segment_hook_default', 'Hook defaults'))
            .'</div>'
            .'<p class="text-sm text-gray-700 dark:text-gray-200">'
            .e($this->t(
                'seo-content-ai::filament.prompt.composed_hook_will_merge',
                '✓ Hook template will be merged at runtime',
            ))
            .'</p>'
            .'<ul class="text-sm text-gray-700 dark:text-gray-200 space-y-1 list-none pl-0">';

        foreach ($summary['items'] as $item) {
            $html .= '<li>✓ '.e((string) $item).'</li>';
        }

        $html .= '</ul>';
        if ($summary['source_path'] !== '') {
            $html .= '<p class="text-xs text-gray-500 dark:text-gray-400 font-mono">'
                .e($this->t(
                    'seo-content-ai::filament.prompt.composed_hook_source',
                    'Source: :path',
                    ['path' => $summary['source_path']],
                ))
                .'</p>';
        }
        $html .= '<p class="text-xs text-violet-700 dark:text-violet-300">'
            .e($this->t(
                'seo-content-ai::filament.prompt.composed_expand_hint',
                'Enable «Show full Prompt» to view the full merge.',
            ))
            .'</p>';
        $html .= '</div></div>';

        return $html;
    }

    private function truncate(string $body): string
    {
        if (mb_strlen($body) <= self::PROMPT_OWN_MAX_CHARS) {
            return $body;
        }

        return mb_substr($body, 0, self::PROMPT_OWN_MAX_CHARS)."\n\n…";
    }

    private function t(string $key, string $fallback, array $replace = []): string
    {
        try {
            if (! function_exists('app') || ! app()->bound('translator')) {
                $text = $fallback;
                foreach ($replace as $search => $value) {
                    $text = str_replace(':'.$search, (string) $value, $text);
                }

                return $text;
            }
            $translated = __($key, $replace);
            if (is_array($translated)) {
                return $fallback;
            }
            $text = trim((string) $translated);

            return $text !== '' ? $text : $fallback;
        } catch (\Throwable) {
            return $fallback;
        }
    }
}
