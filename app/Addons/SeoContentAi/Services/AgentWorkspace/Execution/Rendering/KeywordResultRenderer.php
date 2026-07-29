<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\AgentWorkspace\Execution\Rendering;

use App\Addons\SeoContentAi\Services\AgentWorkspace\Execution\Dtos\AgentExecutionResult;

final class KeywordResultRenderer implements AgentResultRenderer
{
    public function supports(AgentExecutionResult $result): bool
    {
        return str_starts_with($result->capabilityKey, 'keyword.');
    }

    public function render(AgentExecutionResult $result): array
    {
        $base = (new GenericAgentResultRenderer)->render($result);
        $base['title'] = $result->ok ? 'Keyword Intelligence' : 'Keyword lỗi';
        $workspace = $result->data['workspace_ref'] ?? $result->data['keyword_workspace_ref'] ?? null;
        if (is_string($workspace) && $workspace !== '') {
            $base['links'][] = [
                'label' => 'Keyword Workspace',
                'ref' => $workspace,
                'type' => 'keyword_workspace',
            ];
            $base['metrics']['workspace_ref'] = $workspace;
        }

        return $base;
    }
}
