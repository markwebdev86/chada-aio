/* Chada AIO: local administrator UI, resumable jobs and an action-free guided walkthrough. */
(() => {
    'use strict';
    const { __ } = window.wp.i18n;
    const config = window.ChadaAio;
    const root = document.querySelector('.chada-aio');
    if (!config || !root) return;
    const $ = (selector, parent = root) => parent.querySelector(selector);
    const $$ = (selector, parent = root) => [...parent.querySelectorAll(selector)];
    const node = (tag, text, className) => {
        const element = document.createElement(tag);
        if (text !== undefined) element.textContent = String(text);
        if (className) element.className = className;
        return element;
    };
    const setHidden = (element, hidden) => {
        if (!element) return;
        element.hidden = hidden;
        if (hidden) element.setAttribute('hidden', ''); else element.removeAttribute('hidden');
    };
    const buttonStates = new WeakMap();
    function prepareButton(button) {
        if (!button || button.tagName !== 'BUTTON') return null;
        if (buttonStates.has(button)) return buttonStates.get(button);
        const label = node('span', button.textContent.trim(), 'chada-button-label');
        const loader = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        loader.setAttribute('class', 'chada-button-loader'); loader.setAttribute('viewBox', '0 0 20 20'); loader.setAttribute('aria-hidden', 'true'); loader.setAttribute('focusable', 'false');
        const circle = document.createElementNS('http://www.w3.org/2000/svg', 'circle');
        circle.setAttribute('cx', '10'); circle.setAttribute('cy', '10'); circle.setAttribute('r', '7'); circle.setAttribute('fill', 'none'); circle.setAttribute('stroke', 'currentColor'); circle.setAttribute('stroke-width', '2'); circle.setAttribute('stroke-linecap', 'round'); circle.setAttribute('stroke-dasharray', '32 12');
        loader.append(circle); setHidden(loader, true);
        const loadingText = node('span', __('Processing…', 'chada-aio'), 'screen-reader-text chada-button-loading-text'); loadingText.setAttribute('role', 'status'); loadingText.setAttribute('aria-live', 'polite'); loadingText.hidden = true;
        button.replaceChildren(label, loader, loadingText);
        const state = { label, loader, loadingText, busyOwners: new Set(), manualBusy: false, formBusy: false };
        buttonStates.set(button, state);
        return state;
    }
    function syncButton(button) {
        const state = buttonStates.get(button); if (!state) return;
        const busy = state.manualBusy || state.busyOwners.size > 0;
        button.classList.toggle('chada-button-busy', busy);
        if (busy) { button.setAttribute('aria-busy', 'true'); } else { button.removeAttribute('aria-busy'); }
        setHidden(state.loader, !busy); setHidden(state.loadingText, !busy);
        button.disabled = busy || state.formBusy;
    }
    function setButtonBusy(button, busy) {
        const state = prepareButton(button); if (!state) return;
        state.manualBusy = busy; syncButton(button);
    }
    function setButtonLabel(button, label) {
        const state = prepareButton(button); if (state) state.label.textContent = String(label);
        else if (button) button.textContent = String(label);
    }
    function busyToken(button) {
        const state = prepareButton(button); if (!state || state.manualBusy || state.busyOwners.size > 0) return null;
        const owner = {};
        state.busyOwners.add(owner); syncButton(button);
        let released = false;
        return {
            release: () => { if (released) return; released = true; state.busyOwners.delete(owner); syncButton(button); }
        };
    }
    async function withButtonBusy(button, callback) {
        const token = busyToken(button); if (!token) return;
        try { return await callback(token); } finally { token.release(); }
    }
    const operationName = operation => ({ backup: __('Backup', 'chada-aio'), export: __('Migration package', 'chada-aio'), import: __('Migration import', 'chada-aio'),
        restore: __('Restore', 'chada-aio'), optimize: __('Optimization', 'chada-aio'), cleanup: __('Database cleanup', 'chada-aio') }[operation] || operation);
    const typeName = (type, operation = '') => {
        if (type === 'all') return operation === 'backup' ? __('Backup All', 'chada-aio') : __('Migrate All', 'chada-aio');
        return ({ database: __('Database', 'chada-aio'), post: __('Post', 'chada-aio'), media: __('Media', 'chada-aio'), theme: __('Theme', 'chada-aio'),
            plugin: __('Plugin', 'chada-aio'), selected: __('Selected images', 'chada-aio'), library: __('Library', 'chada-aio'), quarter: __('Quarter', 'chada-aio'), year: __('Year', 'chada-aio') }[type] || type);
    };
    const statusName = status => ({ running: __('In progress', 'chada-aio'), complete: __('Complete', 'chada-aio'), failed: __('Failed', 'chada-aio'),
        recovery_required: __('Recovery required', 'chada-aio'), rolling_back: __('Rolling back', 'chada-aio'), rolled_back: __('Rolled back', 'chada-aio'), cancelled: __('Cancelled', 'chada-aio') }[status] || status);
    const stageName = stage => ({ start: __('Preparing', 'chada-aio'), capture: __('Capturing data', 'chada-aio'), package: __('Building package', 'chada-aio'),
        verify: __('Verifying package', 'chada-aio'), extract: __('Reading package', 'chada-aio'), recovery: __('Creating recovery backup', 'chada-aio'), prepare: __('Preparing changes', 'chada-aio'),
        'database-stage': __('Staging database', 'chada-aio'), 'apply-files': __('Applying files', 'chada-aio'), 'apply-content': __('Applying content', 'chada-aio'),
        'database-commit': __('Replacing database', 'chada-aio'), finish: __('Running final checks', 'chada-aio'), images: __('Processing images', 'chada-aio'), clean: __('Cleaning selected data', 'chada-aio'), rollback: __('Restoring recovery state', 'chada-aio') }[stage] || stage);
    const setFormsBusy = busy => {
        $$('.chada-operation, #chada-import, #chada-cleanup, #chada-undo').forEach(form => {
            form.classList.toggle('is-busy', busy);
            $$('button, input, select, textarea', form).forEach(control => {
                if (control.tagName === 'BUTTON') { const state = prepareButton(control); if (state) { state.formBusy = busy; syncButton(control); return; } }
                control.disabled = busy;
            });
        });
    };
    const notice = (text, error = false) => {
        const element = $('#chada-notice'); element.hidden = false;
        element.className = `chada-notice${error ? ' error' : ''}`; element.setAttribute('role', error ? 'alert' : 'status'); element.setAttribute('aria-live', error ? 'assertive' : 'polite'); element.textContent = text;
    };
    const clearNotice = () => { const element = $('#chada-notice'); if (element) { element.hidden = true; element.setAttribute('role', 'status'); element.setAttribute('aria-live', 'polite'); element.textContent = ''; } };
    async function request(path, body, options = {}) {
        const response = await fetch(config.rest + path, {
            credentials: 'same-origin', method: body === undefined ? 'GET' : 'POST',
            headers: { 'X-WP-Nonce': config.nonce, 'Content-Type': options.raw ? 'application/octet-stream' : 'application/json' },
            body: body === undefined ? undefined : options.raw ? body : JSON.stringify(body)
        });
        let result;
        try { result = await response.json(); } catch { throw new Error(__('The server returned an unexpected response. Reopen the job before retrying.', 'chada-aio')); }
        if (!response.ok) throw new Error(result.message || 'The request was not accepted.');
        return result;
    }
    const safely = callback => async event => {
        if (event) event.preventDefault();
        try { await callback(event); } catch (error) { notice(error.message, true); }
    };
    const validateForm = (form, message = __('Please correct the highlighted fields before continuing.', 'chada-aio')) => {
        if (form.reportValidity()) return true;
        const invalid = $$('input, select, textarea', form).filter(control => !control.checkValidity());
        invalid.forEach(control => control.setAttribute('aria-invalid', 'true'));
        if (invalid[0]) invalid[0].focus();
        notice(message, true);
        return false;
    };
    const clearInvalid = form => $$('input, select, textarea', form).forEach(control => {
        ['input', 'change'].forEach(eventName => control.addEventListener(eventName, () => control.removeAttribute('aria-invalid')));
    });
    const bindResolution = form => {
        const resolution = $('[name=resolution]', form); const width = $('[name=landscape_width]', form); const height = $('[name=landscape_height]', form);
        if (!resolution || !width || !height) return;
        const sync = () => {
            const match = /^(\d+)x(\d+)$/.exec(resolution.value); const custom = !match;
            if (match) { width.value = match[1]; height.value = match[2]; }
            [width, height].forEach(control => {
                control.readOnly = !custom;
                if (custom) control.removeAttribute('aria-readonly'); else control.setAttribute('aria-readonly', 'true');
                if (!custom) control.removeAttribute('aria-invalid');
            });
        };
        resolution.addEventListener('change', sync); sync();
    };
    const databaseInspection = $('.chada-database-inspection');
    const databaseInspectionTrigger = databaseInspection ? $('.chada-database-inspection-trigger', databaseInspection) : null;
    const databaseInspectionContent = databaseInspection ? $('.chada-inspection-content', databaseInspection) : null;
    databaseInspectionTrigger?.addEventListener('click', () => {
        const expanded = databaseInspectionTrigger.getAttribute('aria-expanded') === 'true';
        databaseInspectionTrigger.setAttribute('aria-expanded', String(!expanded));
        if (databaseInspectionContent) databaseInspectionContent.hidden = expanded;
    });
    const dialog = $('#chada-dialog');
    let dialogAction = null, dialogBusy = false;
    const resetDialogState = () => {
        dialogAction = null;
        dialogBusy = false;
        $('#chada-dialog-close').disabled = false;
    };
    const showDialog = (title, content, action = null, label = 'Confirm and apply', sourceToken = null) => {
        $('#chada-dialog-title').textContent = title;
        $('#chada-dialog-content').replaceChildren(content);
        const apply = $('#chada-dialog-apply'); prepareButton(apply); apply.hidden = !action; setButtonLabel(apply, label);
        dialogAction = action;
        try {
            if (!dialog.open) dialog.showModal();
            // Opening the popup completes the source action; confirmation has its own loader.
            if (sourceToken) sourceToken.release();
        } catch (error) {
            resetDialogState();
            throw error;
        }
    };
    dialog.addEventListener('cancel', event => { if (dialogBusy) event.preventDefault(); });
    dialog.addEventListener('close', resetDialogState);
    $('#chada-dialog-close').addEventListener('click', () => { if (!dialogBusy) { dialog.close(); resetDialogState(); } });
    $('#chada-dialog-apply').addEventListener('click', safely(async () => {
        if (!dialogAction || dialogBusy) return;
        const action = dialogAction; const button = $('#chada-dialog-apply'); const close = $('#chada-dialog-close'); dialogBusy = true; close.disabled = true;
        try { await withButtonBusy(button, async () => { await action(); if (dialog.open) dialog.close(); }); }
        finally { dialogBusy = false; close.disabled = false; }
    }));
    function describe(data) {
        const container = node('dl', undefined, 'chada-summary-list');
        for (const [key, value] of Object.entries(data)) {
            const label = ({ type: __('Package type', 'chada-aio'), source: __('Source site', 'chada-aio'), files: __('Files affected', 'chada-aio'), existing_paths: __('Existing file paths', 'chada-aio'),
                expanded_bytes: __('Expanded size', 'chada-aio'), period: __('Date range', 'chada-aio'), coverage: __('File coverage', 'chada-aio'), recovery_scope: __('Recovery scope', 'chada-aio'), effect: __('Expected effect', 'chada-aio'), recovery: __('Safety step', 'chada-aio'), file_policy: __('File policy', 'chada-aio'), counts: __('Items selected', 'chada-aio'), source_wordpress: __('Source WordPress', 'chada-aio'), source_php: __('Source PHP', 'chada-aio'), destination_wordpress: __('Destination WordPress', 'chada-aio'), destination_php: __('Destination PHP', 'chada-aio') }[key] || key.replaceAll('_', ' '));
            const term = node('dt', label); const detail = node('dd', typeof value === 'object' ? JSON.stringify(value) : value);
            container.append(term, detail);
        }
        return container;
    }
    const terminalStatuses = ['complete', 'failed', 'cancelled', 'rolled_back'];
    let currentJob = null, pumping = false, starting = false;
    function showJob(job, revealTerminal = true) {
        const visible = !!job && (revealTerminal || !terminalStatuses.includes(job.status));
        currentJob = visible ? job : null;
        const panel = $('#chada-job'); panel.hidden = !visible;
        setFormsBusy(visible && ['running', 'rolling_back'].includes(job?.status));
        if (!visible) return;
        const progress = job.progress || { percent: 0, completed: 0, total: 1, stage: job.stage };
        const percent = Math.max(0, Math.min(100, Number(progress.percent) || 0));
        const meter = $('#chada-progress'); meter.max = 100; meter.value = percent;
        meter.setAttribute('aria-valuenow', String(percent)); meter.setAttribute('aria-valuetext', `${percent}%`);
        panel.dataset.status = job.status;
        $('#chada-progress-label').textContent = `${percent}%${progress.total > 1 ? ` · ${progress.completed} of ${progress.total}` : ''}`;
        $('#chada-job-status').textContent = statusName(job.status);
        $('#chada-job-status').className = `chada-status chada-status-${job.status}`;
        $('#chada-progress-stage').textContent = stageName(progress.stage || job.stage);
        $('#chada-job-text').textContent = `${operationName(job.operation)} · ${typeName(job.type, job.operation)}: ${job.message}`;
        meter.hidden = false;
        const resumable = ['running', 'rolling_back'].includes(job.status);
        const paused = resumable && !pumping && !starting;
        $('#chada-resume').hidden = !paused;
        $('#chada-resume').textContent = job.status === 'rolling_back' ? __('Continue rollback', 'chada-aio') : __('Continue operation', 'chada-aio');
        $('#chada-cancel').hidden = !(paused && job.status === 'running' && !job.touched);
        $('#chada-rollback').hidden = starting || !job.recovery_id || !job.touched || !['running', 'recovery_required', 'complete'].includes(job.status);
        $('#chada-retry').hidden = starting || !job.retryable;
        $('#chada-job-close').hidden = starting || !terminalStatuses.includes(job.status);
    }
    async function driveJob(job) {
        if (pumping) return;
        currentJob = job;
        pumping = true;
        showJob(job);
        try {
            while (['running', 'rolling_back'].includes(currentJob.status)) {
                showJob(await request(`jobs/${currentJob.id}/step`, {}));
                if (['running', 'rolling_back'].includes(currentJob.status)) await new Promise(resolve => setTimeout(resolve, 250));
            }
            if (currentJob.status === 'recovery_required' || currentJob.status === 'failed') notice(currentJob.message, true);
            else notice(currentJob.message);
            await refreshGrids();
        } catch (error) { notice(`${error.message} The journal is retained; sign in again if needed, then Resume.`, true); }
        finally { pumping = false; if (currentJob) showJob(currentJob); }
        return currentJob;
    }
    function pump() { if (!pumping && currentJob) return driveJob(currentJob); }
    async function begin(body) {
        starting = true;
        clearNotice();
        if (currentJob) showJob(currentJob);
        try { const job = await request('jobs', body); await driveJob(job); return job; }
        finally { starting = false; if (currentJob && !pumping) showJob(currentJob); }
    }
    $('#chada-resume').addEventListener('click', safely(() => withButtonBusy($('#chada-resume'), pump)));
    $('#chada-cancel').addEventListener('click', safely(() => withButtonBusy($('#chada-cancel'), async () => {
        if (currentJob) showJob(await request(`jobs/${currentJob.id}/control`, { action: 'cancel' }));
    })));
    $('#chada-retry').addEventListener('click', safely(() => withButtonBusy($('#chada-retry'), async () => {
        if (currentJob) await driveJob(await request(`jobs/${currentJob.id}/control`, { action: 'retry' }));
    })));
    $('#chada-rollback').addEventListener('click', safely(event => {
        const button = event.currentTarget;
        return withButtonBusy(button, token => showDialog(__('Rollback operation', 'chada-aio'), node('p', __('Restore the recovery state and undo this operation’s applied changes?', 'chada-aio')), async () => {
            await driveJob(await request(`jobs/${currentJob.id}/control`, { action: 'rollback' }));
        }, 'Confirm rollback', token));
    }));
    $('#chada-job-close').addEventListener('click', () => { currentJob = null; $('#chada-job').hidden = true; setFormsBusy(false); });
    function preflightReview(summary) {
        const content = node('div'); content.append(describe(Object.fromEntries(Object.entries(summary).filter(([key]) => key !== 'preflight'))));
        const preflight = summary.preflight || { status: 'ready', errors: [], warnings: [] };
        const heading = node('h3', __('Import preflight', 'chada-aio')); content.append(heading);
        const status = node('p', preflight.status === 'blocked' ? __('Blocked: resolve these checks before applying.', 'chada-aio') : preflight.status === 'warning' ? __('Review and acknowledge these warnings before applying.', 'chada-aio') : __('Ready: package safety checks passed.', 'chada-aio'), `chada-callout${preflight.status === 'blocked' ? ' error' : ''}`); content.append(status);
        for (const [kind, title] of [['errors', __('Blocking checks', 'chada-aio')], ['warnings', __('Warnings', 'chada-aio')]]) {
            if (!preflight[kind]?.length) continue;
            const section = node('div'); section.append(node('h4', title)); const list = node('ul');
            for (const item of preflight[kind]) { const entry = node('li'); entry.append(node('strong', item.message)); if (item.remedy) entry.append(node('span', ` ${item.remedy}`)); list.append(entry); }
            section.append(list); content.append(section);
        }
        let acknowledgement = null;
        if (preflight.status === 'warning') {
            const label = node('label'); acknowledgement = document.createElement('input'); acknowledgement.type = 'checkbox'; acknowledgement.dataset.chadaWarningAck = 'true'; label.append(acknowledgement, document.createTextNode(` ${__('I understand these warnings and want to continue.', 'chada-aio')}`)); content.append(label);
        }
        return { content, acknowledgement, blocked: preflight.status === 'blocked' };
    }
    async function preview(input, sourceToken = null) {
        const result = await request('previews', input);
        if (!result.summary.preflight) { showDialog(__('Review changes before applying', 'chada-aio'), describe(result.summary), () => begin({ preview_id: result.preview_id, confirm: 'APPLY' }), 'Confirm and apply', sourceToken); return; }
        const review = preflightReview(result.summary);
        if (review.blocked) { showDialog(__('Import cannot continue yet', 'chada-aio'), review.content, null, 'Confirm and apply', sourceToken); return; }
        const action = () => {
            if (review.acknowledgement && !review.acknowledgement.checked) throw new Error(__('Acknowledge the import warnings before applying.', 'chada-aio'));
            return begin({ preview_id: result.preview_id, confirm: 'APPLY', acknowledge_warnings: !!review.acknowledgement });
        };
        showDialog(__('Review changes before applying', 'chada-aio'), review.content, action, 'Confirm and apply', sourceToken);
        if (review.acknowledgement) { const apply = $('#chada-dialog-apply'); apply.disabled = true; review.acknowledgement.addEventListener('change', () => { apply.disabled = !review.acknowledgement.checked; }); }
    }
    function values(form) {
        const data = {};
        for (const element of $$('[name]', form)) {
            if (element.name.endsWith('[]')) {
                const key = element.name.slice(0, -2); if (!data[key]) data[key] = [];
                if (element.type !== 'checkbox' || element.checked) data[key].push(element.value);
            } else if (element.type === 'checkbox') data[element.name] = element.checked;
            else data[element.name] = element.value;
        }
        return data;
    }
    $$('.chada-operation').forEach(form => {
        const type = $('[name=type]', form);
        const action = $('.chada-operation-submit', form);
        const advanced = $('.chada-advanced-options', form);
        const imageInput = $('[name=ids]', form);
        const imageCount = $('[data-image-count]', form);
        const imageSelection = $('.chada-image-selection', form);
        const pickerCount = $('[data-image-picker-count]', form);
        const pluginCount = $('[data-plugin-count]', form);
        const period = $('[name=period]', form);
        const limit = Number(form.dataset.imageLimit || config.selectedImageLimit || 100);
        const typeLabel = () => typeName(type.value, form.dataset.operation);
        function updateAction() {
            if (!action) return;
            const label = form.dataset.operation === 'backup' ? __('Create {type} backup', 'chada-aio').replace('{type}', typeLabel())
                : form.dataset.operation === 'export' ? __('Create {type} package', 'chada-aio').replace('{type}', typeLabel())
                    : __('Optimize images', 'chada-aio');
            setButtonLabel(action, label);
        }
        function typedImageTokens() { return (imageInput?.value || '').split(/[\s,]+/).filter(Boolean); }
        function selectedImageIds() {
            const typed = typedImageTokens().map(Number).filter(id => Number.isInteger(id) && id > 0);
            const chosen = $$('.chada-image-choice:checked', form).map(input => Number(input.value));
            return [...new Set([...typed, ...chosen])];
        }
        function updateCounts() {
            const tokens = typedImageTokens();
            const invalid = type.value === 'selected' && tokens.some(token => !/^\d+$/.test(token) || Number(token) < 1);
            const ids = selectedImageIds();
            const missing = type.value === 'selected' && !ids.length;
            const tooMany = type.value === 'selected' && ids.length > limit;
            if (imageInput) imageInput.setAttribute('aria-required', type.value === 'selected' ? 'true' : 'false');
            if (imageInput) imageInput.setCustomValidity(invalid ? __('Use positive numeric attachment IDs separated by commas or spaces.', 'chada-aio') : missing ? __('Select at least one image ID.', 'chada-aio') : tooMany ? `Choose between 1 and ${limit} images. Further batches remain available.` : '');
            const pluginInputs = $$('[name="plugins[]"], [name="mu_plugins[]"]', form);
            const pluginMissing = type.value === 'plugin' && pluginInputs.length > 0 && !pluginInputs.some(input => input.checked);
            if (pluginInputs[0]) pluginInputs[0].setCustomValidity(pluginMissing ? __('Select at least one plugin to include.', 'chada-aio') : '');
            if (imageCount) imageCount.textContent = `${ids.length} ${__('images selected', 'chada-aio')} · ${__('maximum', 'chada-aio')} ${limit}`;
            if (pickerCount) pickerCount.textContent = `${$$('.chada-image-choice:checked', form).length} ${__('recent images selected', 'chada-aio')}`;
            if (pluginCount) pluginCount.textContent = `${pluginInputs.filter(input => input.checked).length} ${__('plugins selected', 'chada-aio')}`;
        }
        function conditional() {
            const isContent = ['page', 'post', 'media'].includes(type.value);
            ['period', 'year', 'quarter', 'theme'].forEach(name => {
                const field = $(`[name=${name}]`, form);
                if (!field) return;
                let visible = name === 'theme' ? type.value === 'theme' : isContent;
                if (isContent && name === 'year') visible = period.value !== 'all';
                if (isContent && name === 'quarter') visible = period.value === 'quarter';
                if (form.dataset.operation === 'optimize') visible = name === 'year' ? ['year', 'quarter'].includes(type.value) : name === 'quarter' && type.value === 'quarter';
                field.closest('label').hidden = !visible;
                field.required = visible;
                if (!visible) field.setCustomValidity('');
            });
            const pluginSelection = $('.chada-plugin-selection', form);
            if (pluginSelection) { pluginSelection.hidden = type.value !== 'plugin'; pluginSelection.setAttribute('aria-required', type.value === 'plugin' ? 'true' : 'false'); }
            if (advanced) {
                advanced.hidden = !['page', 'post', 'media', 'theme', 'plugin'].includes(type.value);
                if (advanced.hidden) advanced.open = false;
            }
            if (imageSelection) imageSelection.hidden = type.value !== 'selected';
            updateAction(); updateCounts();
        }
        type.addEventListener('change', conditional); conditional();
        period?.addEventListener('change', conditional);
        imageInput?.addEventListener('input', updateCounts);
        $$('.chada-image-choice, [name="plugins[]"], [name="mu_plugins[]"]', form).forEach(input => input.addEventListener('change', updateCounts));
        clearInvalid(form);
        form.addEventListener('submit', safely(async () => {
            if (currentJob && ['running', 'rolling_back'].includes(currentJob.status)) throw new Error(__('Finish the current operation before starting another one.', 'chada-aio'));
            updateCounts();
            if (!validateForm(form)) return;
            const fields = values(form);
            const args = { period: ['page', 'post', 'media'].includes(fields.type) ? fields.period : 'all', year: Number(fields.year), quarter: Number(fields.quarter), theme: fields.theme };
            if (form.dataset.operation === 'optimize') {
                args.ids = selectedImageIds();
                args.period = ['year', 'quarter'].includes(fields.type) ? fields.type : 'all';
                await withButtonBusy(action, token => showDialog(__('Review image optimization', 'chada-aio'), node('p', __('Process the selected images using your saved settings? Original files and metadata will be retained for undo.', 'chada-aio')),
                    () => begin({ operation: 'optimize', type: fields.type, args }), 'Optimize images', token));
            } else {
                if (fields.type === 'plugin') { args.plugins = fields.plugins || []; args.mu_plugins = fields.mu_plugins || []; }
                await withButtonBusy(action, () => begin({ operation: form.dataset.operation, type: fields.type, args }));
            }
        }));
    });
    const grids = $$('.chada-grid');
    async function loadGrid(grid) {
        const page = Number(grid.dataset.page || 1);
        const data = await request(`records?operation=${encodeURIComponent(grid.dataset.operation)}&type=${encodeURIComponent($('.chada-filter', grid).value)}&page=${page}`);
        const body = $('tbody', grid); body.replaceChildren();
        if (!data.records.length) { const row = node('tr'); const cell = node('td', __('No matching records yet.', 'chada-aio')); cell.colSpan = 5; row.append(cell); body.append(row); }
        for (const record of data.records) {
            const row = node('tr');
            row.append(node('td', `${record.created_at} UTC`));
            row.append(node('td', operationName(record.operation)));
            const type = node('td', typeName(record.type, record.operation));
            type.append(node('div', record.bytes ? `${(record.bytes / 1048576).toFixed(2)} MiB` : (record.recovery_id ? __('Protected recovery backup', 'chada-aio') : ''), 'description')); row.append(type);
            const status = node('td'); const statusKey = String(record.status || '').replace(/[^a-z0-9_-]/gi, '');
            status.append(node('span', statusName(record.status), `chada-status chada-status-${statusKey}`));
            if (record.protected) status.append(node('div', __('Protected recovery data', 'chada-aio'), 'description'));
            row.append(status);
            const actions = node('td');
            const button = (label, callback) => { const result = node('button', label, 'button chada-loader-button'); result.type = 'button'; result.addEventListener('click', safely(event => withButtonBusy(result, token => callback(token, event)))); actions.append(result); };
            if (record.operation === 'backup' && record.archive && config.providers.backup.includes(record.type)) button(__('Restore', 'chada-aio'), token => preview({ operation: 'restore', record_id: record.id }, token));
            button(record.operation === 'export' ? __('View and download', 'chada-aio') : __('View details', 'chada-aio'), async token => {
                const detail = await request(`records/${record.id}`); const content = node('div');
                if (detail.download) { const link = node('a', __('Download verified package', 'chada-aio'), 'button button-primary'); link.href = detail.download; content.append(link); }
                if (detail.recovery_id) content.append(node('p', __('A protected recovery backup was created before this operation.', 'chada-aio'), 'chada-callout'));
                const printable = { ...detail };
                delete printable.download;
                if (printable.manifest) printable.manifest = { ...printable.manifest, entries: `${Object.keys(printable.manifest.entries || {}).length} verified file entries` };
                const details = node('pre', JSON.stringify(printable, null, 2)); details.className = 'chada-technical-details'; content.append(details);
                showDialog(__('Record details', 'chada-aio'), content, null, 'Confirm and apply', token);
            });
            button(__('Delete', 'chada-aio'), token => {
                showDialog(__('Delete record', 'chada-aio'), node('p', record.protected ? __('This is a protected recovery backup. Deleting it removes this recovery archive permanently. Continue only if you have another verified copy.', 'chada-aio') : __('Delete this record and its archive, if any? This does not delete imported website content.', 'chada-aio')), async () => {
                    await request(`records/${record.id}/delete`, { confirm: 'DELETE', protected: !!record.protected }); await loadGrid(grid);
                }, __('Delete permanently', 'chada-aio'), token);
            });
            row.append(actions); body.append(row);
        }
        $('.chada-page', grid).textContent = `Page ${data.page} of ${data.pages} · ${data.total} records`;
        $('.chada-prev', grid).disabled = data.page <= 1;
        $('.chada-next', grid).disabled = data.page >= data.pages;
    }
    async function refreshGrids() { for (const grid of grids) await loadGrid(grid); }
    grids.forEach(grid => {
        $('.chada-refresh', grid).addEventListener('click', safely(event => withButtonBusy(event.currentTarget, () => loadGrid(grid))));
        $('.chada-filter', grid).addEventListener('change', safely(() => { grid.dataset.page = 1; return loadGrid(grid); }));
        $('.chada-prev', grid).addEventListener('click', safely(event => withButtonBusy(event.currentTarget, () => { grid.dataset.page = Math.max(1, Number(grid.dataset.page || 1) - 1); return loadGrid(grid); })));
        $('.chada-next', grid).addEventListener('click', safely(event => withButtonBusy(event.currentTarget, () => { grid.dataset.page = Number(grid.dataset.page || 1) + 1; return loadGrid(grid); })));
    });
    const importForm = $('#chada-import');
    async function uploadChunkWithRetry(id, offset, chunk) {
        let lastError = null;
        for (let attempt = 1; attempt <= 3; attempt++) {
            try { return await request(`uploads/${id}?offset=${offset}`, chunk, { raw: true }); }
            catch (error) {
                lastError = error;
                try { const checkpoint = await request(`uploads/${id}/status`); if (Number(checkpoint.offset) > offset || checkpoint.sealed) return checkpoint; } catch { /* Retry the chunk when the status probe is also unavailable. */ }
                if (attempt < 3) await new Promise(resolve => setTimeout(resolve, attempt * 350));
            }
        }
        throw lastError || new Error(__('The upload could not be resumed.', 'chada-aio'));
    }
    if (importForm) importForm.addEventListener('submit', safely(async () => {
        if (!validateForm(importForm)) return;
        const archiveInput = $('[name=archive]', importForm); const file = archiveInput.files[0];
        if (!file || !/\.zip$/i.test(file.name)) { archiveInput.setCustomValidity(__('Choose a Chada ZIP archive.', 'chada-aio')); validateForm(importForm); return; }
        const button = $('button[type=submit]', importForm);
        await withButtonBusy(button, async token => {
            const uploadProgress = $('#chada-upload-progress');
            const uploadKey = `chada-aio-upload:${location.origin}:${config.rest}`;
            const fingerprint = { name: file.name, size: file.size, last_modified: file.lastModified || 0 };
            let saved = null;
            let uploadId = '';
            try { saved = JSON.parse(localStorage.getItem(uploadKey) || 'null'); } catch { saved = null; }
            try {
                let upload = null;
                if (saved && saved.size === file.size && saved.name === file.name && Number(saved.last_modified || 0) === Number(file.lastModified || 0) && saved.id) { try { upload = await request(`uploads/${saved.id}/status`); } catch { upload = null; } }
                if (!upload) upload = await request('uploads', { ...fingerprint, resume_id: saved && saved.size === file.size ? saved.id : '' });
                uploadId = upload.id;
                try { localStorage.setItem(uploadKey, JSON.stringify({ ...fingerprint, id: upload.id })); } catch { /* Local storage is optional. */ }
                if (uploadProgress) { uploadProgress.hidden = false; uploadProgress.value = 0; uploadProgress.setAttribute('aria-valuenow', '0'); }
                let offset = Number(upload.offset || 0);
                const initialPercent = Math.min(100, Math.round(offset / file.size * 100));
                if (uploadProgress) { uploadProgress.value = initialPercent; uploadProgress.setAttribute('aria-valuenow', String(initialPercent)); }
                $('#chada-upload-status').textContent = `${offset ? __('Resuming', 'chada-aio') : __('Uploading', 'chada-aio')} ${file.name} · ${initialPercent}%`;
                while (offset < file.size) {
                    const previous = offset; const checkpoint = await uploadChunkWithRetry(upload.id, offset, file.slice(offset, offset + upload.chunk_size));
                    offset = Number(checkpoint.offset);
                    if (!Number.isInteger(offset) || offset <= previous || offset > file.size) throw new Error(__('The upload returned an invalid durable checkpoint.', 'chada-aio'));
                    const percent = Math.min(100, Math.round(offset / file.size * 100));
                    if (uploadProgress) { uploadProgress.value = percent; uploadProgress.setAttribute('aria-valuenow', String(percent)); }
                    $('#chada-upload-status').textContent = `${__('Uploading', 'chada-aio')} ${file.name} · ${percent}%`;
                }
                try { localStorage.removeItem(uploadKey); } catch { /* Local storage is optional. */ }
                $('#chada-upload-status').textContent = __('Upload complete. Reviewing the package…', 'chada-aio');
                await preview({ operation: 'import', upload_id: upload.id, overwrite: $('[name=overwrite]', importForm).checked }, token);
            } catch (error) {
                try { localStorage.setItem(uploadKey, JSON.stringify({ ...fingerprint, id: uploadId || saved?.id || null })); } catch { /* Local storage is optional. */ }
                throw error;
            }
        });
    }));
    const cleanup = $('#chada-cleanup');
    if (cleanup) {
        const categoryInputs = $$('[name=category]', cleanup);
        const validateCategories = () => {
            const valid = categoryInputs.some(input => input.checked);
            if (categoryInputs[0]) categoryInputs[0].setCustomValidity(valid ? '' : __('Select at least one cleanup category.', 'chada-aio'));
        };
        categoryInputs.forEach(input => input.addEventListener('change', validateCategories));
        clearInvalid(cleanup);
        cleanup.addEventListener('submit', safely(() => {
            validateCategories();
            if (!validateForm(cleanup)) return;
            return withButtonBusy($('button[type=submit]', cleanup), token => preview({ operation: 'cleanup', categories: categoryInputs.filter(input => input.checked).map(input => input.value) }, token));
        }));
    }
    const undo = $('#chada-undo');
    if (undo) undo.addEventListener('submit', safely(() => {
        if (!validateForm(undo)) return;
        const id = Number($('[name=id]', undo).value);
        return withButtonBusy($('button[type=submit]', undo), token => showDialog(__('Restore original image', 'chada-aio'), node('p', __('Replace this image and its derivatives with the retained original files and metadata?', 'chada-aio')), async () => {
            await request(`images/${id}/undo`, { confirm: 'UNDO' }); notice(__('Original image restored.', 'chada-aio'));
        }, 'Restore original', token));
    }));
    if (importForm) { const archiveInput = $('[name=archive]', importForm); archiveInput.addEventListener('change', () => archiveInput.setCustomValidity('')); clearInvalid(importForm); }
    $$('.chada-settings, .chada-wizard-step').forEach(bindResolution);
    $$('.chada-settings').forEach(form => {
        clearInvalid(form);
        form.addEventListener('submit', safely(async () => {
            if (!validateForm(form, __('Please correct the highlighted settings before saving.', 'chada-aio'))) return;
            const button = $('.chada-settings-submit', form); const status = $('.chada-save-status', form); const label = button.textContent;
            button.disabled = true; button.textContent = __('Saving…', 'chada-aio'); if (status) status.textContent = __('Saving changes…', 'chada-aio');
            try { await request(form.dataset.endpoint || 'settings', { tab: form.dataset.tab, values: values(form) }); if (status) status.textContent = __('Saved just now. Other tabs were preserved.', 'chada-aio'); notice(__('Settings saved. Other tabs were preserved.', 'chada-aio')); }
            catch (error) { if (status) status.textContent = error.message; throw error; }
            finally { button.disabled = false; button.textContent = label; }
        }));
    });

    // Instructions tabs: all content stays readable when JavaScript is unavailable.
    const tabs = $$('.chada-tabs [role=tab]');
    function activateTab(id) {
        const active = tabs.find(tab => tab.getAttribute('aria-controls') === id) || tabs[0];
        if (!active) return;
        tabs.forEach(tab => { const selected = tab === active; tab.setAttribute('aria-selected', selected); tab.tabIndex = selected ? 0 : -1; $(`#${tab.getAttribute('aria-controls')}`).hidden = !selected; });
    }
    tabs.forEach((tab, index) => {
        tab.addEventListener('click', () => activateTab(tab.getAttribute('aria-controls')));
        tab.addEventListener('keydown', event => {
            if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
            event.preventDefault();
            let next = event.key === 'Home' ? 0 : event.key === 'End' ? tabs.length - 1 : (index + (event.key === 'ArrowRight' ? 1 : -1) + tabs.length) % tabs.length;
            tabs[next].focus(); tabs[next].click();
        });
    });
    activateTab(location.hash.slice(1));
    window.addEventListener('hashchange', () => activateTab(location.hash.slice(1)));

    // Setup uses the same tab validators and never starts a site operation.
    const wizard = $('#chada-wizard');
    if (wizard) {
        let wizardStep = Math.min(3, Number(config.wizard?.step || 0));
        const wizardLabels = $$('[data-wizard-label]', wizard);
        $$('.chada-wizard-step', wizard).forEach(clearInvalid);
        function drawWizard() {
            $$('.chada-wizard-step', wizard).forEach(panel => { panel.hidden = Number(panel.dataset.step) !== wizardStep; });
            wizardLabels.forEach((step, index) => { step.classList.toggle('is-current', index === wizardStep); step.classList.toggle('is-complete', index < wizardStep); });
            $('#chada-wizard-progress').textContent = `${__('Step', 'chada-aio')} ${wizardStep + 1} ${__('of', 'chada-aio')} 4 · ${wizardLabels[wizardStep]?.textContent || ''}`;
            $('#chada-wizard-prev').disabled = wizardStep === 0;
            $('#chada-wizard-next').textContent = wizardStep === 3 ? __('Finish setup', 'chada-aio') : __('Save and next', 'chada-aio');
        }
        $('#chada-wizard-open').addEventListener('click', () => { drawWizard(); wizard.showModal(); });
        $('#chada-wizard-close').addEventListener('click', () => wizard.close());
        $('#chada-wizard-prev').addEventListener('click', safely(async () => {
            wizardStep = Math.max(0, wizardStep - 1); await request('user-state', { kind: 'wizard', step: wizardStep }); drawWizard();
        }));
        $('#chada-wizard-next').addEventListener('click', safely(async () => {
            const panel = $(`.chada-wizard-step[data-step="${wizardStep}"]`, wizard);
            if (wizardStep < 3) {
                if (!validateForm(panel, __('Please correct the highlighted settings before continuing.', 'chada-aio'))) return;
                await request('settings', { tab: panel.dataset.tab, values: values(panel) }); wizardStep++;
                await request('user-state', { kind: 'wizard', step: wizardStep });
                if (wizardStep === 3) { const status = await request('status'); const errors = [...(status.settings_errors || [])]; if (!status.zip) errors.push({ label: __('PHP ZIP', 'chada-aio'), message: __('The PHP ZIP extension is required before completing setup.', 'chada-aio') }); $('#chada-wizard-readiness').textContent = errors.length ? errors.map(error => `${error.label}: ${error.message}`).join(' ') : __('Protected storage and required settings are ready.', 'chada-aio'); }
                drawWizard();
            } else { await request('user-state', { kind: 'wizard', step: 0, complete: true }); wizard.close(); notice(__('Setup complete. Create and test a backup when ready.', 'chada-aio')); }
        }));
        if (config.autoOpenWizard) { drawWizard(); wizard.showModal(); }
    }

    // Original local walkthrough: guidance only, with persisted per-administrator position.
    const tour = $('#chada-tour');
    const proAvailable = !!config.proAvailable;
    const optimizationTourText = proAvailable ? 'Select a repeatable image batch. Original files are retained for undo; additional library and date selection are available.' : 'Select a repeatable image batch. Original files are retained for undo.';
    const settingsTourText = 'Each tab saves independently. Review retention controls before creating history.';
    const featuresTourText = proAvailable ? 'The comparison identifies the complete Free workflow and the additional providers available with Pro.' : 'This page lists the complete workflow included with the Free plugin.';
    const tourSteps = [
        ['dashboard', 'dashboard', 'Dashboard', 'Review protected storage, backup history, migration activity, and the recommended next step.'],
        ['backup', 'backup', 'Create a backup', 'Choose an available backup provider. Advanced date, theme, and plugin options appear only when they apply.'],
        ['backup', 'records', 'Backup records', 'Restore previews changes and creates recovery first. View details contains checksums and protected downloads.'],
        ['migration', 'migration', 'Migration', 'Create a migration package on the source site, then upload and review it on the destination site.'],
        ['optimization', 'optimization', 'Image optimization', optimizationTourText],
        ['optimization', 'cleanup', 'Database cleanup', 'Preview explicitly selected disposable records. Unknown tables are never automatically dropped.'],
        ['settings', 'settings', 'Settings tabs', settingsTourText],
        ['settings', 'wizard', 'Setup Wizard', 'Activation opens a guided setup reminder; administrators can rerun it manually. Setup never starts destructive operations.'],
        ['features', 'features', 'Features and editions', featuresTourText],
        ['instructions', 'instructions', 'Instructions', 'Return here for workflows, recovery guidance, troubleshooting and this walkthrough.']
    ];
    let tourStep = Math.min(tourSteps.length - 1, Number(config.tour?.step || 0));
    async function showTour() {
        if (tourStep >= tourSteps.length) {
            await request('user-state', { kind: 'tour', step: 0, complete: true });
            tour.close(); notice(__('Walkthrough complete. You can replay it from Instructions.', 'chada-aio')); return;
        }
        const [page, anchor, title, text] = tourSteps[tourStep];
        if (config.page !== page) { location.assign(`${config.admin}${page}&tour=1`); return; }
        const target = $(`[data-tour="${anchor}"]`);
        if (!target) { tourStep++; await request('user-state', { kind: 'tour', step: tourStep }); return showTour(); }
        $$('.chada-tour-target').forEach(element => element.classList.remove('chada-tour-target'));
        target.classList.add('chada-tour-target'); target.scrollIntoView({ block: 'center', behavior: 'instant' });
        $('#chada-tour-count').textContent = `Step ${tourStep + 1} of ${tourSteps.length}`;
        $('#chada-tour-title').textContent = title; $('#chada-tour-text').textContent = text;
        $('#chada-tour-prev').disabled = tourStep === 0;
        $('#chada-tour-next').textContent = tourStep === tourSteps.length - 1 ? 'Finish' : 'Next';
        if (!tour.open) tour.showModal();
    }
    $('#chada-tour-next').addEventListener('click', safely(async () => { tourStep++; await request('user-state', { kind: 'tour', step: tourStep }); await showTour(); }));
    $('#chada-tour-prev').addEventListener('click', safely(async () => { tourStep = Math.max(0, tourStep - 1); await request('user-state', { kind: 'tour', step: tourStep }); await showTour(); }));
    $('#chada-tour-close').addEventListener('click', () => { tour.close(); $$('.chada-tour-target').forEach(element => element.classList.remove('chada-tour-target')); });
    tour.addEventListener('close', () => $$('.chada-tour-target').forEach(element => element.classList.remove('chada-tour-target')));
    for (const [selector, restart] of [['#chada-tour-start', true], ['#chada-tour-resume', false]]) {
        const button = $(selector);
        if (button) button.addEventListener('click', safely(async () => { if (restart) tourStep = 0; await request('user-state', { kind: 'tour', step: tourStep }); await showTour(); }));
    }
    void (async () => {
        try { const status = await request('status'); showJob(status.active, false); await refreshGrids(); }
        catch (error) { notice(error.message, true); }
        if (new URL(location.href).searchParams.get('tour') === '1') { try { await showTour(); } catch (error) { notice(error.message, true); } }
    })();
})();
