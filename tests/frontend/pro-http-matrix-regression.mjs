/**
 * Native Joomla Pro raw JSON response regression (six HTTP cases).
 *
 * Run with @playwright/test installed in the test project:
 *   GANTRY_PRO_BASE_URL=http://127.0.0.1:48293 \
 *   GANTRY_PRO_ADMIN_STATE=/private/admin-state.json \
 *   GANTRY_PRO_MANAGER_STATE=/private/manager-state.json \
 *   node tests/frontend/pro-http-matrix-regression.mjs
 *
 * The two mode-0600 storage states must come from separate, already signed-in
 * disposable identities on the same isolated Joomla clone. No login or
 * storage-state creation is performed by this test.
 */
import { createHash } from 'node:crypto';
import { stat } from 'node:fs/promises';
import { resolve } from 'node:path';
import { expect, request } from '@playwright/test';

const defaultAnonymousHash = '6702cb3b06d10e6c4dbc69517bd2474a45bbaf11582c7c78d3cc3882bfe8b355';
const routes = ['api/inventory', 'api/openapi'];

function required(name) {
    const value = process.env[name];
    if (!value) {
        throw new Error('Set ' + name + ' before running the Pro HTTP matrix.');
    }
    return value;
}

async function privateState(variable) {
    const path = resolve(required(variable));
    const mode = await stat(path);
    if (!mode.isFile() || (mode.mode & 0o077) !== 0) {
        throw new Error(variable + ' must point to a private regular file (mode 0600).');
    }
    return path;
}

function endpoint(base, theme, view) {
    const target = new URL('administrator/index.php', base);
    target.search = new URLSearchParams({
        option: 'com_gantry5',
        theme,
        view,
        format: 'json',
    }).toString();
    return target;
}

async function check(context, role, view, url, anonymousHash) {
    const label = role + ' ' + view;
    let response;
    try {
        response = await context.get(url.toString(), { maxRedirects: 0, timeout: 10000 });
    } catch {
        throw new Error(label + ' did not return a response from the isolated clone.');
    }

    const expectedStatus = role === 'anonymous' || (role === 'manager' && view === 'api/inventory')
        ? 403 : 200;
    expect(response.status(), label + ' HTTP status').toBe(expectedStatus);

    const rawHeaders = await response.headersArray();
    const headerValues = name => rawHeaders
        .filter(header => header.name.toLowerCase() === name)
        .map(header => header.value);
    expect(headerValues('cache-control'), label + ' Cache-Control').toEqual(['private, no-store']);
    expect(headerValues('x-content-type-options'), label + ' content sniffing').toEqual(['nosniff']);
    expect(headerValues('pragma'), label + ' Joomla Pragma').toEqual(['no-cache']);
    expect(headerValues('expires'), label + ' Joomla Expires').toEqual(['Wed, 17 Aug 2005 00:00:00 GMT']);
    expect(headerValues('content-type'), label + ' JSON content type').toEqual(['application/json; charset=utf-8']);

    const bytes = await response.body();
    if (role === 'manager') {
        expect(bytes.length, label + ' must have an empty denial response').toBe(0);
    } else {
        let data;
        try {
            data = JSON.parse(bytes.toString('utf8'));
        } catch {
            throw new Error(label + ' did not return valid JSON.');
        }
        if (role === 'anonymous') {
            expect(Object.keys(data).sort(), label + ' original Joomla error shape')
                .toEqual(['code', 'error', 'message']);
            expect(data.code, label + ' Joomla error code').toBe(403);
            expect(createHash('sha256').update(bytes).digest('hex'), label + ' original error body identity')
                .toBe(anonymousHash);
        } else if (view === 'api/inventory') {
            expect(data.schema_version, label + ' inventory schema version').toBe(1);
            expect(Array.isArray(data.extensions), label + ' extension list').toBe(true);
            expect(Boolean(data.template_library_review), label + ' review section').toBe(true);
        } else {
            expect(typeof data.openapi, label + ' OpenAPI version').toBe('string');
            expect(Boolean(data.paths), label + ' OpenAPI paths').toBe(true);
        }
    }

    process.stdout.write('PASS ' + label + ' ' + expectedStatus + '\n');
}

async function main() {
    const base = new URL(required('GANTRY_PRO_BASE_URL'));
    const loopback = ['127.0.0.1', 'localhost', '[::1]'].includes(base.hostname);
    if (!loopback || !['http:', 'https:'].includes(base.protocol) || base.username || base.password || base.search || base.hash) {
        throw new Error('GANTRY_PRO_BASE_URL must be the isolated Joomla loopback base URL.');
    }
    if (!base.pathname.endsWith('/')) {
        base.pathname += '/';
    }

    const admin = await privateState('GANTRY_PRO_ADMIN_STATE');
    const manager = await privateState('GANTRY_PRO_MANAGER_STATE');
    if (admin === manager) {
        throw new Error('Use separate administrator and Manager storage-state files.');
    }
    const theme = process.env.GANTRY_PRO_THEME || 'g6_jmafia';
    const anonymousHash = process.env.GANTRY_PRO_ANONYMOUS_BODY_SHA256 || defaultAnonymousHash;
    if (!/^[a-f0-9]{64}$/.test(anonymousHash)) {
        throw new Error('GANTRY_PRO_ANONYMOUS_BODY_SHA256 must be a lowercase SHA-256 value.');
    }

    for (const [role, state] of [['anonymous', undefined], ['manager', manager], ['administrator', admin]]) {
        let context;
        try {
            context = await request.newContext({ storageState: state });
        } catch {
            throw new Error('Could not open the private Playwright state for ' + role + '.');
        }
        try {
            for (const route of routes) {
                await check(context, role, route, endpoint(base, theme, route), anonymousHash);
            }
        } finally {
            await context.dispose();
        }
    }
    process.stdout.write('PASS all 6 native Pro HTTP cases.\n');
}

await main();
