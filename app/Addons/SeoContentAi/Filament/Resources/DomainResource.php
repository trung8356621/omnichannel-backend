<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Filament\Resources;

use App\Addons\SeoContentAi\Filament\Resources\DomainResource\Pages;
use App\Models\Site;
use Filament\Forms;
use Filament\Forms\Components\Actions\Action as FormInputAction;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Str;

class DomainResource extends Resource
{
    protected static ?string $model = Site::class;

    protected static ?string $slug = 'domains';

    protected static ?string $navigationIcon = 'heroicon-o-globe-alt';

    protected static ?string $navigationGroup = 'SEO Workspace';

    protected static ?string $navigationLabel = 'Danh sách tên miền';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('seo_platform')
                    ->label('Nền tảng')
                    ->options([
                        'wordpress' => 'WordPress',
                        'shopify' => 'Shopify',
                        'custom' => 'Tùy chỉnh',
                    ])
                    ->required()
                    ->native(false)
                    ->live(),
                Forms\Components\Select::make('seo_domain_type')
                    ->label('Loại Website')
                    ->options([
                        'news'         => 'Tin tức',
                        'production'   => 'Sản xuất',
                        'e-commerce'   => 'Thương mại điện tử',
                    ])
                    ->required()
                    ->native(false),
                Forms\Components\TextInput::make('seo_read_token')
                    ->label('Read token')
                    ->key('seo_read_token')
                    ->maxLength(255)
                    ->readOnly()
                    ->helperText('Có thể sao chép bằng Ctrl+C.')
                    ->visible(fn (Get $get): bool => $get('seo_platform') === 'wordpress')
                    ->suffixAction(
                        FormInputAction::make('generate_read_token')
                            ->label('Tạo mới')
                            ->icon('heroicon-o-arrow-path')
                            ->action(fn (Set $set) => $set('seo_read_token', Str::random(60)))
                    ),
                Forms\Components\TextInput::make('seo_migration_token')
                    ->label('Migration / Write token')
                    ->key('seo_migration_token')
                    ->maxLength(255)
                    ->readOnly()
                    ->helperText('Dùng làm API WRITE TOKEN trên plugin WordPress (đăng comment/review).')
                    ->visible(fn (Get $get): bool => $get('seo_platform') === 'wordpress')
                    ->suffixAction(
                        FormInputAction::make('generate_migration_token')
                            ->label('Tạo mới')
                            ->icon('heroicon-o-arrow-path')
                            ->action(fn (Set $set) => $set('seo_migration_token', Str::random(60)))
                    ),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('domain')
                    ->label('Tên miền')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\IconColumn::make('is_main')
                    ->label('Miền chính')
                    ->boolean()
                    ->getStateUsing(function (Site $record): bool {
                        if ($record->relationLoaded('metas')) {
                            $v = $record->metas->firstWhere('meta_key', 'seo_is_main')?->meta_value;

                            return $v === '1';
                        }

                        return $record->getMeta('seo_is_main') === '1';
                    })
                    ->trueIcon('heroicon-s-star')
                    ->falseIcon('heroicon-o-star')
                    ->trueColor('warning')
                    ->falseColor('gray'),
            ])
            ->defaultSort('domain')
            ->actions([
                Tables\Actions\Action::make('overview')
                    ->label('Tổng quan')
                    ->icon('heroicon-o-chart-bar')
                    ->color('info')
                    ->url(fn (Site $record): string => DomainResource::getUrl('general', ['record' => $record])),
            ])
            ->bulkActions([]);
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with('metas');

        if (auth()->user()?->role !== 'admin') {
            return $query->where('user_id', auth()->id());
        }

        return $query->withoutGlobalScopes([
            SoftDeletingScope::class,
        ]);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDomains::route('/'),
            'edit' => Pages\EditDomain::route('/{record}/edit'),
            'info' => Pages\EditDomainInfo::route('/{record}/info'),
            'general' => Pages\GeneralDomain::route('/{record}/general'),
            'internal-links' => Pages\ListDomainInternalLinks::route('/{record}/internal-links'),
        ];
    }
}
