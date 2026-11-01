"use strict";

/* In-memory Gantry navigation for its native Admin2 iframe. Matthew C. Murphy, 2026. MIT. */
module.exports = function(settings) {
    if (window.parent === window || !settings || typeof settings.url !== 'string') {
        throw new Error('A native Gantry editor URL is required.');
    }

    var origin = window.parent.location.origin,
        initial = new URL(settings.url, document.baseURI),
        match = initial.pathname.match(/^(.+\/gantry\/view)(?:\/|$)/),
        prefix = match && match[1],
        Adapter = require('./history-adapter').Adapter,
        states = [], position = 0, sequence = 0;

    function address(value) {
        if (typeof value !== 'string' || value.length > 65536) {
            throw new Error('Use a bounded native Gantry URL.');
        }
        var url = new URL(value, states.length ? states[position].url : initial.href);
        if (!prefix || !/^https?:$/.test(url.protocol) || url.origin !== origin || url.username || url.password
            || !(url.pathname === prefix || url.pathname.indexOf(prefix + '/') === 0)) {
            throw new Error('Gantry navigation must stay inside its native editor.');
        }
        url.hash = '';
        return url.href;
    }
    function copy(value) { return JSON.parse(JSON.stringify(value)); }
    function state(data, title, url) {
        data = data || {};
        var encoded = JSON.stringify(data);
        if (typeof data !== 'object' || Array.isArray(data) || encoded.length > 65536) {
            throw new Error('Use bounded Gantry navigation state.');
        }
        return {id: String(++sequence), data: JSON.parse(encoded), title: String(title || '').slice(0, 512), url: address(url)};
    }
    function changed() {
        settings.url = states[position].url;
        window.dispatchEvent(new CustomEvent('gantry-admin-historychange', {detail: {url: settings.url}}));
        Adapter.trigger(window, 'statechange');
    }

    states.push(state({}, document.title, settings.url));
    settings.url = states[0].url;
    var History = {
        Adapter: Adapter,
        getState: function() { return copy(states[position]); },
        getPageUrl: function() { return states[position].url; },
        pushState: function(data, title, url) {
            var next = state(data, title, url);
            states = states.slice(0, position + 1);
            states.push(next);
            if (states.length > 64) { states.shift(); }
            position = states.length - 1;
            changed();
            return true;
        },
        replaceState: function(data, title, url) {
            states[position] = state(data, title, url);
            changed();
            return true;
        },
        back: function() {
            if (position === 0) { return false; }
            --position;
            changed();
            return true;
        }
    };
    window.History = History;
    return History;
};
