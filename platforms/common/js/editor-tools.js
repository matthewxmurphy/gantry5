/* Gantry editor tools. Copyright 2026 Matthew C. Murphy. MIT. */
(function () {
    'use strict';
    function pageLocation() {
        if (window.GANTRY_NATIVE_API === true) {
            var history = window.GANTRY_ADMIN_HISTORY;
            if (!history || typeof history.url !== 'string') { throw new Error('No native editor URL'); }
            var url = new URL(history.url);
            if (!/^https?:$/.test(url.protocol) || url.origin !== window.parent.location.origin || url.username || url.password) {
                throw new Error('Unsupported native editor origin');
            }
            return url;
        }
        return window.location;
    }
    function associateStyles() {
        document.querySelectorAll('[data-section-style-pane]').forEach(function (pane) {
            var id = pane.getAttribute('data-section-style-pane');
            pane.querySelectorAll('input,select,textarea,button').forEach(function (control) {
                if (control.getAttribute('form') !== id) { control.setAttribute('form', id); }
                if (control.type === 'submit') { control.type = 'button'; }
            });
        });
    }
    function ajaxURL(action) {
        var current = pageLocation();
        var url = new URL(action, current.href);
        if (url.origin !== current.origin) { throw new Error('Nonlocal action'); }
        var suffix = window.GANTRY_AJAX_SUFFIX || '';
        if (suffix.charAt(0) === '.') {
            if (!url.pathname.endsWith(suffix)) { url.pathname += suffix; }
        } else if (suffix) {
            new URLSearchParams(suffix.replace(/^[?&]/, '')).forEach(function (value, key) { url.searchParams.set(key, value); });
        }
        return url;
    }
    function overrideEnabled(control) {
        var param = control.closest('.settings-param');
        var toggle = param && Array.from(param.children).find(function (child) { return child.matches('input.settings-param-toggle'); });
        if (!toggle && control.getAttribute('data-override-target')) {
            toggle = document.querySelector(control.getAttribute('data-override-target'));
        }
        return !toggle || toggle.checked;
    }
    async function saveStyles(form) {
        var pane = Array.from(document.querySelectorAll('[data-section-style-pane]')).find(function (item) {
            return item.getAttribute('data-section-style-pane') === form.id;
        });
        if (!pane || form.getAttribute('aria-busy') === 'true') { return; }
        associateStyles();
        var status = pane.querySelector('[data-section-style-status]');
        var button = pane.querySelector('[data-section-style-save]');
        var body = new URLSearchParams();
        var controls = Array.from(form.elements);
        for (var i = 0; i < controls.length; ++i) {
            var input = controls[i];
            if (!input.name || input.disabled || !overrideEnabled(input) || (input.type === 'radio' && !input.checked)) { continue; }
            if (!input.checkValidity()) { input.reportValidity(); return; }
            if (input.type === 'select-multiple') {
                Array.from(input.selectedOptions).forEach(function (option) { body.append(input.name.endsWith('[]') ? input.name : input.name + '[]', option.value); });
            } else {
                body.append(input.name, input.type === 'checkbox' ? (input.checked ? '1' : '0') : input.value);
            }
        }
        form.setAttribute('aria-busy', 'true');
        button.disabled = true;
        status.textContent = 'Saving section styles…';
        try {
            var response = await fetch(ajaxURL(form.action).href, { method: 'POST', credentials: 'same-origin',
                headers: { Accept: 'application/json' }, body: body });
            var reply = await response.json();
            if (!response.ok || !reply.success) { throw new Error('Save unconfirmed'); }
            status.textContent = reply.warning ? 'Styles saved with compiler warnings. Review the Styles page. Your Layout draft is unchanged.'
                : 'Section styles saved. Your unsaved layout changes are unchanged.';
        } catch (error) {
            status.textContent = 'Save could not be confirmed. Your Layout draft is unchanged; review the Styles page before retrying.';
        } finally {
            form.removeAttribute('aria-busy');
            button.disabled = false;
        }
    }
    document.addEventListener('click', function (event) {
        associateStyles(); // Before LM reads outer form.elements, including newly inserted controls.
        var button = event.target.closest && event.target.closest('[data-section-style-save]');
        if (!button) { return; }
        event.preventDefault(); event.stopImmediatePropagation();
        var form = document.getElementById(button.getAttribute('data-section-style-save'));
        if (form && form.hasAttribute('data-section-style-form')) { saveStyles(form); }
    }, true);
    document.addEventListener('keydown', function (event) { if (event.key === 'Enter') { associateStyles(); } }, true);
    document.addEventListener('submit', function (event) {
        if (!event.target.hasAttribute('data-section-style-form')) { return; }
        event.preventDefault(); event.stopImmediatePropagation(); saveStyles(event.target);
    }, true);
    associateStyles();
    new MutationObserver(associateStyles).observe(document.documentElement, { childList: true, subtree: true });

    document.querySelectorAll('[data-saved-site-preview]').forEach(function (panel) {
        var frame = panel.querySelector('iframe');
        var status = panel.querySelector('[data-preview-status]');
        var url;
        try {
            var raw = panel.getAttribute('data-saved-site-preview');
            if (!raw || !raw.trim()) { throw new Error('No saved frontend URL'); }
            var current = pageLocation();
            url = new URL(raw, current.href);
            if (!/^https?:$/.test(url.protocol) || url.origin !== current.origin || url.username || url.password) { throw new Error('Unsupported origin'); }
            if (url.pathname === current.pathname && url.search === current.search) { throw new Error('Recursive preview URL'); }
        } catch (error) {
            status.textContent = 'A saved-page preview is unavailable for this site.';
            return;
        }
        var open = panel.querySelector('[data-preview-open]');
        open.href = url.href; open.hidden = false;
        frame.hidden = false;
        function load() { frame.src = url.href; status.textContent = 'Loading the saved page…'; }
        frame.addEventListener('load', function () { status.textContent = 'Saved-page navigation finished. Open the site separately if the preview does not appear.'; });
        panel.querySelector('[data-preview-reload]').addEventListener('click', load);
        panel.querySelectorAll('[data-preview-width]').forEach(function (button) {
            button.addEventListener('click', function () {
                var width = button.getAttribute('data-preview-width');
                if (!['1280', '768', '390'].includes(width)) { return; }
                frame.style.width = width + 'px';
                panel.querySelectorAll('[data-preview-width]').forEach(function (other) { other.setAttribute('aria-pressed', other === button ? 'true' : 'false'); });
            });
        });
        load();
    });
}());
