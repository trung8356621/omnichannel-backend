<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use App\Models\User;
use App\Services\Users\UserHierarchyService;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Hash;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->before(function (): void {
                    /** @var User $record */
                    $record = $this->getRecord();
                    app(UserHierarchyService::class)->assertCanDelete($record);
                    app(UserHierarchyService::class)->detachStaffFromManager($record);
                }),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['password'] = '';

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        /** @var User $record */
        $record = $this->getRecord();
        $hierarchy = app(UserHierarchyService::class);

        $newRole = (string) ($data['role'] ?? $record->role);
        $hierarchy->handleManagerRoleChange($record, $newRole);
        $data = $hierarchy->normalizeFormData($data, $record);

        if (
            (string) (auth()->user()?->role ?? '') === User::ROLE_OWNER
            && in_array($newRole, [User::ROLE_MANAGER, User::ROLE_STAFF], true)
        ) {
            $data['parent_id'] = auth()->id();
        }

        if (blank($data['password'] ?? null)) {
            unset($data['password']);
        } else {
            $data['password'] = Hash::make((string) $data['password']);
        }

        return $data;
    }
}
