<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Filament\Resources\SeoProjectResource\Pages;

use App\Addons\SeoContentAi\Filament\Resources\SeoProjectResource;
use App\Addons\SeoContentAi\Models\SeoProject;
use App\Addons\SeoContentAi\Support\SeoAccessControl;
use Filament\Actions;

final class ViewSeoProject extends EditSeoProject
{
    public function mount(int|string $record): void
    {
        parent::mount($record);

        $this->form->disabled();
    }

    public function getTitle(): string
    {
        /** @var SeoProject $record */
        $record = $this->getRecord();

        return __('seo-content-ai::filament.projects.view_project', [
            'name' => (string) $record->name,
        ]);
    }

    protected function authorizeAccess(): void
    {
        abort_unless(self::getResource()::canView($this->getRecord()), 403);
    }

    protected function getFormActions(): array
    {
        return [];
    }

    protected function getHeaderActions(): array
    {
        /** @var SeoProject $record */
        $record = $this->getRecord();

        return [
            SeoProjectResource::makeArchiveProjectPageAction($record),
            Actions\Action::make('view_runs')
                ->label(__('seo-content-ai::filament.projects.view_runs'))
                ->icon('heroicon-o-queue-list')
                ->color('gray')
                ->visible(fn (): bool => SeoAccessControl::canAccessContentProjectRun($this->getRecord()))
                ->url(fn (): string => SeoProjectResource::getRunHistoryUrl($this->getRecord())),
        ];
    }
}
