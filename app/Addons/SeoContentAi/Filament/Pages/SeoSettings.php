<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Filament\Pages;

use App\Addons\SeoContentAi\Services\CreateArticlesFromTaskService;
use App\Addons\SeoContentAi\Services\SeoCreateArticleSettingsService;
use App\Addons\SeoContentAi\Services\SeoMainDomainService;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class SeoSettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $slug = 'settings';

    protected static ?string $navigationIcon = 'heroicon-o-adjustments-horizontal';

    protected static ?string $navigationGroup = 'SEO Workspace';

    protected static ?string $navigationLabel = 'Tùy chỉnh';

    protected static ?string $title = 'Tùy chỉnh';

    protected static string $view = 'seo-content-ai::filament.pages.seo-settings';

    /** @var array{task_id: ?int} */
    public array $settingsData = [
        'task_id' => null,
    ];

    public function mount(SeoCreateArticleSettingsService $settings): void
    {
        $this->settingsData = $settings->getSettings();
        $this->form->fill($this->settingsData);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Tạo bài viết tự động (create_article_task)')
                    ->description('Quy trình chạy khi bạn chọn «Tạo tự động từ khóa» trên danh sách bài viết. Bài viết luôn gắn với miền chính (đặt tại Danh sách tên miền). Cấu hình lưu trong wp_options.')
                    ->schema([
                        Forms\Components\Select::make('task_id')
                            ->label('Quy trình (Task)')
                            ->options(fn (CreateArticlesFromTaskService $service): array => $service->taskOptionsForSettings())
                            ->searchable()
                            ->native(false)
                            ->placeholder('Chọn quy trình…')
                            ->helperText('Quy trình phải có widget Hành động «Tạo bài viết» và các khối Prompt liên quan.'),
                    ]),
            ])
            ->statePath('settingsData');
    }

    public function saveSettings(SeoCreateArticleSettingsService $settings): void
    {
        $data = $this->form->getState();
        $settings->saveSettings([
            'task_id' => filled($data['task_id'] ?? null) ? (int) $data['task_id'] : null,
        ]);

        Notification::make()
            ->title('Đã lưu cấu hình')
            ->success()
            ->send();
    }
}
