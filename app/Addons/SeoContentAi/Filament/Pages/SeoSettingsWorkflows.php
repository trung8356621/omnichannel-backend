<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Filament\Pages;

use App\Addons\SeoContentAi\Services\CreateArticlesFromTaskService;
use App\Addons\SeoContentAi\Services\SeoCreateArticleSettingsService;
use App\Addons\SeoContentAi\Services\SeoPromptSettingsOptionsService;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class SeoSettingsWorkflows extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $slug = 'settings/workflows';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $title = 'Quy trình';

    protected static string $view = 'seo-content-ai::filament.pages.seo-settings-workflows';

    /** @var array<string, ?int> */
    public array $settingsData = [];

    public function mount(SeoCreateArticleSettingsService $settings): void
    {
        $this->settingsData = $settings->getSettings();
        $this->form->fill($this->settingsData);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                $this->taskSelect(
                    SeoCreateArticleSettingsService::KEY_PUBLISH_ARTICLE,
                    'Đăng bài viết',
                    'Chạy khi tạo bài mới từ khóa, đăng nội dung bài viết, v.v.',
                ),
                $this->taskSelect(
                    SeoCreateArticleSettingsService::KEY_EDIT_ARTICLE,
                    'Sửa bài viết',
                    'Chạy khi cập nhật / chỉnh sửa bài có sẵn. Để trống sẽ dùng quy trình «Đăng bài viết».',
                ),
                $this->taskSelect(
                    SeoCreateArticleSettingsService::KEY_POST_REVIEW,
                    'Đăng review',
                    'Chạy khi đăng bình luận / review lên WordPress.',
                ),
                $this->taskSelect(
                    SeoCreateArticleSettingsService::KEY_CREATE_IMAGE,
                    'Tạo ảnh',
                    'Chạy khi có yêu cầu tạo hoặc xử lý ảnh (prompt công cụ Hình ảnh).',
                ),
                $this->taskSelect(
                    SeoCreateArticleSettingsService::KEY_CREATE_VIDEO,
                    'Tạo video',
                    'Chạy khi có yêu cầu tạo video (prompt công cụ Video).',
                ),
                Forms\Components\Section::make('FAQ trên editor bài viết')
                    ->description('Prompt dùng khi biên tập bấm «Làm mới» một câu FAQ. Gợi ý biến: {{faq_question}}, {{faq_answer}}, {{post_title}}, {{post_content}}, {{site_domain}}. Đầu ra JSON: {"question":"…","answer":"…"} hoặc Markdown H3 + nội dung.')
                    ->schema([
                        Forms\Components\Select::make(SeoCreateArticleSettingsService::KEY_RENEW_FAQ_PROMPT_ID)
                            ->label('Prompt làm mới FAQ')
                            ->options(fn (SeoPromptSettingsOptionsService $options): array => $options->activePromptOptions())
                            ->searchable()
                            ->native(false)
                            ->placeholder('Chọn prompt…'),
                    ]),
            ])
            ->statePath('settingsData');
    }

    private function taskSelect(string $field, string $label, string $helperText): Forms\Components\Select
    {
        return Forms\Components\Select::make($field)
            ->label($label)
            ->options(fn (CreateArticlesFromTaskService $service): array => $service->taskOptionsForSettings())
            ->searchable()
            ->native(false)
            ->placeholder('Chọn quy trình…')
            ->helperText($helperText);
    }

    public function saveSettings(SeoCreateArticleSettingsService $settings): void
    {
        $data = $this->form->getState();

        $settings->saveSettings([
            SeoCreateArticleSettingsService::KEY_PUBLISH_ARTICLE => $data[SeoCreateArticleSettingsService::KEY_PUBLISH_ARTICLE] ?? null,
            SeoCreateArticleSettingsService::KEY_EDIT_ARTICLE => $data[SeoCreateArticleSettingsService::KEY_EDIT_ARTICLE] ?? null,
            SeoCreateArticleSettingsService::KEY_POST_REVIEW => $data[SeoCreateArticleSettingsService::KEY_POST_REVIEW] ?? null,
            SeoCreateArticleSettingsService::KEY_CREATE_IMAGE => $data[SeoCreateArticleSettingsService::KEY_CREATE_IMAGE] ?? null,
            SeoCreateArticleSettingsService::KEY_CREATE_VIDEO => $data[SeoCreateArticleSettingsService::KEY_CREATE_VIDEO] ?? null,
            SeoCreateArticleSettingsService::KEY_RENEW_FAQ_PROMPT_ID => $data[SeoCreateArticleSettingsService::KEY_RENEW_FAQ_PROMPT_ID] ?? null,
        ]);

        Notification::make()
            ->title('Đã lưu cấu hình quy trình')
            ->success()
            ->send();
    }
}
