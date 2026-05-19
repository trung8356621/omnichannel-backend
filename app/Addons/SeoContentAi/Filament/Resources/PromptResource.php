<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Filament\Resources;

use App\Addons\SeoContentAi\Filament\Resources\PromptResource\Pages;
use App\Addons\SeoContentAi\Models\SeoPrompt;
use App\Models\ApiConnection;
use Filament\Forms;
use Filament\Forms\Components\Actions\Action as FormAction;
use Filament\Forms\Components\Builder\Block;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Livewire\Component;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;

class PromptResource extends Resource
{
    protected static ?string $model = SeoPrompt::class;

    protected static ?string $slug = 'prompts';

    protected static ?string $navigationIcon = 'heroicon-o-chat-bubble-bottom-center-text';

    protected static ?string $navigationGroup = 'SEO Workspace';

    protected static ?string $navigationLabel = 'Quản lý Prompts';

    protected static ?string $modelLabel = 'Prompt';

    protected static ?string $pluralModelLabel = 'Prompts';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Grid::make(12)
                    ->schema([
                        Forms\Components\Group::make()
                            ->schema([
                                Forms\Components\Section::make('Thông tin chung')
                                    ->schema([
                                        Forms\Components\TextInput::make('name')
                                            ->label('Tên Prompt')
                                            ->required()
                                            ->maxLength(255),
                                        Forms\Components\Textarea::make('description')
                                            ->label('Miêu tả')
                                            ->rows(3)
                                            ->columnSpanFull(),
                                        Forms\Components\Select::make('ai_connection_id')
                                            ->label('Kết nối AI')
                                            ->options(fn (): array => self::aiConnectionOptions())
                                            ->searchable()
                                            ->required()
                                            ->native(false)
                                            ->placeholder('Chọn AI để thực thi Prompt này'),
                                        Forms\Components\Radio::make('tools')
                                            ->label('Công cụ')
                                            ->options([
                                                'default' => 'Mặc định',
                                                'image' => 'Hình ảnh',
                                                'video' => 'Video',
                                            ])
                                            ->default('default')
                                            ->inline(),
                                        Forms\Components\Toggle::make('is_active')
                                            ->label('Kích hoạt')
                                            ->default(true),
                                    ]),
                                Forms\Components\Section::make('Khai báo Biến (Variables)')
                                    ->schema([
                                        Forms\Components\Repeater::make('variables')
                                            ->label('')
                                            ->live()
                                            ->schema([
                                                Forms\Components\TextInput::make('name')
                                                    ->label('Tên biến (VD: focus_keyword)')
                                                    ->required()
                                                    ->maxLength(128),
                                                Forms\Components\TextInput::make('description')
                                                    ->label('Ghi chú')
                                                    ->maxLength(255),
                                            ])
                                            ->defaultItems(0)
                                            ->addActionLabel('Thêm biến')
                                            ->reorderable()
                                            ->collapsible(),
                                    ]),
                            ])
                            ->columnSpan(4),

                        Forms\Components\Tabs::make('PromptTabs')
                            ->live()
                            ->tabs([
                                Forms\Components\Tabs\Tab::make('Editor')
                                    ->icon('heroicon-o-pencil-square')
                                    ->schema([
                                        Forms\Components\Builder::make('prompt_data')
                                            ->label('')
                                            ->live()
                                            ->default(fn (): array => self::defaultPromptDataTemplate())
                                            ->addActionLabel('Thêm thành phần Prompt')
                                            ->blocks([
                                                self::promptPartBlock('role', 'Vai trò'),
                                                self::promptPartBlock('context', 'Bối cảnh'),
                                                self::promptPartBlock('task', 'Nhiệm vụ chính', isTask: true),
                                                self::promptPartBlock('formatting', 'Định dạng đầu ra'),
                                                self::promptPartBlock('constraints', 'Ràng buộc / Quy tắc'),
                                            ])
                                            ->collapsible(),
                                    ]),
                                Forms\Components\Tabs\Tab::make('JSON Preview')
                                    ->icon('heroicon-o-code-bracket')
                                    ->schema([
                                        Forms\Components\Placeholder::make('json_preview')
                                            ->label('')
                                            ->content(function (Get $get, Component $livewire): HtmlString {
                                                $parts = $get('prompt_data') ?? [];
                                                $payload = [
                                                    'variables' => self::formatVariablesForJsonPreview($get, $parts, $livewire),
                                                    'parts' => self::formatPartsForJsonPreview($parts),
                                                ];
                                                $jsonStr = json_encode(
                                                    $payload,
                                                    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
                                                );

                                                return new HtmlString(
                                                    '<pre style="background:#1e1e1e;color:#d4d4d4;padding:1rem;border-radius:0.5rem;overflow-x:auto;margin:0;">'
                                                    . '<code>' . e((string) $jsonStr) . '</code></pre>'
                                                );
                                            }),
                                    ]),
                            ])
                            ->columnSpan(8),
                    ]),
            ]);
    }

    /**
     * @return array<int|string, string>
     */
    private static function aiConnectionOptions(): array
    {
        $userId = auth()->id();

        return ApiConnection::query()
            ->where('status', 'active')
            ->when(
                auth()->user()?->role !== 'admin',
                fn ($query) => $query->where(function ($q) use ($userId): void {
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

                $label = $ai->name . ' (' . $providerName;
                if (filled($ai->default_model)) {
                    $label .= ' - ' . $ai->default_model;
                }
                $label .= ')';

                return [$ai->id => $label];
            })
            ->all();
    }

    /**
     * Template mặc định khi tạo prompt mới: Role → Context → Task → Format → Constraints.
     *
     * @return array<int, array{type: string, data: array<string, mixed>}>
     */
    public static function defaultPromptDataTemplate(): array
    {
        return [
            ['type' => 'role', 'data' => ['content' => '']],
            ['type' => 'context', 'data' => ['content' => '']],
            ['type' => 'task', 'data' => ['name' => '', 'rules' => '', 'content' => '']],
            ['type' => 'formatting', 'data' => ['content' => '']],
            ['type' => 'constraints', 'data' => ['content' => '']],
        ];
    }

    private static function promptPartBlock(
        string $role,
        string $defaultLabel,
        bool $isTask = false,
    ): Block {
        $schema = [];

        if ($isTask) {
            $schema[] = Forms\Components\TextInput::make('name')
                ->label('Tên thành phần')
                ->placeholder('VD: Cấu trúc dàn ý')
                ->live(debounce: 500)
                ->maxLength(255);
            $schema[] = Forms\Components\Textarea::make('rules')
                ->label('Ràng buộc / Quy tắc')
                ->placeholder('VD: Bắt buộc có H1, tối thiểu 3 H2…')
                ->rows(4)
                ->columnSpanFull();
        }

        $schema[] = Forms\Components\Textarea::make('content')
            ->label('Nội dung')
            ->required()
            ->rows(6)
            ->extraInputAttributes(['data-prompt-content' => '1'])
            ->hintActions([
                FormAction::make('choose_template')
                    ->label('Chọn mẫu')
                    ->icon('heroicon-m-bookmark-square')
                    ->color('info')
                    ->size('sm')
                    ->form([
                        Forms\Components\Select::make('selected_content')
                            ->label('Chọn nội dung từ mẫu có sẵn')
                            ->options(fn (): array => self::templateOptionsForRole($role))
                            ->searchable()
                            ->required(),
                    ])
                    ->action(function (Set $set, array $data): void {
                        $set('content', (string) ($data['selected_content'] ?? ''));
                    }),
                FormAction::make('insert_variable')
                    ->label('Chèn biến')
                    ->icon('heroicon-m-variable')
                    ->form([
                        Forms\Components\Select::make('selected_var')
                            ->label('Chọn biến')
                            ->options(fn (Get $get, Component $livewire): array => self::insertVariableOptions($get, $livewire))
                            ->required()
                            ->searchable(),
                    ])
                    ->action(function (array $data, Component $livewire, Forms\Components\Textarea $component): void {
                        $insert = trim((string) ($data['selected_var'] ?? ''));
                        if ($insert === '') {
                            return;
                        }

                        $livewire->dispatch(
                            'insert-prompt-variable',
                            variable: $insert,
                            statePath: $component->getStatePath(),
                        );
                    }),
            ]);

        return Block::make($role)
            ->label(fn (?array $state): string => $isTask && filled($state['name'] ?? null)
                ? (string) $state['name']
                : $defaultLabel)
            ->schema($schema);
    }

    /**
     * Chuẩn hóa builder state → JSON preview (nhiệm vụ chính: name + rules thành cặp).
     *
     * @param  array<int, array<string, mixed>>  $parts
     * @return array<int, array<string, mixed>>
     */
    public static function formatPartsForJsonPreview(array $parts): array
    {
        return collect($parts)
            ->map(static function (array $item): array {
                $type = (string) ($item['type'] ?? '');
                $data = is_array($item['data'] ?? null) ? $item['data'] : [];
                $content = trim((string) ($data['content'] ?? ''));

                if ($type === 'task') {
                    $pair = array_filter([
                        'name' => filled($data['name'] ?? null) ? (string) $data['name'] : null,
                        'rules' => filled($data['rules'] ?? null) ? trim((string) $data['rules']) : null,
                    ], static fn ($v) => $v !== null && $v !== '');

                    return array_filter([
                        'type' => 'task',
                        'content' => $content !== '' ? $content : null,
                        'pair' => $pair !== [] ? $pair : null,
                    ], static fn ($v) => $v !== null);
                }

                return array_filter([
                    'type' => $type !== '' ? $type : null,
                    'content' => $content !== '' ? $content : null,
                ], static fn ($v) => $v !== null);
            })
            ->values()
            ->all();
    }

    /**
     * @return array{role: string, name: ?string, content: string, position: int, meta: ?array<string, mixed>}|null
     */
    public static function partAttributesFromBuilderItem(array $item, int $index): ?array
    {
        $content = trim((string) ($item['data']['content'] ?? ''));
        if ($content === '' || empty($item['type'])) {
            return null;
        }

        $role = (string) $item['type'];
        $meta = null;

        if ($role === 'task') {
            $rules = trim((string) ($item['data']['rules'] ?? ''));
            if ($rules !== '') {
                $meta = ['rules' => $rules];
            }
        }

        return [
            'role' => $role,
            'name' => $role === 'task' && filled($item['data']['name'] ?? null)
                ? (string) $item['data']['name']
                : null,
            'content' => $content,
            'position' => $index,
            'meta' => $meta,
        ];
    }

    /**
     * @return array{type: string, data: array<string, mixed>}
     */
    public static function builderItemFromPart(object $part): array
    {
        $data = [
            'content' => $part->content,
        ];

        if ($part->role === 'task') {
            $data['name'] = $part->name;
            $meta = is_array($part->meta ?? null) ? $part->meta : [];
            $data['rules'] = (string) ($meta['rules'] ?? '');
        }

        return [
            'type' => (string) $part->role,
            'data' => $data,
        ];
    }

    /**
     * @return array<int, array{name: string, label: string, description: ?string}>
     */
    public static function variableDefinitionsForPrompt(SeoPrompt $prompt): array
    {
        $defaults = self::defaultVariableLabels();
        $declared = collect(is_array($prompt->variables) ? $prompt->variables : []);

        $parts = $prompt->parts()
            ->orderBy('position')
            ->get()
            ->map(static fn ($part): array => self::builderItemFromPart($part))
            ->values()
            ->all();

        $names = $declared
            ->pluck('name')
            ->filter()
            ->merge(self::extractVariableNamesFromParts($parts))
            ->unique()
            ->values();

        return $names
            ->map(static function (string $name) use ($declared, $defaults): array {
                $row = $declared->firstWhere('name', $name);

                return [
                    'name' => $name,
                    'label' => $defaults[$name] ?? $name,
                    'description' => filled($row['description'] ?? null) ? (string) $row['description'] : null,
                ];
            })
            ->all();
    }

    /**
     * @return array<string, string>
     */
    public static function defaultVariableLabels(): array
    {
        return [
            'post_title' => 'Tiêu đề bài viết',
            'post_content' => 'Nội dung bài viết',
            'focus_keyword' => 'Từ khóa chính',
            'post_excerpt' => 'Mô tả ngắn (Đoạn trích)',
            'site_domain' => 'Tên miền website',
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $parts
     * @return array<int, array<string, mixed>>
     */
    public static function formatVariablesForJsonPreview(Get $get, array $parts, ?Component $livewire = null): array
    {
        $defaults = self::defaultVariableLabels();
        $declaredRows = self::resolveFormVariableRows($get, $livewire);
        $merged = [];

        foreach ($declaredRows as $row) {
            $name = trim((string) ($row['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $merged[$name] = [
                'name' => $name,
                'description' => filled($row['description'] ?? null) ? (string) $row['description'] : null,
                'source' => 'declared',
            ];
        }

        foreach (self::extractVariableNamesFromParts($parts) as $name) {
            if (isset($merged[$name])) {
                continue;
            }

            $merged[$name] = [
                'name' => $name,
                'description' => $defaults[$name] ?? null,
                'source' => array_key_exists($name, $defaults) ? 'default' : 'detected',
            ];
        }

        return array_values($merged);
    }

    /**
     * @param  array<int, array<string, mixed>>  $parts
     * @return array<int, string>
     */
    public static function extractVariableNamesFromParts(array $parts): array
    {
        $text = collect($parts)
            ->map(static function (array $item): string {
                $data = is_array($item['data'] ?? null) ? $item['data'] : [];

                return implode("\n", array_filter([
                    (string) ($data['content'] ?? ''),
                    (string) ($data['rules'] ?? ''),
                ]));
            })
            ->join("\n");

        if (! preg_match_all('/\{\{\s*([a-zA-Z_][a-zA-Z0-9_]*)\s*\}\}/', $text, $matches)) {
            return [];
        }

        return array_values(array_unique($matches[1]));
    }

    /**
     * @return array<string, string>
     */
    private static function insertVariableOptions(Get $get, ?Component $livewire = null): array
    {
        $defaultVars = self::defaultVariableLabels();
        $customVars = collect(self::resolveFormVariableRows($get, $livewire))
            ->pluck('name')
            ->filter()
            ->values()
            ->all();

        $options = [];
        foreach ($defaultVars as $key => $label) {
            $options["{{{$key}}}"] = "Mặc định: {{{$key}}} ({$label})";
        }
        foreach ($customVars as $var) {
            $options["{{{$var}}}"] = "Tự tạo: {{{$var}}}";
        }

        return $options;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function resolveFormVariableRows(Get $get, ?Component $livewire = null): array
    {
        if ($livewire !== null) {
            $state = $livewire->data ?? [];
            if (is_array($state['variables'] ?? null) && $state['variables'] !== []) {
                return $state['variables'];
            }
        }

        foreach (['variables', '../../variables', '../../../variables', '../../../../variables', '../../../../../variables'] as $path) {
            $vars = $get($path);
            if (is_array($vars) && $vars !== []) {
                return $vars;
            }
        }

        return [];
    }

    /**
     * @return array<string, string>
     */
    private static function templateOptionsForRole(string $role): array
    {
        return DB::connection('omi_seo_ai')
            ->table('prompt_parts')
            ->where('role', $role)
            ->whereNotNull('content')
            ->where('content', '!=', '')
            ->distinct()
            ->orderByDesc('id')
            ->limit(50)
            ->pluck('content', 'content')
            ->all();
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Tên Prompt')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('aiConnection.name')
                    ->label('Kết nối AI')
                    ->placeholder('—'),
                Tables\Columns\ToggleColumn::make('is_active')
                    ->label('Trạng thái'),
            ])
            ->defaultSort('updated_at', 'desc')
            ->actions([
                Tables\Actions\Action::make('test')
                    ->label('Test')
                    ->icon('heroicon-o-play')
                    ->color('success')
                    ->url(fn (SeoPrompt $record): string => static::getUrl('test', ['record' => $record])),
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
        $query = parent::getEloquentQuery()
            ->with('aiConnection');

        if (auth()->user()?->role !== 'admin') {
            $query->where('user_id', auth()->id());
        }

        return $query->withoutGlobalScopes([
            SoftDeletingScope::class,
        ]);
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
