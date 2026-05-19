<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Filament\Resources\ArticleResource\Pages;

use App\Addons\SeoContentAi\Filament\Resources\ArticleResource;
use App\Addons\SeoContentAi\Services\CreateArticlesFromTaskService;
use App\Addons\SeoContentAi\Services\SeoMainDomainService;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListArticles extends ListRecords
{
    protected static string $resource = ArticleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\ActionGroup::make([
                Actions\Action::make('create_manual')
                    ->label('Tạo thủ công')
                    ->icon('heroicon-o-pencil-square')
                    ->url(fn (): string => ArticleResource::getUrl('create')),
                Actions\Action::make('create_from_keywords')
                    ->label('Tạo tự động từ khóa')
                    ->icon('heroicon-o-sparkles')
                    ->form([
                        Forms\Components\Placeholder::make('main_domain')
                            ->label('Miền chính')
                            ->content(fn (SeoMainDomainService $mainDomain): string => $mainDomain->resolveMainSiteLabel()),
                        Forms\Components\Textarea::make('keywords')
                            ->label('Từ khóa')
                            ->placeholder("Mỗi dòng một từ khóa\nVD:\nbalo da nam\ntúi vải không dệt")
                            ->rows(8)
                            ->required()
                            ->columnSpanFull(),
                    ])
                    ->action(function (array $data, CreateArticlesFromTaskService $service): void {
                        try {
                            $result = $service->runFromKeywords(
                                (string) ($data['keywords'] ?? ''),
                            );

                            $body = sprintf(
                                'Thành công: %d · Lỗi: %d',
                                $result['created'],
                                $result['failed'],
                            );

                            if ($result['messages'] !== []) {
                                $body .= "\n" . implode("\n", array_slice($result['messages'], 0, 8));
                                if (count($result['messages']) > 8) {
                                    $body .= "\n…";
                                }
                            }

                            $notification = Notification::make()
                                ->title('Đã xử lý từ khóa')
                                ->body($body);

                            if ($result['failed'] > 0 && $result['created'] === 0) {
                                $notification->danger();
                            } elseif ($result['failed'] > 0) {
                                $notification->warning();
                            } else {
                                $notification->success();
                            }

                            $notification->send();
                        } catch (\InvalidArgumentException $exception) {
                            Notification::make()
                                ->title('Không thể tạo bài')
                                ->body($exception->getMessage())
                                ->danger()
                                ->send();
                        }
                    })
                    ->modalHeading('Tạo bài viết tự động')
                    ->modalDescription('Nhập danh sách từ khóa. Hệ thống chạy quy trình đã cấu hình tại SEO → Tùy chỉnh (wp_options: create_article_task).')
                    ->modalSubmitActionLabel('Chạy quy trình & tạo bài'),
            ])
                ->label('Tạo bài viết mới')
                ->icon('heroicon-o-plus')
                ->color('success')
                ->button(),
            Actions\Action::make('trash')
                ->label('Thùng rác')
                ->icon('heroicon-o-trash')
                ->color('gray')
                ->url(fn (): string => ArticleResource::getUrl('trash')),
        ];
    }
}
