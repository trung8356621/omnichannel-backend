/**
 * Built-in modules — composition root import only.
 * Side-effect: registers into builtinModulesRegistry for the runtime singleton.
 * Publishing stays Laravel/Alpine shell (not an editor document module).
 */

import { setBuiltinArticleEditorModules } from '../runtime/builtinModulesRegistry';
import { coreModule } from './core';
import { articleMetaModule } from './article-meta';
import { seoModule } from './seo';
import { mediaModule } from './media';
import { featuredModule } from './featured';
import { galleryModule } from './gallery';
import { linksModule } from './links';
import { faqModule } from './faq';
import { ctaContactModule } from './cta-contact';
import { aiModule } from './ai';

/** @type {ReadonlyArray<object>} */
export const BUILTIN_ARTICLE_EDITOR_MODULES = Object.freeze([
    coreModule,
    articleMetaModule,
    seoModule,
    mediaModule,
    featuredModule,
    galleryModule,
    linksModule,
    faqModule,
    ctaContactModule,
    aiModule,
]);

setBuiltinArticleEditorModules(BUILTIN_ARTICLE_EDITOR_MODULES);

export {
    coreModule,
    articleMetaModule,
    seoModule,
    mediaModule,
    featuredModule,
    galleryModule,
    linksModule,
    faqModule,
    ctaContactModule,
    aiModule,
};
