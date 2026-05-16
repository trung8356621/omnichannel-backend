<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Filament\Resources\DomainResource\Pages;

use App\Addons\SeoContentAi\Filament\Resources\DomainResource;
use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Services\SyncDomainContentService;
use App\Models\Site;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
class GeneralDomain extends Page
{
    use InteractsWithRecord;

    protected static string $resource = DomainResource::class;

    protected static string $view = 'seo-content-ai::filament.resources.domain-resource.pages.general-domain';

    public function mount(int | string $record): void
    {
        $this->record = $this->resolveRecord($record);

        static::authorizeResourceAccess();

        abort_unless(static::getResource()::canEdit($this->getRecord()), 403);
    }

    public function getTitle(): string | Htmlable
    {
        /** @var Site $site */
        $site = $this->getRecord();

        return __('Tổng quan') . ': ' . $site->domain;
    }

    /**
     * Đã có ít nhất một bản ghi SEO article cho site này.
     */
    public function isSiteSynced(): bool
    {
        return SeoArticle::query()
            ->where('site_id', $this->getRecord()->getKey())
            ->exists();
    }

    /**
     * @return array{scored: int, avg_score: float|null, min_score: float|null, max_score: float|null}
     */
    public function getScoringStatistics(): array
    {
        $siteId = (int) $this->getRecord()->getKey();
        $base = SeoArticle::query()->where('site_id', $siteId)->whereNotNull('seo_score');

        $scored = (clone $base)->count();
        if ($scored === 0) {
            return [
                'scored' => 0,
                'avg_score' => null,
                'min_score' => null,
                'max_score' => null,
            ];
        }

        return [
            'scored' => $scored,
            'avg_score' => round((float) (clone $base)->avg('seo_score'), 1),
            'min_score' => round((float) (clone $base)->min('seo_score'), 1),
            'max_score' => round((float) (clone $base)->max('seo_score'), 1),
        ];
    }

    /**
     * @return array{articles: int, products: int, categories: int, product_categories: int, other: int}
     */
    public function getSyncStatistics(): array
    {
        $siteId = (int) $this->getRecord()->getKey();
        $base = SeoArticle::query()->where('site_id', $siteId);

        $articles = (clone $base)->where(function ($q): void {
            $q->where('type', 'article')->orWhereNull('type');
        })->count();

        $products = (clone $base)->where('type', 'product')->count();
        $categories = (clone $base)->where('type', 'category')->count();
        $productCategories = (clone $base)->where('type', 'product_category')->count();

        $other = (clone $base)->whereNotNull('type')
            ->whereNotIn('type', ['article', 'product', 'category', 'product_category'])
            ->count();

        return [
            'articles'           => $articles,
            'products'           => $products,
            'categories'         => $categories,
            'product_categories' => $productCategories,
            'other'              => $other,
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('sync_data')
                ->label('Đồng bộ dữ liệu')
                ->color('warning')
                ->icon('heroicon-o-arrow-path')
                ->requiresConfirmation()
                ->action(fn () => $this->runDomainSync(false)),
            Action::make('test_sync_data')
                ->label('Test đồng bộ (Debug)')
                ->icon('heroicon-o-bug-ant')
                ->color('danger')
                ->visible(fn (): bool => auth()->user()?->role === 'admin')
                ->requiresConfirmation()
                ->action(fn () => $this->runDomainSync(true)),
        ];
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
                ->title($isTest ? 'Test đồng bộ thành công' : 'Đồng bộ thành công')
                ->body($result['message'])
                ->success()
                ->send();

            return;
        }

        Notification::make()
            ->title($isTest ? 'Test đồng bộ thất bại' : 'Đồng bộ thất bại')
            ->body($result['message'])
            ->danger()
            ->send();
    }
}
