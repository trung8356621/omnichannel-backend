<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Filament\Resources\ArticleResource\Pages;

use App\Addons\SeoContentAi\Filament\Resources\ArticleResource;
use App\Addons\SeoContentAi\Filament\Resources\SeoProjectResource;
use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Services\ArticlePromptRunHistoryService;
use App\Addons\SeoContentAi\Support\SeoAccessControl;
use Filament\Actions;
use Filament\Resources\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;

final class ViewArticlePrompts extends Page
{
    protected static string $resource = ArticleResource::class;

    protected static string $view = 'seo-content-ai::filament.resources.article-resource.pages.view-article-prompts';

    protected static bool $shouldRegisterNavigation = false;

    public int|string $record;

    public ?SeoArticle $articleRecord = null;

    public function mount(int|string $record): void
    {
        self::authorizeResourceAccess();
        abort_unless(SeoAccessControl::canAccessContentFeatures(), 403);

        $this->record = (int) $record;
        $this->articleRecord = ArticleResource::getRecordRouteBindingEloquentQuery()
            ->findOrFail($this->record);
    }

    public function getTitle(): string|Htmlable
    {
        return 'Prompts - ' . trim((string) ($this->articleRecord?->title ?? 'Bài viết'));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getRunGroups(): array
    {
        if (! $this->articleRecord instanceof SeoArticle) {
            return [];
        }

        $projectIds = SeoProjectResource::getEloquentQuery()
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        return app(ArticlePromptRunHistoryService::class)->build(
            $this->articleRecord,
            $projectIds,
        );
    }

    public function getArticleId(): int
    {
        return (int) ($this->articleRecord?->getKey() ?? 0);
    }

    public function getArticleEditUrl(): ?string
    {
        $articleId = $this->getArticleId();

        return $articleId > 0
            ? ArticleResource::panelUrl('edit', ['record' => $articleId])
            : null;
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('back_to_article')
                ->label('Back to article')
                ->icon('heroicon-o-arrow-left')
                ->url(fn (): ?string => $this->getArticleEditUrl()),
        ];
    }
}

