<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services;

use App\Addons\SeoContentAi\Models\SeoAiModel;
use App\Addons\SeoContentAi\Support\AiModelCategory;
use App\Addons\SeoContentAi\Support\GoogleAiModelRegistry;
use App\Addons\SeoContentAi\Support\ImageModelInputLengthPolicy;

final class SeoImageModelPriorityOptionsService
{
    /**
     * @return array<string, string> slug => label
     */
    public function imageModelSelectOptions(): array
    {
        $options = [];

        $models = SeoAiModel::query()
            ->where('category', AiModelCategory::IMAGEN_PRO)
            ->where('status', SeoAiModel::STATUS_ACTIVE)
            ->orderByDesc('priority')
            ->orderBy('raw_model_name')
            ->get(['raw_model_name', 'display_name']);

        foreach ($models as $model) {
            $slug = GoogleAiModelRegistry::normalizeSlug((string) $model->raw_model_name);
            if ($slug === '') {
                continue;
            }

            $label = trim((string) $model->display_name);
            $options[$slug] = $this->formatOptionLabel(
                $slug,
                $label !== '' ? $label.' ('.$slug.')' : $slug,
            );
        }

        foreach (GoogleAiModelRegistry::imageSelectOptions() as $slug => $label) {
            $options[$slug] ??= $this->formatOptionLabel($slug, $label);
        }

        return $options;
    }

    public function labelForSlug(string $slug): ?string
    {
        $slug = GoogleAiModelRegistry::normalizeSlug($slug);
        if ($slug === '') {
            return null;
        }

        $options = $this->imageModelSelectOptions();

        return $options[$slug] ?? $slug;
    }

    private function formatOptionLabel(string $slug, string $label): string
    {
        $tierHint = ImageModelInputLengthPolicy::tierHint(
            ImageModelInputLengthPolicy::tierForModel($slug),
        );

        if ($tierHint === '') {
            return $label;
        }

        return $label.' · '.$tierHint;
    }
}
