@php
    /** @var int $siteId */
    $siteId = (int) ($this->siteId ?? 0);
@endphp

<x-filament-panels::page>
    <x-filament::section
        :heading="__('seo-content-ai::filament.projects.archive_dashboard_heading')"
        :description="__('seo-content-ai::filament.projects.archive_dashboard_description')"
    >
        @include('seo-content-ai::filament.resources.seo-project-resource.partials.archive-dashboard', [
            'siteId' => $siteId,
            'siteIds' => $this->scopedSiteIds,
            'canReopen' => $this->canReopenArchivedArticles(),
        ])
    </x-filament::section>
</x-filament-panels::page>
