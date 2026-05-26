<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Filament\Resources;

use App\Addons\SeoContentAi\Filament\Resources\PromptResource\Pages;
use App\Addons\SeoContentAi\Filament\Pages\SeoSettingsOverview;
use App\Addons\SeoContentAi\Models\SeoPrompt;
use App\Addons\SeoContentAi\Support\Utf8Sanitizer;
use App\Addons\SeoContentAi\Services\AiModelsReadinessService;
use App\Addons\SeoContentAi\Support\AiModelCategory;
use App\Addons\SeoContentAi\Support\PromptLoaiSanPhamVariable;
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
                                            ->live()
                                            ->native(false)
                                            ->placeholder('Chọn AI để thực thi Prompt này'),
                                        Forms\Components\Select::make('model_category')
                                            ->label('Lựa chọn Model đại diện')
                                            ->options(fn (Get $get): array => self::modelCategoryOptionsForConnection($get('ai_connection_id')))
                                            ->default(fn (Get $get): ?string => self::defaultModelCategoryForConnection($get('ai_connection_id')))
                                            ->required()
                                            ->native(false)
                                            ->helperText('Hệ thống tự chọn phiên bản API tốt nhất và chuyển dự phòng khi hết quota. Công cụ Hình ảnh tự dùng GEMINI Image Pro.'),
                                        Forms\Components\Radio::make('tools')
                                            ->label('Công cụ')
                                            ->options([
                                                'default' => 'Mặc định (văn bản)',
                                                'image' => 'Hình ảnh (Gemini sinh ảnh)',
                                                'video' => 'Video (URL thủ công)',
                                            ])
                                            ->helperText(
                                                'Chuỗi task + sub_task: bước cha luôn văn bản (GEMINI Flash/Pro); bước con tự sinh ảnh (Imagen 4 / Nano Banana) dù chọn Mặc định hay Hình ảnh. '
                                                . 'Prompt một bước + Hình ảnh: toàn bộ chạy Imagen/Nano Banana. Gemini 3.1 Flash Lite chỉ là model chữ — không sinh ảnh.',
                                            )
                                            ->default('default')
                                            ->inline(),
                                        Forms\Components\Toggle::make('is_active')
                                            ->label('Kích hoạt')
                                            ->default(true),
                                    ]),
                                Forms\Components\Section::make('Khai báo Biến (Variables)')
                                    ->description('Biến mặc định ({{input}}, {{loai_san_pham}}, {{PARENT_RESULT}}, …) có sẵn khi chèn vào nội dung — không cần thêm ở đây. Chỉ khai báo biến tùy chỉnh khác.')
                                    ->schema([
                                        Forms\Components\Repeater::make('variables')
                                            ->label('')
                                            ->live()
                                            ->schema([
                                                Forms\Components\TextInput::make('name')
                                                    ->label('Tên biến (VD: focus_keyword)')
                                                    ->required()
                                                    ->maxLength(128)
                                                    ->helperText(
                                                        fn (?string $state): ?string => PromptLoaiSanPhamVariable::isLoaiSanPhamName((string) $state)
                                                            ? 'loai_san_pham là biến mặc định — không cần khai báo; dùng nút Chèn biến trong builder.'
                                                            : null,
                                                    )
                                                    ->rules([
                                                        fn (): \Closure => static function (string $attribute, $value, \Closure $fail): void {
                                                            if (PromptLoaiSanPhamVariable::isLoaiSanPhamName((string) $value)) {
                                                                $fail('loai_san_pham là biến mặc định — không thêm vào danh sách khai báo.');
                                                            }
                                                        },
                                                    ]),
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
                                                self::subTaskBlock(),
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
                                                    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE,
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

                $label = $ai->name . ' (' . $providerName . ')';

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

        $contentField = Forms\Components\Textarea::make('content')
            ->label('Nội dung')
            ->required()
            ->rows(6)
            ->extraInputAttributes(['data-prompt-content' => '1']);

        $schema[] = $contentField
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

    private static function subTaskBlock(): Block
    {
        return Block::make('sub_task')
            ->label(fn (?array $state): string => 'Nhiệm vụ phụ thuộc (Sub-Prompt)'
                . (filled($state['name'] ?? null) ? ' - ' . $state['name'] : ''))
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->label('Tên Sub-Prompt (Gợi nhớ)')
                    ->placeholder('Ví dụ: Anh_2_Goc_nghieng, Anh_3_POV...')
                    ->live(debounce: 500)
                    ->required(),
                Forms\Components\Textarea::make('rules')
                    ->label('Ràng buộc / Quy tắc')
                    ->placeholder('VD: Bắt buộc có H1, tối thiểu 3 H2…')
                    ->rows(4)
                    ->columnSpanFull(),
                Forms\Components\Textarea::make('specific_constraints')
                    ->label('Ràng buộc riêng (sub-prompt)')
                    ->placeholder('VD: Góc máy 45°, nền trắng, không chèn chữ…')
                    ->helperText('Chỉ áp dụng cho nhiệm vụ phụ thuộc này; khác với quy tắc chung của block.')
                    ->rows(4)
                    ->columnSpanFull(),
                Forms\Components\Textarea::make('content')
                    ->label('Nội dung Prompt phụ thuộc')
                    ->helperText('Sử dụng biến {{PARENT_RESULT}} để AI tham chiếu đến kết quả của Prompt cha.')
                    ->required()
                    ->rows(6)
                    ->hintAction(
                        FormAction::make('insert_variable_sub')
                            ->label('Chèn biến')
                            ->icon('heroicon-m-variable')
                            ->form([
                                Forms\Components\Select::make('selected_var')
                                    ->label('Chọn biến')
                                    ->options(function (Get $get): array {
                                        $customVars = collect($get('../../variables') ?? [])
                                            ->pluck('name')
                                            ->filter()
                                            ->toArray();

                                        $options = [
                                            '{{PARENT_RESULT}}' => 'Hệ thống: Kết quả Prompt cha (Text hoặc File URL)',
                                        ];

                                        foreach ($customVars as $var) {
                                            $options["{{$var}}"] = "Tự tạo: {{$var}}";
                                        }

                                        return $options;
                                    })
                                    ->required()
                                    ->searchable(),
                            ])
                            ->action(function (Set $set, Get $get, array $data): void {
                                $current = (string) ($get('content') ?? '');
                                $insert = (string) ($data['selected_var'] ?? '');
                                if ($insert === '') {
                                    return;
                                }

                                $set('content', trim($current . ' ' . $insert));
                            }),
                    ),
            ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    public static function filterDeprecatedPromptDataItems(array $items): array
    {
        return array_values(array_filter(
            $items,
            static fn (array $item): bool => (string) ($item['type'] ?? '') !== 'global_constraints',
        ));
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

                if ($type === 'sub_task') {
                    $pair = array_filter([
                        'name' => filled($data['name'] ?? null) ? (string) $data['name'] : null,
                        'rules' => filled($data['rules'] ?? null) ? trim((string) $data['rules']) : null,
                        'specific_constraints' => filled($data['specific_constraints'] ?? null)
                            ? trim((string) $data['specific_constraints'])
                            : null,
                    ], static fn ($v) => $v !== null && $v !== '');

                    return array_filter([
                        'type' => 'sub_task',
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
     * @param  array<string, mixed>  $data
     * @return array<string, string>|null
     */
    public static function taskMetaFromBuilderData(string $role, array $data): ?array
    {
        $meta = [];
        $rules = trim(Utf8Sanitizer::string((string) ($data['rules'] ?? '')));
        if ($rules !== '') {
            $meta['rules'] = $rules;
        }

        if ($role === 'sub_task') {
            $specific = trim(Utf8Sanitizer::string((string) ($data['specific_constraints'] ?? '')));
            if ($specific !== '') {
                $meta['specific_constraints'] = $specific;
            }
        }

        return $meta !== [] ? $meta : null;
    }

    /**
     * @return array{role: string, name: ?string, content: string, position: int, meta: ?array<string, mixed>}|null
     */
    public static function partAttributesFromBuilderItem(array $item, int $index): ?array
    {
        $content = trim(Utf8Sanitizer::string((string) ($item['data']['content'] ?? '')));
        if ($content === '' || empty($item['type'])) {
            return null;
        }

        $role = (string) $item['type'];

        if ($role === 'global_constraints') {
            return null;
        }

        $meta = null;

        if (in_array($role, ['task', 'sub_task'], true)) {
            $meta = self::taskMetaFromBuilderData($role, is_array($item['data'] ?? null) ? $item['data'] : []);
        }

        return [
            'role' => $role,
            'name' => in_array($role, ['task', 'sub_task'], true) && filled($item['data']['name'] ?? null)
                ? Utf8Sanitizer::string((string) $item['data']['name'])
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

        if (in_array((string) $part->role, ['task', 'sub_task'], true)) {
            $data['name'] = $part->name;
        }

        if (in_array((string) $part->role, ['task', 'sub_task'], true)) {
            $meta = is_array($part->meta ?? null) ? $part->meta : [];
            $data['rules'] = (string) ($meta['rules'] ?? '');
            if ((string) $part->role === 'sub_task') {
                $data['specific_constraints'] = (string) ($meta['specific_constraints'] ?? '');
            }
        }

        return [
            'type' => (string) $part->role,
            'data' => $data,
        ];
    }

    public static function promptUsesInputVariable(SeoPrompt $prompt): bool
    {
        $declared = collect(is_array($prompt->variables) ? $prompt->variables : []);

        if ($declared->contains(static fn ($row): bool => trim((string) ($row['name'] ?? '')) === 'input')) {
            return true;
        }

        $parts = $prompt->parts()
            ->orderBy('position')
            ->get()
            ->map(static fn ($part): array => self::builderItemFromPart($part))
            ->values()
            ->all();

        return in_array('input', self::extractVariableNamesFromParts($parts), true);
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

        $names = $declared->pluck('name')
            ->filter()
            ->merge(self::extractVariableNamesFromParts($parts))
            ->unique()
            ->values();

        return $names
            ->reject(static fn (string $name): bool => PromptLoaiSanPhamVariable::isLoaiSanPhamName($name)
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

                return $name !== '' && ! PromptLoaiSanPhamVariable::isLoaiSanPhamName($name);
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
            'input' => 'Kết quả edge nối vào (SEO Flow)',
            'post_title' => 'Tiêu đề bài viết',
            'post_content' => 'Nội dung bài viết',
            'focus_keyword' => 'Từ khóa chính',
            'post_excerpt' => 'Mô tả ngắn (Đoạn trích)',
            'site_domain' => 'Tên miền website',
            'site_short_description' => 'Mô tả ngắn website (domain)',
            'site_cta' => 'CTA / liên hệ website (domain)',
            'site_links' => 'Danh sách link (từ khóa → URL, domain)',
            'loai_san_pham' => 'Loại sản phẩm (product_cat) — biến mặc định, chạy thử: tên miền → product_cat',
        ];
    }

    /**
     * Biến luôn có trong menu chèn / JSON preview (không cần khai báo trong repeater).
     *
     * @return list<string>
     */
    public static function defaultRuntimeVariableNames(): array
    {
        return [
            PromptLoaiSanPhamVariable::NAME,
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
                    (string) ($data['specific_constraints'] ?? ''),
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
            if (array_key_exists($var, $defaultVars)) {
                continue;
            }

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
                    ->label(fn (SeoPrompt $record): string => app(AiModelsReadinessService::class)->isPromptReady($record)
                        ? 'Test'
                        : 'Đồng bộ model')
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
