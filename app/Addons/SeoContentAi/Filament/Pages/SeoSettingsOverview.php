<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Filament\Pages;

use App\Addons\SeoContentAi\Services\SeoOverviewSettingsService;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class SeoSettingsOverview extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $slug = 'settings/overview';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $title = 'Tổng quan';

    protected static string $view = 'seo-content-ai::filament.pages.seo-settings-overview';

    /** @var array<string, mixed> */
    public array $overviewSettingsData = [];

    public function mount(SeoOverviewSettingsService $settings): void
    {
        $raw = $settings->getSettings();

        $this->overviewSettingsData = [
            SeoOverviewSettingsService::KEY_FAQ_CATCH_KEYWORDS => $settings->keywordsToTextarea(
                $raw[SeoOverviewSettingsService::KEY_FAQ_CATCH_KEYWORDS],
            ),
        ];

        $this->form->fill($this->overviewSettingsData);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('FAQ catch')
                    ->description('Tiêu đề Markdown (H2–H6) chứa một trong các từ khóa bên dưới được coi là mở đầu khối FAQ. Dùng khi bóc tách FAQ và cắt nội dung trước khi chèn [omi_faq]. Mỗi từ khóa một dòng, không phân biệt hoa thường.')
                    ->schema([
                        Forms\Components\Textarea::make(SeoOverviewSettingsService::KEY_FAQ_CATCH_KEYWORDS)
                            ->label('Từ khóa nhận diện FAQ')
                            ->rows(10)
                            ->required()
                            ->columnSpanFull()
                            ->helperText('Ví dụ: faq, câu hỏi thường gặp, hỏi đáp'),
                    ]),
            ])
            ->statePath('overviewSettingsData');
    }

    public function saveOverviewSettings(SeoOverviewSettingsService $settings): void
    {
        $data = $this->form->getState();
        $raw = (string) ($data[SeoOverviewSettingsService::KEY_FAQ_CATCH_KEYWORDS] ?? '');

        $settings->saveSettings([
            SeoOverviewSettingsService::KEY_FAQ_CATCH_KEYWORDS => $settings->keywordsFromTextarea($raw),
        ]);

        Notification::make()
            ->title('Đã lưu cài đặt tổng quan')
            ->success()
            ->send();
    }
}
