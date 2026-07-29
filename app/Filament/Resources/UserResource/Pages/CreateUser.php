<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use App\Models\User;
use App\Services\Users\UserHierarchyService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Hash;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data = app(UserHierarchyService::class)->normalizeFormData($data);

        $role = (string) ($data['role'] ?? '');
        if (
            (string) (auth()->user()?->role ?? '') === User::ROLE_OWNER
            && in_array($role, [User::ROLE_MANAGER, User::ROLE_STAFF], true)
        ) {
            $data['parent_id'] = auth()->id();
            if ($role === User::ROLE_MANAGER) {
                $data['manager_id'] = null;
            }
        }

        $data['password'] = Hash::make((string) ($data['password'] ?? UserResource::generateRandomPassword()));

        return $data;
    }
}
