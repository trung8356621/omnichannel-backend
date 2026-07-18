<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Automation\Migration;

use App\Addons\SeoContentAi\Automation\Data\ActionResult;
use App\Addons\SeoContentAi\Automation\Enums\MigrationMode;
use Illuminate\Support\Str;

/**
 * Shadow: legacy ghi thật; parity qua dry-run/plan — không double-write, không emit event từ shadow.
 * Action: chỉ ActionRunner ghi.
 */
final class AutomationCallerMigrator
{
    public function __construct(
        private readonly AutomationMigrationFlags $flags,
        private readonly AutomationParityLogger $parityLogger,
    ) {}

    /**
     * @param  callable(): mixed  $legacyWrite
     * @param  callable(): ActionResult  $actionWrite  Chỉ gọi khi mode=action
     * @param  callable(): array<string, mixed>  $parityExpected  Dry-run/plan — không ghi
     * @param  callable(mixed): array<string, mixed>  $normalizeLegacy
     * @param  callable(array<string, mixed>): array<string, mixed>  $normalizeExpected
     */
    public function run(
        string $callerKey,
        callable $legacyWrite,
        callable $actionWrite,
        callable $parityExpected,
        callable $normalizeLegacy,
        callable $normalizeExpected,
        string $actionKey = '',
        ?string $correlationId = null,
    ): mixed {
        $mode = $this->flags->mode($callerKey);
        $correlationId = $correlationId !== null && $correlationId !== ''
            ? $correlationId
            : Str::uuid()->toString();

        if ($mode === MigrationMode::Action) {
            $result = $actionWrite();
            if (! $result instanceof ActionResult) {
                throw new \RuntimeException("Action write for [{$callerKey}] must return ActionResult.");
            }
            if (! $result->success) {
                throw new AutomationMigrationWriteException(
                    $callerKey,
                    (string) ($result->error['message'] ?? 'Automation action failed.'),
                    $result,
                );
            }

            return $result;
        }

        if ($mode === MigrationMode::Shadow) {
            $started = hrtime(true);
            $expectedRaw = $parityExpected();
            $legacyResult = $legacyWrite();
            $durationMs = (int) ((hrtime(true) - $started) / 1_000_000);

            $this->parityLogger->compare(
                callerKey: $callerKey,
                actionKey: $actionKey,
                expected: $normalizeExpected(is_array($expectedRaw) ? $expectedRaw : []),
                actual: $normalizeLegacy($legacyResult),
                correlationId: $correlationId,
                durationMs: $durationMs,
            );

            return $legacyResult;
        }

        return $legacyWrite();
    }
}
