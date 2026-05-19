<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Filament\Resources;

use App\Addons\SeoContentAi\Filament\Resources\ArticleResource\Pages;
use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Models\Site;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Enums\FiltersLayout;
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

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('site_id')
                    ->label('Website')
                    ->options(fn (): array => static::siteOptionsForForm())
                    ->required()
                    ->searchable()
                    ->visibleOn('create'),
                Forms\Components\Select::make('type')
                    ->label('Loại nội dung')
                    ->options([
                        'article' => 'Bài viết',
                        'product' => 'Sản phẩm',
                        'category' => 'Danh mục',
                        'product_category' => 'Danh mục sản phẩm',
                    ])
                    ->default('article')
                    ->native(false)
                    ->visibleOn('create'),
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

    /**
     * @return array<int|string, string>
     */
    public static function siteOptionsForForm(): array
    {
        $query = Site::query()->orderBy('domain');

        if (auth()->user()?->role !== 'admin') {
            $query->where('user_id', auth()->id());
        }

        return $query->pluck('domain', 'id')->all();
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
                Tables\Columns\TextColumn::make('keywords.keyword')
                    ->label('Từ khóa')
                    ->badge()
                    ->limitList(3)
                    ->separator(', '),
                Tables\Columns\TextColumn::make('seo_score')
                    ->label('Rank')
                    ->sortable()
                    ->numeric(decimalPlaces: 0)
                    ->badge()
                    ->formatStateUsing(fn ($state): string => $state !== null && $state !== ''
                        ? (string) (int) round((float) $state)
                        : '—')
                    ->color(function ($state): string {
                        if ($state === null || $state === '') {
                            return 'gray';
                        }

                        $score = (float) $state;

                        return match (true) {
                            $score < 50 => 'danger',
                            $score < 70 => 'warning',
                            default => 'success',
                        };
                    }),
                Tables\Columns\TextColumn::make('links')
                    ->label('Liên kết')
                    ->html()
                    ->getStateUsing(function (SeoArticle $record): string {
                        $internal = (int) ($record->internal_link_count ?? 0);
                        $external = (int) ($record->external_link_count ?? 0);

                        return "In: {$internal} | Out: {$external}";
                    }),
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
            ])
            ->defaultSort('updated_at', 'desc')
            ->filters([
                SelectFilter::make('site_id')
                    ->label('Tên miền')
                    ->relationship(
                        'site',
                        'domain',
                        modifyQueryUsing: function (Builder $query): Builder {
                            if (auth()->user()?->role !== 'admin') {
                                $query->where('user_id', auth()->id());
                            }

                            return $query->orderBy('domain');
                        },
                    )
                    ->searchable()
                    ->preload()
                    ->native(false)
                    ->placeholder('Tất cả tên miền')
                    ->indicator('Tên miền'),
            ], layout: FiltersLayout::AboveContent)
            ->filtersFormColumns([
                'default' => 1,
                'sm' => 2,
                'lg' => 3,
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
                'articleMetas' => static fn ($query) => $query->where('meta_key', 'seo_extracted_links'),
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
        return true;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListArticles::route('/'),
            'create' => Pages\CreateArticle::route('/create'),
            'trash' => Pages\ListArticlesTrash::route('/trash'),
            'edit' => Pages\EditArticle::route('/{record}/edit'),
        ];
    }
}
