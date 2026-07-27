<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\ContentProject\Application\Support;

use App\Addons\SeoContentAi\Services\ContentProject\Application\ContentProjectActionCodes;
use App\Addons\SeoContentAi\Services\ContentProject\Application\ContentProjectActionResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Idempotency store — retention 7 ngày.
 */
final class ContentProjectIdempotencyStore
{
    private const RETENTION_DAYS = 7;

    public function begin(string $tenantKey, string $action, string $key): ?ContentProjectActionResult
    {
        if ($key === '' || ! $this->ready()) {
            return null;
        }

        $this->purgeExpired();

        $existing = DB::connection('omi_seo_ai')->table('seo_content_project_idempotency_keys')
            ->where('tenant_key', $tenantKey)
            ->where('action', $action)
            ->where('idempotency_key', $key)
            ->first();

        if ($existing !== null) {
            if ((string) $existing->status === 'processing') {
                return ContentProjectActionResult::ok(
                    ContentProjectActionCodes::PROCESSING,
                    'Request trước đó đang processing.',
                    metadata: ['idempotent' => true, 'status' => 'processing'],
                );
            }

            if ((string) $existing->status === 'succeeded' && is_string($existing->result_payload)) {
                $payload = json_decode($existing->result_payload, true);
                if (is_array($payload)) {
                    return new ContentProjectActionResult(
                        success: (bool) ($payload['success'] ?? true),
                        code: ContentProjectActionCodes::IDEMPOTENT_REPLAY,
                        message: (string) ($payload['message'] ?? 'Idempotent replay.'),
                        projectId: isset($payload['project_id']) ? (int) $payload['project_id'] : null,
                        affectedItemIds: is_array($payload['affected_item_ids'] ?? null)
                            ? array_map('intval', $payload['affected_item_ids'])
                            : [],
                        warnings: is_array($payload['warnings'] ?? null) ? $payload['warnings'] : [],
                        errors: is_array($payload['errors'] ?? null) ? $payload['errors'] : [],
                        metadata: array_merge(
                            is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [],
                            ['idempotent' => true, 'replay' => true],
                        ),
                    );
                }
            }
        }

        try {
            DB::connection('omi_seo_ai')->table('seo_content_project_idempotency_keys')->insert([
                'tenant_key' => $tenantKey,
                'action' => $action,
                'idempotency_key' => $key,
                'status' => 'processing',
                'result_payload' => null,
                'expires_at' => now()->addDays(self::RETENTION_DAYS),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (Throwable) {
            // race → re-read
            return $this->begin($tenantKey, $action, $key);
        }

        return null;
    }

    public function complete(string $tenantKey, string $action, string $key, ContentProjectActionResult $result): void
    {
        if ($key === '' || ! $this->ready()) {
            return;
        }

        DB::connection('omi_seo_ai')->table('seo_content_project_idempotency_keys')
            ->where('tenant_key', $tenantKey)
            ->where('action', $action)
            ->where('idempotency_key', $key)
            ->update([
                'status' => $result->success ? 'succeeded' : 'failed',
                'result_payload' => json_encode($result->toArray(), JSON_UNESCAPED_UNICODE),
                'expires_at' => now()->addDays(self::RETENTION_DAYS),
                'updated_at' => now(),
            ]);
    }

    private function ready(): bool
    {
        return Schema::connection('omi_seo_ai')->hasTable('seo_content_project_idempotency_keys');
    }

    private function purgeExpired(): void
    {
        try {
            DB::connection('omi_seo_ai')->table('seo_content_project_idempotency_keys')
                ->where('expires_at', '<', now())
                ->limit(200)
                ->delete();
        } catch (Throwable) {
            // ignore
        }
    }
}
