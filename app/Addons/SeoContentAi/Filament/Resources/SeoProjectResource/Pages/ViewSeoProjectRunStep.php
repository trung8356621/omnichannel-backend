<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Filament\Resources\SeoProjectResource\Pages;

use App\Addons\SeoContentAi\Filament\Resources\ArticleResource;
use App\Addons\SeoContentAi\Filament\Resources\SeoProjectResource;
use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Models\SeoProjectRun;
use App\Addons\SeoContentAi\Services\ArticlePromptRunHistoryService;
use App\Addons\SeoContentAi\Support\SeoAccessControl;
use Filament\Actions;
use Filament\Resources\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;

final class ViewSeoProjectRunStep extends Page
{
    protected static string $resource = SeoProjectResource::class;

    protected static string $view = 'seo-content-ai::filament.resources.seo-project-resource.pages.view-project-run-step';

    protected static bool $shouldRegisterNavigation = false;

    public int|string $run;

    public int|string $article;

    public ?SeoProjectRun $projectRun = null;

    public ?SeoArticle $articleRecord = null;

    public function mount(int|string $run, int|string $article): void
    {
        self::authorizeResourceAccess();

        $this->run = (int) $run;
        $this->article = (int) $article;
        $this->projectRun = SeoProjectRun::query()
            ->with('project')
            ->findOrFail($this->run);

        abort_unless(
            SeoAccessControl::canAccessContentProjectRun($this->projectRun->project),
            403,
        );

        abort_unless(
            SeoProjectResource::getRecordRouteBindingEloquentQuery()
                ->whereKey((int) $this->projectRun->project_id)
                ->exists(),
            403,
        );

        $this->articleRecord = ArticleResource::getRecordRouteBindingEloquentQuery()
            ->findOrFail($this->article);
    }

    public function getTitle(): string|Htmlable
    {
        return 'Runs - '.trim((string) ($this->articleRecord?->title ?? 'Bài viết'));
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
            Actions\Action::make('back_to_run')
                ->label('Back to run')
                ->icon('heroicon-o-arrow-left')
                ->url(fn (): string => SeoProjectResource::getUrl('view-run', ['run' => $this->projectRun])),
        ];
    }
}
