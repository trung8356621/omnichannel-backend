<div class="flex items-center gap-3 mr-4">
    <div class="flex items-center gap-1.5">
        <span class="text-[11px] font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('seo-content-ai::filament.global_bar.domain') }}</span>
        <select
            wire:model.live="globalSiteId"
            class="text-xs font-semibold py-1.5 px-3 bg-white dark:bg-slate-900 border border-gray-300 dark:border-slate-700 rounded-lg focus:ring-rose-500 focus:border-rose-500 text-gray-700 dark:text-slate-200 transition-colors cursor-pointer shadow-sm"
        >
            <option value="">{{ __('seo-content-ai::filament.global_bar.all_domains') }}</option>
            @foreach($sites as $site)
                <option value="{{ $site->id }}">{{ $site->name ?? $site->domain }}</option>
            @endforeach
        </select>
    </div>

    @if($showContentProjectPicker)
        <div class="flex items-center gap-1.5">
            <span class="text-[11px] font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('seo-content-ai::filament.global_bar.content_project') }}</span>
            <select
                wire:model.live="globalContentProjectId"
                class="text-xs font-semibold py-1.5 px-3 max-w-[16rem] bg-white dark:bg-slate-900 border border-gray-300 dark:border-slate-700 rounded-lg focus:ring-rose-500 focus:border-rose-500 text-gray-700 dark:text-slate-200 transition-colors cursor-pointer shadow-sm"
            >
                <option value="">{{ __('seo-content-ai::filament.global_bar.no_content_project') }}</option>
                @foreach($contentProjectOptions as $projectId => $label)
                    <option value="{{ $projectId }}">{{ $label }}</option>
                @endforeach
            </select>
        </div>
    @endif

    <div class="flex items-center gap-1.5">
        <span class="text-[11px] font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('seo-content-ai::filament.global_bar.view_as') }}</span>
        <select
            wire:model.live="simulatedRole"
            class="text-xs font-semibold py-1.5 px-3 bg-white dark:bg-slate-900 border border-gray-300 dark:border-slate-700 rounded-lg focus:ring-rose-500 focus:border-rose-500 text-gray-700 dark:text-slate-200 transition-colors cursor-pointer shadow-sm"
        >
            @foreach($roleOptions as $key => $label)
                <option value="{{ $key }}">{{ $label }}</option>
            @endforeach
        </select>
    </div>
</div>
