import React, { useCallback } from 'react';
import { Sparkles } from 'lucide-react';
import { t } from '../utils/i18n';

/** FAB: open AI images & videos panel directly (Extract FAQ sống ở FAQ bar). */
export default function ArticleAiFloatingLauncher() {
    const openAiImagesVideos = useCallback(() => {
        window.dispatchEvent(new CustomEvent('seo-article-ai-chat-open'));
    }, []);

    return (
        <div className="seo-ai-fab" aria-live="polite">
            <button
                type="button"
                className="seo-ai-fab__trigger"
                title={t('ai_images_videos')}
                aria-label={t('ai_images_videos')}
                onClick={openAiImagesVideos}
            >
                <Sparkles size={22} aria-hidden />
            </button>
        </div>
    );
}
