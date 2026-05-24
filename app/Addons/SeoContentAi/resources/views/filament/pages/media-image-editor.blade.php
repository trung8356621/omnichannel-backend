@viteReactRefresh
@vite('app/Addons/SeoContentAi/resources/js/media-image-editor-page.jsx')

<div
    id="seo-media-image-editor-root"
    data-image-url="{{ $imageUrl }}"
    data-image-id="{{ $imageId }}"
    data-wp-attachment-id="{{ $wpAttachmentId }}"
    data-pending-wp-sync="{{ $pendingWpSync ? '1' : '0' }}"
    data-library-url="{{ \App\Addons\SeoContentAi\Filament\Pages\MediaLibrary::getUrl() }}"
></div>
