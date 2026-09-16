(function () {
    'use strict';

    var form = document.getElementById('kir-reservation-status-form');
    if (!form) {
        return;
    }

    var autoSaveControls = document.querySelectorAll('[form="' + form.id + '"][data-kir-auto-save="1"]');
    Array.prototype.forEach.call(autoSaveControls, function (control) {
        control.addEventListener('change', function () {
            if (form.getAttribute('data-kir-saving') === '1') {
                return;
            }

            form.setAttribute('data-kir-saving', '1');
            form.submit();
        });
    });
}());
