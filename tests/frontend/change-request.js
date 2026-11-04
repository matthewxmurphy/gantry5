/* Copyright 2026 Matthew X Murphy, https://MatthewxMurphy.com. MIT. https://builtnotbegged.com. */
'use strict';

// Exercise the native transport adapter's revision lifecycle without a CMS or token.
// Browser acceptance separately checks actual forms and preserved unsaved values.
var assert = require('node:assert/strict');
var Module = require('node:module');
var load = Module._load;
var first = 'a'.repeat(64), next = 'b'.repeat(64), remote = 'c'.repeat(64);
var marker = { value: first, getAttribute: function () { return this.value; }, setAttribute: function (name, value) { this.value = value; } };
var hidden = { value: first };
var sent, reply;
global.document = {
    querySelector: function () { return marker; },
    querySelectorAll: function (selector) { return selector.indexOf('input') === 0 ? [hidden] : [marker]; }
};
Module._load = function (name, parent, main) {
    if (name === 'agent') {
        return function (method, uri, data, callback) { sent = { method: method, uri: uri, data: data }; callback(null, { body: reply }); };
    }
    return load(name, parent, main);
};
var request = require('../../platforms/common/application/utils/change-request');
Module._load = load;
var checks = 0;
function check(value) { assert.ok(value); checks += 1; }

reply = { success: false, revision: remote, code: 409 };
var original = { 'styles[accent][color-1]': '#123456' };
request('POST', '/native/styles', original, function () {});
check(sent.data.g6_revision === first);
check(sent.data['styles[accent][color-1]'] === '#123456');
check(!Object.hasOwn(original, 'g6_revision'));
check(marker.value === first && hidden.value === first); // Conflict never silently rebases a draft.

reply = { success: true, preview: true, revision: remote };
request('POST', '/native/styles', { g6_mode: 'preview' }, function () {});
check(sent.data.g6_revision === first && sent.data.g6_mode === 'preview');
check(marker.value === first && hidden.value === first);

reply = { success: true, revision: next };
request('POST', '/native/styles', original, function () {});
check(marker.value === next && hidden.value === next);
reply = { success: false };
request('POST', '/native/layout', 'layout=%5B%5D', function () {});
check(sent.data === 'layout=%5B%5D&g6_revision=' + next);
request('POST', '/native/outline/delete', function () {});
check(sent.data.g6_revision === next);
request('GET', '/native/outline/form', { title: 'Example' }, function () {});
check(!Object.hasOwn(sent.data, 'g6_revision'));
request.take({ success: true, revision: 'malformed' });
check(marker.value === next);
request.take({ success: false, revision: remote });
check(marker.value === next);
delete global.document;
console.log('PASS change-request: ' + checks + ' checks');
