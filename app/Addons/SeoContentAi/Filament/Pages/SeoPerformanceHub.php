<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Filament\Pages;

use App\Addons\SeoContentAi\Filament\Resources\KeywordResource;
use App\Addons\SeoContentAi\Models\Keyword;
use App\Addons\SeoContentAi\Services\AiKeywordDiscoveryService;
use App\Addons\SeoContentAi\Services\CreateArticlesFromTaskService;
use App\Addons\SeoContentAi\Services\KeywordPersistenceService;
use App\Addons\SeoContentAi\Services\SeoPerformanceHubService;
use App\Addons\SeoContentAi\Support\CtaKeywordBlacklistFilter;
use App\Addons\SeoContentAi\Support\InternalAnchorKeywordFilter;
use App\Addons\SeoContentAi\Support\SeoAccessControl;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Support\Htmlable;
use Livewire\Attributes\Url;

final class SeoPerformanceHub extends SeoPanelPage
{
    protected static ?string $navigationIcon = 'heroicon-o-chart-bar-square';

    protected static ?string $navigationGroup = 'SEO Workspace';

    protected static ?string $navigationLabel = 'Performance & R&D Hub';

    protected static ?int $navigationSort = 13;

    protected static ?string $slug = 'performance-hub';

    protected static string $view = 'seo-content-ai::seo.performance-hub';

    protected static bool $shouldRegisterNavigation = false;

    #[Url(as: 'tab')]
    public string $activeTab = 'gsc';

    #[Url(as: 'sort')]
    public string $querySortBy = 'impressions';

    #[Url(as: 'dir')]
    public string $querySortDir = 'desc';

    #[Url(as: 'seed')]
    public string $seedKeyword = '';

    #[Url(as: 'intent')]
    public string $searchIntent = 'any';

    #[Url(as: 'region')]
    public string $targetRegion = 'vietnam';

    /** @var list<array<string, mixed>> */
    public array $suggestions = [];

    /** @var list<string> */
    public array $selectedSuggestionIds = [];

    private SeoPerformanceHubService $performanceHub;

    private AiKeywordDiscoveryService $discovery;

    private KeywordPersistenceService $keywordPersistence;

    private CreateArticlesFromTaskService $articleCreator;

    public function boot(
        SeoPerformanceHubService $performanceHub,
        AiKeywordDiscoveryService $discovery,
        KeywordPersistenceService $keywordPersistence,
        CreateArticlesFromTaskService $articleCreator,
    ): void {
        $this->performanceHub = $performanceHub;
        $this->discovery = $discovery;
        $this->keywordPersistence = $keywordPersistence;
        $this->articleCreator = $articleCreator;
    }

    public static function canAccess(array $parameters = []): bool
    {
        return SeoAccessControl::canAccessPlannerFeatures();
    }

    public function mount(): void
    {
        if (! in_array($this->activeTab, ['gsc', 'quick-wins', 'ai-discovery', 'cannibalization'], true)) {
            $this->activeTab = 'gsc';
        }

        if (! in_array($this->searchIntent, ['any', 'informational', 'commercial', 'transactional'], true)) {
            $this->searchIntent = 'any';
        }

        if (! in_array($this->targetRegion, ['vietnam', 'global', 'us', 'uk', 'sea'], true)) {
            $this->targetRegion = 'vietnam';
        }
    }

    public function getTitle(): string|Htmlable
    {
        return __('seo-content-ai::filament.performance_hub.title');
    }

    public function setActiveTab(string $tab): void
    {
        if (! in_array($tab, ['gsc', 'quick-wins', 'ai-discovery', 'cannibalization'], true)) {
            return;
        }

        $this->activeTab = $tab;
    }

    public function sortGscQueries(string $column): void
    {
        if ($this->querySortBy === $column) {
            $this->querySortDir = $this->querySortDir === 'asc' ? 'desc' : 'asc';

            return;
        }

        $this->querySortBy = $column;
        $this->querySortDir = 'desc';
    }

    /**
     * @return array<string, mixed>
     */
    public function getGscKpisProperty(): array
    {
        return $this->performanceHub->getGscKpis($this->resolveSiteId());
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getGscQueriesProperty(): array
    {
        return $this->performanceHub->getGscQueries(
            $this->resolveSiteId(),
            $this->querySortBy,
            $this->querySortDir,
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getQuickWinRowsProperty(): array
    {
        return $this->performanceHub->getQuickWinQueries($this->resolveSiteId());
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getCannibalizationRowsProperty(): array
    {
        return $this->performanceHub->detectCannibalization($this->resolveSiteId());
    }

    public function pushQuickWinToEditor(string $phrase, string $type = Keyword::TYPE_SUGGEST): void
    {
        if (! SeoAccessControl::canMutateInSeoPanel()) {
            Notification::make()
                ->title(__('seo-content-ai::filament.keyword.workspace_save_denied'))
                ->danger()
                ->send();

            return;
        }

        $siteId = (int) ($this->resolveSiteId() ?? 0);
        if ($siteId <= 0) {
            Notification::make()
                ->title(__('seo-content-ai::filament.performance_hub.no_domain'))
                ->warning()
                ->send();

            return;
        }

        $keyword = $this->performanceHub->pushKeywordToEditor($phrase, $siteId, $type);
        if (! $keyword instanceof Keyword) {
            Notification::make()
                ->title(__('seo-content-ai::filament.performance_hub.push_failed'))
                ->danger()
                ->send();

            return;
        }

        Notification::make()
            ->title(__('seo-content-ai::filament.performance_hub.push_success', ['phrase' => $keyword->phrase]))
            ->success()
            ->send();
    }

    public function generateAiKeywords(): void
    {
        $this->selectedSuggestionIds = [];

        try {
            $this->suggestions = $this->discovery->discover(
                $this->seedKeyword,
                $this->searchIntent,
                $this->targetRegion,
            );
        } catch (\InvalidArgumentException|\App\Addons\SeoContentAi\Exceptions\PromptRunException $exception) {
            Notification::make()
                ->title(__('seo-content-ai::filament.keyword.discovery_failed'))
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return;
        }

        Notification::make()
            ->title(__('seo-content-ai::filament.keyword.discovery_success', ['count' => count($this->suggestions)]))
            ->success()
            ->send();
    }

    public function toggleSuggestion(string $suggestionId): void
    {
        if ($suggestionId === '') {
            return;
        }

        if (in_array($suggestionId, $this->selectedSuggestionIds, true)) {
            $this->selectedSuggestionIds = array_values(array_filter(
                $this->selectedSuggestionIds,
                static fn (string $id): bool => $id !== $suggestionId,
            ));

            return;
        }

        $this->selectedSuggestionIds[] = $suggestionId;
    }

    public function batchImportSuggestions(): void
    {
        if (! SeoAccessControl::canMutateInSeoPanel()) {
            Notification::make()
                ->title(__('seo-content-ai::filament.keyword.workspace_save_denied'))
                ->danger()
                ->send();

            return;
        }

        $siteId = (int) ($this->resolveSiteId() ?? 0);
        if ($siteId <= 0) {
            Notification::make()
                ->title(__('seo-content-ai::filament.keyword.discovery_no_domain'))
                ->warning()
                ->send();

            return;
        }

        $selected = $this->resolveSelectedSuggestions();
        if ($selected === []) {
            Notification::make()
                ->title(__('seo-content-ai::filament.keyword.discovery_none_selected'))
                ->warning()
                ->send();

            return;
        }

        $created = 0;
        $skipped = 0;

        foreach ($selected as $item) {
            $phrase = Keyword::decodePhrase((string) ($item['keyword'] ?? ''));
            if ($phrase === '' || ! InternalAnchorKeywordFilter::isUsableAnchorPhrase($phrase)) {
                $skipped++;

                continue;
            }

            if (app(CtaKeywordBlacklistFilter::class)->isBlocked($phrase)) {
                $skipped++;

                continue;
            }

            $metrics = [
                'discovery_intent' => (string) ($item['intent'] ?? ''),
                'discovery_difficulty' => (string) ($item['difficulty'] ?? ''),
                'discovery_title_idea' => (string) ($item['title_idea'] ?? ''),
                'discovery_reason' => (string) ($item['relevancy_reason'] ?? ''),
                'discovery_seed' => trim($this->seedKeyword),
                'discovery_region' => $this->targetRegion,
            ];

            $existing = Keyword::query()
                ->whereRaw('phrase COLLATE utf8mb4_unicode_ci = ?', [$phrase])
                ->exists();

            $this->keywordPersistence->upsert(
                phrase: $phrase,
                type: Keyword::TYPE_SUGGEST,
                siteId: $siteId,
                metrics: $metrics,
            );

            if (! $existing) {
                $created++;
            }
        }

        Notification::make()
            ->title(__('seo-content-ai::filament.keyword.discovery_import_success', ['count' => $created]))
            ->body($skipped > 0
                ? __('seo-content-ai::filament.keyword.discovery_import_skipped', ['count' => $skipped])
                : null)
            ->success()
            ->send();
    }

    public function getSelectedSuggestionCount(): int
    {
        return count($this->selectedSuggestionIds);
    }

    public function isAllSuggestionsSelected(): bool
    {
        $total = count($this->suggestions);

        return $total > 0 && count($this->selectedSuggestionIds) === $total;
    }

    public function toggleSelectAllSuggestions(): void
    {
        $allIds = collect($this->suggestions)
            ->pluck('id')
            ->filter(static fn (mixed $id): bool => is_string($id) && $id !== '')
            ->values()
            ->all();

        if ($allIds !== [] && count($this->selectedSuggestionIds) === count($allIds)) {
            $this->selectedSuggestionIds = [];

            return;
        }

        $this->selectedSuggestionIds = $allIds;
    }

    public function toggleSelectAll(): void
    {
        $this->toggleSelectAllSuggestions();
    }

    public function isAllSelected(): bool
    {
        return $this->isAllSuggestionsSelected();
    }

    public function batchImport(): void
    {
        $this->batchImportSuggestions();
    }

    public function copyKeyword(string $suggestionId): void
    {
        $keyword = collect($this->suggestions)
            ->first(static fn (array $item): bool => ($item['id'] ?? '') === $suggestionId);

        if (! is_array($keyword)) {
            return;
        }

        $phrase = trim((string) ($keyword['keyword'] ?? ''));
        if ($phrase === '') {
            return;
        }

        $this->dispatch('discovery-copy-keyword', phrase: $phrase);

        Notification::make()
            ->title(__('seo-content-ai::filament.keyword.discovery_copied'))
            ->success()
            ->send();
    }

    public function createDraftArticles(): void
    {
        if (! SeoAccessControl::canMutateInSeoPanel()) {
            Notification::make()
                ->title(__('seo-content-ai::filament.keyword.workspace_save_denied'))
                ->danger()
                ->send();

            return;
        }

        $siteId = (int) ($this->resolveSiteId() ?? 0);
        if ($siteId <= 0) {
            Notification::make()
                ->title(__('seo-content-ai::filament.keyword.discovery_no_domain'))
                ->warning()
                ->send();

            return;
        }

        $selected = $this->resolveSelectedSuggestions();
        if ($selected === []) {
            Notification::make()
                ->title(__('seo-content-ai::filament.keyword.discovery_none_selected'))
                ->warning()
                ->send();

            return;
        }

        $keywordsRaw = collect($selected)
            ->pluck('keyword')
            ->filter(static fn (mixed $value): bool => is_string($value) && trim($value) !== '')
            ->implode("\n");

        try {
            $result = $this->articleCreator->runFromKeywordsForSite($keywordsRaw, $siteId);
        } catch (\InvalidArgumentException $exception) {
            Notification::make()
                ->title(__('seo-content-ai::filament.keyword.discovery_draft_failed'))
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return;
        }

        Notification::make()
            ->title(__('seo-content-ai::filament.keyword.discovery_draft_success', [
                'created' => (int) ($result['created'] ?? 0),
                'failed' => (int) ($result['failed'] ?? 0),
            ]))
            ->success()
            ->send();
    }

    /**
     * @return array<int, string>
     */
    public function getSiteFilterOptionsProperty(): array
    {
        return KeywordResource::siteSelectOptions();
    }

    private function resolveSiteId(): ?int
    {
        $siteId = SeoAccessControl::globalSiteId();

        return ($siteId !== null && $siteId > 0) ? $siteId : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function resolveSelectedSuggestions(): array
    {
        if ($this->selectedSuggestionIds === []) {
            return [];
        }

        return collect($this->suggestions)
            ->filter(fn (array $item): bool => in_array((string) ($item['id'] ?? ''), $this->selectedSuggestionIds, true))
            ->values()
            ->all();
    }
}
