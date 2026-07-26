<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Filament\Resources\SeoProjectResource\Pages;

use App\Addons\SeoContentAi\Filament\Resources\Pages\SeoCreateRecord;
use App\Addons\SeoContentAi\Filament\Resources\SeoProjectResource;
use App\Addons\SeoContentAi\Models\SeoProject;
use App\Addons\SeoContentAi\Services\ContentProjectStaffAvailabilityService;
use App\Addons\SeoContentAi\Services\SeoProjectTaskSyncService;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateSeoProject extends SeoCreateRecord
{
    protected static string $resource = SeoProjectResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $writerId = (int) request()->query('writer_id', 0);
        if ($writerId <= 0) {
            return $data;
        }

        $service = app(ContentProjectStaffAvailabilityService::class);
        if (! $service->unassignedStaffQuery()->whereKey($writerId)->exists()
            && ! array_key_exists($writerId, SeoProjectResource::userSelectOptions())) {
            return $data;
        }

        $data['user_id'] = $writerId;
        $data['unassigned_staff_ids'] = $service->isUnassigned($writerId) ? [$writerId] : [];
        $data['assign_from_unassigned'] = $service->isUnassigned($writerId);

        return $data;
    }

    public function create(bool $another = false): void
    {
        // Chặn double/triple submit Livewire (tạo nhiều project cùng tháng).
        if ($this->formSaveExplicitlyLocked) {
            return;
        }

        $this->lockFormSave();

        try {
            parent::create($another);
        } catch (\Throwable $exception) {
            $this->unlockFormSave();

            throw $exception;
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data = SeoProjectResource::normalizeProjectSiteId($data);

        if (! empty($data['month'])) {
            $data['month'] = Carbon::parse($data['month'])->startOfMonth()->format('Y-m-d');
            $data['name'] = SeoProject::defaultNameFromMonth($data['month']);
        }

        $siteId = isset($data['site_id']) ? (int) $data['site_id'] : 0;
        $month = (string) ($data['month'] ?? '');
        if ($siteId > 0 && $month !== '' && SeoProjectResource::monthlyProjectExistsForSiteMonth($siteId, $month)) {
            throw ValidationException::withMessages([
                'data.month' => __('seo-content-ai::filament.projects.month_already_exists'),
            ]);
        }

        $userId = (int) ($data['user_id'] ?? 0);
        $fromUnassigned = filter_var($data['assign_from_unassigned'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if ($fromUnassigned && $userId > 0) {
            $this->assertStillUnassigned($userId);
        }

        $tasksData = $data['tasks_data'] ?? [];
        $projectSiteId = $siteId > 0 ? $siteId : null;
        $sanitized = app(SeoProjectTaskSyncService::class)->sanitizeTasksData($tasksData, $projectSiteId);

        app(SeoProjectTaskSyncService::class)->assertWithinMonthlyLimit($data['month'], $sanitized);

        $data['total_tasks'] = count($sanitized);
        $data['status'] = SeoProject::STATUS_MANUAL;
        $data['kind'] = SeoProject::KIND_MONTHLY;

        unset($data['tasks_data'], $data['unassigned_staff_ids'], $data['assign_from_unassigned']);

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $tasksData = $this->form->getState()['tasks_data'] ?? [];
        $userId = (int) ($data['user_id'] ?? 0);
        $assignFromUnassigned = filter_var(
            $this->form->getState()['assign_from_unassigned'] ?? false,
            FILTER_VALIDATE_BOOLEAN,
        );

        return DB::connection('omi_seo_ai')->transaction(function () use ($data, $tasksData, $userId, $assignFromUnassigned): Model {
            if ($assignFromUnassigned && $userId > 0) {
                $this->assertStillUnassigned($userId);
            }

            /** @var SeoProject $project */
            $project = static::getModel()::create($data);

            app(SeoProjectTaskSyncService::class)->sync($project, $tasksData);

            return $project;
        });
    }

    protected function getRedirectUrl(): string
    {
        /** @var SeoProject $record */
        $record = $this->getRecord();

        return SeoProjectResource::getUrl('edit', ['record' => $record]);
    }

    private function assertStillUnassigned(int $userId): void
    {
        $service = app(ContentProjectStaffAvailabilityService::class);
        if ($service->isUnassigned($userId)) {
            return;
        }

        $user = User::query()->find($userId);
        $label = $user instanceof User
            ? $service->formatLabel($user)
            : '#'.$userId;

        throw ValidationException::withMessages([
            'data.user_id' => __('seo-content-ai::filament.projects.unassigned_staff_race', [
                'name' => $label,
            ]),
        ]);
    }
}
