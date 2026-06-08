import { clearArticleEditorStorage } from './articleEditorStorage';
import { clearArticleMediaPickerCache } from './articleMediaPickerCache';
import { clearProductAlbumStorage } from './articleProductAlbumStorage';

const BLOCK_HEIGHT_PREFIX = 'seo-block-editor-h:';

export function clearArticleLocalState(articleId, siteId) {
    clearArticleEditorStorage(articleId);
    clearProductAlbumStorage(articleId);
    clearArticleMediaPickerCache(siteId);

    const sessionKeys = [];
    for (let index = 0; index < sessionStorage.length; index += 1) {
        const key = sessionStorage.key(index);
        if (key?.startsWith(BLOCK_HEIGHT_PREFIX)) {
            sessionKeys.push(key);
        }
    }
    sessionKeys.forEach((key) => sessionStorage.removeItem(key));
}
