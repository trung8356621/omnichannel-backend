<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Filament\Resources\AiConnectionResource\Pages;

use App\Addons\SeoContentAi\Filament\Resources\AiConnectionResource;
use App\Addons\SeoContentAi\Services\ApiConnectionsListService;
use App\Addons\SeoContentAi\Support\ApiConnectionProviders;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;

class ListAiConnections extends ListRecords
{
    protected static string $resource = AiConnectionResource::class;

    protected static string $view = 'seo-content-ai::filament.pages.seo-settings-api-list';

    private ApiConnectionsListService $connectionsList;

    public function boot(ApiConnectionsListService $connectionsList): void
    {
        $this->connectionsList = $connectionsList;
        $this->notifyOAuthFlash();
    }

    private function notifyOAuthFlash(): void
    {
        $success = session()->pull('gsc_oauth_success');
        if (is_string($success) && $success !== '') {
            Notification::make()
                ->title($success)
                ->success()
                ->send();
        }

        $error = session()->pull('gsc_oauth_error');
        if (is_string($error) && $error !== '') {
            Notification::make()
                ->title($error)
                ->danger()
                ->send();
        }
    }

    public function getTitle(): string
    {
        return '';
    }

    public function getTableRecords(): EloquentCollection|Paginator|CursorPaginator
    {
        $records = $this->connectionsList->recordsForUser((int) auth()->id());

        $search = trim((string) $this->getTableSearch());
        if ($search !== '') {
            $needle = mb_strtolower($search);
            $records = $records
                ->filter(function (Model $record) use ($needle): bool {
                    $name = mb_strtolower((string) $record->getAttribute('name'));
                    $provider = mb_strtolower(
                        ApiConnectionProviders::label((string) $record->getAttribute('provider')),
                    );

                    return str_contains($name, $needle) || str_contains($provider, $needle);
                })
                ->values();
        }

        $sortColumn = $this->getTableSortColumn();
        if (in_array($sortColumn, ['name', 'provider'], true)) {
            $descending = $this->getTableSortDirection() === 'desc';
            $records = $records
                ->sortBy(
                    fn (Model $record): string => $sortColumn === 'provider'
                        ? ApiConnectionProviders::label((string) $record->getAttribute('provider'))
                        : (string) $record->getAttribute('name'),
                    SORT_NATURAL | SORT_FLAG_CASE,
                    $descending,
                )
                ->values();
        }

        return $records;
    }

    public function getTableRecord(?string $key): ?Model
    {
        if ($key === null) {
            return null;
        }

        $record = $this->getTableRecords()->first(
            fn (Model $record): bool => (string) $record->getKey() === $key,
        );

        return $record ?? parent::getTableRecord($key);
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label(__('seo-content-ai::filament.api_connections.add_connection')),
        ];
    }
}
