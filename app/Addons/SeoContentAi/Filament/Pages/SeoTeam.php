<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Filament\Pages;

use App\Addons\SeoContentAi\Support\SeoAccessControl;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SeoTeam extends Page implements HasActions
{
    use InteractsWithActions;

    protected static ?string $slug = 'team';

    protected static ?string $navigationIcon = 'heroicon-o-users';

    protected static ?string $navigationGroup = 'SEO Workspace';

    protected static ?string $navigationLabel = 'Team members';

    protected static ?string $title = 'Team management';

    protected static string $view = 'seo-content-ai::filament.pages.seo-team';

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

    public function addMemberAction(): Action
    {
        return Action::make('addMember')
            ->label(__('seo-content-ai::filament.team.add_member'))
            ->icon('heroicon-o-user-plus')
            ->modalHeading(__('seo-content-ai::filament.team.add_team_member'))
            ->modalDescription(__('seo-content-ai::filament.team.add_team_member_hint'))
            ->modalSubmitActionLabel(__('seo-content-ai::filament.team.add_member'))
            ->modalWidth('lg')
            ->form($this->addMemberFormSchema())
            ->action(function (array $data): void {
                $this->persistTeamMember($data);
            });
    }

    protected function getHeaderActions(): array
    {
        return [];
    }

    /**
     * @return list<\Filament\Forms\Components\Component>
     */
    private function addMemberFormSchema(): array
    {
        return [
            Forms\Components\Hidden::make('existingUserId'),
            Forms\Components\TextInput::make('memberEmail')
                ->label(__('seo-content-ai::filament.team.email'))
                ->email()
                ->required()
                ->autocomplete('off')
                ->placeholder('member@example.com')
                ->live(debounce: 350)
                ->afterStateUpdated(function (?string $state, Set $set): void {
                    $email = strtolower(trim((string) $state));
                    if ($email === '') {
                        $set('existingUserId', null);
                        $set('pickExistingEmail', null);
                        $set('memberName', '');

                        return;
                    }

                    $existing = User::query()->where('email', $email)->first();
                    if ($existing instanceof User) {
                        $this->applyExistingUserToForm($existing, $set);

                        return;
                    }

                    $set('existingUserId', null);
                    $set('pickExistingEmail', null);
                }),
            Forms\Components\Select::make('pickExistingEmail')
                ->label(__('seo-content-ai::filament.team.pick_existing_email'))
                ->placeholder(__('seo-content-ai::filament.team.pick_existing_email_placeholder'))
                ->options(fn (Get $get): array => $this->searchExistingUsersForTeam($get('memberEmail')))
                ->live()
                ->visible(fn (Get $get): bool => blank($get('existingUserId'))
                    && mb_strlen(trim((string) $get('memberEmail'))) >= 2)
                ->afterStateUpdated(function (?string $state, Set $set): void {
                    if (! filled($state)) {
                        return;
                    }

                    $existing = User::query()->where('email', strtolower(trim($state)))->first();
                    if ($existing instanceof User) {
                        $this->applyExistingUserToForm($existing, $set);
                    }
                }),
            Forms\Components\Placeholder::make('existingUserNotice')
                ->label('')
                ->content(function (Get $get): HtmlString {
                    $name = trim((string) $get('memberName'));
                    $email = trim((string) $get('memberEmail'));

                    return new HtmlString(
                        '<div class="rounded-lg border border-success-200 bg-success-50 px-3 py-2 text-sm text-success-800 dark:border-success-800 dark:bg-success-950 dark:text-success-200">'
                        .e(__('seo-content-ai::filament.team.existing_user_notice', [
                            'name' => $name !== '' ? $name : $email,
                            'email' => $email,
                        ]))
                        .'</div>',
                    );
                })
                ->visible(fn (Get $get): bool => filled($get('existingUserId'))),
            Forms\Components\TextInput::make('memberName')
                ->label(__('seo-content-ai::filament.team.name'))
                ->placeholder(__('seo-content-ai::filament.team.full_name_placeholder'))
                ->maxLength(255)
                ->required(fn (Get $get): bool => blank($get('existingUserId')))
                ->visible(fn (Get $get): bool => blank($get('existingUserId'))),
            Forms\Components\TextInput::make('memberPassword')
                ->label(__('seo-content-ai::filament.team.password'))
                ->password()
                ->revealable()
                ->placeholder(__('seo-content-ai::filament.team.password_placeholder'))
                ->minLength(8)
                ->required(fn (Get $get): bool => blank($get('existingUserId')))
                ->visible(fn (Get $get): bool => blank($get('existingUserId'))),
            Forms\Components\Select::make('memberSeoRole')
                ->label(__('seo-content-ai::filament.team.seo_role'))
                ->options($this->seoRoleOptions())
                ->default(SeoAccessControl::ROLE_CONTENT_MANAGER)
                ->required(fn (Get $get): bool => blank($get('existingUserId')))
                ->visible(fn (Get $get): bool => blank($get('existingUserId'))),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function persistTeamMember(array $data): void
    {
        /** @var User|null $owner */
        $owner = auth()->user();
        if (! $owner instanceof User) {
            return;
        }

        $existingUserId = (int) ($data['existingUserId'] ?? 0);
        if ($existingUserId > 0) {
            $existing = User::query()->find($existingUserId);
            if (! $existing instanceof User) {
                Notification::make()
                    ->title(__('seo-content-ai::filament.team.member_add_failed'))
                    ->body(__('seo-content-ai::filament.team.existing_user_not_found'))
                    ->danger()
                    ->send();

                return;
            }

            try {
                $this->attachExistingMember($owner, $existing);
            } catch (ValidationException $exception) {
                Notification::make()
                    ->title(__('seo-content-ai::filament.team.member_add_failed'))
                    ->body(collect($exception->errors())->flatten()->first() ?? '')
                    ->danger()
                    ->send();

                return;
            }

            Notification::make()
                ->title(__('seo-content-ai::filament.team.member_linked'))
                ->success()
                ->send();

            return;
        }

        $validated = validator($data, [
            'memberName' => ['required', 'string', 'max:255'],
            'memberEmail' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'memberPassword' => ['required', 'string', 'min:8'],
            'memberSeoRole' => ['required', Rule::in(array_keys($this->seoRoleOptions()))],
        ])->validate();

        User::query()->create([
            'parent_id' => (int) $owner->id,
            'role' => User::ROLE_STAFF,
            'seo_role' => (string) $validated['memberSeoRole'],
            'status' => User::STATUS_NORMAL,
            'name' => trim((string) $validated['memberName']),
            'email' => strtolower(trim((string) $validated['memberEmail'])),
            'password' => Hash::make((string) $validated['memberPassword']),
        ]);

        Notification::make()
            ->title(__('seo-content-ai::filament.team.member_added'))
            ->success()
            ->send();
    }

    private function attachExistingMember(User $owner, User $existing): void
    {
        if ((int) $existing->id === (int) $owner->id) {
            throw ValidationException::withMessages([
                'memberEmail' => __('seo-content-ai::filament.team.cannot_add_self'),
            ]);
        }

        if ((int) $existing->parent_id === (int) $owner->id) {
            throw ValidationException::withMessages([
                'memberEmail' => __('seo-content-ai::filament.team.already_team_member'),
            ]);
        }

        if ((int) $existing->parent_id > 0 && (int) $existing->parent_id !== (int) $owner->id) {
            throw ValidationException::withMessages([
                'memberEmail' => __('seo-content-ai::filament.team.already_other_team'),
            ]);
        }

        $existing->update([
            'parent_id' => (int) $owner->id,
            'role' => User::ROLE_STAFF,
            'seo_role' => SeoAccessControl::normalizeRole(
                (string) ($existing->seo_role ?: SeoAccessControl::ROLE_CONTENT_MANAGER),
            ),
        ]);
    }

    private function applyExistingUserToForm(User $existing, Set $set): void
    {
        $set('memberEmail', (string) $existing->email);
        $set('pickExistingEmail', (string) $existing->email);
        $set('existingUserId', (int) $existing->id);
        $set('memberName', (string) $existing->name);
    }

    /**
     * @return array<string, string>
     */
    private function searchExistingUsersForTeam(mixed $search): array
    {
        $term = strtolower(trim((string) $search));
        if (mb_strlen($term) < 2) {
            return [];
        }

        /** @var User|null $owner */
        $owner = auth()->user();
        if (! $owner instanceof User) {
            return [];
        }

        $ownerId = (int) $owner->id;

        return User::query()
            ->where('email', 'like', '%'.$term.'%')
            ->whereKeyNot($ownerId)
            ->where(function ($query) use ($ownerId): void {
                $query->whereNull('parent_id')
                    ->orWhere('parent_id', '!=', $ownerId);
            })
            ->orderBy('email')
            ->limit(8)
            ->get()
            ->mapWithKeys(function (User $user): array {
                $label = trim((string) $user->email);
                $name = trim((string) $user->name);
                if ($name !== '') {
                    $label .= ' — '.$name;
                }

                return [(string) $user->email => $label];
            })
            ->all();
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
