<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Filament\Resources\KeywordResource\Pages;

use App\Addons\SeoContentAi\Filament\Resources\KeywordResource;
use App\Addons\SeoContentAi\Models\Keyword;
use App\Addons\SeoContentAi\Services\KeywordDomainResyncService;
use App\Addons\SeoContentAi\Services\KeywordPersistenceService;
use App\Addons\SeoContentAi\Support\CtaKeywordBlacklistFilter;
use App\Addons\SeoContentAi\Support\InternalAnchorKeywordFilter;
use App\Addons\SeoContentAi\Support\SeoAccessControl;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;

class ListKeywords extends ListRecords
{
    protected static string $resource = KeywordResource::class;

    #[Url(as: 'parent_id')]
    public ?int $parentId = null;

    public function mount(): void
    {
        SeoAccessControl::setGlobalSiteId(null);
    }

    public function getSubheading(): ?string
    {
        if ($this->parentId === null || $this->parentId <= 0) {
            return null;
        }

        $parentPhrase = Keyword::query()
            ->whereKey($this->parentId)
            ->value('phrase');

        if (! is_string($parentPhrase) || $parentPhrase === '') {
            return __('seo-content-ai::filament.keyword.viewing_children');
        }

        return __('seo-content-ai::filament.keyword.viewing_children_of', [
            'phrase' => $parentPhrase,
        ]);
    }

    /**
     * Global keyword dictionary: GlobalSeoBar domain must not filter this listing.
     */
    protected function getTableQuery(): ?Builder
    {
        $query = parent::getTableQuery();

        if (! $query instanceof Builder) {
            return $query;
        }

        return KeywordResource::applyParentScopeToQuery($query, $this->parentId);
    }

    protected function getHeaderActions(): array
    {
        $actions = [];

        if ($this->parentId !== null && $this->parentId > 0) {
            $actions[] = Actions\Action::make('back_to_roots')
                ->label(__('seo-content-ai::filament.keyword.back_to_parents'))
                ->icon('heroicon-o-arrow-left')
                ->color('gray')
                ->url(KeywordResource::buildRootKeywordsUrl());
        }

        $actions[] = Actions\Action::make('resync_keywords')
            ->label(__('seo-content-ai::filament.keyword.resync_linked'))
            ->icon('heroicon-o-arrow-path')
            ->color('danger')
            ->visible(fn (): bool => SeoAccessControl::globalSiteId() !== null)
            ->requiresConfirmation()
            ->modalHeading(__('seo-content-ai::filament.keyword.resync_linked'))
            ->modalDescription(__('seo-content-ai::filament.keyword.resync_linked_confirm'))
            ->modalSubmitActionLabel(__('seo-content-ai::filament.keyword.resync_linked_submit'))
            ->action(function (KeywordDomainResyncService $resyncService): void {
                $siteId = (int) (SeoAccessControl::globalSiteId() ?? 0);
                if ($siteId <= 0) {
                    Notification::make()
                        ->title(__('seo-content-ai::filament.keyword.resync_linked_failed'))
                        ->body(__('seo-content-ai::filament.keyword.resync_linked_no_domain'))
                        ->danger()
                        ->send();

                    return;
                }

                $result = $resyncService->resetAndResync($siteId);

                Notification::make()
                    ->title(__('seo-content-ai::filament.keyword.resync_linked_completed'))
                    ->body(__('seo-content-ai::filament.keyword.resync_linked_body', $result))
                    ->success()
                    ->send();
            });

        $actions[] = Actions\Action::make('add_keywords')
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
                    ->helperText('Mỗi dòng là một keyword free (type=free), không tự gắn vào bài viết.')
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
                $blocked = 0;
                foreach ($phrases as $phrase) {
                    $decodedPhrase = Keyword::decodePhrase($phrase);
                    if ($decodedPhrase === '') {
                        $invalid++;

                        continue;
                    }

                    if (! InternalAnchorKeywordFilter::isUsableAnchorPhrase($decodedPhrase)) {
                        $invalid++;

                        continue;
                    }

                    if (app(CtaKeywordBlacklistFilter::class)->isBlocked($decodedPhrase)) {
                        $blocked++;

                        continue;
                    }

                    $alreadyExists = Keyword::query()
                        ->whereRaw('phrase COLLATE utf8mb4_unicode_ci = ?', [$decodedPhrase])
                        ->exists();

                    app(KeywordPersistenceService::class)->upsert(
                        $decodedPhrase,
                        Keyword::TYPE_FREE,
                        $siteId,
                    );

                    if (! $alreadyExists) {
                        $created++;
                    }
                }

                Notification::make()
                    ->title("Đã thêm {$created} keyword free")
                    ->body(collect([
                        $invalid > 0 ? "Bỏ qua {$invalid} dòng không hợp lệ." : null,
                        $blocked > 0 ? "Bỏ qua {$blocked} dòng thuộc CTA blacklist." : null,
                    ])->filter()->implode(' '))
                    ->success()
                    ->send();
            });

        return $actions;
    }
}
