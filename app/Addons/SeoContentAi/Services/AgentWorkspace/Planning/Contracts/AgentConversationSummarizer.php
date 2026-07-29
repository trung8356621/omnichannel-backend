<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\AgentWorkspace\Planning\Contracts;

use App\Addons\SeoContentAi\Services\AgentWorkspace\Planning\Data\AgentConversationSummary;
use App\Addons\SeoContentAi\Services\AgentWorkspace\Planning\Data\AgentSummarizationRequest;

interface AgentConversationSummarizer
{
    public function summarize(AgentSummarizationRequest $request): AgentConversationSummary;

    public function shouldSummarize(int $messageCount, int $approxTokens): bool;
}
