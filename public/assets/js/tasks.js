// Inline editing for project tasks: each field saves on change and updates status badges.
(function () {
    const head = document.querySelector('[data-project]');
    if (!head) return;
    const projectId = head.dataset.project;
    const csrf = head.dataset.csrf;
    const phaseClasses = ['pre_install', 'installation', 'closeout', 'complete', 'cancelled'];

    async function save(row, field, value) {
        const body = new URLSearchParams({ _csrf: csrf, field, value });
        row.classList.add('is-saving');
        try {
            const res = await fetch('/projects/' + projectId + '/tasks/' + row.dataset.id, {
                method: 'POST', body, headers: { 'Accept': 'application/json' }, credentials: 'same-origin',
            });
            const data = await res.json().catch(() => ({ ok: false, error: 'Server error' }));
            if (!data.ok) throw new Error(data.error || 'Save failed');
            applyStatus(data);
            flashRow(row, 'saved');
            return data;
        } catch (err) {
            flashRow(row, 'error');
            alert('Not saved: ' + err.message);
            throw err;
        } finally {
            row.classList.remove('is-saving');
        }
    }

    function flashRow(row, cls) {
        row.classList.add('is-' + cls);
        setTimeout(() => row.classList.remove('is-' + cls), 1200);
    }

    function applyStatus(d) {
        Object.entries(d.resolved || {}).forEach(([id, ok]) => {
            const r = document.querySelector('.trow[data-id="' + id + '"]');
            if (r) r.classList.toggle('is-resolved', !!ok);
        });
        const badge = document.getElementById('phase-badge');
        if (badge && d.phase) {
            phaseClasses.forEach((c) => badge.classList.remove('phase-' + c));
            badge.classList.add('phase-' + d.phase);
            badge.textContent = d.phase_label;
        }
        const clear = document.getElementById('clear-badge');
        if (clear) clear.hidden = !(d.phase === 'pre_install' && d.clear_to_install);
        const days = document.getElementById('days-badge');
        if (days && d.days !== null && d.days !== undefined) {
            days.hidden = false;
            document.getElementById('days-num').textContent = d.days;
        }
    }

    // Target date highlight: past due with no done date (cream), 30+ days past due (red).
    function localToday() {
        const d = new Date();
        return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
    }
    function markOverdue(row) {
        const target = row.querySelector('[data-field="target_date"]');
        if (!target) return;
        const done = row.querySelector('[data-field="done_date"]');
        const needed = row.querySelector('[data-field="needed"]') || { value: (row.querySelector('.needed-cell') || {}).dataset?.needed ?? '' };
        let cls = '';
        const today = localToday();
        if (target.value && target.value < today && !(done && done.value)
            && !(needed && needed.value === '0') && !row.classList.contains('parent-na')) {
            const days = Math.round((Date.parse(today) - Date.parse(target.value)) / 86400000);
            cls = days >= 30 ? 'is-overdue-30' : 'is-overdue';
        }
        target.classList.remove('is-overdue', 'is-overdue-30');
        if (cls) target.classList.add(cls);
        target.title = cls ? 'Past target date' + (cls === 'is-overdue-30' ? ' by 30+ days' : '') : '';
    }

    document.querySelectorAll('.trow[data-id]').forEach((row) => {
        row.querySelectorAll('[data-field]').forEach((el) => {
            el.addEventListener('change', async () => {
                const field = el.dataset.field;
                try {
                    await save(row, field, el.value);
                    if (field === 'needed') {
                        row.classList.toggle('is-na', el.value === '0');
                        if (row.classList.contains('trow-task')) {
                            row.closest('.task').querySelectorAll('.trow-sub').forEach((s) => { s.classList.toggle('parent-na', el.value === '0'); markOverdue(s); });
                        }
                    }
                    if (['needed', 'target_date', 'done_date'].includes(field)) markOverdue(row);
                } catch (e) { /* alert already shown */ }
            });
        });
    });

    // Menu actions
    document.addEventListener('click', async (e) => {
        const btn = e.target.closest('[data-action]');
        if (!btn) return;
        const row = btn.closest('.trow');
        btn.closest('details')?.removeAttribute('open');
        if (btn.dataset.action === 'rename') {
            const name = prompt('Rename to:', btn.dataset.name);
            if (name && name.trim() && name !== btn.dataset.name) {
                await save(row, 'name', name.trim());
                location.reload();
            }
        }
        if (btn.dataset.action === 'set-needed') {
            await save(row, 'needed', btn.dataset.value);
            location.reload();
        }
        if (btn.dataset.action === 'add-sub') {
            const name = prompt('New sub-task name:');
            if (name && name.trim()) {
                const f = document.getElementById('add-sub-form');
                f.elements['parent_id'].value = btn.dataset.parent;
                f.elements['name'].value = name.trim();
                f.submit();
            }
        }
    });

    // Close other open menus when one opens
    document.querySelectorAll('details.tmenu').forEach((d) => {
        d.addEventListener('toggle', () => {
            if (d.open) document.querySelectorAll('details.tmenu[open]').forEach((o) => { if (o !== d) o.removeAttribute('open'); });
        });
    });

    // Sub-tasks under a "No" task look disabled
    document.querySelectorAll('.task').forEach((t) => {
        const sel = t.querySelector('.trow-task [data-field="needed"]') || { value: (t.querySelector('.trow-task .needed-cell') || {}).dataset?.needed ?? '' };
        if (sel && sel.value === '0') t.querySelectorAll('.trow-sub').forEach((s) => s.classList.add('parent-na'));
    });

    document.querySelectorAll('.trow[data-id]').forEach(markOverdue);

    // Sticky column headers sit just under the sticky top bar, whatever its height.
    const topbar = document.querySelector('.topbar');
    function setTopbarHeight() {
        if (topbar) document.documentElement.style.setProperty('--topbar-h', topbar.offsetHeight + 'px');
    }
    setTopbarHeight();
    window.addEventListener('resize', setTopbarHeight);
})();
