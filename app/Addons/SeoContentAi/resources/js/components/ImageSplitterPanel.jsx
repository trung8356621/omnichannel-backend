import React from 'react';
import ImageSplitterApp from './ImageSplitterApp';

export default function ImageSplitterPanel({
    siteId = null,
    articleId = null,
    seoMediaId = null,
    wpAttachmentId = null,
    slug = '',
    imageUrl = '',
    splitPayload = null,
    canDeleteOriginal = true,
    onSplitSaved = null,
}) {
    return (
        <div
            className="image-splitter-panel"
            role="tabpanel"
            id="media-editor-panel-splitter"
            aria-labelledby="media-editor-tab-splitter"
        >
            <ImageSplitterApp
                embedded
                siteId={siteId}
                articleId={articleId}
                seoMediaId={seoMediaId}
                wpAttachmentId={wpAttachmentId}
                slug={slug}
                fallbackImageUrl={imageUrl}
                splitPayload={splitPayload}
                canDeleteOriginal={canDeleteOriginal}
                onSplitSaved={onSplitSaved}
            />
        </div>
    );
}
