<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Filament\Resources;

use App\Addons\SeoContentAi\Filament\Resources\AiConnectionResource\Pages;
use App\Addons\SeoContentAi\Services\AiModelRouterService;
use App\Addons\SeoContentAi\Support\SeoAccessControl;
use App\Models\ApiConnection;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

class AiConnectionResource extends Resource
{
    protected static ?string $model = ApiConnection::class;

    protected static ?string $slug = 'settings/ai';

    protected static ?string $navigationIcon = 'heroicon-o-cpu-chip';

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function canViewAny(): bool
    {
        return SeoAccessControl::canAccessManagerFeatures();
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

    protected static ?string $modelLabel = 'AI connection';

    protected static ?string $pluralModelLabel = 'AI settings';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('provider')
                    ->label(__('seo-content-ai::filament.ai_connection.provider'))
                    ->options([
                        'gemini' => 'Google Gemini',
                        'claude' => 'Anthropic Claude',
                    ])
                    ->live()
                    ->required()
                    ->native(false)
                    ->helperText(fn (Get $get): ?HtmlString => match ($get('provider')) {
                        'gemini' => new HtmlString(
                            '<a href="https://aistudio.google.com/app/apikey" target="_blank" rel="noopener noreferrer" '
                            . 'class="text-primary-600 hover:underline inline-flex items-center gap-1" '
                            . 'style="color: #3b82f6; text-decoration: underline; font-weight: 500;">'
                            . e('👉 How to get Gemini API key from Google AI Studio')
                            . '</a>'
                        ),
                        'claude' => new HtmlString(
                            '<a href="https://console.anthropic.com/settings/keys" target="_blank" rel="noopener noreferrer" '
                            . 'class="text-primary-600 hover:underline inline-flex items-center gap-1" '
                            . 'style="color: #3b82f6; text-decoration: underline; font-weight: 500;">'
                            . e('👉 How to get Claude API key from Anthropic Console')
                            . '</a>'
                        ),
                        default => null,
                    }),
                Forms\Components\TextInput::make('name')
                    ->label(__('seo-content-ai::filament.ai_connection.name'))
                    ->required()
                    ->maxLength(255),
                Forms\Components\TextInput::make('api_key')
                    ->label(__('seo-content-ai::filament.ai_connection.api_key'))
                    ->password()
                    ->revealable()
                    ->required(fn (string $operation): bool => $operation === 'create')
                    ->dehydrated(fn (?string $state): bool => filled($state))
                    ->maxLength(65535)
                    ->helperText(__('seo-content-ai::filament.ai_connection.helper_sync')),
                Forms\Components\Select::make('status')
                    ->label(__('seo-content-ai::filament.ai_connection.status'))
                    ->options([
                        'active' => __('seo-content-ai::filament.ai_connection.active'),
                        'inactive' => __('seo-content-ai::filament.ai_connection.inactive'),
                    ])
                    ->default('active')
                    ->native(false),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->contentGrid([
                'md' => 1,
            ])
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label(__('seo-content-ai::filament.ai_connection.model'))
                    ->description(fn (ApiConnection $record): string => match ($record->provider) {
                        'gemini' => 'Google Gemini',
                        'claude' => 'Anthropic Claude',
                        default => (string) $record->provider,
                    })
                    ->searchable()
                    ->sortable(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        $userId = auth()->id();

        return parent::getEloquentQuery()
            ->where(function (Builder $query) use ($userId): void {
                $query->where('user_id', $userId)
                    ->orWhere('is_global', true);
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAiConnections::route('/'),
            'create' => Pages\CreateAiConnection::route('/create'),
            'edit' => Pages\EditAiConnection::route('/{record}/edit'),
        ];
    }
}
