/* Native Admin2 Gantry page. Copyright 2026 Matthew C. Murphy. MIT. */
(function () {
    'use strict';
    const protocol = 'gantry6-admin-frame';
    const tag = window.__GRAV_PAGE_TAG;
    if (!tag || customElements.get(tag)) { return; }

    function localURL(value) {
        const url = new URL(value, window.location.href);
        if (url.origin !== window.location.origin || !/^https?:$/.test(url.protocol) || url.username || url.password) {
            throw new Error('Gantry requires the native same-origin API.');
        }
        return url;
    }
    function envelope(value) {
        const result = value && value.data && Number.isInteger(value.data.status) ? value.data : value;
        if (!result || !Number.isInteger(result.status) || result.status < 200 || result.status > 599
            || !['utf8', 'base64'].includes(result.encoding) || typeof result.body !== 'string'
            || result.body.length > 12 * 1024 * 1024 || !result.headers || typeof result.headers !== 'object') {
            throw new Error('Gantry returned an unsupported response.');
        }
        return result;
    }
    function header(result, name) {
        const key = Object.keys(result.headers).find(key => key.toLowerCase() === name.toLowerCase());
        return key ? String(result.headers[key]) : '';
    }
    function bytes(result) {
        if (result.encoding === 'utf8') { return new TextEncoder().encode(result.body); }
        return Uint8Array.from(atob(result.body), character => character.charCodeAt(0));
    }
    function descriptor(value) {
        if (!value || !['GET', 'POST', 'PUT', 'PATCH', 'DELETE'].includes(value.method)
            || typeof value.path !== 'string' || value.path.length > 2048
            || /[\\\x00-\x20?#:]/.test(value.path) || /(?:^|\/)\.{1,2}(?:\/|$)/.test(value.path)
            || value.path.includes('//') || !value.query || typeof value.query !== 'object'
            || Array.isArray(value.query) || JSON.stringify(value.query).length > 65536
            || typeof value.fields !== 'string' || value.fields.length > 2 * 1024 * 1024) {
            throw new Error('Unsupported Gantry request.');
        }
        return {method: value.method, path: value.path, query: value.query, fields: value.fields};
    }

    customElements.define(tag, class extends HTMLElement {
        connectedCallback() {
            if (this._connected) { return; }
            this._connected = true;
            this._epoch = (this._epoch || 0) + 1;
            this._channel = '';
            this._uploads = new Map();
            const root = this.shadowRoot || this.attachShadow({mode: 'open'});
            root.innerHTML = '<style>:host{display:block;min-width:0;font:inherit} .toolbar{display:flex;gap:.75rem;align-items:center;flex-wrap:wrap;margin-bottom:.75rem} button{font:inherit;padding:.4rem .8rem;cursor:pointer} [role=status]{margin:0} iframe{display:block;box-sizing:border-box;width:100%;min-width:0;height:calc(100vh - 13rem);min-height:32rem;border:1px solid #888;background:#fff}</style><div class="toolbar"><button type="button">Reload Gantry</button><p role="status" aria-live="polite">Opening Gantry…</p></div><iframe title="Gantry 6 administration" referrerpolicy="same-origin"></iframe>';
            this._frame = root.querySelector('iframe');
            this._status = root.querySelector('[role=status]');
            this._listener = event => this._message(event);
            window.addEventListener('message', this._listener);
            root.querySelector('button').addEventListener('click', () => this._navigate(this._current || this._initial()));
            this._navigate(this._initial());
        }
        disconnectedCallback() {
            this._connected = false;
            this._epoch++;
            this._channel = '';
            window.removeEventListener('message', this._listener);
            for (const controller of this._uploads.values()) { controller.abort(); }
            this._uploads.clear();
        }
        _initial() {
            const url = new URL(window.location.href);
            let query = Object.create(null);
            try { query = JSON.parse(url.searchParams.get('gantry_query') || '{}'); } catch (_) { /* Use the native default. */ }
            return {method: 'GET', path: url.searchParams.get('gantry_view') || '', query, fields: ''};
        }
        async _request(value, entries, id, redirects = 0) {
            const request = descriptor(value);
            if (entries != null && !Array.isArray(entries)) { throw new Error('Unsupported form fields.'); }
            if (!entries || !entries.some(entry => entry[1] instanceof Blob)) {
                if (!window.__GRAV_API || typeof window.__GRAV_API.post !== 'function') {
                    throw new Error('The native API is unavailable.');
                }
                return this._follow(envelope(await window.__GRAV_API.post('/gantry/dispatch', request)), request, entries, id, redirects);
            }
            if (!this._dispatchURL || !Array.isArray(entries) || entries.length > 1024) { throw new Error('Unsupported upload.'); }
            const data = new FormData();
            let total = 0;
            for (const entry of entries) {
                if (!Array.isArray(entry) || typeof entry[0] !== 'string' || entry[0] === '_gantry_request' || entry[0].length > 1024) {
                    throw new Error('Unsupported upload fields.');
                }
                const value = entry[1];
                if (value instanceof Blob) { total += value.size; data.append(entry[0], value, value.name || 'upload'); }
                else if (typeof value === 'string') { total += new TextEncoder().encode(value).length; data.append(entry[0], value); }
                else { throw new Error('Unsupported upload field.'); }
            }
            if (total > 4 * 1024 * 1024) { throw new Error('The upload exceeds the supported size.'); }
            data.append('_gantry_request', JSON.stringify({...request, fields: ''}));
            const token = window.__GRAV_API_TOKEN;
            if (typeof token !== 'string' || !token) { throw new Error('Sign in again to continue.'); }
            const environment = window.__GRAV_ENVIRONMENT;
            if (typeof environment !== 'string' || !environment.trim() || environment.length > 255
                || /[\x00-\x1f\x7f]/.test(environment)) { throw new Error('The native environment is unavailable. Reload before uploading.'); }
            const controller = new AbortController();
            this._uploads.set(id, controller);
            try {
                const response = await fetch(this._dispatchURL.href, {method: 'POST', credentials: 'same-origin',
                    headers: {'X-API-Token': token, Accept: 'application/json',
                        'X-Grav-Environment': environment, 'X-Config-Environment': environment}, body: data, signal: controller.signal});
                if (!response.ok) { const error = new Error('The native API refused this upload.'); error.status = response.status; throw error; }
                return this._follow(envelope(await response.json()), request, entries, id, redirects);
            } finally { this._uploads.delete(id); }
        }
        async _follow(result, request, entries, id, redirects) {
            const location = header(result, 'Location');
            if (result.status >= 300 && result.status < 400 && location) {
                if (redirects >= 5) { throw new Error('Too many Gantry redirects.'); }
                const base = localURL(result.base_url);
                const destination = localURL(new URL(location, base.href + '/').href);
                if (!(destination.pathname === base.pathname || destination.pathname.startsWith(base.pathname + '/'))) {
                    throw new Error('Gantry redirected outside its native editor.');
                }
                const get = result.status === 303 || ([301, 302].includes(result.status) && request.method === 'POST');
                const next = {method: get ? 'GET' : request.method,
                    path: decodeURIComponent(destination.pathname.slice(base.pathname.length).replace(/^\//, '')),
                    query: Object.fromEntries(destination.searchParams), fields: get ? '' : request.fields};
                return this._request(next, get ? null : entries, id, redirects + 1);
            }
            return {...result, request};
        }
        async _navigate(value) {
            const epoch = this._epoch;
            this._status.textContent = 'Opening Gantry…';
            try {
                const result = await this._request(value);
                if (this._connected && epoch === this._epoch) { this._show(result, value); }
            } catch (_) {
                if (this._connected && epoch === this._epoch) { this._status.textContent = 'Gantry could not be opened. Check your permissions or sign in again.'; }
            }
        }
        _show(result, value) {
            if (!this._connected) { return; }
            if (/\battachment\b/i.test(header(result, 'Content-Disposition'))) { this._download(result); return; }
            if (!/text\/html/i.test(header(result, 'Content-Type'))) { throw new Error('Expected a Gantry page.'); }
            const base = localURL(result.base_url);
            if (!base.pathname.endsWith('/gantry/view')) { throw new Error('Unsupported Gantry page route.'); }
            const assets = localURL(result.asset_base);
            const bridge = localURL(result.bridge_url);
            if (!bridge.pathname.endsWith('/gantry5/admin-next/gantry-frame.js')) { throw new Error('Unsupported Gantry frame script.'); }
            this._dispatchURL = new URL(base.href);
            this._dispatchURL.pathname = base.pathname.replace(/\/view$/, '/dispatch');
            this._dispatchURL.search = '';
            const random = new Uint32Array(4); crypto.getRandomValues(random);
            const channel = Array.from(random, value => value.toString(16).padStart(8, '0')).join('');
            const document = new DOMParser().parseFromString(new TextDecoder().decode(bytes(result)), 'text/html');
            document.querySelectorAll('base').forEach(element => element.remove());
            const anchor = document.createElement('base'); anchor.href = assets.href;
            const script = document.createElement('script'); script.src = bridge.href;
            script.dataset.channel = channel; script.dataset.virtualBase = base.href;
            const current = descriptor(result.request || value);
            // Reload revisits the last document. It must never replay a form
            // mutation or retry an upload whose File is no longer available.
            const navigation = current.method === 'GET' ? current : (this._current || this._initial());
            const view = new URL(base.href + '/' + navigation.path);
            for (const [key, values] of Object.entries(navigation.query)) {
                for (const item of Array.isArray(values) ? values : [values]) { view.searchParams.append(key, item); }
            }
            script.dataset.viewUrl = view.href;
            document.head.prepend(script); document.head.prepend(anchor);
            this._next = {channel, request: navigation, status: result.status};
            this._frame.srcdoc = '<!doctype html>\n' + document.documentElement.outerHTML;
        }
        _download(result) {
            const match = /filename="?([^";]+)"?/i.exec(header(result, 'Content-Disposition'));
            const filename = (match ? match[1] : 'gantry-download.json').replace(/[^a-zA-Z0-9_.-]/g, '_');
            const url = URL.createObjectURL(new Blob([bytes(result)], {type: header(result, 'Content-Type') || 'application/octet-stream'}));
            const link = document.createElement('a'); link.href = url; link.download = filename;
            this.shadowRoot.append(link); link.click(); link.remove();
            setTimeout(() => URL.revokeObjectURL(url), 30000);
            this._status.textContent = 'Download prepared.';
        }
        _newTab(request) {
            const url = new URL(window.location.href);
            url.searchParams.set('gantry_view', request.path);
            const query = Object.create(null);
            for (const key of Object.keys(request.query)) {
                if (!/token|nonce/i.test(key)) { query[key] = request.query[key]; }
            }
            url.searchParams.set('gantry_query', JSON.stringify(query));
            window.open(url.href, '_blank', 'noopener,noreferrer');
        }
        async _message(event) {
            const message = event.data;
            if (!this._connected || event.origin !== window.location.origin || event.source !== this._frame.contentWindow
                || !message || message.protocol !== protocol) { return; }
            if (message.kind === 'ready' && this._next && message.channel === this._next.channel) {
                this._channel = this._next.channel; this._current = this._next.request;
                this._status.textContent = this._next.status >= 400 ? 'Gantry returned a message. Review it below.' : '';
                this._next = null; return;
            }
            if (message.channel !== this._channel) { return; }
            if (message.kind === 'cancel') { this._uploads.get(message.channel + ':' + message.id)?.abort(); return; }
            const channel = this._channel;
            try {
                const request = descriptor(message.request);
                if (message.kind === 'state') {
                    if (request.method !== 'GET' || request.fields) { throw new Error('Only document navigation can update editor history.'); }
                    this._current = request; return;
                }
                if (message.kind === 'new-tab') { this._newTab(request); return; }
                if (!['request', 'navigate'].includes(message.kind) || !Number.isSafeInteger(message.id)) { return; }
                const result = await this._request(request, message.entries, channel + ':' + message.id);
                if (!this._connected || channel !== this._channel) { return; }
                if (message.kind === 'navigate') {
                    this._show(result, request);
                    if (/\battachment\b/i.test(header(result, 'Content-Disposition'))) {
                        this._frame.contentWindow.postMessage({protocol, channel, kind: 'response', id: message.id, result}, window.location.origin);
                    }
                    return;
                }
                if (/\battachment\b/i.test(header(result, 'Content-Disposition'))) { this._download(result); }
                this._frame.contentWindow.postMessage({protocol, channel, kind: 'response', id: message.id, result}, window.location.origin);
            } catch (error) {
                if (!this._connected || channel !== this._channel) { return; }
                const status = [400, 401, 403, 404, 409].includes(error.status) ? error.status : 500;
                const result = {status, encoding: 'utf8', headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({success: false, code: status, message: 'The Gantry request could not be confirmed. Reload or sign in again.'})};
                this._frame.contentWindow.postMessage({protocol, channel, kind: 'response', id: message.id, result}, window.location.origin);
                if (message.kind === 'navigate') { this._status.textContent = 'Gantry could not be opened. Reload or sign in again.'; }
            }
        }
    });
}());
