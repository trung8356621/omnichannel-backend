<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Filament\Resources;

use App\Addons\SeoContentAi\Filament\Pages\SeoSettingsOverview;
use App\Addons\SeoContentAi\Filament\Resources\PromptResource\Pages;
use App\Addons\SeoContentAi\Models\SeoPrompt;
use App\Addons\SeoContentAi\PromptHooks\PromptHookFormSchema;
use App\Addons\SeoContentAi\Services\AiModelsReadinessService;
use App\Addons\SeoContentAi\Support\AiModelCategory;
use App\Addons\SeoContentAi\Support\PromptLoaiSanPhamVariable;
use App\Addons\SeoContentAi\Support\PromptSiteContextVariable;
use App\Addons\SeoContentAi\Support\PromptVariableSync;
use App\Addons\SeoContentAi\Support\SeoAccessControl;
use App\Models\ApiConnection;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class PromptResource extends SeoPanelResource
{
    protected static ?string $model = SeoPrompt::class;

    protected static ?string $slug = 'prompts';

    protected static ?string $navigationIcon = 'heroicon-o-chat-bubble-bottom-center-text';

    protected static ?string $navigationGroup = 'SEO Workspace';

    protected static ?string $navigationLabel = 'Prompt management';

    protected static ?string $modelLabel = 'Prompt';

    protected static ?string $pluralModelLabel = 'Prompts';

    public static function canViewAny(): bool
    {
        return SeoAccessControl::canAccessPlannerFeatures();
    }

    public static function canCreate(): bool
    {
        return static::allowsSeoPanelMutation()
            && SeoAccessControl::canAccessPlannerFeatures();
    }

    public static function canEdit(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return static::allowsSeoPanelMutation()
            && SeoAccessControl::canAccessPlannerFeatures();
    }

    public static function canDelete(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return static::allowsSeoPanelMutation()
            && SeoAccessControl::canAccessPlannerFeatures();
    }

    public static function getNavigationLabel(): string
    {
        return __('seo-content-ai::filament.nav.prompt_management');
    }

    public static function getModelLabel(): string
    {
        return __('seo-content-ai::filament.nav.prompt');
    }

    public static function getPluralModelLabel(): string
    {
        return __('seo-content-ai::filament.nav.prompts');
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Grid::make(12)
                    ->schema([
                        Forms\Components\Group::make()
                            ->schema([
                                Forms\Components\Section::make(__('seo-content-ai::filament.prompt.general_info'))
                                    ->schema([
                                        Forms\Components\TextInput::make('name')
                                            ->label(__('seo-content-ai::filament.prompt.name'))
                                            ->required()
                                            ->maxLength(255),
                                        Forms\Components\Textarea::make('description')
                                            ->label(__('seo-content-ai::filament.prompt.description'))
                                            ->rows(3)
                                            ->columnSpanFull(),
                                        Forms\Components\Select::make('ai_connection_id')
                                            ->label(__('seo-content-ai::filament.prompt.ai_connection'))
                                            ->options(fn (): array => self::aiConnectionOptions())
                                            ->searchable()
                                            ->required()
                                            ->live()
                                            ->native(false)
                                            ->placeholder('Choose AI connection for this prompt'),
                                        Forms\Components\Radio::make('tools')
                                            ->label(__('seo-content-ai::filament.prompt.tool'))
                                            ->options(fn (): array => \App\Addons\SeoContentAi\Support\ImageToolType::promptSelectOptions())
                                            ->default('default')
                                            ->inline()
                                            ->live(),
                                        Forms\Components\Toggle::make('is_active')
                                            ->label(__('seo-content-ai::filament.prompt.active'))
                                            ->default(true),
                                    ]),
                                ...PromptHookFormSchema::section(),
                                Forms\Components\Section::make(__('seo-content-ai::filament.prompt.variables'))
                                    ->description('Auto-sync from {{variable_name}} in Markdown. Runtime mặc định: {{input}}, {{loai_san_pham}}, {{tone}}, {{site_cta}}, {{keyword_density}}, {{article_length}}, ... — không cần khai báo.')
                                    ->schema([
                                        Forms\Components\Repeater::make('variables')
                                            ->label('')
                                            ->schema([
                                                Forms\Components\TextInput::make('name')
                                                    ->label('Variable name (e.g. focus_keyword)')
                                                    ->required()
                                                    ->maxLength(128),
                                                Forms\Components\TextInput::make('description')
                                                    ->label('Note')
                                                    ->maxLength(255),
                                            ])
                                            ->defaultItems(0)
                                            ->addActionLabel(__('seo-content-ai::filament.prompt.add_variable'))
                                            ->reorderable()
                                            ->collapsible(),
                                    ]),
                            ])
                            ->columnSpan(4),

                        Forms\Components\Group::make()
                            ->columnSpan(8)
                            ->schema([
                                Forms\Components\Section::make(__('seo-content-ai::filament.prompt.content_markdown'))
                                    ->description('Use H1 (#) to split blocks: # Role, # Context, # Task: ..., # Sub-task: ...')
                                    ->schema([
                                        Forms\Components\MarkdownEditor::make('markdown_content')
                                            ->label('')
                                            ->required()
                                            ->columnSpanFull()
                                            ->minHeight('440px')
                                            ->toolbarButtons([
                                                'bold',
                                                'italic',
                                                'bulletList',
                                                'orderedList',
                                                'blockquote',
                                                'link',
                                                'undo',
                                                'redo',
                                            ])
                                            ->placeholder(
                                                "# Role\nYou are an expert...\n\n"
                                                ."# Context\nSystem...\n\n"
                                                ."# Task: Main image\nCapture product image...\n\n"
                                                ."# Sub-task: Side shot\n..."
                                            ),
                                    ]),
                                Forms\Components\Section::make(__('seo-content-ai::filament.prompt.post_processing.title'))
                                    ->description(__('seo-content-ai::filament.prompt.post_processing.description'))
                                    ->visible(fn (Get $get): bool => \App\Addons\SeoContentAi\Support\ImageToolType::fromMixed($get('tools'))->isImagePipeline())
                                    ->schema(self::postProcessingFormSchema()),
                            ]),
                    ]),
            ]);
    }

    /**
     * @return array<int, Forms\Components\Component>
     */
    public static function postProcessingFormSchema(): array
    {
        return [
            Forms\Components\View::make('seo-content-ai::filament.forms.prompt-post-processing-styles'),
            Forms\Components\Fieldset::make(__('seo-content-ai::filament.prompt.post_processing.quick_split'))
                ->schema([
                    Forms\Components\Toggle::make('settings.post_processing.split_enabled')
                        ->label(__('seo-content-ai::filament.prompt.post_processing.split_enable'))
                        ->inline(false),
                    Forms\Components\Grid::make(2)
                        ->schema([
                            Forms\Components\TextInput::make('settings.post_processing.split_rows')
                                ->label(__('seo-content-ai::filament.media_tools.split_rows'))
                                ->numeric()
                                ->minValue(1)
                                ->maxValue(12)
                                ->default(3)
                                ->required(),
                            Forms\Components\TextInput::make('settings.post_processing.split_columns')
                                ->label(__('seo-content-ai::filament.media_tools.split_columns'))
                                ->numeric()
                                ->minValue(1)
                                ->maxValue(12)
                                ->default(2)
                                ->required(),
                        ]),
                    Forms\Components\Placeholder::make('split_hint')
                        ->label('')
                        ->content(__('seo-content-ai::filament.prompt.post_processing.split_hint')),
                ]),
            Forms\Components\Fieldset::make(__('seo-content-ai::filament.prompt.post_processing.quick_resize'))
                ->schema([
                    Forms\Components\Toggle::make('settings.post_processing.resize_enabled')
                        ->label(__('seo-content-ai::filament.prompt.post_processing.resize_enable'))
                        ->inline(false),
                    Forms\Components\Grid::make(2)
                        ->schema([
                            Forms\Components\TextInput::make('settings.post_processing.resize_width')
                                ->label(__('seo-content-ai::filament.media_tools.width'))
                                ->numeric()
                                ->minValue(1)
                                ->placeholder('px'),
                            Forms\Components\TextInput::make('settings.post_processing.resize_height')
                                ->label(__('seo-content-ai::filament.media_tools.height'))
                                ->numeric()
                                ->minValue(1)
                                ->placeholder('px'),
                        ]),
                    Forms\Components\Placeholder::make('resize_hint')
                        ->label('')
                        ->content(__('seo-content-ai::filament.prompt.post_processing.resize_hint')),
                ]),
        ];
    }

    /**
     * @return array<int|string, string>
     */
    private static function aiConnectionOptions(): array
    {
        return ApiConnection::query()
            ->where('status', 'active')
            ->when(
                SeoAccessControl::shouldScopeToAccountOwner(),
                fn ($query) => $query->where(function ($q): void {
                    $userId = SeoAccessControl::accountSiteOwnerId();
                    $q->where('user_id', $userId)->orWhere('is_global', true);
                })
            )
            ->orderBy('name')
            ->get()
            ->mapWithKeys(function (ApiConnection $ai): array {
                $providerName = match ($ai->provider) {
                    'gemini' => 'Gemini',
                    'claude' => 'Claude',
                    default => (string) $ai->provider,
                };

                $label = $ai->name.' ('.$providerName.')';

                return [$ai->id => $label];
            })
            ->all();
    }

    /**
     * @return array<string, string>
     */
    public static function modelCategoryOptionsForConnection(mixed $connectionId): array
    {
        if (blank($connectionId)) {
            return AiModelCategory::promptSelectOptions();
        }

        $connection = ApiConnection::query()->find((int) $connectionId);
        if ($connection === null) {
            return AiModelCategory::promptSelectOptions();
        }

        $options = AiModelCategory::connectionSelectOptions((string) $connection->provider);

        return $options !== [] ? $options : AiModelCategory::promptSelectOptions();
    }

    public static function defaultModelCategoryForConnection(mixed $connectionId): ?string
    {
        if (blank($connectionId)) {
            return AiModelCategory::GEMINI_FLASH;
        }

        $connection = ApiConnection::query()->find((int) $connectionId);

        return $connection !== null
            ? \App\Addons\SeoContentAi\Support\AiModelCatalog::defaultForConnection($connection)
            : AiModelCategory::GEMINI_FLASH;
    }

    public static function markdownFromParts(Collection $parts): string
    {
        $blocks = [];

        foreach ($parts as $part) {
            $role = strtolower(trim((string) ($part->role ?? '')));
            $heading = match ($role) {
                'role' => 'Role',
                'context' => 'Context',
                'task' => 'Task',
                'sub_task' => 'Sub-task',
                'constraints' => 'Constraints',
                'formatting' => 'Output format',
                'global_constraints' => 'Global constraints',
                default => ucfirst($role !== '' ? $role : 'context'),
            };

            $name = trim((string) ($part->name ?? ''));
            if (in_array($role, ['task', 'sub_task'], true) && $name !== '') {
                $heading .= ': '.$name;
            }

            $content = trim((string) ($part->content ?? ''));
            if ($content === '') {
                continue;
            }

            $block = '# '.$heading."\n".$content;
            $meta = is_array($part->meta ?? null) ? $part->meta : [];

            $rules = trim((string) ($meta['rules'] ?? ''));
            if ($rules !== '') {
                $block .= "\n\nRules:\n".$rules;
            }

            if ($role === 'sub_task') {
                $specific = trim((string) ($meta['specific_constraints'] ?? ''));
                if ($specific !== '') {
                    $block .= "\n\nSpecific constraints (sub-prompt):\n".$specific;
                }
            }

            $blocks[] = $block;
        }

        return implode("\n\n", $blocks);
    }

    public static function promptUsesInputVariable(SeoPrompt $prompt): bool
    {
        $declared = collect(is_array($prompt->variables) ? $prompt->variables : []);

        if ($declared->contains(static fn ($row): bool => trim((string) ($row['name'] ?? '')) === 'input')) {
            return true;
        }

        return in_array('input', self::extractVariableNamesFromMarkdown((string) ($prompt->markdown_content ?? '')), true);
    }

    /**
     * @return array<int, array{name: string, label: string, description: ?string}>
     */
    public static function variableDefinitionsForPrompt(SeoPrompt $prompt): array
    {
        $defaults = self::defaultVariableLabels();
        $declared = collect(is_array($prompt->variables) ? $prompt->variables : []);

        $names = $declared->pluck('name')
            ->filter()
            ->merge(self::extractVariableNamesFromMarkdown((string) ($prompt->markdown_content ?? '')))
            ->unique()
            ->values();

        return $names
            ->reject(static fn (string $name): bool => PromptLoaiSanPhamVariable::isLoaiSanPhamName($name)
                || PromptSiteContextVariable::isName($name)
                || strtoupper($name) === 'PARENT_RESULT')
            ->map(static function (string $name) use ($declared, $defaults): array {
                $row = $declared->firstWhere('name', $name);

                return [
                    'name' => $name,
                    'label' => $defaults[$name] ?? $name,
                    'description' => filled($row['description'] ?? null) ? (string) $row['description'] : null,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Loại biến mặc định khỏi repeater khai báo (vẫn dùng được trong nội dung prompt).
     *
     * @param  array<int, array<string, mixed>>|null  $variables
     * @return array<int, array<string, mixed>>
     */
    public static function sanitizeDeclaredVariables(?array $variables): array
    {
        return collect($variables ?? [])
            ->filter(static function (array $row): bool {
                $name = trim((string) ($row['name'] ?? ''));

                return $name !== ''
                    && ! PromptLoaiSanPhamVariable::isLoaiSanPhamName($name)
                    && ! PromptSiteContextVariable::isName($name);
            })
            ->values()
            ->all();
    }

    /**
     * @return array<string, string>
     */
    public static function defaultVariableLabels(): array
    {
        return [
            'input' => 'Input from connected edge (SEO Flow)',
            'post_title' => 'Article title',
            'post_content' => 'Article content',
            'focus_keyword' => 'Focus keyword',
            'post_excerpt' => 'Excerpt',
            'site_domain' => 'Website domain',
            'site_short_description' => 'Website short description (domain)',
            'site_cta' => 'Website CTA / contact (domain) — includes [phone], [website], … placeholders for AI',
            'site_links' => 'Link list (deprecated — luôn rỗng; dùng đồng bộ keyword + gợi ý editor)',
            'tone' => 'Giọng văn (domain override, fallback SEO → Tùy chỉnh → Prompt)',
            'article_length' => 'Độ dài bài theo post_type hiện tại (số chữ, settings)',
            'article_length_product' => 'Độ dài bài — product (mặc định 1000)',
            'article_length_default' => 'Độ dài bài — các loại khác (mặc định 2000)',
            'keyword_density' => 'Mật độ từ khóa theo post_type hiện tại',
            'keyword_density_product' => 'Mật độ từ khóa — product',
            'keyword_density_default' => 'Mật độ từ khóa — các loại khác',
            'language' => 'Ngôn ngữ (Polylang bài viết hoặc mặc định SEO → Tùy chỉnh → Prompt)',
            'loai_san_pham' => 'Product category (product_cat) - default runtime variable from domain -> product_cat',
        ];
    }

    /**
     * Biến luôn có trong menu chèn / JSON preview (không cần khai báo trong repeater).
     *
     * @return list<string>
     */
    public static function defaultRuntimeVariableNames(): array
    {
        return array_values(array_unique([
            PromptLoaiSanPhamVariable::NAME,
            ...PromptSiteContextVariable::names(),
        ]));
    }

    /**
     * @return array<int, string>
     */
    public static function extractVariableNamesFromMarkdown(string $markdown): array
    {
        return PromptVariableSync::extractNames($markdown);
    }

    /**
     * @param  array<int, array<string, mixed>>|null  $declared
     * @return array<int, array{name: string, description: ?string}>
     */
    public static function mergeVariablesFromMarkdown(string $markdown, ?array $declared): array
    {
        return PromptVariableSync::mergeFromMarkdown($markdown, $declared);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label(__('seo-content-ai::filament.prompt.name'))
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('hook_key')
                    ->label(__('seo-content-ai::filament.prompt.hook'))
                    ->formatStateUsing(function (?string $state): string {
                        if ($state === null || trim($state) === '') {
                            return '—';
                        }
                        try {
                            return app(\App\Addons\SeoContentAi\PromptHooks\PromptHookRegistry::class)
                                ->get($state)
                                ->label();
                        } catch (\Throwable) {
                            return $state;
                        }
                    })
                    ->toggleable(),
                Tables\Columns\TextColumn::make('aiConnection.name')
                    ->label(__('seo-content-ai::filament.prompt.ai_connection'))
                    ->placeholder('—'),
                Tables\Columns\ToggleColumn::make('is_active')
                    ->label(__('seo-content-ai::filament.prompt.status')),
            ])
            ->defaultSort('updated_at', 'desc')
            ->actions([
                Tables\Actions\Action::make('test')
                    ->label(fn (SeoPrompt $record): string => app(AiModelsReadinessService::class)->isPromptReady($record)
                        ? 'Test'
                        : __('seo-content-ai::filament.prompt.sync_model'))
                    ->icon(fn (SeoPrompt $record): string => app(AiModelsReadinessService::class)->isPromptReady($record)
                        ? 'heroicon-o-play'
                        : 'heroicon-o-cpu-chip')
                    ->color(fn (SeoPrompt $record): string => app(AiModelsReadinessService::class)->isPromptReady($record)
                        ? 'success'
                        : 'warning')
                    ->url(fn (SeoPrompt $record): string => app(AiModelsReadinessService::class)->isPromptReady($record)
                        ? static::getUrl('test', ['record' => $record])
                        : SeoSettingsOverview::getUrl()),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions(static::seoPanelBulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]));
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()
            ->with('aiConnection');

        if (SeoAccessControl::shouldScopeToAccountOwner()) {
            $query->where('user_id', SeoAccessControl::accountSiteOwnerId());
        }

        // SoftDeletes scope giữ nguyên: prompt đã xóa không hiện lại list
        // (trước đây withoutGlobalScopes SoftDeletingScope → row còn, mất nút Xóa).
        return $query;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPrompts::route('/'),
            'create' => Pages\CreatePrompt::route('/create'),
            'edit' => Pages\EditPrompt::route('/{record}/edit'),
            'test' => Pages\TestPrompt::route('/{record}/test'),
        ];
    }
}
