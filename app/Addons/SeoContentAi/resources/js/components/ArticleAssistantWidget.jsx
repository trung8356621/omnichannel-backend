import React, { useState } from 'react';
import { ChevronDown, ChevronRight } from 'lucide-react';

/**
 * @param {{
 *   title: string,
 *   icon?: React.ComponentType<{ size?: number, className?: string, 'aria-hidden'?: boolean }>,
 *   badge?: string|number|null,
 *   defaultCollapsed?: boolean,
 *   className?: string,
 *   children: React.ReactNode,
 * }} props
 */
export default function ArticleAssistantWidget({
    title,
    icon: Icon,
    badge = null,
    defaultCollapsed = false,
    className = '',
    children,
}) {
    const [collapsed, setCollapsed] = useState(defaultCollapsed);

    return (
        <section className={`seo-assistant-widget ${className}`.trim()}>
            <header className="seo-assistant-widget__header">
                <button
                    type="button"
                    className="seo-assistant-widget__toggle"
                    aria-expanded={!collapsed}
                    onClick={() => setCollapsed((value) => !value)}
                >
                    {collapsed ? (
                        <ChevronRight size={16} aria-hidden />
                    ) : (
                        <ChevronDown size={16} aria-hidden />
                    )}
                    {Icon ? <Icon size={18} className="seo-assistant-widget__icon" aria-hidden /> : null}
                    <span className="seo-assistant-widget__title">{title}</span>
                    {badge != null && badge !== '' ? (
                        <span className="seo-assistant-widget__badge">{badge}</span>
                    ) : null}
                </button>
            </header>
            {!collapsed ? <div className="seo-assistant-widget__body">{children}</div> : null}
        </section>
    );
}
