<x-filament-panels::page>
    <div class="space-y-6">
        <div class="rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-800 dark:bg-gray-900">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-200 px-4 py-3 dark:border-gray-800">
                <div>
                    <h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('seo-content-ai::filament.team.team_list') }}</h3>
                    <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">{{ __('seo-content-ai::filament.team.add_team_member_hint') }}</p>
                </div>
                <div class="flex items-center gap-2">
                    <span class="text-xs text-gray-500 dark:text-gray-400">{{ trans_choice('seo-content-ai::filament.team.members_count', $this->teamMembers->count(), ['count' => $this->teamMembers->count()]) }}</span>
                    {{ $this->addMemberAction }}
                </div>
            </div>

            @if ($this->teamMembers->isEmpty())
                <div class="px-4 py-10 text-center text-sm text-gray-500 dark:text-gray-400">
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
