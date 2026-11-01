/* Same-origin Gantry UI transport through the native Admin2 API. Matthew C. Murphy, 2026. MIT. */
(function () {
    'use strict';
    const bootstrap = document.currentScript;
    if (!bootstrap || window.parent === window) { return; }
    const channel = bootstrap.dataset.channel;
    const base = new URL(bootstrap.dataset.virtualBase);
    const origin = new URL(bootstrap.src).origin;
    if (!channel || base.origin !== origin || !base.pathname.endsWith('/gantry/view')) { return; }
    const current = new URL(bootstrap.dataset.viewUrl);
    if (current.origin !== origin || !(current.pathname === base.pathname || current.pathname.startsWith(base.pathname + '/'))) { return; }
    // srcdoc has no browser history URL. Gantry's own history adapter keeps
    // bounded editor states while native Admin2 retains the outer page route.
    window.GANTRY_ADMIN_HISTORY = {url: current.href};
    const protocol = 'gantry6-admin-frame';
    const pending = new Map();
    let sequence = 0;
    const nativeFetch = window.fetch.bind(window);
    const NativeXHR = window.XMLHttpRequest;

    function queryObject(parameters) {
        const query = Object.create(null);
        for (const [key, value] of parameters) {
            if (Object.prototype.hasOwnProperty.call(query, key)) {
                query[key] = Array.isArray(query[key]) ? [...query[key], value] : [query[key], value];
            } else { query[key] = value; }
        }
        return query;
    }
    function target(value, method) {
        const url = new URL(value, document.baseURI);
        const prefix = base.pathname + '/';
        if (url.origin !== origin || !(url.pathname === base.pathname || url.pathname.startsWith(prefix))) { return null; }
        return {method: method.toUpperCase(), path: decodeURIComponent(url.pathname.slice(base.pathname.length).replace(/^\//, '')),
            query: queryObject(url.searchParams), fields: ''};
    }
    window.addEventListener('gantry-admin-historychange', function (event) {
        const value = event.detail && target(event.detail.url, 'GET');
        if (value) { window.parent.postMessage({protocol, channel, kind: 'state', request: value}, origin); }
    });
    function request(kind, value, entries) {
        const id = ++sequence;
        let timer;
        const promise = new Promise((resolve, reject) => {
            timer = setTimeout(() => { pending.delete(id); reject(new Error('The Gantry request timed out.')); }, 120000);
            pending.set(id, {resolve, reject, timer});
            window.parent.postMessage({protocol, channel, kind, id, request: value, entries}, origin);
        });
        return {promise, cancel: function () {
            const active = pending.get(id);
            clearTimeout(timer); pending.delete(id);
            if (active) { active.reject(new DOMException('Aborted', 'AbortError')); }
            window.parent.postMessage({protocol, channel, kind: 'cancel', id}, origin);
        }};
    }
    window.addEventListener('message', function (event) {
        const message = event.data;
        if (event.source !== window.parent || event.origin !== origin || !message || message.protocol !== protocol
            || message.channel !== channel || message.kind !== 'response') { return; }
        const active = pending.get(message.id);
        if (!active) { return; }
        clearTimeout(active.timer); pending.delete(message.id); active.resolve(message.result);
    });
    function asBytes(result) {
        return result.encoding === 'base64' ? Uint8Array.from(atob(result.body), c => c.charCodeAt(0)) : new TextEncoder().encode(result.body);
    }
    function formBody(value, body) {
        if (body instanceof FormData) {
            const entries = Array.from(body.entries());
            value.fields = new URLSearchParams(entries.filter(entry => typeof entry[1] === 'string')).toString();
            return entries;
        }
        value.fields = body instanceof URLSearchParams ? body.toString() : (body == null ? '' : String(body));
        return null;
    }

    window.fetch = async function (input, options) {
        const address = typeof input === 'string' || input instanceof URL ? input : input.url;
        const value = target(address, options?.method || (input instanceof Request ? input.method : 'GET'));
        if (!value) { return nativeFetch(input, options); }
        const actual = new Request(input, options);
        if (actual.signal.aborted) { throw new DOMException('Aborted', 'AbortError'); }
        const contentType = actual.headers.get('Content-Type') || '';
        const body = /multipart\/form-data/i.test(contentType) ? await actual.formData() : await actual.text();
        const entries = formBody(value, body);
        const active = request('request', value, entries);
        const abort = () => active.cancel(); actual.signal.addEventListener('abort', abort, {once: true});
        try {
            const result = await active.promise;
            return new Response([204, 205, 304].includes(result.status) ? null : asBytes(result), {status: result.status, headers: result.headers});
        } finally { actual.signal.removeEventListener('abort', abort); }
    };

    class BridgeXHR extends EventTarget {
        constructor() {
            super(); this.readyState = 0; this.status = 0; this.statusText = ''; this.responseType = '';
            this.responseText = ''; this.response = null; this.responseURL = ''; this.timeout = 0;
            this.withCredentials = false; this.upload = new EventTarget(); this._headers = Object.create(null);
        }
        _emit(type) {
            const event = new Event(type); this.dispatchEvent(event);
            if (typeof this['on' + type] === 'function') { this['on' + type](event); }
        }
        open(method, url, asynchronous = true, user, password) {
            this._native = null; this._aborted = false; this._headers = Object.create(null);
            this._request = target(url, method);
            if (!this._request) {
                this._native = new NativeXHR();
                for (const type of ['readystatechange', 'loadstart', 'load', 'error', 'abort', 'timeout', 'loadend', 'progress']) {
                    this._native.addEventListener(type, () => {
                        for (const key of ['readyState', 'status', 'statusText', 'response', 'responseURL']) { this[key] = this._native[key]; }
                        try { this.responseText = this._native.responseText; } catch (_) { /* Binary response. */ }
                        this._emit(type);
                    });
                }
                return this._native.open(method, url, asynchronous, user, password);
            }
            if (asynchronous === false || user || password) { throw new DOMException('Synchronous or credential-bearing Gantry requests are unsupported.', 'InvalidAccessError'); }
            this.readyState = 1; this._emit('readystatechange');
        }
        setRequestHeader(name, value) {
            if (this._native) { return this._native.setRequestHeader(name, value); }
            this._headers[name.toLowerCase()] = value;
        }
        getResponseHeader(name) {
            if (this._native) { return this._native.getResponseHeader(name); }
            const key = Object.keys(this._responseHeaders || {}).find(key => key.toLowerCase() === name.toLowerCase());
            return key ? this._responseHeaders[key] : null;
        }
        getAllResponseHeaders() {
            if (this._native) { return this._native.getAllResponseHeaders(); }
            const lines = Object.entries(this._responseHeaders || {}).map(([key, value]) => key + ': ' + value);
            return lines.length ? lines.join('\r\n') + '\r\n' : '';
        }
        overrideMimeType(value) { if (this._native) { this._native.overrideMimeType(value); } }
        send(body) {
            if (this._native) {
                this._native.responseType = this.responseType; this._native.timeout = this.timeout;
                this._native.withCredentials = this.withCredentials; return this._native.send(body);
            }
            if (!this._request || this.readyState !== 1) { throw new DOMException('Open a request first.', 'InvalidStateError'); }
            const entries = formBody(this._request, body);
            this._active = request('request', this._request, entries); this._emit('loadstart');
            if (this.timeout) { this._timeout = setTimeout(() => { this.abort(); this._emit('timeout'); }, this.timeout); }
            this._active.promise.then(result => {
                if (this._aborted) { return; }
                clearTimeout(this._timeout); this.status = result.status; this._responseHeaders = result.headers;
                this.readyState = 2; this._emit('readystatechange');
                const data = asBytes(result); this.responseText = new TextDecoder().decode(data);
                let json = null;
                if (this.responseType === 'json') { try { json = JSON.parse(this.responseText); } catch (_) { /* Native JSON response is null when invalid. */ } }
                this.response = this.responseType === 'json' ? json
                    : this.responseType === 'arraybuffer' ? data.buffer
                        : this.responseType === 'blob' ? new Blob([data], {type: this.getResponseHeader('Content-Type') || ''}) : this.responseText;
                this.readyState = 4; this._emit('readystatechange'); this._emit('load'); this._emit('loadend');
            }).catch(() => {
                if (this._aborted) { return; }
                clearTimeout(this._timeout); this.status = 0; this.readyState = 4;
                this._emit('readystatechange'); this._emit('error'); this._emit('loadend');
            });
        }
        abort() {
            if (this._native) { return this._native.abort(); }
            this._aborted = true; clearTimeout(this._timeout); this._active?.cancel(); this.status = 0; this.readyState = 0;
            this._emit('abort'); this._emit('loadend');
        }
    }
    Object.assign(BridgeXHR, {UNSENT: 0, OPENED: 1, HEADERS_RECEIVED: 2, LOADING: 3, DONE: 4});
    window.XMLHttpRequest = BridgeXHR;

    function navigate(form, submitter) {
        const value = target(form.action, form.method || 'GET');
        if (!value) { return false; }
        let body;
        try { body = new FormData(form, submitter || undefined); }
        catch (_) { body = new FormData(form); if (submitter?.name) { body.append(submitter.name, submitter.value); } }
        let entries = formBody(value, body);
        if (value.method === 'GET') {
            const parameters = new URLSearchParams();
            for (const [key, values] of Object.entries(value.query)) {
                for (const item of Array.isArray(values) ? values : [values]) { parameters.append(key, item); }
            }
            for (const [key, item] of body) { parameters.append(key, item instanceof File ? item.name : item); }
            value.query = queryObject(parameters); value.fields = ''; entries = null;
        }
        request('navigate', value, entries).promise.catch(() => {});
        return true;
    }
    document.addEventListener('submit', function (event) {
        if (event.defaultPrevented || !(event.target instanceof HTMLFormElement)) { return; }
        if (navigate(event.target, event.submitter)) { event.preventDefault(); }
    });
    const nativeSubmit = HTMLFormElement.prototype.submit;
    HTMLFormElement.prototype.submit = function () { if (!navigate(this, null)) { nativeSubmit.call(this); } };
    document.addEventListener('click', function (event) {
        if (event.defaultPrevented || event.button !== 0 || event.altKey || event.ctrlKey || event.metaKey || event.shiftKey) { return; }
        const link = event.target.closest && event.target.closest('a[href]');
        if (!link || link.hasAttribute('download') || link.getAttribute('href').startsWith('#')) { return; }
        const value = target(link.href, 'GET');
        if (!value) { return; }
        event.preventDefault();
        if (link.target === '_blank') { window.parent.postMessage({protocol, channel, kind: 'new-tab', request: value}, origin); }
        else { request('navigate', value).promise.catch(() => {}); }
    });
    window.parent.postMessage({protocol, channel, kind: 'ready'}, origin);
}());
