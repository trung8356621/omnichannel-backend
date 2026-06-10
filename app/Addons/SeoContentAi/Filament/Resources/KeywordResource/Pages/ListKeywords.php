<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Filament\Resources\KeywordResource\Pages;

use App\Addons\SeoContentAi\Filament\Resources\KeywordResource;
use App\Addons\SeoContentAi\Models\Keyword;
use App\Addons\SeoContentAi\Support\InternalAnchorKeywordFilter;
use App\Addons\SeoContentAi\Support\SeoAccessControl;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListKeywords extends ListRecords
{
    protected static string $resource = KeywordResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('add_keywords')
                ->label('Add keyword')
                ->icon('heroicon-o-plus')
                ->form([
                    Forms\Components\Select::make('site_id')
                        ->label('Domain')
                        ->options(fn (): array => KeywordResource::siteSelectOptions())
                        ->default(fn (): ?int => SeoAccessControl::globalSiteId())
                        ->hidden(fn (): bool => SeoAccessControl::hasGlobalSiteScope())
                        ->required()
                        ->searchable()
                        ->preload()
                        ->native(false),
                    Forms\Components\Textarea::make('phrases')
                        ->label('Keywords')
                        ->helperText('Mỗi dòng là một keyword internal, không tự gắn vào bài viết.')
                        ->rows(12)
                        ->required(),
                ])
                ->action(function (array $data): void {
                    $siteId = (int) ($data['site_id'] ?? SeoAccessControl::globalSiteId() ?? 0);
                    $phrases = collect(preg_split('/\R/u', (string) ($data['phrases'] ?? '')) ?: [])
                        ->map(static fn (string $phrase): string => trim($phrase))
                        ->filter()
                        ->unique(static fn (string $phrase): string => mb_strtolower($phrase))
                        ->values();

                    $created = 0;
                    $invalid = 0;
                    foreach ($phrases as $phrase) {
                        if (! InternalAnchorKeywordFilter::isUsableAnchorPhrase($phrase)) {
                            $invalid++;

                            continue;
                        }

                        $keyword = Keyword::query()->firstOrCreate(
                            [
                                'site_id' => $siteId,
                                'type' => Keyword::TYPE_INTERNAL,
                                'phrase' => $phrase,
                            ],
                            [
                                'user_id' => auth()->id(),
                                'parent_id' => null,
                            ],
                        );
                        if ($keyword->wasRecentlyCreated) {
                            $created++;
                        }
                    }

                    Notification::make()
                        ->title("Đã thêm {$created} keyword internal")
                        ->body($invalid > 0 ? "Bỏ qua {$invalid} dòng không hợp lệ." : null)
                        ->success()
                        ->send();
                }),
        ];
    }

    /**
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        return [
            'free' => Tab::make('Free')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query
                    ->whereNull('parent_id')
                    ->whereDoesntHave('children')),
            'pillar_cluster' => Tab::make('Pillar / Cluster')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query
                    ->where(fn (Builder $clusterQuery): Builder => $clusterQuery
                        ->whereNotNull('parent_id')
                        ->orWhereHas('children'))),
        ];
    }
}
