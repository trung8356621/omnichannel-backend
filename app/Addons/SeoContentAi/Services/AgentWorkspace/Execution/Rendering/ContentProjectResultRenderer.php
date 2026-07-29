<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\AgentWorkspace\Execution\Rendering;

use App\Addons\SeoContentAi\Services\AgentWorkspace\Execution\Dtos\AgentExecutionResult;

final class ContentProjectResultRenderer implements AgentResultRenderer
{
    public function supports(AgentExecutionResult $result): bool
    {
        return str_starts_with($result->capabilityKey, 'content_project.');
    }

    public function render(AgentExecutionResult $result): array
    {
        $base = (new GenericAgentResultRenderer)->render($result);
        $base['title'] = $result->ok ? 'Content Project' : 'Content Project lỗi';
        $projectRef = isset($result->data['project_ref']) && is_string($result->data['project_ref'])
            ? $result->data['project_ref']
            : null;
        $itemCount = null;
        if (isset($result->data['item_count']) && is_numeric($result->data['item_count'])) {
            $itemCount = (int) $result->data['item_count'];
        } elseif (isset($result->data['selected_item_refs']) && is_array($result->data['selected_item_refs'])) {
            $itemCount = count($result->data['selected_item_refs']);
        }

        $base['metrics'] = array_merge($base['metrics'] ?? [], array_filter([
            'project_ref' => $projectRef,
            'item_count' => $itemCount,
        ], static fn ($v) => $v !== null));

        if ($projectRef) {
            $base['links'] = array_values(array_merge($base['links'] ?? [], [[
                'label' => 'Mở project',
                'ref' => $projectRef,
                'type' => 'content_project',
            ]]));
        }

        return $base;
    }
}
