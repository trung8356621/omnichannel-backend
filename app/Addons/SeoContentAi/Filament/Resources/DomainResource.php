<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Filament\Resources;

use App\Addons\SeoContentAi\Filament\Resources\DomainResource\Forms\DomainTechnicalSeoForm;
use App\Addons\SeoContentAi\Filament\Resources\DomainResource\Pages;
use App\Addons\SeoContentAi\Services\SeoMainDomainService;
use App\Addons\SeoContentAi\Support\SeoAccessControl;
use App\Models\Site;
use Filament\Forms;
use Filament\Forms\Components\Actions\Action as FormInputAction;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class DomainResource extends Resource
{
    protected static ?string $model = Site::class;

    protected static ?string $slug = 'domains';

    protected static ?string $navigationIcon = 'heroicon-o-globe-alt';

    protected static ?string $navigationGroup = 'SEO Workspace';

    protected static ?string $navigationLabel = 'Domain list';

    public static function canViewAny(): bool
    {
        return SeoAccessControl::canAccessManagerFeatures();
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('domain')
                    ->label(__('seo-content-ai::filament.domain.domain'))
                    ->placeholder('example.com')
                    ->required()
                    ->unique(ignoreRecord: true)
                    ->maxLength(255)
                    ->live(onBlur: true)
                    ->afterStateUpdated(function (?string $state, Set $set): void {
                        if ($state === null || $state === '') {
                            return;
                        }
                        if (filter_var($state, FILTER_VALIDATE_URL) || str_contains($state, '://')) {
                            $host = parse_url($state, PHP_URL_HOST);
                            if (is_string($host) && $host !== '') {
                                $set('domain', $host);
                            }
                        }
                    })
                    ->columnSpanFull(),

                Forms\Components\Toggle::make('ssl')
                    ->label(__('seo-content-ai::filament.domain.ssl'))
                    ->default(true),

                Forms\Components\Select::make('status')
                    ->label(__('seo-content-ai::filament.domain.status'))
                    ->options([
                        'active' => 'Active',
                        'inactive' => 'Inactive',
                        'maintenance' => 'Maintenance',
                    ])
                    ->default('active')
                    ->required()
                    ->native(false),

                Forms\Components\Select::make('seo_platform')
                    ->label(__('seo-content-ai::filament.domain.platform'))
                    ->options([
                        'wordpress' => 'WordPress',
                        'shopify' => 'Shopify',
                        'custom' => 'Custom',
                    ])
                    ->required()
                    ->native(false)
                    ->live(),
                Forms\Components\Select::make('seo_domain_type')
                    ->label(__('seo-content-ai::filament.domain.website_type'))
                    ->options([
                        'news'         => 'News',
                        'production'   => 'Production',
                        'e-commerce'   => 'E-commerce',
                    ])
                    ->required()
                    ->native(false),
                Forms\Components\TextInput::make('seo_read_token')
                    ->label('Read token')
                    ->key('seo_read_token')
                    ->maxLength(255)
                    ->readOnly()
                    ->helperText('Can be copied with Ctrl+C.')
                    ->visible(fn (Get $get): bool => $get('seo_platform') === 'wordpress')
                    ->suffixAction(
                        FormInputAction::make('generate_read_token')
                            ->label('Generate')
                            ->icon('heroicon-o-arrow-path')
                            ->action(fn (Set $set) => $set('seo_read_token', Str::random(60)))
                    ),
                Forms\Components\TextInput::make('seo_migration_token')
                    ->label('Migration / Write token')
                    ->key('seo_migration_token')
                    ->maxLength(255)
                    ->readOnly()
                    ->helperText('Used as API WRITE TOKEN in the WordPress plugin (post comment/review).')
                    ->visible(fn (Get $get): bool => $get('seo_platform') === 'wordpress')
                    ->suffixAction(
                        FormInputAction::make('generate_migration_token')
                            ->label('Generate')
                            ->icon('heroicon-o-arrow-path')
                            ->action(fn (Set $set) => $set('seo_migration_token', Str::random(60)))
                    ),
                ...DomainTechnicalSeoForm::schema(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('domain')
                    ->label(__('seo-content-ai::filament.domain.domain'))
                    ->searchable()
                    ->sortable(),
                Tables\Columns\ViewColumn::make('is_main')
                    ->label(__('seo-content-ai::filament.domain.main_domain'))
                    ->view('seo-content-ai::filament.tables.columns.domain-main-star'),
            ])
            ->defaultSort('domain')
            ->actions([
                Tables\Actions\Action::make('set_as_main')
                    ->label(__('seo-content-ai::filament.domain.set_as_main'))
                    ->icon('heroicon-o-star')
                    ->color('warning')
                    ->visible(fn (Site $record): bool => ! app(SeoMainDomainService::class)->isMain($record))
                    ->requiresConfirmation()
                    ->modalDescription(__('seo-content-ai::filament.domain.set_as_main_confirm'))
                    ->action(function (Site $record): void {
                        app(SeoMainDomainService::class)->setAsMain($record);

                        Notification::make()
                            ->title(__('seo-content-ai::filament.domain.set_as_main_success'))
                            ->body(__('seo-content-ai::filament.domain.set_as_main_success_body', [
                                'domain' => $record->domain,
                            ]))
                            ->success()
                            ->send();
                    }),
                Tables\Actions\Action::make('overview')
                    ->label(__('seo-content-ai::filament.domain.overview'))
                    ->icon('heroicon-o-chart-bar')
                    ->color('info')
                    ->url(fn (Site $record): string => DomainResource::getUrl('general', ['record' => $record])),
                Tables\Actions\DeleteAction::make()
                    ->label('Xóa')
                    ->icon('heroicon-o-trash'),
            ])
            ->bulkActions([]);
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with('metas');

        if (auth()->user()?->role !== 'admin') {
            return $query->where('user_id', auth()->id());
        }

        return $query;
    }

    public static function canCreate(): bool
    {
        return SeoAccessControl::canAccessManagerFeatures();
    }

    public static function canEdit(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return SeoAccessControl::canAccessManagerFeatures();
    }

    public static function canDelete(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return SeoAccessControl::canAccessManagerFeatures();
    }

    public static function getNavigationLabel(): string
    {
        return __('seo-content-ai::filament.nav.domain_list');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDomains::route('/'),
            'create' => Pages\CreateDomain::route('/create'),
            'edit' => Pages\EditDomain::route('/{record}/edit'),
            'info' => Pages\RedirectDomainInfoToEdit::route('/{record}/info'),
            'general' => Pages\GeneralDomain::route('/{record}/general'),
            'internal-links' => Pages\ListDomainInternalLinks::route('/{record}/internal-links'),
        ];
    }
}
