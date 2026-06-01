@php
    $wpAttachmentId = (int) ($image['wp_attachment_id'] ?? ($mediaPickerTab === 'original' ? ($image['id'] ?? 0) : 0));
    $seoMediaId = (int) ($image['seo_media_id'] ?? ($mediaPickerTab === 'local' ? ($image['id'] ?? 0) : 0));
    $pickerKey = $mediaPickerTab . '-' . ($seoMediaId > 0 ? 'seo-' . $seoMediaId : 'wp-' . $wpAttachmentId) . '-' . md5((string) ($image['url'] ?? ''));
    $isGalleryMode = $mediaPickerMode === 'gallery';
@endphp

@if ($isGalleryMode)
    <button
        type="button"
        class="seo-article-media-modal__item"
        wire:key="picker-media-{{ $mediaPickerTab }}-{{ $mediaPickerPage }}-{{ $pickerKey }}"
        data-picker-key="{{ $pickerKey }}"
        data-picker-wp="{{ $wpAttachmentId }}"
        data-picker-seo="{{ $seoMediaId }}"
        data-picker-url="{{ $image['url'] ?? '' }}"
        data-picker-alt="{{ $image['alt'] ?? '' }}"
        data-picker-slug="{{ $image['slug'] ?? '' }}"
        x-bind:class="{ 'is-selected': isGalleryPickerSelected(@js($pickerKey)) }"
        x-on:click="toggleGalleryPickerItem($event, @js($pickerKey), $el)"
    >
        <img
            src="{{ $image['url'] }}"
            alt="{{ $image['alt'] ?? $image['slug'] }}"
            loading="lazy"
            class="seo-article-media-modal__thumb"
        />
        @if (filled($image['slug'] ?? ''))
            <span class="seo-article-media-modal__slug">{{ $image['slug'] }}</span>
        @endif
    </button>
@else
    <button
        type="button"
        class="seo-article-media-modal__item"
        wire:key="picker-media-{{ $mediaPickerTab }}-{{ $mediaPickerPage }}-{{ $pickerKey }}"
        data-picker-key="{{ $pickerKey }}"
        data-picker-wp="{{ $wpAttachmentId }}"
        data-picker-seo="{{ $seoMediaId }}"
        data-picker-url="{{ $image['url'] ?? '' }}"
        data-picker-alt="{{ $image['alt'] ?? '' }}"
        data-picker-slug="{{ $image['slug'] ?? '' }}"
        wire:click="selectMediaFromPicker({{ $wpAttachmentId }}, @js($image['url'] ?? ''), @js($image['alt'] ?? ''), @js($image['slug'] ?? ''), {{ $seoMediaId }})"
    >
        <img
            src="{{ $image['url'] }}"
            alt="{{ $image['alt'] ?? $image['slug'] }}"
            loading="lazy"
            class="seo-article-media-modal__thumb"
        />
        @if (filled($image['slug'] ?? ''))
            <span class="seo-article-media-modal__slug">{{ $image['slug'] }}</span>
        @endif
    </button>
@endif
