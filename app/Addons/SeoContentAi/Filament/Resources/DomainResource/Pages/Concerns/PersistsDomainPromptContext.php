<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Filament\Resources\DomainResource\Pages\Concerns;

use App\Addons\SeoContentAi\Services\DomainLinkListKeywordSyncService;
use App\Addons\SeoContentAi\Services\SiteDomainPromptContextService;
use App\Models\Site;
use Filament\Notifications\Notification;
use Illuminate\Support\Str;

trait PersistsDomainPromptContext
{
    /** @var array<string, mixed> */
    protected array $pendingPromptContext = [];

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function fillDomainPromptContextFormData(Site $site, array $data): array
    {
        $site->loadMissing('metas');

        $data['promptContext'] = $this->preparePromptContextForForm(
            app(SiteDomainPromptContextService::class)->getForSite($site),
        );

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function stripPromptContextFromFormData(array $data): array
    {
        unset($data['promptContext']);

        return $data;
    }

    /**
     * @param  array<string, mixed>  $formState
     */
    protected function queuePromptContextFromFormState(array $formState): void
    {
        $ctx = is_array($formState['promptContext'] ?? null) ? $formState['promptContext'] : [];

        $this->pendingPromptContext = [
            'tone' => trim((string) ($ctx['tone'] ?? '')),
            'short_description' => (string) ($ctx['short_description'] ?? ''),
            'cta_intro' => (string) ($ctx['cta_intro'] ?? ''),
            'cta' => $this->repeaterItemsFromState($ctx['cta'] ?? []),
            'links' => $this->repeaterItemsFromState($ctx['links'] ?? []),
        ];
    }

    protected function persistPendingDomainPromptContext(Site $site): void
    {
        if ($this->pendingPromptContext === []) {
            return;
        }

        try {
            app(SiteDomainPromptContextService::class)->saveForSite($site, $this->pendingPromptContext);
            $synced = app(DomainLinkListKeywordSyncService::class)->syncLinks(
                $site,
                $this->pendingPromptContext['links'] ?? [],
            );

            if ($synced > 0) {
                Notification::make()
                    ->title(__('seo-content-ai::filament.domain.link_sync_title'))
                    ->body(__('seo-content-ai::filament.domain.link_sync_body', ['count' => $synced]))
                    ->success()
                    ->send();
            }
        } catch (\InvalidArgumentException $exception) {
            Notification::make()
                ->title(__('seo-content-ai::filament.domain.save_failed'))
                ->body($exception->getMessage())
                ->danger()
                ->send();
        } finally {
            $this->pendingPromptContext = [];
        }
    }

    /**
     * @param  array{
     *     tone?: string,
     *     short_description?: string,
     *     cta_intro?: string,
     *     cta?: list<array<string, string>>,
     *     links?: list<array<string, string>>,
     * }  $context
     * @return array<string, mixed>
     */
    protected function preparePromptContextForForm(array $context): array
    {
        return [
            'tone' => (string) ($context['tone'] ?? ''),
            'short_description' => (string) ($context['short_description'] ?? ''),
            'cta_intro' => (string) ($context['cta_intro'] ?? ''),
            'cta' => $this->repeaterStateForFill($context['cta'] ?? []),
            'links' => $this->repeaterStateForFill($context['links'] ?? []),
        ];
    }

    /**
     * Filament Repeater cần key UUID khi hydrate — list thuần từ JSON sẽ không hiển thị.
     *
     * @param  list<array<string, mixed>>  $items
     * @return array<string, array<string, mixed>>
     */
    protected function repeaterStateForFill(array $items): array
    {
        $state = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $state[(string) Str::uuid()] = $item;
        }

        return $state;
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function repeaterItemsFromState(mixed $state): array
    {
        if (! is_array($state)) {
            return [];
        }

        $items = [];

        foreach ($state as $item) {
            if (! is_array($item)) {
                continue;
            }

            $items[] = $item;
        }

        return $items;
    }
}
