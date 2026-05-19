<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Filament\Resources;

use App\Addons\SeoContentAi\Filament\Resources\AiConnectionResource\Pages;
use App\Addons\SeoContentAi\Support\GeminiModelCatalog;
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

    protected static ?string $navigationGroup = 'Tùy chỉnh';

    protected static ?string $navigationLabel = 'Cấu hình AI';

    protected static ?string $modelLabel = 'Kết nối AI';

    protected static ?string $pluralModelLabel = 'Cấu hình AI';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('provider')
                    ->label('Nhà cung cấp')
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
                            . e(app()->getLocale() === 'vi'
                                ? '👉 Hướng dẫn lấy API Key Gemini tại Google AI Studio'
                                : '👉 How to get Gemini API Key from Google AI Studio')
                            . '</a>'
                        ),
                        'claude' => new HtmlString(
                            '<a href="https://console.anthropic.com/settings/keys" target="_blank" rel="noopener noreferrer" '
                            . 'class="text-primary-600 hover:underline inline-flex items-center gap-1" '
                            . 'style="color: #3b82f6; text-decoration: underline; font-weight: 500;">'
                            . e(app()->getLocale() === 'vi'
                                ? '👉 Hướng dẫn lấy API Key Claude tại Anthropic Console'
                                : '👉 How to get Claude API Key from Anthropic Console')
                            . '</a>'
                        ),
                        default => null,
                    }),
                Forms\Components\TextInput::make('name')
                    ->label('Tên gợi nhớ (VD: API Claude Chính)')
                    ->required()
                    ->maxLength(255),
                Forms\Components\TextInput::make('api_key')
                    ->label('API Key')
                    ->password()
                    ->revealable()
                    ->required(fn (string $operation): bool => $operation === 'create')
                    ->dehydrated(fn (?string $state): bool => filled($state))
                    ->maxLength(65535),
                Forms\Components\Select::make('default_model')
                    ->label('Model mặc định')
                    ->required()
                    ->native(false)
                    ->default(fn (Get $get): ?string => $get('provider') === 'gemini'
                        ? GeminiModelCatalog::defaultModel()
                        : null)
                    ->options(fn (Get $get): array => match ($get('provider')) {
                        'gemini' => GeminiModelCatalog::selectOptions(),
                        'claude' => [
                            'claude-3-5-sonnet-20240620' => 'Claude 3.5 Sonnet',
                            'claude-3-opus-20240229' => 'Claude 3 Opus',
                            'claude-3-haiku-20240307' => 'Claude 3 Haiku',
                        ],
                        default => [],
                    }),
                Forms\Components\Select::make('status')
                    ->label('Trạng thái')
                    ->options([
                        'active' => 'Hoạt động',
                        'inactive' => 'Tắt',
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
                    ->label('Kết nối')
                    ->description(fn (ApiConnection $record): string => $record->provider . ' - ' . ($record->default_model ?? '—'))
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
