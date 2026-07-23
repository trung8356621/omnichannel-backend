/**
 * Assistant Dock — always visible, CSS sticky on scroll, DOM-registered tabs.
 * Alpine-only; no Livewire round-trips. No scroll-to-widget.
 */

const REACT_WIDGET_IDS = new Set(['seo', 'images', 'links', 'reviews']);

function normalizeSearchText(value) {
    return String(value ?? '').trim().toLowerCase();
}

function parseKeywords(raw) {
    return String(raw ?? '')
        .split(',')
        .map((part) => part.trim().toLowerCase())
        .filter(Boolean);
}

function dispatchWidgetControl(widgetId, detail) {
    if (!widgetId || !REACT_WIDGET_IDS.has(widgetId)) {
        return;
    }

    window.dispatchEvent(
        new CustomEvent('seo-assistant-widget-control', {
            detail: { widgetId, ...detail },
        }),
    );
}

function expandPanelWidgets(widgetId) {
    if (widgetId === 'images' || widgetId === 'featured' || widgetId === 'product-album') {
        dispatchWidgetControl('images', { action: 'set-collapsed', collapsed: false });
        return;
    }

    if (REACT_WIDGET_IDS.has(widgetId)) {
        dispatchWidgetControl(widgetId, { action: 'set-collapsed', collapsed: false });
    }
}

function buildSearchCatalog(chips) {
    const items = [];

    chips.forEach((chip) => {
        items.push({
            label: chip.fullLabel,
            panelId: chip.id,
            keywords: [chip.id, chip.label, chip.fullLabel, ...(chip.keywords ?? [])].map(normalizeSearchText),
        });

        (chip.keywords ?? []).forEach((keyword) => {
            items.push({
                label: `${chip.fullLabel} — ${keyword}`,
                panelId: chip.id,
                keywords: [keyword],
            });
        });
    });

    return items;
}

function matchesSearch(item, query) {
    const q = normalizeSearchText(query);
    if (q === '') {
        return true;
    }

    if (normalizeSearchText(item.label).includes(q)) {
        return true;
    }

    return (item.keywords ?? []).some((keyword) => keyword.includes(q) || q.includes(keyword));
}

export function createSeoAssistantNavigator() {
    return {
        chips: [],
        searchCatalog: [],
        searchQuery: '',
        searchOpen: false,
        searchHighlightIndex: 0,
        activePanel: '',
        // Exclusive accordion from first paint — never stack every assistant panel.
        panelFilterActive: true,
        badges: {},

        get filteredSearchResults() {
            const query = this.searchQuery;
            if (normalizeSearchText(query) === '') {
                return [];
            }

            const chipIds = new Set(this.chips.map((chip) => chip.id));

            return this.searchCatalog
                .filter((item) => chipIds.has(item.panelId) && matchesSearch(item, query))
                .slice(0, 14);
        },

        initWorkspace() {
            const onDestroy = () => {
                this.destroyWorkspace();
            };
            this.$el.addEventListener('alpine:destroying', onDestroy, { once: true });

            this.$nextTick(() => {
                this.discoverWidgets();
            });

            this._onBadgeUpdate = (event) => {
                const detail = event?.detail ?? {};
                Object.keys(detail).forEach((key) => {
                    if (detail[key] !== undefined) {
                        this.badges[key] = detail[key];
                    }
                });
            };

            this._onSwitchPanel = (event) => {
                const panelId = event?.detail?.panel ?? event?.detail?.widgetId;
                if (panelId) {
                    this.switchPanel(panelId);
                }
            };

            this._onOpenPublishing = () => {
                this.switchPanel('publishing');
                window.dispatchEvent(new CustomEvent('seo-assistant-open-publishing'));
            };

            window.addEventListener('seo-assistant-navigator-badges', this._onBadgeUpdate);
            window.addEventListener('seo-assistant-switch-panel', this._onSwitchPanel);
            window.addEventListener('seo-sidebar-open-publish-tab', this._onOpenPublishing);
        },

        destroyWorkspace() {
            if (this._onBadgeUpdate) {
                window.removeEventListener('seo-assistant-navigator-badges', this._onBadgeUpdate);
            }

            if (this._onSwitchPanel) {
                window.removeEventListener('seo-assistant-switch-panel', this._onSwitchPanel);
            }

            if (this._onOpenPublishing) {
                window.removeEventListener('seo-sidebar-open-publish-tab', this._onOpenPublishing);
            }

            this._badgeRefreshRaf && cancelAnimationFrame(this._badgeRefreshRaf);
            this._badgeRefreshRaf = null;
            this._badgeObserver?.disconnect();
            this._badgeObserver = null;
        },

        discoverWidgets() {
            const host = this.$el;
            if (!host) {
                return;
            }

            const slots = Array.from(host.querySelectorAll('[data-assistant-widget]')).filter(
                (element) => element.dataset.assistantWidgetId && !element.dataset.assistantRegisterOnly,
            );

            const chips = slots.map((element, index) => ({
                id: element.dataset.assistantWidgetId,
                label: element.dataset.assistantTabLabel || element.dataset.assistantWidgetId,
                fullLabel:
                    element.dataset.assistantWidgetLabel
                    || element.dataset.assistantTabLabel
                    || element.dataset.assistantWidgetId,
                keywords: parseKeywords(element.dataset.assistantSearchKeywords),
                panelSlot: true,
                linkSection: null,
                order: index * 10,
                element,
            }));

            const linksIndex = chips.findIndex((chip) => chip.id === 'links');
            if (linksIndex >= 0) {
                // FAQ mở từ shortcode block — không còn chip FAQ trên assistant dock.
                chips.splice(linksIndex + 1, 0, {
                    id: 'cta',
                    label: 'CTA',
                    fullLabel: 'CTA Assistant',
                    keywords: ['cta', 'call', 'phone'],
                    panelSlot: false,
                    linkSection: 'cta',
                    order: linksIndex * 10 + 6,
                    element: chips[linksIndex].element,
                });
            }

            this.chips = chips;
            this.searchCatalog = buildSearchCatalog(chips);
            const defaultId = chips[0]?.id ?? '';
            this.panelFilterActive = true;
            this.activePanel = defaultId;
            if (defaultId) {
                // Sync React mount gate with Alpine exclusive default (seo / first chip).
                window.dispatchEvent(
                    new CustomEvent('seo-assistant-switch-panel', {
                        detail: { panel: defaultId, source: 'discover' },
                    }),
                );
            }
        },

        isWidgetVisible(widgetId) {
            if (!this.panelFilterActive) {
                return true;
            }

            if (!this.activePanel) {
                return false;
            }

            if (this.activePanel === widgetId) {
                return true;
            }

            if (widgetId === 'links') {
                const activeChip = this.chips.find((chip) => chip.id === this.activePanel);
                return Boolean(activeChip?.linkSection);
            }

            return false;
        },

        setupBadgeObserver() {
            // Debounced childList only — never observe characterData (React SEO updates freeze the page).
            const workspace = this.$root;
            if (!workspace || typeof MutationObserver === 'undefined') {
                return;
            }

            this._badgeObserver = new MutationObserver(() => {
                if (this._badgeRefreshRaf) {
                    return;
                }

                this._badgeRefreshRaf = requestAnimationFrame(() => {
                    this._badgeRefreshRaf = null;
                    this.refreshBadgesFromDom();
                });
            });

            this._badgeObserver.observe(workspace, {
                childList: true,
                subtree: true,
            });
        },

        switchPanel(panelId) {
            const chip = this.chips.find((entry) => entry.id === panelId);
            if (!chip) {
                return;
            }

            this.panelFilterActive = true;
            this.activePanel = panelId;

            if (chip.linkSection) {
                window.dispatchEvent(
                    new CustomEvent('seo-assistant-link-section', {
                        detail: { section: chip.linkSection },
                    }),
                );
                expandPanelWidgets('links');
            } else if (panelId === 'links') {
                window.dispatchEvent(
                    new CustomEvent('seo-assistant-link-section', {
                        detail: { section: 'links' },
                    }),
                );
                expandPanelWidgets('links');
            } else {
                window.dispatchEvent(
                    new CustomEvent('seo-assistant-link-section', {
                        detail: { section: 'all' },
                    }),
                );
                expandPanelWidgets(panelId);
            }

            if (panelId === 'publishing') {
                window.dispatchEvent(new CustomEvent('seo-assistant-open-publishing'));
            }

            this.closeSearch();
        },

        selectChip(panelId) {
            // Exclusive accordion toggle: same chip closes all panels.
            if (this.panelFilterActive && this.activePanel === panelId) {
                this.activePanel = '';
                this.panelFilterActive = true;
                window.dispatchEvent(
                    new CustomEvent('seo-assistant-switch-panel', {
                        detail: { panel: null, closed: true },
                    }),
                );

                return;
            }

            // Dispatch trước — _onSwitchPanel (listener chính nó) sẽ gọi switchPanel().
            // Nhờ vậy React (SeoArticleEditor) cũng biết panel nào vừa được người dùng mở
            // (mount lazy Images/Reviews/Links — Phase 1 perf).
            window.dispatchEvent(
                new CustomEvent('seo-assistant-switch-panel', {
                    detail: { panel: panelId },
                }),
            );
        },

        onSearchInput() {
            this.searchOpen = normalizeSearchText(this.searchQuery) !== '';
            this.searchHighlightIndex = 0;
        },

        openSearch() {
            if (normalizeSearchText(this.searchQuery) !== '') {
                this.searchOpen = true;
            }
        },

        closeSearch() {
            this.searchOpen = false;
            this.searchHighlightIndex = 0;
        },

        selectSearchResult(index) {
            const item = this.filteredSearchResults[index];
            if (!item) {
                return;
            }

            // Force-open via selectChip path so React receives the same switch event.
            if (this.panelFilterActive && this.activePanel === item.panelId) {
                this.closeSearch();
                this.searchQuery = '';

                return;
            }

            this.selectChip(item.panelId);
            this.searchQuery = '';
            this.closeSearch();
        },

        onSearchKeydown(event) {
            const results = this.filteredSearchResults;

            if (event.key === 'Escape') {
                event.preventDefault();
                this.searchQuery = '';
                this.closeSearch();
                event.target.blur();
                return;
            }

            if (!this.searchOpen || results.length === 0) {
                return;
            }

            if (event.key === 'ArrowDown') {
                event.preventDefault();
                this.searchHighlightIndex = (this.searchHighlightIndex + 1) % results.length;
            } else if (event.key === 'ArrowUp') {
                event.preventDefault();
                this.searchHighlightIndex = (this.searchHighlightIndex - 1 + results.length) % results.length;
            } else if (event.key === 'Enter') {
                event.preventDefault();
                this.selectSearchResult(this.searchHighlightIndex);
            }
        },

        chipBadge(chipId) {
            const value = this.badges[chipId];
            if (value === null || value === undefined || value === '') {
                return chipId === 'reviews' ? 0 : null;
            }

            const numeric = Number(value);
            if (!Number.isNaN(numeric) && numeric === 0) {
                return chipId === 'reviews' ? 0 : null;
            }

            return value;
        },

        refreshBadgesFromDom() {
            if (!this.chips.length) {
                return;
            }

            this.chips.forEach((chip) => {
                if (!chip.panelSlot || !chip.element) {
                    return;
                }

                const badge = chip.element.querySelector('.seo-assistant-widget__badge');
                if (badge?.textContent?.trim()) {
                    const next = badge.textContent.trim();
                    const numeric = Number(next);
                    if (!Number.isNaN(numeric) && numeric === 0) {
                        this.badges[chip.id] = chip.id === 'reviews' ? 0 : null;
                    } else {
                        this.badges[chip.id] = next;
                    }
                }

                if (chip.id === 'seo') {
                    const failed = chip.element.querySelectorAll('.seo-assistant-score__issues li').length;
                    if (failed > 0) {
                        this.badges.seo = failed;
                    }
                }
            });

            const ctaBadge = document.querySelector('[data-assistant-link-section="cta"] .seo-assistant-widget__badge');
            if (ctaBadge?.textContent?.trim()) {
                const next = Number(ctaBadge.textContent.trim());
                this.badges.cta = Number.isFinite(next) && next > 0 ? next : null;
            }
            // FAQ badge removed — FAQ opens from shortcode, not assistant dock.
            if (this.badges.faq !== undefined) {
                delete this.badges.faq;
            }
        },
    };
}

function registerSeoAssistantNavigator() {
    if (window.__seoAssistantNavigatorRegistered) {
        return;
    }

    window.__seoAssistantNavigatorRegistered = true;

    if (!window.Alpine?.data) {
        return;
    }

    window.Alpine.data('seoAssistantNavigator', createSeoAssistantNavigator);
}

document.addEventListener('alpine:init', registerSeoAssistantNavigator);
registerSeoAssistantNavigator();
