<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services;

use App\Addons\SeoContentAi\Exceptions\AiModelsNotReadyException;
use App\Addons\SeoContentAi\Filament\Pages\SeoSettingsOverview;
use App\Addons\SeoContentAi\Models\SeoAiModel;
use App\Addons\SeoContentAi\Models\SeoPrompt;
use App\Models\ApiConnection;

final class AiModelsReadinessService
{
    public function overviewUrl(): string
    {
        return SeoSettingsOverview::getUrl();
    }

    public function isConnectionReady(?ApiConnection $connection): bool
    {
        if ($connection === null) {
            return false;
        }

        if ($connection->status !== 'active') {
            return false;
        }

        if (blank($connection->api_key)) {
            return false;
        }

        return SeoAiModel::query()
            ->where('api_connection_id', $connection->id)
            ->where('status', SeoAiModel::STATUS_ACTIVE)
            ->exists();
    }

    public function isPromptReady(SeoPrompt $prompt): bool
    {
        $prompt->loadMissing('aiConnection');

        return $this->isConnectionReady($prompt->aiConnection);
    }

    public function assertConnectionReady(?ApiConnection $connection, ?string $connectionLabel = null): void
    {
        if ($this->isConnectionReady($connection)) {
            return;
        }

        $name = $connectionLabel ?? ($connection?->name ?? 'Kết nối AI');

        throw new AiModelsNotReadyException(
            '«' . $name . '»: chưa có model AI active trong hệ thống (đồng bộ lỗi hoặc chưa chạy). '
            . 'Vào trang Tổng quan → Đồng bộ model trước khi chạy prompt / quy trình.',
            $this->overviewUrl(),
        );
    }

    public function assertPromptReady(SeoPrompt $prompt): void
    {
        $prompt->loadMissing('aiConnection');
        $this->assertConnectionReady($prompt->aiConnection, (string) ($prompt->aiConnection?->name ?? 'Kết nối AI'));
    }

    public function blockMessage(): string
    {
        return 'Model AI chưa sẵn sàng. Mở Tổng quan cài đặt để đồng bộ model (Imagen, Gemini, Claude…).';
    }

    /**
     * Có ít nhất một kết nối AI (của user) đã đồng bộ model active.
     */
    public function userHasReadyAiConnection(?int $userId = null): bool
    {
        $userId ??= (int) auth()->id();

        $connectionIds = ApiConnection::query()
            ->where(function ($query) use ($userId): void {
                $query->where('user_id', $userId)
                    ->orWhere('is_global', true);
            })
            ->where('status', 'active')
            ->whereNotNull('api_key')
            ->pluck('id');

        if ($connectionIds->isEmpty()) {
            return false;
        }

        return SeoAiModel::query()
            ->whereIn('api_connection_id', $connectionIds)
            ->where('status', SeoAiModel::STATUS_ACTIVE)
            ->exists();
    }
}
