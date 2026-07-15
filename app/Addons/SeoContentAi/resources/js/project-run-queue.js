function registerSeoProjectRunQueue() {
    if (window.__seoProjectRunQueueRegistered) {
        return;
    }

    window.__seoProjectRunQueueRegistered = true;

    Alpine.store('seoRunQueue', {
        isRunning: false,
        stopRequested: false,
        currentTaskId: null,

        requestStop() {
            this.stopRequested = true;
        },

        reset() {
            this.isRunning = false;
            this.stopRequested = false;
            this.currentTaskId = null;
            document.body.classList.remove('seo-run-queue-active');
        },

        setRunning(isRunning) {
            this.isRunning = isRunning;
            document.body.classList.toggle('seo-run-queue-active', isRunning);
        },
    });

    Alpine.data('seoProjectRunQueue', (config = {}) => ({
        config,

        init() {
            const hasTaskIds = Array.isArray(this.config.taskIds) && this.config.taskIds.length > 0;
            const shouldRun =
                (this.config.autorun || this.config.runStatus === 'running')
                && hasTaskIds;

            if (!shouldRun) {
                if (
                    (this.config.autorun || this.config.runStatus === 'running')
                    && this.config.runStatus === 'running'
                    && !hasTaskIds
                ) {
                    this.$nextTick(() => {
                        queueMicrotask(() => this.completeEmptyQueue());
                    });
                }

                return;
            }

            // Tránh Alpine re-init (Livewire refresh) spawn queue thứ 2 chỉ còn vài task cuối.
            const store = Alpine.store('seoRunQueue');
            if (store?.isRunning) {
                return;
            }

            this.$nextTick(() => {
                queueMicrotask(() => this.processQueue());
            });
        },

        async completeEmptyQueue() {
            const wire = this.resolveWire();
            if (!wire) {
                return;
            }

            await wire.completeRunQueue(false);
            await wire.refresh();
        },

        resolveWire() {
            const livewireId = String(this.config?.livewireId ?? '').trim();
            if (livewireId !== '' && typeof window.Livewire?.find === 'function') {
                const component = window.Livewire.find(livewireId);
                if (component?.call) {
                    return {
                        runItemQueued: (taskId) => component.call('runItemQueued', taskId),
                        completeRunQueue: (stopped) => component.call('completeRunQueue', stopped),
                        refresh: async () => {
                            if (typeof component.$wire?.$refresh === 'function') {
                                await component.$wire.$refresh();

                                return;
                            }

                            if (typeof component.$refresh === 'function') {
                                await component.$refresh();
                            }
                        },
                        checkArticleEditorReady: (articleId) => component.call('checkArticleEditorReady', articleId),
                    };
                }
            }

            if (this.$wire?.runItemQueued) {
                return {
                    runItemQueued: (taskId) => this.$wire.runItemQueued(taskId),
                    completeRunQueue: (stopped) => this.$wire.completeRunQueue(stopped),
                    refresh: async () => {
                        if (typeof this.$wire.$refresh === 'function') {
                            await this.$wire.$refresh();
                        }
                    },
                    checkArticleEditorReady: (articleId) => this.$wire.checkArticleEditorReady(articleId),
                };
            }

            return null;
        },

        async processQueue() {
            const store = Alpine.store('seoRunQueue');
            const wire = this.resolveWire();

            if (store.isRunning || !wire) {
                return;
            }

            store.setRunning(true);
            store.stopRequested = false;

            let stopped = false;

            for (const taskId of this.config.taskIds) {
                if (store.stopRequested) {
                    stopped = true;
                    break;
                }

                store.currentTaskId = taskId;
                this.markRowRunning(taskId);

                const response = await wire.runItemQueued(taskId);

                if (response?.stats) {
                    this.updateStats(response.stats);
                }

                if (response?.success && response?.item) {
                    this.applyItemResult(taskId, response.item, response.displayError ?? '');
                } else if (!response?.success) {
                    this.applyItemFailure(taskId, response?.message ?? 'Không chạy được quy trình.');
                }

                if (store.stopRequested) {
                    stopped = true;
                    break;
                }
            }

            store.currentTaskId = null;
            await wire.completeRunQueue(stopped);
            store.reset();

            await wire.refresh();

            if (stopped) {
                return;
            }

            const url = new URL(window.location.href);
            if (url.searchParams.has('autorun')) {
                url.searchParams.delete('autorun');
                window.history.replaceState({}, '', url.toString());
            }
        },

        markRowRunning(taskId) {
            const row = this.findRow(taskId);
            if (!row) {
                return;
            }

            row.classList.remove('bg-warning-50/40', 'dark:bg-warning-500/5');
            row.classList.add('bg-primary-50/40', 'dark:bg-primary-500/5');

            const statusCell = row.querySelector('[data-run-status]');
            if (statusCell) {
                statusCell.innerHTML =
                    `<span class="inline-flex rounded-md bg-primary-50 px-2 py-0.5 text-xs font-medium text-primary-700 dark:bg-primary-500/10 dark:text-primary-400">${this.escapeHtml(this.config.labels?.running ?? 'Đang chạy…')}</span>`;
            }

            const messageCell = row.querySelector('[data-run-message]');
            if (messageCell) {
                messageCell.textContent = this.config.labels?.running ?? 'Đang chạy…';
            }
        },

        applyItemResult(taskId, item, displayError) {
            const row = this.findRow(taskId);
            if (!row) {
                return;
            }

            row.classList.remove('bg-primary-50/40', 'dark:bg-primary-500/5', 'bg-warning-50/40', 'dark:bg-warning-500/5');

            const status = String(item?.status ?? 'failed');
            const statusCell = row.querySelector('[data-run-status]');
            const messageCell = row.querySelector('[data-run-message]');
            const actionsCell = row.querySelector('[data-run-actions]');

            if (status === 'success') {
                row.dataset.runItemStatus = 'success';

                if (statusCell) {
                    statusCell.innerHTML =
                        '<span class="inline-flex rounded-md bg-success-50 px-2 py-0.5 text-xs font-medium text-success-700 dark:bg-success-500/10 dark:text-success-400">OK</span>';
                }

                if (messageCell) {
                    messageCell.textContent = String(item?.message ?? '');
                }

                const articleId = Number(item?.article_id ?? 0);
                const editorReady = item?.article_editor_ready !== false;
                if (articleId > 0 && !editorReady) {
                    this.pollArticleEditorReady(taskId, articleId, item);
                }
            } else {
                row.dataset.runItemStatus = 'failed';

                if (statusCell) {
                    statusCell.innerHTML =
                        `<span class="inline-flex rounded-md bg-danger-50 px-2 py-0.5 text-xs font-medium text-danger-700 dark:bg-danger-500/10 dark:text-danger-400">${this.escapeHtml(this.config.labels?.failed ?? 'Lỗi')}</span>`;
                }

                if (messageCell) {
                    messageCell.innerHTML =
                        `<p class="font-medium text-danger-600 dark:text-danger-400">${this.escapeHtml(displayError)}</p>`;
                }
            }

            if (actionsCell) {
                actionsCell.innerHTML = '—';
            }
        },

        applyItemFailure(taskId, message) {
            this.applyItemResult(taskId, { status: 'failed', message }, message);
        },

        updateStats(stats) {
            this.setStatValue('total', stats.total);
            this.setStatValue('succeeded', stats.succeeded);
            this.setStatValue('failed', stats.failed);
            this.setStatValue('pending', stats.pending);
        },

        setStatValue(key, value) {
            const el = document.querySelector(`[data-run-stat="${key}"]`);
            if (el) {
                el.textContent = String(value ?? 0);
            }
        },

        findRow(taskId) {
            return document.querySelector(`[data-run-task-id="${taskId}"]`);
        },

        escapeHtml(value) {
            return String(value ?? '')
                .replaceAll('&', '&amp;')
                .replaceAll('<', '&lt;')
                .replaceAll('>', '&gt;')
                .replaceAll('"', '&quot;')
                .replaceAll("'", '&#39;');
        },

        async pollArticleEditorReady(taskId, articleId, item) {
            const wire = this.resolveWire();
            if (!wire?.checkArticleEditorReady) {
                return;
            }

            const maxAttempts = 120;
            let attempts = 0;

            while (attempts < maxAttempts) {
                attempts += 1;

                // Đừng refresh Livewire khi queue đang chạy — gây mất DOM hàng đã OK / Alpine re-init lệch.
                if (Alpine.store('seoRunQueue')?.isRunning) {
                    await new Promise((resolve) => {
                        window.setTimeout(resolve, 3000);
                    });
                    continue;
                }

                const response = await wire.checkArticleEditorReady(articleId);
                if (response?.ready) {
                    await wire.refresh();
                    return;
                }

                await new Promise((resolve) => {
                    window.setTimeout(resolve, 3000);
                });
            }
        },
    }));
}

if (window.Alpine) {
    registerSeoProjectRunQueue();
} else {
    document.addEventListener('alpine:init', registerSeoProjectRunQueue);
}
