<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Filament\Resources\DomainResource\Pages;

use App\Addons\SeoContentAi\Filament\Resources\DomainResource;
use App\Addons\SeoContentAi\Services\ClearDomainArticlesService;
use App\Addons\SeoContentAi\Services\DomainOverviewService;
use App\Addons\SeoContentAi\Services\SyncDomainContentService;
use App\Models\Site;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class GeneralDomain extends Page
{
    use InteractsWithRecord;

    protected static string $resource = DomainResource::class;

    protected static string $view = 'seo-content-ai::filament.resources.domain-resource.pages.general-domain';

    public string $internalLinkTab = 'keywords';

    public bool $tokensUnlocked = false;

    public bool $readTokenVisible = false;

    public bool $migrationTokenVisible = false;

    public bool $showPasswordPrompt = false;

    public ?string $pendingRevealField = null;

    public string $tokenPassword = '';

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        static::authorizeResourceAccess();

        abort_unless(static::getResource()::canEdit($this->getRecord()), 403);

        $this->tokensUnlocked = $this->canRevealTokensWithoutPassword();

        $tab = request()->query('tab');
        if (is_string($tab) && in_array($tab, ['keywords', 'links'], true)) {
            $this->internalLinkTab = $tab;
        }
    }

    public function getTitle(): string|Htmlable
    {
        /** @var Site $site */
        $site = $this->getRecord();

        return __('Overview') . ': ' . $site->domain;
    }

    public function getSite(): Site
    {
        /** @var Site $site */
        $site = $this->getRecord();

        return $site;
    }

    public function isSiteSynced(): bool
    {
        return app(DomainOverviewService::class)->isSiteSynced((int) $this->getRecord()->getKey());
    }

    public function getClearDomainConfirmMessage(): string
    {
        $count = app(ClearDomainArticlesService::class)->countForSite($this->getRecord());

        return "This will permanently delete {$count} records from the SEO storage (synced posts, products, categories). "
            . 'WordPress content is not changed. This action cannot be undone.';
    }

    /**
     * @return array<string, mixed>
     */
    public function getApiTokenSummary(): array
    {
        return app(DomainOverviewService::class)->getApiTokenSummary($this->getSite());
    }

    /**
     * @return array{read_token: string, migration_token: string}
     */
    public function getPlainTokens(): array
    {
        return app(DomainOverviewService::class)->getApiTokensPlain($this->getSite());
    }

    public function toggleTokenVisibility(string $field): void
    {
        if (! $this->tokensUnlocked) {
            $this->pendingRevealField = in_array($field, ['read', 'migration'], true) ? $field : 'read';
            $this->showPasswordPrompt = true;
            $this->tokenPassword = '';

            return;
        }

        if ($field === 'migration') {
            $this->migrationTokenVisible = ! $this->migrationTokenVisible;

            return;
        }

        $this->readTokenVisible = ! $this->readTokenVisible;
    }

    public function cancelPasswordPrompt(): void
    {
        $this->showPasswordPrompt = false;
        $this->tokenPassword = '';
        $this->pendingRevealField = null;
    }

    public function confirmRevealTokens(): void
    {
        $user = auth()->user();
        if ($user === null) {
            throw ValidationException::withMessages([
                'tokenPassword' => 'You need to sign in to view tokens.',
            ]);
        }

        if (! Hash::check($this->tokenPassword, $user->password)) {
            throw ValidationException::withMessages([
                'tokenPassword' => 'Incorrect password.',
            ]);
        }

        session(['seo_domain_tokens_verified' => true]);
        $this->tokensUnlocked = true;
        $this->showPasswordPrompt = false;
        $this->applyPendingTokenVisibility();
        $this->tokenPassword = '';
    }

    public function getInternalLinkTabUrl(string $tab): string
    {
        return static::getUrl(['record' => $this->getRecord()]) . '?tab=' . urlencode($tab);
    }

    public function getArticlesFilterUrl(string $band): string
    {
        return app(DomainOverviewService::class)->buildArticlesFilterUrl(
            (int) $this->getRecord()->getKey(),
            $band,
        );
    }

    public function getArticlesFilterUrlForLink(string $url, string $type): string
    {
        return app(DomainOverviewService::class)->buildArticlesFilterUrlForLink(
            (int) $this->getRecord()->getKey(),
            $url,
            $type,
        );
    }

    public function getArticlesFilterUrlForKeyword(int $keywordId): string
    {
        return app(DomainOverviewService::class)->buildArticlesFilterUrlForKeyword(
            (int) $this->getRecord()->getKey(),
            $keywordId,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function getScoringStatistics(): array
    {
        return app(DomainOverviewService::class)->getScoringStatistics((int) $this->getRecord()->getKey());
    }

    /**
     * @return array<string, mixed>
     */
    public function getScoreDistribution(): array
    {
        return app(DomainOverviewService::class)->getScoreDistribution((int) $this->getRecord()->getKey());
    }

    /**
     * @return array<string, mixed>
     */
    public function getSyncStatistics(): array
    {
        return app(DomainOverviewService::class)->getSyncStatistics((int) $this->getRecord()->getKey());
    }

    /**
     * @return Collection<int, object>
     */
    public function getTopKeywords(): Collection
    {
        return app(DomainOverviewService::class)->getTopKeywords((int) $this->getRecord()->getKey());
    }

    /**
     * @return Collection<int, object>
     */
    public function getTopLinks(): Collection
    {
        return app(DomainOverviewService::class)->getTopLinks((int) $this->getRecord()->getKey());
    }

    /**
     * @return array<string, mixed>
     */
    public function getTechnicalSeoSummary(): array
    {
        return app(DomainOverviewService::class)->getTechnicalSeoSummary($this->getSite());
    }

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return $this->domainActions();
    }

    /**
     * @return array<int, Action>
     */
    protected function domainActions(): array
    {
        return [
            DeleteAction::make()
                ->label(__('Delete domain'))
                ->icon('heroicon-o-trash')
                ->successRedirectUrl(DomainResource::getUrl('index')),
            Action::make('sync_data')
                ->label('Sync data')
                ->color('warning')
                ->icon('heroicon-o-arrow-path')
                ->requiresConfirmation()
                ->modalDescription('Run content sync from WordPress for this domain.')
                ->action(function (): void {
                    $this->runDomainSync(false);
                    $this->redirectToOverview();
                }),
            Action::make('test_sync_data')
                ->label('Test sync (debug)')
                ->icon('heroicon-o-bug-ant')
                ->color('danger')
                ->visible(fn (): bool => auth()->user()?->role === 'admin')
                ->requiresConfirmation()
                ->modalDescription('Run sync test (limit 2 records per type).')
                ->action(function (): void {
                    $this->runDomainSync(true);
                    $this->redirectToOverview();
                }),
            Action::make('clear_domain_content')
                ->label('Cleanup')
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->visible(fn (): bool => $this->isSiteSynced())
                ->requiresConfirmation()
                ->modalHeading('Clean up domain content')
                ->modalDescription(fn (): string => $this->getClearDomainConfirmMessage())
                ->modalSubmitActionLabel('Delete all')
                ->action(function (): void {
                    $this->runClearDomainContent();
                    $this->redirectToOverview();
                }),
        ];
    }

    private function applyPendingTokenVisibility(): void
    {
        if ($this->pendingRevealField === 'migration') {
            $this->migrationTokenVisible = true;
        } elseif ($this->pendingRevealField === 'read') {
            $this->readTokenVisible = true;
        }

        $this->pendingRevealField = null;
    }

    private function canRevealTokensWithoutPassword(): bool
    {
        if (auth('sanctum')->check()) {
            return true;
        }

        return (bool) session('seo_domain_tokens_verified', false);
    }

    private function redirectToOverview(): void
    {
        $this->redirect(static::getUrl(['record' => $this->getRecord()]), navigate: false);
    }

    private function runClearDomainContent(): void
    {
        /** @var Site $site */
        $site = $this->getRecord();

        $result = app(ClearDomainArticlesService::class)->clear($site);

        Notification::make()
            ->title($result['deleted'] > 0 ? 'Cleanup completed' : 'No data')
            ->body($result['message'])
            ->success()
            ->send();
    }

    private function runDomainSync(bool $isTest): void
    {
        /** @var Site $site */
        $site = $this->getRecord();

        $result = app(SyncDomainContentService::class)->sync($site, [
            'is_test' => $isTest,
            'limit_per_type' => $isTest ? 2 : 0,
        ]);

        if ($result['success']) {
            Notification::make()
                ->title($isTest ? 'Test sync successful' : 'Sync successful')
                ->body($result['message'])
                ->success()
                ->send();

            return;
        }

        Notification::make()
            ->title($isTest ? 'Test sync failed' : 'Sync failed')
            ->body($result['message'])
            ->danger()
            ->send();
    }
}
