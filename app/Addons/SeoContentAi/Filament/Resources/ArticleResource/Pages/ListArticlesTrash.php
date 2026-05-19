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

    protected static ?string $navigationLabel = 'Thùng rác';

    protected static ?string $title = 'Thùng rác bài viết';

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
                ->modalHeading('Dọn thùng rác')
                ->modalDescription('Xóa vĩnh viễn tất cả bài viết trong thùng rác. Không thể hoàn tác.')
                ->modalSubmitActionLabel('Xóa hết')
                ->disabled(fn (): bool => ! $this->getTableQuery()->exists())
                ->action(function (): void {
                    $query = $this->getTableQuery();
                    $count = (clone $query)->count();

                    $query->cursor()->each(static function ($article): void {
                        $article->forceDelete();
                    });

                    Notification::make()
                        ->title($count > 0
                            ? "Đã xóa vĩnh viễn {$count} bài viết"
                            : 'Thùng rác đã trống')
                        ->success()
                        ->send();
                }),
            Actions\Action::make('backToList')
                ->label('Danh sách bài viết')
                ->icon('heroicon-o-arrow-left')
                ->url(ArticleResource::getUrl('index')),
        ];
    }
}
