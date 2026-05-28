<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Filament\Pages;

use App\Addons\SeoContentAi\Support\SeoAccessControl;
use App\Models\User;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class SeoTeam extends Page
{
    protected static ?string $slug = 'team';

    protected static ?string $navigationIcon = 'heroicon-o-users';

    protected static ?string $navigationGroup = 'SEO Workspace';

    protected static ?string $navigationLabel = 'Team members';

    protected static ?string $title = 'Team management';

    protected static string $view = 'seo-content-ai::filament.pages.seo-team';

    public string $memberName = '';

    public string $memberEmail = '';

    public string $memberPassword = '';

    public string $memberSeoRole = SeoAccessControl::ROLE_CONTENT_MANAGER;

    /**
     * @return array<string, string>
     */
    public function seoRoleOptions(): array
    {
        return [
            SeoAccessControl::ROLE_MANAGER => __('seo-content-ai::filament.team.role_manager'),
            SeoAccessControl::ROLE_PLANNER => __('seo-content-ai::filament.team.role_planner'),
            SeoAccessControl::ROLE_CONTENT_MANAGER => __('seo-content-ai::filament.team.role_content_manager'),
        ];
    }

    /**
     * @return Collection<int, User>
     */
    public function getTeamMembersProperty(): Collection
    {
        $user = auth()->user();
        if (! $user instanceof User) {
            return new Collection();
        }

        return User::query()
            ->where('parent_id', (int) $user->id)
            ->orderBy('name')
            ->get();
    }

    public function addMember(): void
    {
        $user = auth()->user();
        if (! $user instanceof User) {
            return;
        }

        $payload = $this->validate([
            'memberName' => ['required', 'string', 'max:255'],
            'memberEmail' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'memberPassword' => ['required', 'string', 'min:8'],
            'memberSeoRole' => ['required', Rule::in(array_keys($this->seoRoleOptions()))],
        ]);

        User::query()->create([
            'parent_id' => (int) $user->id,
            'role' => User::ROLE_STAFF,
            'seo_role' => (string) $payload['memberSeoRole'],
            'status' => User::STATUS_NORMAL,
            'name' => trim((string) $payload['memberName']),
            'email' => strtolower(trim((string) $payload['memberEmail'])),
            'password' => Hash::make((string) $payload['memberPassword']),
        ]);

        $this->memberName = '';
        $this->memberEmail = '';
        $this->memberPassword = '';
        $this->memberSeoRole = SeoAccessControl::ROLE_CONTENT_MANAGER;

        Notification::make()
            ->title(__('seo-content-ai::filament.team.member_added'))
            ->success()
            ->send();
    }

    public static function canAccess(): bool
    {
        return SeoAccessControl::canAccessManagerFeatures();
    }

    public static function getNavigationLabel(): string
    {
        return __('seo-content-ai::filament.nav.team_members');
    }

    public function getTitle(): string
    {
        return __('seo-content-ai::filament.nav.team_management');
    }
}
