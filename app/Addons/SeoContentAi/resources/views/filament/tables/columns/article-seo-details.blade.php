@php
    /** @var \App\Addons\SeoContentAi\Models\SeoArticle $record */
    use App\Addons\SeoContentAi\Support\ArticleListSeoSummary;

    $record = $getRecord();
    $seo = ArticleListSeoSummary::for($record);
@endphp

<div class="article-seo-cell" wire:key="article-seo-{{ $record->id }}" onclick="event.stopPropagation()">
    <div class="article-seo-cell__box">
        

        @if (! empty($seo['score_skipped']))
            <span class="article-seo-score article-seo-score--skipped" title="{{ __('seo-content-ai::filament.article_list.seo_score_skipped_label') }}">
                {{ __('seo-content-ai::filament.article_list.seo_score_skipped_label') }}
            </span>
        @elseif ($seo['score'] !== null)
            <span class="article-seo-score article-seo-score--{{ $seo['score_tone'] }}">
                {{ $seo['score'] }} / 100
            </span>
        @else
            <span class="article-seo-score article-seo-score--muted">— / 100</span>
        @endif

        <p class="article-seo-line">
            <span class="article-seo-line__label">Từ khóa:</span>
            @if (filled($seo['keyword']))
                <span class="article-seo-line__value">{{ $seo['keyword'] }}</span>
            @else
                <span class="article-seo-line__empty">Chưa có</span>
            @endif
        </p>

        <p class="article-seo-line">
            <span class="article-seo-line__label">Loại:</span>
            <span class="article-seo-line__value">{{ $seo['schema'] }}</span>
        </p>

        <p class="article-seo-line">
            <span class="article-seo-line__label">Hình ảnh:</span>
            <span class="article-seo-line__value">{{ $seo['image_count'] }}</span>
        </p>

        <p class="article-seo-line">
            <span class="article-seo-line__label">FAQ:</span>
            <span class="article-seo-line__value">
                {{ $seo['faq_count'] }} câu
                <span class="article-seo-line__sep" aria-hidden="true">·</span>
                {{ $seo['faq_points'] }}/10 điểm
            </span>
        </p>

        <p class="article-seo-line">
            <span class="article-seo-line__label">Featured Snippet:</span>
            <span class="article-seo-line__value">{{ $seo['featured_snippet_points'] }}/10 điểm</span>
        </p>

        <p class="article-seo-line article-seo-line--links">
            <span class="article-seo-line__label">Liên kết:</span>
            <span class="article-seo-links">
                <span class="article-seo-links__item" title="Tổng liên kết">
                    <x-filament::icon icon="heroicon-m-link" class="article-seo-links__icon" />
                    {{ $seo['links_total'] }}
                </span>
                <span class="article-seo-links__sep" aria-hidden="true">|</span>
                <span class="article-seo-links__item" title="Liên kết ngoài">
                    <x-filament::icon icon="heroicon-m-arrow-top-right-on-square" class="article-seo-links__icon" />
                    {{ $seo['links_external'] }}
                </span>
                <span class="article-seo-links__sep" aria-hidden="true">|</span>
                <span class="article-seo-links__item" title="Liên kết nội bộ">
                    <x-filament::icon icon="heroicon-m-arrow-uturn-left" class="article-seo-links__icon" />
                    {{ $seo['links_internal'] }}
                </span>
            </span>
        </p>

        <div class="article-seo-cell__actions">
            <button
                type="button"
                class="article-seo-cell__detail-link js-article-seo-open"
                data-article-id="{{ $record->id }}"
            >
                Chi tiết
            </button>
        </div>
    </div>
</div>

@once
    <style>
        .article-seo-cell {
            min-width: 14rem;
            max-width: 22rem;
        }

        .article-seo-cell__box {
            position: relative;
            display: flex;
            flex-direction: column;
            gap: 0.375rem;
            padding: 0.5rem 4rem 0.5rem 0;
            text-align: left;
        }

        .article-seo-cell__actions {
            position: relative;
            z-index: 1;
        }

        .article-seo-cell__detail-link {
            margin: 0;
            padding: 0;
            border: none;
            background: none;
            font-size: 0.8125rem;
            font-weight: 600;
            color: rgb(37 99 235);
            cursor: pointer;
            text-decoration: underline;
            text-underline-offset: 2px;
        }

        .article-seo-cell__detail-link:hover {
            color: rgb(29 78 216);
        }

        .dark .article-seo-cell__detail-link {
            color: rgb(96 165 250);
        }

        .dark .article-seo-cell__detail-link:hover {
            color: rgb(147 197 253);
        }

        .article-seo-score {
            display: inline-block;
            align-self: flex-start;
            padding: 0.125rem 0.5rem;
            border-radius: 0.375rem;
            font-size: 0.75rem;
            font-weight: 700;
            line-height: 1.4;
        }

        .article-seo-score--success {
            background: rgb(220 252 231);
            color: rgb(21 128 61);
        }

        .article-seo-score--warning {
            background: rgb(254 243 199);
            color: rgb(146 64 14);
        }

        .article-seo-score--danger {
            background: rgb(254 226 226);
            color: rgb(185 28 28);
        }

        .article-seo-score--muted {
            background: rgb(243 244 246);
            color: rgb(107 114 128);
        }

        .article-seo-score--skipped {
            background: rgb(254 243 199);
            color: rgb(146 64 14);
            border: 1px dashed rgb(245 158 11);
        }

        .dark .article-seo-score--success {
            background: rgba(22, 163, 74, 0.2);
            color: rgb(134 239 172);
        }

        .dark .article-seo-score--warning {
            background: rgba(245, 158, 11, 0.2);
            color: rgb(252 211 77);
        }

        .dark .article-seo-score--danger {
            background: rgba(220, 38, 38, 0.2);
            color: rgb(252 165 165);
        }

        .dark .article-seo-score--muted {
            background: rgb(31 41 55);
            color: rgb(156 163 175);
        }

        .dark .article-seo-score--skipped {
            background: rgba(245, 158, 11, 0.15);
            color: rgb(252 211 77);
            border-color: rgb(180 83 9);
        }

        .article-seo-line {
            margin: 0;
            font-size: 0.75rem;
            line-height: 1.4;
            color: rgb(55 65 81);
            word-break: break-word;
        }

        .dark .article-seo-line {
            color: rgb(209 213 219);
        }

        .article-seo-line__label {
            font-weight: 600;
            color: rgb(17 24 39);
        }

        .dark .article-seo-line__label {
            color: rgb(243 244 246);
        }

        .article-seo-line__value {
            font-weight: 500;
        }

        .article-seo-line__empty {
            color: rgb(156 163 175);
            font-style: italic;
        }

        .article-seo-line__sep {
            margin: 0 0.2rem;
            color: rgb(156 163 175);
            font-weight: 400;
        }

        .article-seo-line--links {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 0.25rem 0.375rem;
        }

        .article-seo-links {
            display: inline-flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 0.25rem 0.375rem;
            font-weight: 600;
            color: rgb(55 65 81);
        }

        .dark .article-seo-links {
            color: rgb(209 213 219);
        }

        .article-seo-links__item {
            display: inline-flex;
            align-items: center;
            gap: 0.125rem;
        }

        .article-seo-links__icon {
            width: 0.875rem;
            height: 0.875rem;
            flex-shrink: 0;
        }

        .article-seo-links__sep {
            color: rgb(209 213 219);
            font-weight: 400;
            user-select: none;
        }

        .dark .article-seo-links__sep {
            color: rgb(75 85 99);
        }

        .seo-article-preview-modal-root {
            max-height: min(70vh, 640px);
            overflow-y: auto;
        }
    </style>
@endonce
