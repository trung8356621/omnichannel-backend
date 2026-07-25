<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\RunEngine;

use App\Addons\SeoContentAi\Models\SeoProjectRun;
use App\Addons\SeoContentAi\Support\RunEngine\ArticleExecutionResult;

/**
 * Phase 1: abstraction only (log / Laravel event). SSE binds in Phase 2.
 * Engine must not stream HTTP here.
 */
interface ContentProjectRunEventPublisher
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function publish(SeoProjectRun $run, string $event, array $payload = []): void;

    public function runStarted(SeoProjectRun $run): void;

    public function runStopping(SeoProjectRun $run, ?string $reason = null): void;

    public function runCancelled(SeoProjectRun $run, ?string $reason = null): void;

    public function articleStarted(SeoProjectRun $run, int $taskId, ?int $runItemId = null): void;

    public function articleCompleted(SeoProjectRun $run, ArticleExecutionResult $result): void;

    public function articleFailed(SeoProjectRun $run, ArticleExecutionResult $result): void;

    public function articleCancelled(SeoProjectRun $run, ArticleExecutionResult $result): void;

    public function runProgressUpdated(SeoProjectRun $run): void;

    public function runCompleted(SeoProjectRun $run): void;

    public function runFailed(SeoProjectRun $run, string $message): void;
}
