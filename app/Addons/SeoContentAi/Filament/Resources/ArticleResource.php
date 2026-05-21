<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Filament\Resources;

use App\Addons\SeoContentAi\Filament\Resources\ArticleResource\Pages;
use App\Addons\SeoContentAi\Models\Keyword;
use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Models\Site;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class ArticleResource extends Resource
{
    protected static ?string $model = SeoArticle::class;

    protected static ?string $slug = 'articles';

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationGroup = 'SEO Workspace';

    protected static ?string $navigationLabel = 'Bài viết';

    protected static ?string $modelLabel = 'Bài viết';

    protected static ?string $pluralModelLabel = 'Bài viết';

    public static function panelId(): string
    {
        return 'seo';
    }

    /**
     * URL resource trong panel SEO (dùng khi gọi ngoài ngữ cảnh Filament, VD: API preview).
     */
    public static function panelUrl(string $name = 'index', array $parameters = [], bool $isAbsolute = true): string
    {
        return static::getUrl($name, $parameters, $isAbsolute, panel: static::panelId());
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('title')
                    ->label('Tiêu đề')
                    ->required()
                    ->maxLength(255)
                    ->columnSpanFull(),
                Forms\Components\Select::make('status')
                    ->label('Trạng thái')
                    ->options([
                        'draft' => 'Nháp',
                        'published' => 'Đã xuất bản',
                        'scheduled' => 'Hẹn giờ',
                        'private' => 'Riêng tư',
                    ])
                    ->default('draft')
                    ->native(false),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordAction('edit')
            ->columns([
                Tables\Columns\TextColumn::make('title')
                    ->label('Tiêu đề')
                    ->searchable()
                    ->sortable()
                    ->wrap()
                    ->description(function (SeoArticle $record): ?string {
                        if (filled($record->slug)) {
                            return '/' . ltrim((string) $record->slug, '/');
                        }

                        if ($record->wp_post_id) {
                            return 'WP ID: ' . $record->wp_post_id;
                        }

                        return null;
                    }),
                Tables\Columns\TextColumn::make('type')
                    ->label('Loại')
                    ->sortable()
                    ->badge()
                    ->color('info')
                    ->formatStateUsing(fn (?string $state): string => $state
                        ? Str::ucfirst(str_replace('_', ' ', $state))
                        : '—'),
                Tables\Columns\TextColumn::make('author')
                    ->label('Tác giả')
                    ->badge()
                    ->getStateUsing(function (SeoArticle $record): string {
                        if ($record->user_id === null) {
                            return 'System';
                        }

                        $record->loadMissing('user');

                        return (string) ($record->user?->name ?? $record->user?->email ?? 'System');
                    })
                    ->color(fn (string $state): string => $state === 'System' ? 'gray' : 'primary'),
                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Cập nhật')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
                Tables\Columns\ViewColumn::make('seo_details')
                    ->label('Chi tiết SEO')
                    ->view('seo-content-ai::filament.tables.columns.article-seo-details')
                    ->disabledClick(),
            ])
            ->defaultSort('updated_at', 'desc')
            ->filters([
                SelectFilter::make('site_id')
                    ->label('Tên miền')
                    ->options(function (): array {
                        $query = Site::query()->orderBy('domain');

                        if (auth()->user()?->role !== 'admin') {
                            $query->where('user_id', auth()->id());
                        }

                        return $query->pluck('domain', 'id')->all();
                    })
                    ->searchable()
                    ->preload()
                    ->native(false)
                    ->placeholder('Tất cả tên miền')
                    ->indicator('Tên miền')
                    ->query(function (Builder $query, array $data): void {
                        $siteId = $data['value'] ?? null;
                        if ($siteId === null || $siteId === '') {
                            return;
                        }

                        $query->where('site_id', $siteId);
                    }),
                SelectFilter::make('type')
                    ->label('Loại bài viết')
                    ->options([
                        'article' => 'Bài viết',
                        'product' => 'Sản phẩm',
                        'category' => 'Danh mục',
                        'product_category' => 'Danh mục sản phẩm',
                    ])
                    ->native(false)
                    ->placeholder('Tất cả loại')
                    ->indicator('Loại')
                    ->query(function (Builder $query, array $data): void {
                        $type = $data['value'] ?? null;
                        if (! is_string($type) || $type === '') {
                            return;
                        }

                        if ($type === 'article') {
                            $query->where(function (Builder $q): void {
                                $q->where('type', 'article')->orWhereNull('type');
                            });

                            return;
                        }

                        $query->where('type', $type);
                    }),
                SelectFilter::make('seo_score_band')
                    ->label('Điểm SEO')
                    ->options([
                        'poor' => '0–49',
                        'fair' => '50–69',
                        'good' => '70–89',
                        'excellent' => '90–100',
                    ])
                    ->query(function (Builder $query, array $data): void {
                        $band = $data['value'] ?? null;
                        if (! is_string($band) || $band === '') {
                            return;
                        }

                        $query->whereNotNull('seo_score');

                        match ($band) {
                            'poor' => $query->where('seo_score', '<', 50),
                            'fair' => $query->whereBetween('seo_score', [50, 69.99]),
                            'good' => $query->whereBetween('seo_score', [70, 89.99]),
                            'excellent' => $query->where('seo_score', '>=', 90),
                            default => null,
                        };
                    })
                    ->native(false)
                    ->placeholder('Tất cả điểm')
                    ->indicator('Điểm SEO'),
                Filter::make('seo_link')
                    ->label('Link trong bài')
                    ->form([
                        Forms\Components\Hidden::make('url'),
                        Forms\Components\Hidden::make('type'),
                    ])
                    ->query(function (Builder $query, array $data): void {
                        $url = $data['url'] ?? null;
                        if (! is_string($url) || trim($url) === '') {
                            return;
                        }

                        $type = $data['type'] ?? null;

                        $query->whereHas('links', function (Builder $linkQuery) use ($url, $type): void {
                            $linkQuery->where('url', $url);

                            if (is_string($type) && $type !== '') {
                                $linkQuery->where('type', $type);
                            }
                        });
                    })
                    ->indicateUsing(function (array $data): ?string {
                        $url = $data['url'] ?? null;
                        if (! is_string($url) || trim($url) === '') {
                            return null;
                        }

                        $type = $data['type'] ?? null;
                        $typeLabel = $type === 'internal' ? 'nội bộ' : ($type === 'external' ? 'ngoài' : '');

                        return 'Link' . ($typeLabel !== '' ? ' ' . $typeLabel : '') . ': ' . Str::limit($url, 48);
                    }),
                Filter::make('keyword')
                    ->label('Từ khóa')
                    ->form([
                        Forms\Components\Hidden::make('keyword_id'),
                        Forms\Components\Hidden::make('internal_link_only'),
                    ])
                    ->query(function (Builder $query, array $data): void {
                        $keywordId = $data['keyword_id'] ?? null;
                        if ($keywordId === null || $keywordId === '') {
                            return;
                        }

                        $query->whereHas('keywords', function (Builder $keywordQuery) use ($keywordId): void {
                            $keywordQuery->where('keywords.id', $keywordId);
                        });

                        if (($data['internal_link_only'] ?? '') === '1') {
                            $query->whereHas('links', function (Builder $linkQuery): void {
                                $linkQuery->where('type', 'internal');
                            });
                        }
                    })
                    ->indicateUsing(function (array $data): ?string {
                        $keywordId = $data['keyword_id'] ?? null;
                        if ($keywordId === null || $keywordId === '') {
                            return null;
                        }

                        $phrase = Keyword::query()
                            ->whereKey($keywordId)
                            ->value('phrase');

                        if (! is_string($phrase) || $phrase === '') {
                            return __('Từ khóa') . ' #' . $keywordId;
                        }

                        $suffix = ($data['internal_link_only'] ?? '') === '1'
                            ? ' (' . __('có link nội bộ') . ')'
                            : '';

                        return __('Từ khóa') . ': ' . $phrase . $suffix;
                    }),
            ], layout: FiltersLayout::AboveContent)
            ->filtersFormColumns([
                'default' => 1,
                'sm' => 2,
                'lg' => 4,
            ])
            ->persistFiltersInSession()
            ->actions([
                Tables\Actions\EditAction::make()
                    ->iconButton(),
                Tables\Actions\DeleteAction::make()
                    ->iconButton(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()
            ->with([
                'keywords',
                'user',
                'site',
                'articleMetas' => static fn ($query) => $query->whereIn('meta_key', [
                    'seo_focus_keyword',
                    'seo_rank_math_score',
                    'wp_post_images',
                ]),
            ]);

        if (auth()->user()?->role !== 'admin') {
            $query->whereIn(
                'site_id',
                Site::query()->where('user_id', auth()->id())->select('id')
            );
        }

        return $query;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListArticles::route('/'),
            'trash' => Pages\ListArticlesTrash::route('/trash'),
            'edit' => Pages\EditArticle::route('/{record}/edit'),
        ];
    }
}
