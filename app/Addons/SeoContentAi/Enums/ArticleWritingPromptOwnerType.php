<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Enums;

enum ArticleWritingPromptOwnerType: string
{
    case SettingsBinding = 'settings_binding';
    case WorkflowNode = 'workflow_node';

    public function historyLabel(): string
    {
        return match ($this) {
            self::SettingsBinding => 'Settings',
            self::WorkflowNode => 'Workflow',
        };
    }
}
