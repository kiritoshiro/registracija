(function () {
    'use strict';

    var STORAGE_KEY = 'kir_owner_token_v1';
    var runtimeOwnerToken = '';

    function getConfig(wrap) {
        var node = wrap ? wrap.querySelector('.kir-config') : null;
        if (node) {
            try {
                return JSON.parse(node.textContent || '{}');
            } catch (e) {}
        }
        if (typeof window.KIR_DATA === 'object' && window.KIR_DATA) {
            return window.KIR_DATA;
        }
        return {};
    }

    function isValidOwnerToken(token) {
        return /^[a-f0-9]{64}$/i.test(String(token || ''));
    }

    function getOwnerToken() {
        if (isValidOwnerToken(runtimeOwnerToken)) {
            return runtimeOwnerToken;
        }
        try {
            var stored = window.localStorage.getItem(STORAGE_KEY) || '';
            if (isValidOwnerToken(stored)) {
                runtimeOwnerToken = stored;
                return stored;
            }
        } catch (e) {}
        return '';
    }

    function saveOwnerToken(token) {
        if (!isValidOwnerToken(token)) {
            return;
        }
        runtimeOwnerToken = token;
        try {
            window.localStorage.setItem(STORAGE_KEY, token);
        } catch (e) {}
    }

    function clearOwnerToken() {
        runtimeOwnerToken = '';
        try {
            window.localStorage.removeItem(STORAGE_KEY);
        } catch (e) {}
    }

    function post(config, data) {
        if (!config.ajaxUrl) {
            return Promise.reject(new Error(config.invalidMessage || 'Ryšio klaida.'));
        }
        return fetch(config.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
            },
            body: new URLSearchParams(data).toString()
        }).then(function (response) {
            return response.text().then(function (text) {
                var json;
                try {
                    json = JSON.parse(text);
                } catch (e) {
                    throw new Error(config.invalidMessage || 'Ryšio klaida.');
                }
                return { ok: response.ok, json: json };
            });
        });
    }

    function setMessage(form, text, type) {
        var box = form.querySelector('.kir-message');
        if (!box) {
            return;
        }
        box.textContent = text || '';
        box.classList.remove('is-success', 'is-error');
        if (type) {
            box.classList.add(type === 'success' ? 'is-success' : 'is-error');
        }
    }

    function markCongregationFull(select, congregation, config) {
        Array.prototype.forEach.call(select.options, function (option) {
            if (option.value === congregation) {
                option.disabled = true;
                var base = option.getAttribute('data-base-label') || option.textContent;
                option.textContent = base + ' — ' + (config.fullSuffix || 'visos vietos pasirinktos');
            }
        });
    }

    function markCongregationAvailable(select, congregation) {
        Array.prototype.forEach.call(select.options, function (option) {
            if (option.value === congregation) {
                option.disabled = false;
                var base = option.getAttribute('data-base-label');
                if (base) {
                    option.textContent = base;
                }
            }
        });
    }

    function getSelectedChapters(form) {
        return Array.prototype.map.call(
            form.querySelectorAll('.kir-chapter-checkbox:checked'),
            function (input) { return input.value; }
        );
    }

    function setChaptersPlaceholder(container, text) {
        container.innerHTML = '';
        var p = document.createElement('p');
        p.className = 'kir-chapters-placeholder';
        p.textContent = text;
        container.appendChild(p);
    }

    function summarizeChapters(chapters) {
        chapters = chapters || [];
        var free = chapters.filter(function (chapter) { return !chapter.reserved; }).length;
        var numbers = chapters.map(function (chapter) { return parseInt(chapter.number, 10); }).filter(Boolean);
        var range = '';
        if (numbers.length === 1) {
            range = String(numbers[0]);
        } else if (numbers.length > 1) {
            range = numbers[0] + '–' + numbers[numbers.length - 1];
        }
        return {
            chapters: chapters,
            count: chapters.length,
            free: free,
            range: range,
            full: chapters.length > 0 && free === 0
        };
    }

    function renderChapters(form, data, config) {
        var container = form.querySelector('.kir-chapters');
        container.innerHTML = '';

        (data.chapters || []).forEach(function (chapter) {
            var row = document.createElement('div');
            row.className = 'kir-chapter-option' + (chapter.reserved ? ' is-reserved' : '');

            var info = document.createElement('div');
            info.className = 'kir-chapter-info';

            var strong = document.createElement('strong');
            strong.textContent = String(chapter.number) + ' skyrius';
            info.appendChild(strong);

            var title = document.createElement('span');
            title.className = 'kir-chapter-title';
            title.textContent = chapter.title || '';
            info.appendChild(title);

            var actions = document.createElement('div');
            actions.className = 'kir-chapter-actions';

            var selectLabel = document.createElement('label');
            selectLabel.className = 'kir-select-control';

            var input = document.createElement('input');
            input.type = 'checkbox';
            input.className = 'kir-chapter-checkbox';
            input.name = 'chapters[]';
            input.value = String(chapter.number);
            input.disabled = !!chapter.reserved;

            var selectText = document.createElement('span');
            selectText.textContent = chapter.reserved ? (config.reservedSuffix || 'jau pasirinktas') : (config.selectChapterLabel || 'Pasirinkti');

            selectLabel.appendChild(input);
            selectLabel.appendChild(selectText);

            actions.appendChild(selectLabel);

            row.appendChild(info);
            row.appendChild(actions);
            container.appendChild(row);
        });
    }

    function updateChapterUi(form, data, congregation, config) {
        var select = form.querySelector('.kir-congregation');
        var info = form.querySelector('.kir-congregation-info');
        var submit = form.querySelector('.kir-submit');

        renderChapters(form, data, config);
        if (info) {
            info.textContent = 'Skyriai: ' + data.range + ' · Laisva: ' + data.free + ' iš ' + data.count;
        }

        if (data.full) {
            markCongregationFull(select, congregation, config);
            submit.disabled = true;
        } else {
            markCongregationAvailable(select, congregation);
            submit.disabled = false;
        }
    }

    function localChapterData(config, congregation) {
        var map = config.chaptersByCongregation || {};
        if (!Object.prototype.hasOwnProperty.call(map, congregation)) {
            return null;
        }
        return summarizeChapters(map[congregation]);
    }

    function replaceLocalChapterData(config, congregation, chapters) {
        if (!config.chaptersByCongregation) {
            config.chaptersByCongregation = {};
        }
        config.chaptersByCongregation[congregation] = (chapters || []).map(function (item) {
            return {
                number: parseInt(item.number, 10),
                title: item.title || '',
                page: parseInt(item.page, 10) || 1,
                reserved: !!item.reserved
            };
        });
    }

    function markLocalReserved(config, congregation, chapterNumbers, reserved) {
        var map = config.chaptersByCongregation || {};
        var items = map[congregation] || [];
        var lookup = {};
        (chapterNumbers || []).forEach(function (number) {
            lookup[String(number)] = true;
        });
        items.forEach(function (item) {
            if (lookup[String(item.number)]) {
                item.reserved = !!reserved;
            }
        });
    }

    function renderMySelection(form, reservations) {
        var panel = form.querySelector('.kir-my-selection');
        var list = form.querySelector('.kir-my-selection-list');
        if (!panel || !list) {
            return;
        }

        list.innerHTML = '';
        if (!reservations || !reservations.length) {
            panel.hidden = true;
            return;
        }

        var ul = document.createElement('ul');
        ul.className = 'kir-my-selection-items';
        reservations.forEach(function (item) {
            var li = document.createElement('li');
            var text = String(item.chapter) + ' skyrius';
            if (item.title) {
                text += ' — ' + item.title;
            }
            if (item.congregation) {
                text += ' (' + item.congregation + ')';
            }
            li.textContent = text;
            ul.appendChild(li);
        });
        list.appendChild(ul);
        panel.hidden = false;
    }

    function loadMySelection(form, config) {
        var token = getOwnerToken();
        if (!token) {
            renderMySelection(form, []);
            return Promise.resolve();
        }

        return post(config, {
            action: 'kir_get_my_reservations',
            nonce: config.nonce,
            owner_token: token
        }).then(function (result) {
            if (!result.ok || !result.json.success) {
                return;
            }
            var data = result.json.data || {};
            if (!data.found || !data.reservations || !data.reservations.length) {
                clearOwnerToken();
                renderMySelection(form, []);
                return;
            }
            renderMySelection(form, data.reservations);
        }).catch(function () {
            // Laikino ryšio sutrikimo atveju naršyklės rakto netriname.
        });
    }

    function cancelMySelection(form, config) {
        var token = getOwnerToken();
        if (!token) {
            renderMySelection(form, []);
            return;
        }
        if (!window.confirm(config.cancelConfirm || 'Ar tikrai norite atsisakyti pasirinkimo?')) {
            return;
        }

        var button = form.querySelector('.kir-cancel-selection');
        var congregationSelect = form.querySelector('.kir-congregation');
        if (button) {
            button.disabled = true;
        }

        post(config, {
            action: 'kir_cancel_my_reservations',
            nonce: config.nonce,
            owner_token: token
        }).then(function (result) {
            if (!result.ok || !result.json.success) {
                throw new Error((result.json.data && result.json.data.message) || config.invalidMessage);
            }

            var data = result.json.data || {};
            clearOwnerToken();
            renderMySelection(form, []);
            setMessage(form, data.message || config.cancelSuccess || '', 'success');

            (data.congregations || []).forEach(function (congregation) {
                markCongregationAvailable(congregationSelect, congregation);
            });
            (data.released || []).forEach(function (item) {
                markLocalReserved(config, item.congregation, [item.chapter], false);
            });

            if (congregationSelect.value) {
                return loadChapters(form, congregationSelect.value, config);
            }
        }).catch(function (error) {
            setMessage(form, error.message || config.invalidMessage, 'error');
        }).finally(function () {
            if (button) {
                button.disabled = false;
            }
        });
    }

    function loadChapters(form, congregation, config) {
        var container = form.querySelector('.kir-chapters');
        var info = form.querySelector('.kir-congregation-info');
        var submit = form.querySelector('.kir-submit');

        if (!congregation) {
            submit.disabled = true;
            setChaptersPlaceholder(container, config.chooseChapter || 'Pirmiausia pasirinkite bendruomenę');
            if (info) {
                info.textContent = '';
            }
            return Promise.resolve();
        }

        // Pirmiausia parodome su puslapiu jau atsiųstą sąrašą. Dėl to pasirinkimas
        // veikia net jei admin-ajax laikinai blokuoja WAF, talpykla ar optimizavimo įskiepis.
        var local = localChapterData(config, congregation);
        if (local) {
            updateChapterUi(form, local, congregation, config);
        } else {
            submit.disabled = true;
            setChaptersPlaceholder(container, config.loadingText || 'Kraunama…');
            if (info) {
                info.textContent = '';
            }
        }

        // Tada tyliai pasitiksliname dabartinę rezervacijų būseną serveryje.
        return post(config, {
            action: 'kir_get_chapters',
            nonce: config.nonce,
            congregation: congregation
        }).then(function (result) {
            if (!result.ok || !result.json.success) {
                return;
            }
            var data = result.json.data || {};
            replaceLocalChapterData(config, congregation, data.chapters || []);
            updateChapterUi(form, data, congregation, config);
        }).catch(function () {
            // Vietinis sąrašas jau parodytas. Galutinė rezervacija vis tiek tikrinama DB.
        });
    }

    function initForm(form) {
        var wrap = form.closest('.kir-wrap');
        var config = getConfig(wrap);
        var congregationSelect = form.querySelector('.kir-congregation');
        var chaptersContainer = form.querySelector('.kir-chapters');
        var submit = form.querySelector('.kir-submit');
        var cancelButton = form.querySelector('.kir-cancel-selection');
        var normalSubmitText = submit.textContent;

        congregationSelect.addEventListener('change', function () {
            setMessage(form, '', '');
            loadChapters(form, congregationSelect.value, config);
        });

        if (cancelButton) {
            cancelButton.addEventListener('click', function () {
                cancelMySelection(form, config);
            });
        }

        form.addEventListener('submit', function (event) {
            event.preventDefault();
            setMessage(form, '', '');

            if (!form.checkValidity()) {
                form.reportValidity();
                return;
            }

            var selected = getSelectedChapters(form);
            if (!selected.length) {
                setMessage(form, config.selectionRequired || 'Pasirinkite bent vieną laisvą skyrių.', 'error');
                return;
            }

            var formData = new FormData(form);
            var congregation = formData.get('congregation');
            submit.disabled = true;
            submit.textContent = config.loadingText || 'Kraunama…';

            post(config, {
                action: 'kir_submit_reservation',
                nonce: config.nonce,
                full_name: formData.get('full_name') || '',
                email: formData.get('email') || '',
                congregation: congregation || '',
                chapters: selected.join(','),
                owner_token: getOwnerToken(),
                website: formData.get('website') || ''
            }).then(function (result) {
                if (!result.ok || !result.json.success) {
                    var message = (result.json.data && result.json.data.message) || config.invalidMessage;
                    throw new Error(message);
                }

                var data = result.json.data || {};
                setMessage(form, data.message || '', 'success');

                if (data.owner_token) {
                    saveOwnerToken(data.owner_token);
                }

                markLocalReserved(config, congregation, data.chapters || selected, true);
                if (data.congregation_full) {
                    markCongregationFull(congregationSelect, congregation, config);
                }

                return Promise.all([
                    loadChapters(form, congregation, config),
                    loadMySelection(form, config)
                ]);
            }).catch(function (error) {
                setMessage(form, error.message || config.invalidMessage || 'Registracijos išsaugoti nepavyko.', 'error');
                return loadChapters(form, congregation, config);
            }).finally(function () {
                submit.textContent = normalSubmitText;
                var available = form.querySelector('.kir-chapter-checkbox:not(:disabled)');
                submit.disabled = !congregationSelect.value || !available;
            });
        });

        loadMySelection(form, config);

        // Jei naršyklė/formos atstatymas paliko pasirinktą bendruomenę, sąrašą atvaizduojame iškart.
        if (congregationSelect.value) {
            loadChapters(form, congregationSelect.value, config);
        }
    }

    function initReader(wrap) {
        var card = wrap.querySelector('.kir-reader-card');
        var button = wrap.querySelector('.kir-reader-fullscreen');
        if (!card || !button) {
            return;
        }

        if (!card.requestFullscreen || !document.exitFullscreen) {
            button.hidden = true;
            return;
        }

        button.addEventListener('click', function () {
            if (document.fullscreenElement === card) {
                document.exitFullscreen().catch(function () {});
                return;
            }
            card.requestFullscreen().catch(function () {});
        });
    }

    function boot() {
        document.querySelectorAll('.kir-form').forEach(initForm);
        document.querySelectorAll('.kir-wrap').forEach(initReader);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
}());
