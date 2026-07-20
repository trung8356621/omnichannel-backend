<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Filament\Resources\AutomationRuleResource\Pages;

use App\Addons\SeoContentAi\Automation\BusinessHook\Models\AutomationRule;
use App\Addons\SeoContentAi\Filament\Resources\AutomationRuleResource;
use App\Addons\SeoContentAi\Filament\Pages\AutomationWorkflowBuilder;
use App\Addons\SeoContentAi\Filament\Resources\Pages\SeoEditRecord;
use Filament\Actions;
use Filament\Notifications\Notification;

final class EditAutomationRule extends SeoEditRecord
{
    protected static string $resource = AutomationRuleResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data = AutomationRuleResource::mutateFormDataBeforeFill($data);
        $data['actions_data'] = AutomationRuleResource::fillActionsRepeaterFromRecord($data);
        $data['graph_nodes'] = AutomationRuleResource::fillGraphNodesRepeater($data);
        $data['graph_edges'] = AutomationRuleResource::fillGraphEdgesRepeater($data);

        return $data;
    }

    protected function handleRecordUpdate(\Illuminate\Database\Eloquent\Model $record, array $data): AutomationRule
    {
        if (! $record instanceof AutomationRule) {
            throw new \InvalidArgumentException('Expected AutomationRule.');
        }

        return AutomationRuleResource::updateRuleFromFormData($record, $data);
    }

    protected function getSavedNotification(): ?Notification
    {
        return Notification::make()
            ->title('Automation rule updated')
            ->success();
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('visualBuilder')
                ->label('Visual builder')
                ->icon('heroicon-o-squares-2x2')
                ->url(fn (): string => AutomationWorkflowBuilder::getUrl(['rule' => $this->record->getKey()])),
            Actions\ViewAction::make(),
        ];
    }
}
