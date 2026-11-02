/* Copyright 2026 Matthew X Murphy, https://MatthewxMurphy.com. MIT. https://builtnotbegged.com. */
'use strict';

var agent = require('agent');

function current() {
    var marker = document.querySelector('[data-g5-content] [data-g6-current-revision]') || document.querySelector('[data-g6-current-revision]');
    return marker ? marker.getAttribute('data-g6-current-revision') : '';
}

function take(reply) {
    if (!reply || !reply.success || reply.preview || !/^[a-f0-9]{64}$/.test(reply.revision || '')) { return; }
    document.querySelectorAll('[data-g6-current-revision]').forEach(function (marker) { marker.setAttribute('data-g6-current-revision', reply.revision); });
    document.querySelectorAll('input[name="g6_revision"]').forEach(function (input) { input.value = reply.revision; });
}

/** Capture the editor's loaded revision, never fetch a newer one immediately before a save. */
function request(method, uri, data, callback) {
    if (typeof data === 'function') { callback = data; data = {}; }
    var revision = current();
    if (/^(post|put|patch|delete)$/i.test(method) && revision) {
        if (typeof data === 'string') { data += (data ? '&' : '') + 'g6_revision=' + encodeURIComponent(revision); }
        else { data = Object.assign({}, data || {}, { g6_revision: revision }); }
    }
    return agent(method, uri, data, function (error, response) {
        if (!response || !response.body) { response = { body: { success: false, message: 'The change could not be confirmed. Keep your draft and reload before retrying.' } }; }
        if (!response.body.success && !response.body.message && Array.isArray(response.body.exceptions)) {
            response.body.message = response.body.exceptions.map(function (item) {
                return String(item.message || 'The change was refused.').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
            }).join('<br>');
        }
        take(response.body);
        if (callback) { callback(error, response); }
    });
}
request.current = current;
request.take = take;
module.exports = request;
