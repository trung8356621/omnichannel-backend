import '../css/seo-article-media-modal.css';
import {
    readArticleMediaPickerCache,
    writeArticleMediaPickerCache,
    isArticleMediaPickerCacheableTab,
} from './utils/articleMediaPickerCache';
import {
    isCustomPickerTab,
    customTabIdFromPickerTab,
    pickerTabFromCustomId,
    loadCustomPickerTabs,
    addCustomPickerTab,
    removeCustomPickerTab,
    loadStagedPickerImages,
    stagePickerImageToTab,
    countStagedPickerImages,
    readCustomTabFetchCache,
    writeCustomTabFetchCache,
} from './utils/articleMediaPickerCustomTabs';
import { createSeoWorkspaceMediaPicker } from './utils/seoWorkspaceMediaPicker';

window.__seoArticleMediaPickerCache = {
    read: readArticleMediaPickerCache,
    write: writeArticleMediaPickerCache,
    isCacheableTab: isArticleMediaPickerCacheableTab,
};

window.__seoArticleMediaPickerCustomTabs = {
    isCustomTab: isCustomPickerTab,
    customTabIdFromPickerTab,
    pickerTabFromCustomId,
    loadTabs: loadCustomPickerTabs,
    addTab: addCustomPickerTab,
    removeTab: removeCustomPickerTab,
    loadStagedImages: loadStagedPickerImages,
    stageImage: stagePickerImageToTab,
    countStagedImages: countStagedPickerImages,
    readFetchCache: readCustomTabFetchCache,
    writeFetchCache: writeCustomTabFetchCache,
};

function registerSeoWorkspaceMediaPicker() {
    if (!window.Alpine?.data) {
        return;
    }

    window.Alpine.data('seoWorkspaceMediaPicker', (config = {}) => createSeoWorkspaceMediaPicker(config));
}

document.addEventListener('alpine:init', registerSeoWorkspaceMediaPicker);
registerSeoWorkspaceMediaPicker();
