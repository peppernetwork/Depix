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

                fetch('/planbear/public/api_eintrag.php', {
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
