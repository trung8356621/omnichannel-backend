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
        removedTaskIds: [],
        runSettingsOpen: false,
        syncAllOpen: false,
        bulkConfirmOpen: false,
        bulkBusy: false,
        selectedTaskIds: [],
        selectedNodeIds: [],
        generatePostImages: Boolean(config?.runSettings?.generate_post_images ?? false),
        runSettingsSubmitting: false,
        syncAllBusy: false,

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

        handleStartQueue(detail = {}) {
            const taskIds = Array.isArray(detail?.taskIds)
                ? detail.taskIds.map((id) => Number(id)).filter((id) => id > 0)
                : [];

            if (taskIds.length === 0) {
                window.alert('Không có hạng mục để chạy lại.');

                return;
            }

            const confirmMessage = String(detail?.confirm ?? '').trim();
            if (confirmMessage !== '' && ! window.confirm(confirmMessage)) {
                return;
            }

            if (taskIds.length === 1) {
                this.runSingleTask(taskIds[0]);

                return;
            }

            this.startQueue(taskIds, {
                partial: true,
                refresh: false,
                preserveActions: true,
            });
        },

        async runSingleTask(taskId, options = {}) {
            const id = Number(taskId);
            if (id <= 0) {
                window.alert('Task ID không hợp lệ.');

                return;
            }

            const confirmMessage = String(options?.confirm ?? '').trim();
            if (confirmMessage !== '' && ! window.confirm(confirmMessage)) {
                return;
            }

            const store = Alpine.store('seoRunQueue');
            const wire = this.resolveWire();

            if (! wire?.runItemQueued) {
                window.alert('Không kết nối được Livewire (runItemQueued). Hard refresh (Ctrl+F5).');
                console.error('[seo-run-queue] resolveWire failed', this.config);

                return;
            }

            if (store.isRunning) {
                window.alert('Đang có queue chạy — bấm Dừng hoặc đợi xong.');

                return;
            }

            store.setRunning(true);
            store.stopRequested = false;
            store.currentTaskId = id;
            this.markRowRunning(id);

            console.info('[seo-run-queue] runSingleTask start', { taskId: id, livewireId: this.config?.livewireId });

            try {
                const response = await wire.runItemQueued(id, true);
                console.info('[seo-run-queue] runSingleTask response', response);

                if (response?.stats) {
                    this.updateStats(response.stats);
                }

                if (response?.success && response?.item) {
                    this.applyItemResult(id, response.item, response.displayError ?? '', {
                        preserveActions: true,
                        highlight: true,
                    });

                    const stats = response.item?.step_stats ?? {};
                    const skipped = Number(stats.skipped ?? 0);
                    const completed = Number(stats.completed ?? 0);
                    if (skipped > 0 && completed === 0) {
                        window.alert(
                            `Chạy xong nhưng AI bị bỏ qua hết (${skipped} bước skipped). Xem storage/logs và cột «Chạy lần cuối».`,
                        );
                    }
                } else {
                    const message = response?.message ?? 'Không chạy được quy trình.';
                    this.applyItemFailure(id, message, {
                        preserveActions: true,
                        highlight: true,
                    });
                    window.alert(message);
                }

                this.scrollRowIntoView(id);
                this.bumpRowToTop(id);
            } catch (error) {
                const message = error?.message ? String(error.message) : 'Lỗi Livewire khi chạy lại.';
                console.error('[seo-run-queue] runSingleTask error', error);
                this.applyItemFailure(id, message, {
                    preserveActions: true,
                    highlight: true,
                });
                window.alert(message);
            } finally {
                store.currentTaskId = null;
                store.reset();
            }
        },

        bumpRowToTop(taskId) {
            const row = this.findRow(taskId);
            const tbody = row?.closest('tbody');
            if (! row || ! tbody) {
                return;
            }

            tbody.insertBefore(row, tbody.firstChild);
            tbody.querySelectorAll('tr[data-run-task-id]').forEach((tr, index) => {
                const cells = tr.querySelectorAll('td');
                const indexCell = cells[1] ?? cells[0];
                if (indexCell) {
                    indexCell.textContent = String(index + 1);
                }
            });
        },

        visibleTaskIds() {
            return Array.from(this.$el.querySelectorAll('tr[data-run-task-id]'))
                .map((row) => Number(row.getAttribute('data-run-task-id')))
                .filter((id) => id > 0);
        },

        allVisibleSelected() {
            const visible = this.visibleTaskIds();
            if (visible.length === 0) {
                return false;
            }

            return visible.every((id) => this.selectedTaskIds.includes(id));
        },

        toggleSelectAll(checked) {
            const visible = this.visibleTaskIds();
            if (checked) {
                this.selectedTaskIds = Array.from(new Set([...this.selectedTaskIds, ...visible]));
            } else {
                this.selectedTaskIds = this.selectedTaskIds.filter((id) => ! visible.includes(id));
            }
        },

        bulkSelectedLabel() {
            const template = this.config.labels?.bulkSelected ?? 'Đã chọn :count bài';

            return template.replace(':count', String(this.selectedTaskIds.length));
        },

        selectedStepLabels() {
            const steps = Array.isArray(this.config.workflowSteps) ? this.config.workflowSteps : [];
            return steps
                .filter((step) => this.selectedNodeIds.includes(step.node_id))
                .map((step) => step.label || step.title || step.node_id);
        },

        bulkConfirmText() {
            const template = this.config.labels?.bulkConfirmBody
                ?? 'Bạn sắp tạo lại :steps công đoạn cho :articles bài. Tổng số task sẽ được tạo: :total.';
            const steps = this.selectedNodeIds.length;
            const articles = this.selectedTaskIds.length;

            return template
                .replace(':steps', String(steps))
                .replace(':articles', String(articles))
                .replace(':total', String(steps * articles));
        },

        openBulkConfirm() {
            if (this.selectedTaskIds.length === 0 || this.selectedNodeIds.length === 0) {
                return;
            }
            this.bulkConfirmOpen = true;
        },

        async confirmBulkRetry() {
            const wire = this.resolveWire();
            if (! wire?.bulkRetryWorkflowSteps) {
                window.alert('Không kết nối được Livewire (bulkRetryWorkflowSteps). Hard refresh (Ctrl+F5).');
                return;
            }

            this.bulkBusy = true;
            try {
                const response = await wire.bulkRetryWorkflowSteps(
                    this.selectedTaskIds,
                    this.selectedNodeIds,
                );
                window.alert(response?.message ?? 'Đã xử lý bulk retry.');
                this.bulkConfirmOpen = false;
                this.selectedTaskIds = [];
                this.selectedNodeIds = [];
                window.location.reload();
            } catch (error) {
                window.alert(error?.message ? String(error.message) : 'Bulk retry thất bại.');
            } finally {
                this.bulkBusy = false;
            }
        },

        async retryWorkflowStep(taskId, nodeId) {
            const id = Number(taskId);
            const node = String(nodeId ?? '').trim();
            if (id <= 0 || node === '') {
                return;
            }

            const wire = this.resolveWire();
            if (! wire?.retryWorkflowStep) {
                window.alert('Không kết nối được Livewire (retryWorkflowStep). Hard refresh (Ctrl+F5).');
                return;
            }

            const store = Alpine.store('seoRunQueue');
            if (store.isRunning) {
                window.alert('Đang có queue chạy — bấm Dừng hoặc đợi xong.');
                return;
            }

            store.setRunning(true);
            store.currentTaskId = id;
            this.markRowRunning(id);

            try {
                const response = await wire.retryWorkflowStep(id, node);
                if (response?.success) {
                    window.alert(response.message || 'Đã chạy lại prompt.');
                    window.location.reload();
                } else {
                    window.alert(response?.message || 'Không chạy được prompt.');
                }
            } catch (error) {
                window.alert(error?.message ? String(error.message) : 'Lỗi khi chạy lại prompt.');
            } finally {
                store.currentTaskId = null;
                store.reset();
            }
        },

        startRerunAllQueue() {
            // Entry «Chạy lại toàn bộ» đã gỡ.
        },

        openRerunSettingsModal() {
            // no-op — dùng bulk/per-prompt thay thế
        },

        async confirmRerunSettings() {
            this.runSettingsOpen = false;
        },

        openSyncAllConfirm() {
            if (! this.config.canSyncAll) {
                return;
            }

            this.syncAllOpen = true;
        },

        async confirmSyncAll() {
            const wire = this.resolveWire();
            if (! wire?.syncAllCompleted) {
                window.alert('Không kết nối được Livewire để sync.');

                return;
            }

            if (this.syncAllBusy) {
                return;
            }

            this.syncAllBusy = true;

            try {
                await wire.syncAllCompleted();
                this.syncAllOpen = false;
                await wire.refresh?.();
            } catch (error) {
                const message = error?.message ? String(error.message) : 'Không dispatch được sync.';
                window.alert(message);
            } finally {
                this.syncAllBusy = false;
            }
        },

        isRowVisible(taskId) {
            const id = Number(taskId);

            return id <= 0 || ! this.removedTaskIds.map(Number).includes(id);
        },

        hideArchivedRow(taskId) {
            const id = Number(taskId);
            if (id <= 0) {
                return;
            }

            this.removedTaskIds = Array.from(new Set([...this.removedTaskIds.map(Number), id]));

            // x-show trên <tr> không ổn định — xóa DOM ngay sau archive.
            const row = this.findRow(id);
            row?.remove();
        },

        archiveTaskRow(taskId) {
            const id = Number(taskId);
            if (id <= 0) {
                return;
            }

            const confirmMessage = String(
                this.config.labels?.archiveConfirm
                ?? 'Gỡ hạng mục khỏi project tháng và đưa vào kho lưu trữ domain?',
            );
            if (! window.confirm(confirmMessage)) {
                return;
            }

            const row = this.findRow(id);
            const status = String(row?.dataset?.runItemStatus ?? '');

            this.hideArchivedRow(id);
            this.bumpStatsAfterArchive(status);

            const wire = this.resolveWire();
            if (! wire?.archiveItem) {
                return;
            }

            Promise.resolve(wire.archiveItem(id)).catch(() => {
                // Row đã xóa; notification lỗi do Livewire/Filament.
            });
        },

        bumpStatsAfterArchive(status) {
            const totalEl = document.querySelector('[data-run-stat="total"]');
            const total = Math.max(0, Number(totalEl?.textContent ?? 0) - 1);
            this.setStatValue('total', total);

            if (status === 'success') {
                const el = document.querySelector('[data-run-stat="succeeded"]');
                this.setStatValue('succeeded', Math.max(0, Number(el?.textContent ?? 0) - 1));

                return;
            }

            if (status === 'failed') {
                const el = document.querySelector('[data-run-stat="failed"]');
                this.setStatValue('failed', Math.max(0, Number(el?.textContent ?? 0) - 1));

                return;
            }

            if (status === 'pending' || status === 'manual') {
                const el = document.querySelector('[data-run-stat="pending"]');
                this.setStatValue('pending', Math.max(0, Number(el?.textContent ?? 0) - 1));
            }
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
                        runItemQueued: (taskId, markCompleted = false) => component.call('runItemQueued', taskId, markCompleted),
                        retryWorkflowStep: (taskId, nodeId) => component.call('retryWorkflowStep', taskId, nodeId),
                        bulkRetryWorkflowSteps: (taskIds, nodeIds) => component.call('bulkRetryWorkflowSteps', taskIds, nodeIds),
                        beginRunQueue: () => component.call('beginRunQueue'),
                        finalizePartialQueue: () => component.call('finalizePartialQueue'),
                        completeRunQueue: (stopped) => component.call('completeRunQueue', stopped),
                        archiveItem: (taskId) => component.call('archiveItem', taskId),
                        updateRunSettingsForRerun: (settings) => component.call('updateRunSettingsForRerun', settings),
                        syncAllCompleted: () => component.call('syncAllCompleted'),
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
                    runItemQueued: (taskId, markCompleted = false) => this.$wire.runItemQueued(taskId, markCompleted),
                    retryWorkflowStep: (taskId, nodeId) => this.$wire.retryWorkflowStep(taskId, nodeId),
                    bulkRetryWorkflowSteps: (taskIds, nodeIds) => this.$wire.bulkRetryWorkflowSteps(taskIds, nodeIds),
                    beginRunQueue: () => this.$wire.beginRunQueue(),
                    finalizePartialQueue: () => this.$wire.finalizePartialQueue(),
                    completeRunQueue: (stopped) => this.$wire.completeRunQueue(stopped),
                    archiveItem: (taskId) => this.$wire.archiveItem(taskId),
                    updateRunSettingsForRerun: (settings) => this.$wire.updateRunSettingsForRerun(settings),
                    syncAllCompleted: () => this.$wire.syncAllCompleted(),
                    refresh: async () => {
                        if (typeof this.$wire.$refresh === 'function') {
                            await this.$wire.$refresh();
                        }
                    },
                    checkArticleEditorReady: (articleId) => this.$wire.checkArticleEditorReady(articleId),
                };
            }

            if (this.$wire?.archiveItem) {
                return {
                    archiveItem: (taskId) => this.$wire.archiveItem(taskId),
                };
            }

            return null;
        },

        async processQueue() {
            const taskIds = Array.isArray(this.config.taskIds)
                ? this.config.taskIds.map((id) => Number(id)).filter((id) => id > 0)
                : [];

            if (taskIds.length === 0) {
                return;
            }

            await this.startQueue(taskIds, {
                partial: false,
                refresh: true,
                preserveActions: false,
            });
        },

        async startQueue(taskIds, options = {}) {
            const store = Alpine.store('seoRunQueue');
            const wire = this.resolveWire();

            if (!wire?.runItemQueued) {
                window.alert('Không kết nối được Livewire để chạy lại hạng mục.');

                return;
            }

            if (store.isRunning) {
                window.alert('Đang có queue chạy — bấm Dừng hoặc đợi xong rồi thử lại.');

                return;
            }

            const normalizedTaskIds = Array.isArray(taskIds)
                ? taskIds.map((id) => Number(id)).filter((id) => id > 0)
                : [];

            if (normalizedTaskIds.length === 0) {
                return;
            }

            const partial = options.partial === true;
            const shouldRefresh = options.refresh !== false && ! partial;
            const preserveActions = options.preserveActions === true || partial;
            // Chạy lẻ: ghi completed ngay từng item. Queue dài: giữ running đến khi complete.
            const markCompletedPerItem = partial && normalizedTaskIds.length === 1;

            store.setRunning(true);
            store.stopRequested = false;

            let stopped = false;
            let lastFinishedTaskId = null;

            try {
                if (! partial && wire.beginRunQueue) {
                    await wire.beginRunQueue();
                }

                if (partial && ! markCompletedPerItem && wire.beginRunQueue) {
                    await wire.beginRunQueue();
                }

                for (const taskId of normalizedTaskIds) {
                    if (store.stopRequested) {
                        stopped = true;
                        break;
                    }

                    store.currentTaskId = taskId;
                    this.markRowRunning(taskId);

                    const response = await wire.runItemQueued(taskId, markCompletedPerItem);

                    if (response?.stats) {
                        this.updateStats(response.stats);
                    }

                    if (response?.success && response?.item) {
                        this.applyItemResult(taskId, response.item, response.displayError ?? '', {
                            preserveActions,
                            highlight: partial,
                        });
                        lastFinishedTaskId = taskId;
                    } else if (! response?.success) {
                        this.applyItemFailure(taskId, response?.message ?? 'Không chạy được quy trình.', {
                            preserveActions,
                            highlight: partial,
                        });
                        lastFinishedTaskId = taskId;
                    }

                    if (store.stopRequested) {
                        stopped = true;
                        break;
                    }
                }

                store.currentTaskId = null;

                if (partial) {
                    if (! markCompletedPerItem && wire.finalizePartialQueue) {
                        await wire.finalizePartialQueue();
                    }
                } else if (wire.completeRunQueue) {
                    await wire.completeRunQueue(stopped);
                }
            } catch (error) {
                const message = error?.message
                    ? String(error.message)
                    : 'Không chạy được quy trình.';
                if (store.currentTaskId) {
                    this.applyItemFailure(store.currentTaskId, message, {
                        preserveActions,
                        highlight: partial,
                    });
                }
                window.alert(message);
            } finally {
                store.currentTaskId = null;
                store.reset();
            }

            if (lastFinishedTaskId && partial) {
                this.scrollRowIntoView(lastFinishedTaskId);
            }

            if (! shouldRefresh) {
                return;
            }

            try {
                await wire.refresh();
            } catch (_error) {
                // ignore refresh errors after queue finished
            }

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
            const id = Number(taskId);
            if (id <= 0) {
                return;
            }

            const row = this.findRow(id);
            if (!row) {
                return;
            }

            row.dataset.runItemStatus = 'running';
            row.classList.remove(
                'bg-warning-50/40',
                'dark:bg-warning-500/5',
                'seo-run-row-just-finished',
                'seo-run-row-just-failed',
            );
            row.classList.add('bg-primary-50/40', 'dark:bg-primary-500/5');

            const runningLabel = this.escapeHtml(this.config.labels?.running ?? 'Đang chạy…');

            const statusCell = row.querySelector('[data-run-status]');
            if (statusCell) {
                if (! statusCell.dataset.runStatusBackup) {
                    statusCell.dataset.runStatusBackup = statusCell.innerHTML;
                }
                statusCell.innerHTML =
                    `<span class="inline-flex rounded-md bg-primary-50 px-2 py-0.5 text-xs font-medium text-primary-700 dark:bg-primary-500/10 dark:text-primary-400">${runningLabel}</span>`;
            }

            const messageCell = row.querySelector('[data-run-message]');
            if (messageCell) {
                if (! messageCell.dataset.runMessageBackup) {
                    messageCell.dataset.runMessageBackup = messageCell.innerHTML;
                }
                messageCell.textContent = this.config.labels?.running ?? 'Đang chạy…';
            }

            const actionsCell = row.querySelector('[data-run-actions]');
            if (actionsCell) {
                if (! actionsCell.dataset.runActionsBackup) {
                    actionsCell.dataset.runActionsBackup = actionsCell.innerHTML;
                }
                actionsCell.innerHTML = `<span class="text-xs text-primary-600 dark:text-primary-400">${runningLabel}</span>`;
            }
        },

        applyItemResult(taskId, item, displayError, options = {}) {
            const row = this.findRow(taskId);
            if (!row) {
                return;
            }

            const preserveActions = options.preserveActions === true;
            const highlight = options.highlight === true;

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
                    delete statusCell.dataset.runStatusBackup;
                }

                if (messageCell) {
                    const message = String(item?.message ?? '');
                    messageCell.innerHTML = `<p class="font-medium text-success-700 dark:text-success-400">${this.escapeHtml(message)}</p>`;
                    delete messageCell.dataset.runMessageBackup;
                }

                this.updateLastRunCell(row, item?.last_run_at);
                this.updateRetryBadge(row, item?.retry_count);

                const articleId = Number(item?.article_id ?? 0);
                const editorReady = item?.article_editor_ready !== false;
                if (articleId > 0 && !editorReady) {
                    this.pollArticleEditorReady(taskId, articleId, item);
                }

                if (highlight) {
                    row.classList.add('seo-run-row-just-finished');
                    window.setTimeout(() => {
                        row.classList.remove('seo-run-row-just-finished');
                    }, 5000);
                }
            } else {
                row.dataset.runItemStatus = 'failed';

                if (statusCell) {
                    statusCell.innerHTML =
                        `<span class="inline-flex rounded-md bg-danger-50 px-2 py-0.5 text-xs font-medium text-danger-700 dark:bg-danger-500/10 dark:text-danger-400">${this.escapeHtml(this.config.labels?.failed ?? 'Lỗi')}</span>`;
                    delete statusCell.dataset.runStatusBackup;
                }

                if (messageCell) {
                    messageCell.innerHTML =
                        `<p class="font-medium text-danger-600 dark:text-danger-400">${this.escapeHtml(displayError)}</p>`;
                    delete messageCell.dataset.runMessageBackup;
                }

                this.updateLastRunCell(row, item?.last_run_at);
                this.updateRetryBadge(row, item?.retry_count);

                if (highlight) {
                    row.classList.add('seo-run-row-just-failed');
                    window.setTimeout(() => {
                        row.classList.remove('seo-run-row-just-failed');
                    }, 5000);
                }
            }

            if (actionsCell) {
                if (preserveActions && actionsCell.dataset.runActionsBackup) {
                    actionsCell.innerHTML = actionsCell.dataset.runActionsBackup;
                    delete actionsCell.dataset.runActionsBackup;
                } else {
                    actionsCell.innerHTML = '—';
                    delete actionsCell.dataset.runActionsBackup;
                }
            }

            this.updateRetryBadge(row, item?.retry_count);
        },

        applyItemFailure(taskId, message, options = {}) {
            this.applyItemResult(taskId, { status: 'failed', message }, message, options);
        },

        scrollRowIntoView(taskId) {
            const row = this.findRow(taskId);
            if (! row) {
                return;
            }

            row.scrollIntoView({
                behavior: 'smooth',
                block: 'center',
            });
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

        updateLastRunCell(row, lastRunAt) {
            const cell = row?.querySelector('[data-run-last-run]');
            if (! cell) {
                return;
            }

            const raw = String(lastRunAt ?? '').trim();
            if (raw === '') {
                cell.textContent = '—';

                return;
            }

            const parsed = new Date(raw.replace(' ', 'T'));
            if (Number.isNaN(parsed.getTime())) {
                cell.textContent = raw;

                return;
            }

            const pad = (value) => String(value).padStart(2, '0');
            cell.textContent = `${pad(parsed.getDate())}/${pad(parsed.getMonth() + 1)}/${parsed.getFullYear()} ${pad(parsed.getHours())}:${pad(parsed.getMinutes())}:${pad(parsed.getSeconds())}`;
        },

        updateRetryBadge(row, retryCount) {
            const badge = row?.querySelector('[data-run-retry-badge]');
            if (! badge) {
                return;
            }

            const count = Number(retryCount ?? 0);
            if (count <= 0) {
                badge.style.display = 'none';
                badge.textContent = '';
                badge.removeAttribute('title');

                return;
            }

            badge.style.display = '';
            badge.textContent = String(count);
            const template = this.config?.labels?.rerunBadgeTooltip;
            if (typeof template === 'string' && template.includes(':count')) {
                badge.title = template.replaceAll(':count', String(count));
            } else if (typeof template === 'string' && template !== '') {
                badge.title = template;
            } else {
                badge.title = `Đã chạy lại ${count} lần`;
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
                    const row = this.findRow(taskId);
                    row?.querySelector('[data-run-article-preparing]')?.remove();

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
