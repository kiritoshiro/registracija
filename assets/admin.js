(function () {
    'use strict';

    var form = document.getElementById('kir-reservation-status-form');
    var config = window.kirAdmin;
    if (!form || !config || !window.fetch || !window.FormData) {
        return;
    }

    var statusEl = document.querySelector('.kir-autosave-status');
    var pending = 0;

    function setStatus(text, isError) {
        if (!statusEl) {
            return;
        }
        statusEl.textContent = text;
        statusEl.classList.toggle('is-error', !!isError);
    }

    // Each checkbox saves on its own over AJAX, so the page does not reload and
    // several can be ticked in a row. One request per checkbox is in flight at a
    // time; a click during it is sent once it finishes, so the last state wins.
    function save(control) {
        var state = control.kirState;
        var value = control.checked ? 1 : 0;

        state.busy = true;
        state.sent = value;
        pending++;
        control.closest('.kir-status-toggle').classList.add('is-saving');
        setStatus(config.saving, false);

        var data = new FormData();
        data.append('action', 'kir_update_reservation_status');
        data.append('nonce', config.nonce);
        data.append('id', control.getAttribute('data-kir-id'));
        data.append('field', control.getAttribute('data-kir-field'));
        data.append('value', String(value));

        fetch(config.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: data })
            .then(function (response) {
                return response.json().catch(function () {
                    return null;
                });
            })
            .then(function (json) {
                if (!json || !json.success) {
                    throw new Error(json && json.data && json.data.message ? json.data.message : config.failed);
                }
                state.saved = value;
                return null;
            })
            .catch(function (error) {
                return error && error.message ? error.message : config.failed;
            })
            .then(function (errorMessage) {
                state.busy = false;
                pending--;
                control.closest('.kir-status-toggle').classList.remove('is-saving');

                if (errorMessage) {
                    control.checked = state.saved === 1;
                    setStatus(errorMessage, true);
                    return;
                }
                if ((control.checked ? 1 : 0) !== state.sent) {
                    save(control);
                    return;
                }
                if (pending === 0) {
                    setStatus(config.saved, false);
                }
            });
    }

    var autoSaveControls = document.querySelectorAll('[form="' + form.id + '"][data-kir-auto-save="1"]');
    Array.prototype.forEach.call(autoSaveControls, function (control) {
        control.kirState = { busy: false, saved: control.checked ? 1 : 0, sent: null };
        control.addEventListener('change', function () {
            if (!control.kirState.busy) {
                save(control);
            }
        });
    });
}());
