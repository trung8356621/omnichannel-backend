<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Filament\Resources\ArticleResource\Pages;

use App\Addons\SeoContentAi\Filament\Resources\ArticleResource;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ListArticlesTrash extends ListRecords
{
    protected static string $resource = ArticleResource::class;

    protected static ?string $navigationLabel = 'Trash';

    protected static ?string $title = 'Article trash';

    protected static bool $shouldRegisterNavigation = false;

    protected function getTableQuery(): Builder
    {
        return parent::getTableQuery()->onlyTrashed();
    }

    public function table(Table $table): Table
    {
        return ArticleResource::table($table)
            ->recordAction(null)
            ->actions([
                Tables\Actions\RestoreAction::make()
                    ->iconButton(),
                Tables\Actions\ForceDeleteAction::make()
                    ->iconButton(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\RestoreBulkAction::make(),
                    Tables\Actions\ForceDeleteBulkAction::make(),
                ]),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('emptyTrash')
                ->label('Empty')
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Empty trash')
                ->modalDescription('Permanently delete all articles in trash. This cannot be undone.')
                ->modalSubmitActionLabel('Delete all')
                ->disabled(fn (): bool => ! $this->getTableQuery()->exists())
                ->action(function (): void {
                    $query = $this->getTableQuery();
                    $count = (clone $query)->count();

                    $query->cursor()->each(static function ($article): void {
                        $article->forceDelete();
                    });

                    Notification::make()
                        ->title($count > 0
                            ? "Permanently deleted {$count} articles"
                            : 'Trash is already empty')
                        ->success()
                        ->send();
                }),
            Actions\Action::make('backToList')
                ->label('Article list')
                ->icon('heroicon-o-arrow-left')
                ->url(ArticleResource::getUrl('index')),
        ];
    }
}
