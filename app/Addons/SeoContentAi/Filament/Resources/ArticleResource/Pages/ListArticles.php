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

    protected static string $view = 'seo-content-ai::filament.resources.article-resource.pages.list-articles';

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('create_from_keywords')
                ->label('Create new articles')
                ->icon('heroicon-o-plus')
                ->color('success')
                ->form([
                    Forms\Components\Placeholder::make('main_domain')
                        ->label('Main domain')
                        ->content(fn (SeoMainDomainService $mainDomain): string => $mainDomain->resolveMainSiteLabel()),
                    Forms\Components\Textarea::make('keywords')
                        ->label('Keywords')
                        ->placeholder("One keyword per line\nExample:\nmen leather backpack\nnon-woven bags")
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
                            'Success: %d · Failed: %d',
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
                            ->title('Keywords processed')
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
                            ->title('Unable to create articles')
                            ->body($exception->getMessage())
                            ->danger()
                            ->send();
                    }
                })
                ->modalHeading('Auto create articles')
                ->modalDescription('Enter keyword list. System will run configured "Publish article" workflow in SEO -> Settings.')
                ->modalSubmitActionLabel('Run workflow & create'),
            Actions\Action::make('trash')
                ->label('Trash')
                ->icon('heroicon-o-trash')
                ->color('gray')
                ->url(fn (): string => ArticleResource::getUrl('trash')),
        ];
    }
}
