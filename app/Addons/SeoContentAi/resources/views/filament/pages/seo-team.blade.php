<x-filament-panels::page>
    <div class="space-y-6">
        <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('seo-content-ai::filament.team.add_team_member') }}</h3>
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                {{ __('seo-content-ai::filament.team.add_team_member_hint') }}
            </p>

            <div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <div class="sm:col-span-2">
                    <label class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-300">{{ __('seo-content-ai::filament.team.name') }}</label>
                    <input
                        type="text"
                        wire:model.defer="memberName"
                        class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-800 dark:text-white"
                        placeholder="{{ __('seo-content-ai::filament.team.full_name_placeholder') }}"
                    />
                    @error('memberName')
                        <p class="mt-1 text-xs text-danger-600">{{ $message }}</p>
                    @enderror
                </div>

                <div class="sm:col-span-2">
                    <label class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-300">{{ __('seo-content-ai::filament.team.email') }}</label>
                    <input
                        type="email"
                        wire:model.defer="memberEmail"
                        class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-800 dark:text-white"
                        placeholder="member@example.com"
                    />
                    @error('memberEmail')
                        <p class="mt-1 text-xs text-danger-600">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-300">{{ __('seo-content-ai::filament.team.password') }}</label>
                    <input
                        type="password"
                        wire:model.defer="memberPassword"
                        class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-800 dark:text-white"
                        placeholder="{{ __('seo-content-ai::filament.team.password_placeholder') }}"
                    />
                    @error('memberPassword')
                        <p class="mt-1 text-xs text-danger-600">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-300">{{ __('seo-content-ai::filament.team.seo_role') }}</label>
                    <select
                        wire:model.defer="memberSeoRole"
                        class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-800 dark:text-white"
                    >
                        @foreach ($this->seoRoleOptions() as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('memberSeoRole')
                        <p class="mt-1 text-xs text-danger-600">{{ $message }}</p>
                    @enderror
                </div>
            </div>

            <div class="mt-4">
                <x-filament::button wire:click="addMember" wire:loading.attr="disabled">
                    {{ __('seo-content-ai::filament.team.add_member') }}
                </x-filament::button>
            </div>
        </div>

        <div class="rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-800 dark:bg-gray-900">
            <div class="flex items-center justify-between border-b border-gray-200 px-4 py-3 dark:border-gray-800">
                <h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('seo-content-ai::filament.team.team_list') }}</h3>
                <span class="text-xs text-gray-500 dark:text-gray-400">{{ trans_choice('seo-content-ai::filament.team.members_count', $this->teamMembers->count(), ['count' => $this->teamMembers->count()]) }}</span>
            </div>

            @if ($this->teamMembers->isEmpty())
                <div class="px-4 py-6 text-sm text-gray-500 dark:text-gray-400">
                    {{ __('seo-content-ai::filament.team.no_members') }}
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-gray-50 text-left dark:bg-gray-800/60">
                            <tr>
                                <th class="px-4 py-2 font-medium text-gray-600 dark:text-gray-300">{{ __('seo-content-ai::filament.team.name') }}</th>
                                <th class="px-4 py-2 font-medium text-gray-600 dark:text-gray-300">{{ __('seo-content-ai::filament.team.email') }}</th>
                                <th class="px-4 py-2 font-medium text-gray-600 dark:text-gray-300">{{ __('seo-content-ai::filament.team.seo_role') }}</th>
                                <th class="px-4 py-2 font-medium text-gray-600 dark:text-gray-300">{{ __('seo-content-ai::filament.team.status') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($this->teamMembers as $member)
                                <tr class="border-t border-gray-100 dark:border-gray-800">
                                    <td class="px-4 py-2 text-gray-900 dark:text-white">{{ $member->name }}</td>
                                    <td class="px-4 py-2 text-gray-700 dark:text-gray-300">{{ $member->email }}</td>
                                    <td class="px-4 py-2 text-gray-700 dark:text-gray-300">
                                        {{ __('seo-content-ai::filament.team.role_' . (string) $member->seo_role) }}
                                    </td>
                                    <td class="px-4 py-2 text-gray-700 dark:text-gray-300">
                                        {{ __('seo-content-ai::filament.team.user_status_' . (string) $member->status) }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
</x-filament-panels::page>
