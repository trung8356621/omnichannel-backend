@php
    /** @var \App\Addons\SeoContentAi\Models\Keyword $record */
    use App\Addons\SeoContentAi\Filament\Resources\KeywordResource;
    use App\Addons\SeoContentAi\Support\KeywordLinkDetailPanelPresenter;

    $presenter = app(KeywordLinkDetailPanelPresenter::class);
    $linkItems = $presenter->buildItems($record);
    $internalLinks = collect($linkItems)->where('link_type', 'internal')->take(5)->values();
    $linkedArticles = $record->mainArticles;
    $clusterLabel = $record->parent_id
        ? (string) ($record->parent?->phrase ?? '—')
        : (((int) ($record->children_count ?? 0) > 0)
            ? __('seo-content-ai::filament.keyword.type_pillar_short')
            : '—');
    $statusKey = KeywordResource::resolveDictionaryStatusKey($record);
    $statusLabel = KeywordResource::resolveDictionaryStatusLabel($statusKey);
    $statusClass = KeywordResource::resolveDictionaryStatusBadgeClass($statusKey);
    $typeLabel = KeywordResource::keywordTypeShortLabel((string) $record->type);
    $typeClass = match (KeywordResource::keywordTypeBadgeColor((string) $record->type)) {
        'success' => 'ws-badge--type-normal',
        'info' => 'ws-badge--type-cluster',
        default => 'ws-badge--type-gray',
    };
    if ((int) ($record->children_count ?? 0) > 0 && $record->parent_id === null) {
        $typeLabel = __('seo-content-ai::filament.keyword.type_pillar_short');
        $typeClass = 'ws-badge--type-pillar';
    } elseif ($record->parent_id !== null) {
        $typeLabel = __('seo-content-ai::filament.keyword.type_cluster_short');
        $typeClass = 'ws-badge--type-cluster';
    }
    $siteDomain = $record->linkMaps->first()?->sourceArticle?->site?->domain
        ?? $record->mainArticles->first()?->site?->domain
        ?? '—';
@endphp

<div class="keyword-dictionary-drawer">
    <p class="keyword-dictionary-drawer__domain">{{ $siteDomain }}</p>

    <div class="keyword-dictionary-drawer__badges">
        <span @class(['ws-badge', 'ws-badge--status', $statusClass])>
            @if ($statusKey === 'active')
                <x-filament::icon icon="heroicon-m-check-circle" class="h-3.5 w-3.5" />
            @elseif ($statusKey === 'needs_optimization')
                <x-filament::icon icon="heroicon-m-exclamation-triangle" class="h-3.5 w-3.5" />
            @else
                <x-filament::icon icon="heroicon-m-x-circle" class="h-3.5 w-3.5" />
            @endif
            {{ $statusLabel }}
        </span>
        <span @class(['ws-badge', $typeClass])>{{ $typeLabel }}</span>
    </div>

    <div class="keyword-dictionary-drawer__mini-stats">
        <div class="keyword-dictionary-drawer__mini-stat">
            <span class="keyword-dictionary-drawer__mini-stat-label">{{ __('seo-content-ai::filament.keyword.cluster_label') }}</span>
            <span class="keyword-dictionary-drawer__mini-stat-value">{{ $clusterLabel }}</span>
        </div>
        <div class="keyword-dictionary-drawer__mini-stat">
            <span class="keyword-dictionary-drawer__mini-stat-label">{{ __('seo-content-ai::filament.keyword.tags') }}</span>
            <span class="keyword-dictionary-drawer__mini-stat-value">
                {{ $record->tags->isEmpty() ? '—' : $record->tags->pluck('name')->join(', ') }}
            </span>
        </div>
        <div class="keyword-dictionary-drawer__mini-stat">
            <span class="keyword-dictionary-drawer__mini-stat-label">{{ __('seo-content-ai::filament.keyword.linked_articles') }}</span>
            <span class="keyword-dictionary-drawer__mini-stat-value">{{ number_format((int) ($record->linked_articles_count ?? 0)) }}</span>
        </div>
        <div class="keyword-dictionary-drawer__mini-stat">
            <span class="keyword-dictionary-drawer__mini-stat-label">{{ __('seo-content-ai::filament.keyword.internal_links_short') }}</span>
            <span class="keyword-dictionary-drawer__mini-stat-value">{{ number_format((int) ($record->site_links_count ?? 0)) }}</span>
        </div>
    </div>

    <section class="keyword-dictionary-drawer__section">
        <div class="keyword-dictionary-drawer__section-head">
            <h3 class="keyword-dictionary-drawer__section-title">
                {{ __('seo-content-ai::filament.keyword.drawer_linked_articles_heading') }}
            </h3>
        </div>
        @if ($linkedArticles->isEmpty())
            <p class="keyword-dictionary-drawer__empty">—</p>
        @else
            <ul class="keyword-dictionary-drawer__list">
                @foreach ($linkedArticles->take(4) as $article)
                    <li class="keyword-dictionary-drawer__list-item">
                        <div class="keyword-dictionary-drawer__list-body">
                            <p class="keyword-dictionary-drawer__list-title">{{ $article->title }}</p>
                        </div>
                        <span class="keyword-dictionary-drawer__list-badge ws-badge ws-badge--success ws-badge--status">
                            <x-filament::icon icon="heroicon-m-check-circle" class="h-3 w-3" />
                            {{ __('seo-content-ai::filament.keyword.stat_active') }}
                        </span>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    <section class="keyword-dictionary-drawer__section">
        <div class="keyword-dictionary-drawer__section-head">
            <h3 class="keyword-dictionary-drawer__section-title">
                {{ __('seo-content-ai::filament.keyword.drawer_internal_links_heading') }}
            </h3>
        </div>
        @if ($internalLinks->isEmpty())
            <p class="keyword-dictionary-drawer__empty">—</p>
        @else
            <ul class="keyword-dictionary-drawer__list">
                @foreach ($internalLinks as $item)
                    <li class="keyword-dictionary-drawer__list-item">
                        <div class="keyword-dictionary-drawer__list-body">
                            <p class="keyword-dictionary-drawer__list-title">{{ $item['source_title'] ?? '—' }}</p>
                            <p class="keyword-dictionary-drawer__list-meta">{{ $item['target_url'] ?? '—' }}</p>
                        </div>
                        <span class="keyword-dictionary-drawer__list-badge ws-badge ws-badge--success ws-badge--status">
                            <x-filament::icon icon="heroicon-m-check-circle" class="h-3 w-3" />
                            {{ __('seo-content-ai::filament.keyword.link_status_active') }}
                        </span>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
</div>
