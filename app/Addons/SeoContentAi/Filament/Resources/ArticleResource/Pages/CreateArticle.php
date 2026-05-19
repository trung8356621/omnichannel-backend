<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Filament\Resources\ArticleResource\Pages;

use App\Addons\SeoContentAi\Filament\Resources\ArticleResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Str;

class CreateArticle extends CreateRecord
{
    protected static string $resource = ArticleResource::class;

    protected static ?string $title = 'Tạo bài viết mới';

    protected static bool $shouldRegisterNavigation = false;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['user_id'] = auth()->id();
        $data['language'] = $data['language'] ?? 'vi';
        $data['type'] = $data['type'] ?? 'article';
        $data['status'] = $data['status'] ?? 'draft';
        $data['body'] = $data['body'] ?? '';

        $title = trim((string) ($data['title'] ?? ''));
        $baseSlug = $title !== '' ? Str::slug($title) : 'bai-viet-moi';
        $data['slug'] = $baseSlug . '-' . now()->format('YmdHis');

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return ArticleResource::getUrl('edit', ['record' => $this->record]);
    }
}
