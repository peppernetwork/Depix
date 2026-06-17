/**
 * PlanBär — front-end JavaScript
 */
'use strict';

// -------------------------------------------------------------------------
// Inline editing of schedule entries (planung_view.php)
// -------------------------------------------------------------------------
document.addEventListener('DOMContentLoaded', function () {

    // Inline edit cells
    document.querySelectorAll('td.editable').forEach(function (cell) {
        cell.addEventListener('click', function () {
            if (cell.querySelector('input')) return; // Already editing

            var currentVal = cell.dataset.hours || '0';
            var revisionId = cell.dataset.revisionId;
            var employeeId = cell.dataset.employeeId;
            var entryDate  = cell.dataset.date;
            var csrfToken  = document.getElementById('csrf-token-value')
                             ? document.getElementById('csrf-token-value').value
                             : '';

            var originalHtml = cell.innerHTML;

            // Build inline input
            var input = document.createElement('input');
            input.type      = 'number';
            input.step      = '0.25';
            input.min       = '0';
            input.max       = '24';
            input.value     = currentVal;
            input.className = 'inline-edit';

            cell.innerHTML = '';
            cell.appendChild(input);
            input.focus();
            input.select();

            function save() {
                var newVal = parseFloat(input.value);
                if (isNaN(newVal) || newVal < 0) {
                    newVal = 0;
                }

                fetch('/api_eintrag.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({
                        csrf_token:  csrfToken,
                        revision_id: revisionId,
                        employee_id: employeeId,
                        entry_date:  entryDate,
                        hours:       newVal.toFixed(2)
                    })
                })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data.success) {
                        cell.dataset.hours = newVal.toFixed(2);
                        cell.textContent   = newVal > 0 ? newVal.toFixed(2) + ' h' : '—';
                        cell.classList.toggle('entry', newVal > 0);
                    } else {
                        cell.innerHTML = originalHtml;
                        alert('Fehler beim Speichern: ' + (data.error || 'Unbekannter Fehler'));
                    }
                })
                .catch(function () {
                    cell.innerHTML = originalHtml;
                    alert('Netzwerkfehler beim Speichern.');
                });
            }

            input.addEventListener('blur', save);
            input.addEventListener('keydown', function (e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    input.blur();
                }
                if (e.key === 'Escape') {
                    cell.innerHTML = originalHtml;
                }
            });
        });
    });

    // -------------------------------------------------------------------------
    // Zuteilung: multi-location/time segment modal (planung_view.php)
    // -------------------------------------------------------------------------
    var locSegModal = document.getElementById('locSegModal');
    if (locSegModal) {
        locSegModal.addEventListener('show.bs.modal', function (event) {
            var btn = event.relatedTarget;
            if (!btn) return;

            document.getElementById('locSegEmpName').textContent   = btn.dataset.employeeName || '';
            document.getElementById('locSegDateLabel').textContent = btn.dataset.dateLabel || '';
            document.getElementById('locSegEmployeeId').value      = btn.dataset.employeeId || '';
            document.getElementById('locSegEntryDate').value       = btn.dataset.date || '';

            var segments = [];
            try { segments = JSON.parse(btn.dataset.segments || '[]'); } catch (e) { segments = []; }

            var listEl = document.getElementById('locSegList');
            listEl.innerHTML = '';

            if (segments.length === 0) {
                var empty = document.createElement('div');
                empty.className = 'text-muted small';
                empty.textContent = 'Noch keine Orte zugeteilt.';
                listEl.appendChild(empty);
                return;
            }

            segments.forEach(function (seg) {
                var row = document.createElement('div');
                row.className = 'd-flex align-items-center justify-content-between mb-1 p-1 rounded';
                row.style.background = '#f1f3f5';

                var label = document.createElement('span');

                var badge = document.createElement('span');
                badge.className = 'badge me-2';
                badge.style.background = seg.location_color || '#6c757d';
                badge.textContent = seg.location_name || '';
                label.appendChild(badge);

                var text = document.createElement('span');
                text.className = 'small';
                text.textContent = (seg.shift_short_name ? seg.shift_short_name + ' · ' : '')
                                    + seg.time_start + '–' + seg.time_end;
                label.appendChild(text);

                row.appendChild(label);

                var delBtn = document.createElement('button');
                delBtn.type = 'button';
                delBtn.className = 'btn btn-sm btn-outline-danger py-0 px-1';
                var delIcon = document.createElement('i');
                delIcon.className = 'bi bi-trash3';
                delBtn.appendChild(delIcon);
                delBtn.addEventListener('click', function () {
                    if (!confirm('Diese Zuteilung wirklich entfernen?')) return;
                    document.getElementById('locSegDeleteId').value = seg.id;
                    document.getElementById('locSegDeleteForm').submit();
                });
                row.appendChild(delBtn);

                listEl.appendChild(row);
            });
        });

        var locSegShift = document.getElementById('locSegShift');
        if (locSegShift) {
            locSegShift.addEventListener('change', function () {
                var opt = locSegShift.options[locSegShift.selectedIndex];
                if (opt && opt.dataset.start) {
                    document.getElementById('locSegTimeStart').value = opt.dataset.start;
                    document.getElementById('locSegTimeEnd').value   = opt.dataset.end;
                }
            });
        }
    }

    // -------------------------------------------------------------------------
    // Auto-dismiss flash alerts after 5 s
    // -------------------------------------------------------------------------
    document.querySelectorAll('.alert[role="alert"]').forEach(function (el) {
        setTimeout(function () {
            var bsAlert = bootstrap.Alert.getOrCreateInstance(el);
            if (bsAlert) bsAlert.close();
        }, 5000);
    });

    // -------------------------------------------------------------------------
    // Confirm destructive actions
    // -------------------------------------------------------------------------
    document.querySelectorAll('[data-confirm]').forEach(function (el) {
        el.addEventListener('click', function (e) {
            if (!confirm(el.dataset.confirm)) {
                e.preventDefault();
            }
        });
    });

    // -------------------------------------------------------------------------
    // Available days checkboxes: keep at least one checked
    // -------------------------------------------------------------------------
    var dayBoxes = document.querySelectorAll('.day-check');
    if (dayBoxes.length > 0) {
        dayBoxes.forEach(function (box) {
            box.addEventListener('change', function () {
                var checked = document.querySelectorAll('.day-check:checked');
                if (checked.length === 0) {
                    box.checked = true;
                }
            });
        });
    }
});
