import '../css/seo-article-media-modal.css';
import {
    readArticleMediaPickerCache,
    writeArticleMediaPickerCache,
    isArticleMediaPickerCacheableTab,
} from './utils/articleMediaPickerCache';
import { createSeoWorkspaceMediaPicker } from './utils/seoWorkspaceMediaPicker';

window.__seoArticleMediaPickerCache = {
    read: readArticleMediaPickerCache,
    write: writeArticleMediaPickerCache,
    isCacheableTab: isArticleMediaPickerCacheableTab,
};

function registerSeoWorkspaceMediaPicker() {
    if (!window.Alpine?.data) {
        return;
    }

    window.Alpine.data('seoWorkspaceMediaPicker', (config = {}) => createSeoWorkspaceMediaPicker(config));
}

document.addEventListener('alpine:init', registerSeoWorkspaceMediaPicker);
registerSeoWorkspaceMediaPicker();
