import React, { useEffect, useMemo, useState } from 'react';
import {
    ChevronDown,
    Plus,
} from 'lucide-react';
import { t } from '../utils/i18n';
import {
    ctaDisplayLabel,
    formatCtaHref,
    isCtaItemInsertable,
    isCtaPlainTextType,
} from '../utils/ctaLinkFormat';
import { filterUsableCtaContacts } from '../utils/ctaContactUsability';
import { getInsertionContextForCommand } from '../utils/editorInsertionContext';
import {
    getDefaultCtaQuickTemplate,
    loadCtaQuickTemplatesFromStorage,
    normalizeCtaQuickTemplateSettings,
    resolveCtaQuickTemplate,
    saveCtaQuickTemplatesToStorage,
    validateCtaQuickTemplate,
} from '../utils/ctaQuickTemplates';

/**
 * Capture caret BEFORE dropdown/button steals focus. Must run on pointerdown
 * (not click). preventDefault keeps TipTap focused when possible.
 *
 * @param {React.PointerEvent|React.MouseEvent} event
 */
function captureCtaInsertionBeforeFocusSteal(event) {
    event.preventDefault();
    window.dispatchEvent(new CustomEvent('seo-assistant-freeze-insertion-context'));
}

/**
 * Menu items: keep editor from taking focus, but do NOT re-freeze (bookmark
 * already captured on CTA trigger). Re-freeze after blur overwrites caret.
 *
 * @param {React.PointerEvent|React.MouseEvent} event
 */
function preserveCtaFocusWithoutRefreeze(event) {
    event.preventDefault();
}

/**
 * @param {{
 *   items: unknown[],
 *   activeKey: string,
 *   onKeywordClick: Function,
 *   onInsertValue: Function,
 *   onInsertQuickCta: Function,
 *   templatesByType: Record<string, { defaultIndex: number, templates: string[] }>,
 *   emptyText: string,
 * }} props
 */
export function CtaContactInsertList({
    items,
    activeKey,
    onKeywordClick,
    onInsertValue,
    onInsertQuickCta,
    templatesByType,
    emptyText,
}) {
    const usable = useMemo(() => filterUsableCtaContacts(items), [items]);
    const [menuKey, setMenuKey] = useState('');

    useEffect(() => {
        if (!menuKey) {
            return undefined;
        }

        const onDoc = (event) => {
            if (event.target?.closest?.('[data-cta-quick-menu]')) {
                return;
            }
            setMenuKey('');
        };
        document.addEventListener('mousedown', onDoc);
        return () => document.removeEventListener('mousedown', onDoc);
    }, [menuKey]);

    if (!usable.length) {
        return <p className="wp-article-links-empty">{emptyText}</p>;
    }

    return (
        <ul className="wp-article-links-keywords">
            {usable.map((item, index) => {
                const label = ctaDisplayLabel(item);
                const type = String(item?.type ?? '').toLowerCase();
                const itemKey = `cta-${type}-${label}-${index}`;
                const isActive = activeKey === itemKey;
                const insertable = isCtaItemInsertable(item);
                const templates =
                    templatesByType?.[type]?.templates
                    ?? templatesByType?.[type === 'hotline' ? 'phone' : type]?.templates
                    ?? [];

                return (
                    <li key={itemKey} className="wp-article-links-keyword-row wp-article-links-keyword-row--cta">
                        <button
                            type="button"
                            className={`wp-article-links-keyword${isActive ? ' is-active' : ''} is-suggestion`}
                            title={t('cta_widget_find', { label, type })}
                            onPointerDown={captureCtaInsertionBeforeFocusSteal}
                            onMouseDown={captureCtaInsertionBeforeFocusSteal}
                            onClick={() => onKeywordClick(item, index, itemKey)}
                        >
                            <span className="wp-article-domain-cta-stack">
                                <span className="wp-article-domain-cta-type-line">{type || 'cta'}</span>
                                <span className="wp-article-domain-cta-value">{label}</span>
                            </span>
                        </button>
                        <div className="wp-article-links-cta-actions" data-cta-quick-menu>
                            <button
                                type="button"
                                className="wp-article-links-insert-btn wp-article-links-insert-btn--text"
                                aria-label={t('cta_widget_insert_value')}
                                title={t('cta_widget_insert_value')}
                                disabled={!insertable}
                                onPointerDown={captureCtaInsertionBeforeFocusSteal}
                                onMouseDown={captureCtaInsertionBeforeFocusSteal}
                                onClick={(e) => {
                                    e.stopPropagation();
                                    if (insertable) {
                                        onInsertValue(item, itemKey);
                                    }
                                }}
                            >
                                {t('cta_widget_insert_value_short')}
                            </button>
                            <div className="wp-article-links-cta-quick-wrap">
                                <button
                                    type="button"
                                    className="wp-article-links-insert-btn wp-article-links-insert-btn--text"
                                    aria-label={t('cta_widget_insert_sentence')}
                                    title={t('cta_widget_insert_sentence')}
                                    disabled={!insertable}
                                    onPointerDown={captureCtaInsertionBeforeFocusSteal}
                                    onMouseDown={captureCtaInsertionBeforeFocusSteal}
                                    onClick={(e) => {
                                        e.stopPropagation();
                                        if (templates.length > 1) {
                                            setMenuKey((prev) => (prev === itemKey ? '' : itemKey));
                                            return;
                                        }
                                        if (insertable) {
                                            onInsertQuickCta(item, itemKey, null);
                                        }
                                    }}
                                >
                                    CTA
                                    {templates.length > 1 ? <ChevronDown size={12} aria-hidden /> : null}
                                </button>
                                {menuKey === itemKey ? (
                                    <ul className="wp-article-links-cta-template-menu">
                                        <li>
                                            <button
                                                type="button"
                                                onPointerDown={preserveCtaFocusWithoutRefreeze}
                                                onMouseDown={preserveCtaFocusWithoutRefreeze}
                                                onClick={(e) => {
                                                    e.stopPropagation();
                                                    setMenuKey('');
                                                    if (insertable) {
                                                        onInsertQuickCta(item, itemKey, null);
                                                    }
                                                }}
                                            >
                                                {t('cta_widget_insert_sentence')}
                                            </button>
                                        </li>
                                        {templates.map((template) => (
                                            <li key={template}>
                                                <button
                                                    type="button"
                                                    onPointerDown={preserveCtaFocusWithoutRefreeze}
                                                    onMouseDown={preserveCtaFocusWithoutRefreeze}
                                                    onClick={(e) => {
                                                        e.stopPropagation();
                                                        setMenuKey('');
                                                        onInsertQuickCta(item, itemKey, template);
                                                    }}
                                                >
                                                    {resolveCtaQuickTemplate(template, item)}
                                                </button>
                                            </li>
                                        ))}
                                    </ul>
                                ) : null}
                            </div>
                        </div>
                    </li>
                );
            })}
        </ul>
    );
}

/**
 * @param {{
 *   siteId?: number|null,
 *   open: boolean,
 *   onClose: () => void,
 *   settings: Record<string, { defaultIndex: number, templates: string[] }>,
 *   onSave: (next: Record<string, { defaultIndex: number, templates: string[] }>) => void,
 * }} props
 */
export function CtaQuickTemplateSettingsPopover({ siteId = 0, open, onClose, settings, onSave }) {
    const [draft, setDraft] = useState(() => normalizeCtaQuickTemplateSettings(settings));
    const [error, setError] = useState('');
    const [activeType, setActiveType] = useState('hotline');
    const [newTemplate, setNewTemplate] = useState('');

    useEffect(() => {
        if (open) {
            setDraft(normalizeCtaQuickTemplateSettings(settings));
            setError('');
            setNewTemplate('');
        }
    }, [open, settings]);

    if (!open) {
        return null;
    }

    const row = draft[activeType] ?? { defaultIndex: 0, templates: [] };

    return (
        <div className="wp-article-cta-settings" role="dialog" aria-label={t('cta_widget_settings_title')}>
            <div className="wp-article-cta-settings__header">
                <strong>{t('cta_widget_settings_title')}</strong>
                <button type="button" className="wp-article-cta-settings__close" onClick={onClose}>
                    ×
                </button>
            </div>
            <div className="wp-article-cta-settings__types">
                {Object.keys(draft).map((type) => (
                    <button
                        key={type}
                        type="button"
                        className={type === activeType ? 'is-active' : ''}
                        onClick={() => setActiveType(type)}
                    >
                        {type}
                    </button>
                ))}
            </div>
            <ul className="wp-article-cta-settings__list">
                {row.templates.map((template, index) => (
                    <li key={`${activeType}-${index}`}>
                        <label>
                            <input
                                type="radio"
                                name={`cta-default-${activeType}`}
                                checked={row.defaultIndex === index}
                                onChange={() => {
                                    setDraft((prev) => ({
                                        ...prev,
                                        [activeType]: { ...prev[activeType], defaultIndex: index },
                                    }));
                                }}
                            />
                            <span>{template}</span>
                        </label>
                        <button
                            type="button"
                            title={t('cta_widget_delete_template')}
                            onClick={() => {
                                setDraft((prev) => {
                                    const templates = prev[activeType].templates.filter((_, i) => i !== index);
                                    return {
                                        ...prev,
                                        [activeType]: {
                                            templates,
                                            defaultIndex: Math.max(
                                                0,
                                                Math.min(prev[activeType].defaultIndex, templates.length - 1),
                                            ),
                                        },
                                    };
                                });
                            }}
                        >
                            ×
                        </button>
                    </li>
                ))}
            </ul>
            <div className="wp-article-cta-settings__add">
                <input
                    type="text"
                    value={newTemplate}
                    onChange={(e) => setNewTemplate(e.target.value)}
                    placeholder={t('cta_widget_template_placeholder', { type: activeType })}
                />
                <button
                    type="button"
                    onClick={() => {
                        const validation = validateCtaQuickTemplate(newTemplate, activeType);
                        if (!validation.ok) {
                            setError(validation.error);
                            return;
                        }
                        setError('');
                        setDraft((prev) => ({
                            ...prev,
                            [activeType]: {
                                ...prev[activeType],
                                templates: [...prev[activeType].templates, newTemplate.trim()],
                            },
                        }));
                        setNewTemplate('');
                    }}
                >
                    <Plus size={14} />
                </button>
            </div>
            {error ? <p className="wp-article-cta-settings__error">{error}</p> : null}
            <div className="wp-article-cta-settings__footer">
                <button type="button" onClick={onClose}>
                    {t('cancel')}
                </button>
                <button
                    type="button"
                    className="is-primary"
                    onClick={() => {
                        const saved = saveCtaQuickTemplatesToStorage(siteId, draft);
                        onSave(saved);
                        onClose();
                    }}
                >
                    {t('apply')}
                </button>
            </div>
        </div>
    );
}

/**
 * Dispatch CTA insert using current EditorInsertionContext.
 *
 * @param {{ type?: string, value?: string, label?: string, href?: string, plain_text?: boolean }} item
 * @param {'value'|'sentence'} mode
 * @param {string|null} templateOverride
 * @param {Record<string, { defaultIndex: number, templates: string[] }>} templatesByType
 */
export function dispatchCtaInsert(item, mode, templateOverride, templatesByType) {
    const type = String(item?.type ?? '').toLowerCase();
    if (!type) {
        return;
    }

    // Prefer bookmark frozen on pointerdown (before dropdown stole focus).
    const ctx = getInsertionContextForCommand();
    const target = {
        sectionId: ctx.activeSectionId,
        blockId: ctx.activeBlockId,
        selectionBookmark: ctx.selection,
    };

    if (mode === 'sentence') {
        const template =
            String(templateOverride ?? '').trim()
            || getDefaultCtaQuickTemplate(type, templatesByType)
            || getDefaultCtaQuickTemplate(type === 'hotline' ? 'phone' : type, templatesByType);
        const resolved = resolveCtaQuickTemplate(template, item);
        if (!resolved || resolved.includes('[') && /\[(phone|email|zalo|address|facebook|working_hours|website|label)\]/i.test(resolved)) {
            window.dispatchEvent(
                new CustomEvent('seo-article-editor-notify', {
                    detail: {
                        title: t('cta_widget_missing_data_title'),
                        body: t('cta_widget_missing_data_body', { type }),
                        status: 'warning',
                    },
                }),
            );
            return;
        }

        const plainText = isCtaPlainTextType(type) || item?.plain_text === true;
        const href = plainText ? '' : String(item?.href ?? formatCtaHref(type, item?.value)).trim();
        const valueLabel = ctaDisplayLabel(item);
        const stillHasPlaceholder = /\[[^\]]+\]/u.test(resolved);
        if (!resolved || stillHasPlaceholder) {
            window.dispatchEvent(
                new CustomEvent('seo-article-editor-notify', {
                    detail: {
                        title: t('cta_widget_missing_data_title'),
                        body: t('cta_widget_missing_data_body', { type }),
                        status: 'warning',
                    },
                }),
            );
            return;
        }

        window.dispatchEvent(
            new CustomEvent('seo-editor-insert-cta-link', {
                detail: {
                    text: resolved,
                    href: plainText ? '' : href,
                    type,
                    value_label: valueLabel,
                    sentence: resolved,
                    is_sentence: true,
                    is_cta_sentence: true,
                    is_cta_block: false,
                    target,
                },
            }),
        );
        return;
    }

    const text = ctaDisplayLabel(item);
    const plainText = isCtaPlainTextType(type) || item?.plain_text === true;
    const href = plainText ? '' : String(item?.href ?? formatCtaHref(type, item?.value)).trim();
    if (!text || (!href && !plainText)) {
        window.dispatchEvent(
            new CustomEvent('seo-article-editor-notify', {
                detail: {
                    title: t('cta_widget_missing_data_title'),
                    body: t('cta_widget_missing_data_body', { type }),
                    status: 'warning',
                },
            }),
        );
        return;
    }

    window.dispatchEvent(
        new CustomEvent('seo-editor-insert-cta-link', {
            detail: {
                text,
                href,
                type,
                target,
                is_cta_block: false,
                is_sentence: false,
                is_contact_value: true,
            },
        }),
    );
}

/**
 * @param {number|string|null|undefined} siteId
 * @param {unknown} serverTemplates
 */
export function useCtaQuickTemplates(siteId, serverTemplates = null) {
    const [templatesByType, setTemplatesByType] = useState(() => {
        const local = loadCtaQuickTemplatesFromStorage(siteId);
        if (serverTemplates && typeof serverTemplates === 'object') {
            return normalizeCtaQuickTemplateSettings({ ...local, ...normalizeServerTemplates(serverTemplates) });
        }
        return local;
    });

    useEffect(() => {
        if (serverTemplates && typeof serverTemplates === 'object') {
            setTemplatesByType((prev) =>
                normalizeCtaQuickTemplateSettings({
                    ...prev,
                    ...normalizeServerTemplates(serverTemplates),
                }),
            );
        }
    }, [serverTemplates]);

    return [templatesByType, setTemplatesByType];
}

function normalizeServerTemplates(serverTemplates) {
    /** @type {Record<string, { defaultIndex: number, templates: string[] }>} */
    const next = {};
    for (const [type, row] of Object.entries(serverTemplates || {})) {
        if (!row || typeof row !== 'object') {
            continue;
        }
        next[type] = {
            defaultIndex: Number(row.default_index ?? row.defaultIndex ?? 0) || 0,
            templates: Array.isArray(row.templates) ? row.templates.map((v) => String(v)) : [],
        };
    }
    return next;
}
