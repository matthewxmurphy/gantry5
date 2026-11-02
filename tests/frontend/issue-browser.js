/**
 * Isolated headless Chromium runner for shipped-asset regression fixtures.
 * Copyright (c) 2026 Matthew X Murphy, https://MatthewxMurphy.com.
 * SPDX-License-Identifier: MIT
 * Product/support: https://builtnotbegged.com.
 */
'use strict';
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const {pathToFileURL} = require('node:url');
const {execFileSync} = require('node:child_process');

const root = path.resolve(__dirname, '../..');
const autoload = process.argv[2] || path.join(root, 'platforms/joomla/lib_gantry5/vendor/autoload.php');
const chrome = process.env.CHROME_BIN || [
    '/usr/bin/google-chrome', '/usr/bin/chromium', '/usr/bin/chromium-browser',
    '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
].find(filename => fs.existsSync(filename));

function compile(issue) {
    const result = execFileSync(process.env.PHP_BIN || 'php', [
        path.join(__dirname, 'issue-css.php'), autoload, root, String(issue),
    ], {encoding: 'utf8', maxBuffer: 8 * 1024 * 1024, stdio: ['ignore', 'pipe', 'pipe']});
    if (!result.trim()) throw new Error('The native SCSS compiler returned no CSS.');
    return result;
}

function browser(issue, css, markup, assertions, width) {
    if (!chrome || !fs.existsSync(chrome)) throw new Error('Chromium unavailable: set CHROME_BIN. Tests must not skip.');
    const temporary = fs.mkdtempSync(path.join(os.tmpdir(), 'gantry-issue-' + issue + '-'));
    try {
        const fixture = path.join(temporary, 'fixture.html');
        const html = '<!doctype html><html><head><meta charset="utf-8"><style>' + css +
            '</style></head><body>' + markup + '<pre id="result"></pre><script>' +
            '(async()=>{const rows=[];const check=(name,pass,observed)=>rows.push({name,pass:!!pass,observed});' +
            'try{' + assertions + '}catch(error){check("browser assertions completed",false,String(error));}' +
            'document.getElementById("result").setAttribute("data-result",btoa(unescape(encodeURIComponent(JSON.stringify(rows)))));' +
            '})()</script></body></html>';
        fs.writeFileSync(fixture, html, {mode: 0o600});
        const output = execFileSync(chrome, [
            '--headless', '--no-sandbox', '--disable-gpu', '--disable-dev-shm-usage',
            '--no-first-run', '--no-default-browser-check', '--disable-background-networking',
            '--user-data-dir=' + path.join(temporary, 'profile'),
            '--window-size=' + width + ',900', '--virtual-time-budget=1500', '--dump-dom',
            pathToFileURL(fixture).href,
        ], {encoding: 'utf8', timeout: 30000, maxBuffer: 8 * 1024 * 1024, stdio: ['ignore', 'pipe', 'pipe']});
        const encoded = output.match(/data-result="([A-Za-z0-9+/=]+)"/);
        if (!encoded) throw new Error('Chromium did not execute the regression assertions.');
        return JSON.parse(Buffer.from(encoded[1], 'base64').toString('utf8'));
    } finally {
        fs.rmSync(temporary, {recursive: true, force: true});
    }
}

function report(issue, rows) {
    const failed = rows.filter(row => !row.pass);
    console.log(JSON.stringify({issue, checks: rows.length, passed: rows.length - failed.length, failed: failed.length, rows}, null, 2));
    if (!rows.length || failed.length) process.exitCode = 1;
}

module.exports = {root, fs, path, compile, browser, report};
