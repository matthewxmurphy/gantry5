// Run with playwright-cli run-code --filename tests/frontend/issue-2632.cjs.
// Start on the Gantry Menu Editor for a disposable g343qaNNNNNN Joomla menu.
// Create the fixture headings shown below in their native parent/child hierarchy.
async page => {
    const start = new URL(page.url());
    const view = start.searchParams.get('view');
    const menu = /^menu\/(g343qa\d{6})$/.exec(view || '')?.[1];
    if (!menu || start.searchParams.get('option') !== 'com_gantry5') {
        throw new Error('Open the disposable Gantry menu fixture first.');
    }

    const checks = [];
    const check = (name, passed, evidence) => checks.push({ name, passed, evidence });
    const editor = page.locator('#menu-editor');
    const columns = editor.locator('.submenu-selector > [data-mm-id]');
    const list = index => columns.nth(index).locator('ul.submenu-items');
    const childIds = index => list(index).locator(':scope > li[data-mm-id]').evaluateAll(rows => rows.map(row => row.dataset.mmId));
    const open = async (scope, title) => scope.locator('a.menu-item').filter({ hasText: title }).click({ timeout: 8000 });
    const route = async path => {
        const url = new URL(start);
        url.searchParams.set('view', 'menu/' + menu + '/' + path);
        return page.goto(url.href);
    };
    const screenshot = async name => {
        const directory = typeof process !== 'undefined' ? process.env.GANTRY_MENU_PROOF_DIR : undefined;
        if (directory) {
            await page.screenshot({ path: directory + '/' + name + '.png' });
        }
    };
    const drag = async (from, to) => {
        const a = await from.boundingBox({ timeout: 5000 });
        const b = await to.boundingBox({ timeout: 5000 });
        if (!a || !b) throw new Error('Menu drag source or target was not visible.');
        await page.mouse.move(a.x + a.width / 3, a.y + a.height / 2);
        await page.mouse.down();
        await page.mouse.move(b.x + b.width / 2, b.y + b.height - 8, { steps: 25 });
        await page.mouse.up();
    };
    const safeUrl = raw => {
        const url = new URL(raw);
        for (const key of [...url.searchParams.keys()]) {
            if (/^[a-f0-9]{32}$/i.test(key)) url.searchParams.delete(key);
        }
        return url.href;
    };
    const post = (targetView, group) => page.waitForResponse(response => {
        const url = new URL(response.url());
        return response.request().method() === 'POST' && url.searchParams.get('view') === targetView &&
            (group === undefined || url.searchParams.get('group') === String(group));
    }, { timeout: 12000 });
    const jsonResponse = async response => {
        const data = await response.json();
        return { status: response.status(), url: safeUrl(response.url()), success: data.success, html: data.html };
    };
    const left = 'g343-parent/g343-left';
    const right = 'g343-parent/g343-right';
    const deep = right + '/g343-deep';
    const same = (a, b) => JSON.stringify(a) === JSON.stringify(b);

    await open(editor.locator('.g-toplevel'), 'G343 Parent');
    await list(0).locator('li[data-mm-id="' + right + '"]').waitFor({ timeout: 8000 });
    await editor.locator('.add-column').click();
    await drag(list(0).locator('li[data-mm-id="' + right + '"] > a.menu-item'), list(1));
    await list(1).locator('li[data-mm-id="' + right + '"]:not(.original-placeholder)').waitFor({ timeout: 8000 });
    await list(0).locator('li[data-mm-id="' + right + '"]').waitFor({ state: 'detached', timeout: 8000 });
    check('split fixture starts with one child in each column', same(await childIds(0), [left]) && same(await childIds(1), [right]));

    for (const fixture of [
        { name: 'split', parent: 'g343-parent', child: right, title: 'G343 Right', column: 1, children: [deep] },
        { name: 'single', parent: 'g343-control', child: 'g343-control/g343-single-child', title: 'G343 Single Child', column: 0, children: [] },
    ]) {
        if (fixture.column === 0) await open(editor.locator('.g-toplevel'), 'G343 Control');
        await open(list(fixture.column), fixture.title);
        await columns.nth(fixture.column).locator('ul[data-mm-base-level="2"]').waitFor({ timeout: 8000 });
        const marker = 'G343 #2632 ' + fixture.name;
        await drag(page.locator('.g5-mm-particles-picker li[data-mm-id="__particle"]'), list(fixture.column));
        await page.locator('.menu-editor-extras [data-lm-subtype="custom"]').click({ timeout: 8000 });
        await page.locator('.menu-editor-extras [data-mm-select]').click();
        await page.locator('#g-settings-particle textarea[name="particles[custom][html]"]').fill(marker);
        await page.getByRole('button', { name: 'Apply', exact: true }).click();
        const added = list(fixture.column).locator('li[data-mm-id*="/__particle-"]');
        await added.waitFor({ timeout: 8000 });
        const addedId = await added.getAttribute('data-mm-id');
        await screenshot(fixture.name + '-applied');

        const request = post('menu/' + menu + '/' + fixture.parent, fixture.column);
        await columns.nth(fixture.column).locator('a.menu-item-back').click();
        const backResponse = await request;
        const back = await jsonResponse(backResponse);
        const order = JSON.parse(new URLSearchParams(backResponse.request().postData()).get('ordering'));
        const expectedGroups = fixture.column === 1 ? [[left], [right]] : [[fixture.child]];
        check(fixture.name + ' Back sends intact parent and child draft ordering',
            same(order[fixture.parent], expectedGroups) && same(order[fixture.child], [[...fixture.children, addedId]]),
            { parent: order[fixture.parent], child: order[fixture.child], addedId });

        await columns.nth(fixture.column).locator('ul[data-mm-base-level="1"]').waitFor({ timeout: 8000 });
        const rightRows = await childIds(fixture.column);
        const leftRows = fixture.column === 1 ? await childIds(0) : null;
        const fullPageInjected = /<!doctype|<html\b|id=["']menu-editor/i.test(back.html || '');
        check(fixture.name + ' Back restores the full editor with its child in place',
            back.status === 200 && back.success === true && !fullPageInjected &&
            await editor.count() === 1 && same(rightRows, [fixture.child]) &&
            (fixture.column === 0 || same(leftRows, [left])),
            { status: back.status, url: back.url, fullPageInjected, leftRows, rightRows });
        await screenshot(fixture.name + '-back');

        if (rightRows.includes(fixture.child)) {
            await open(list(fixture.column), fixture.title);
            const draftParticle = list(fixture.column).locator('li[data-mm-original-type="particle"]').getByText('Custom HTML', { exact: true });
            const visible = await draftParticle.waitFor({ timeout: 8000 }).then(() => true, () => false);
            check(fixture.name + ' Custom HTML particle is visible after Back', visible);
            await screenshot(fixture.name + '-reopen');
        } else {
            check(fixture.name + ' Custom HTML particle is visible after Back', false, { reason: 'Child is missing' });
        }

        const saveRequest = post('menu/' + menu);
        await page.getByRole('button', { name: 'Save Menu', exact: true }).click();
        const save = await jsonResponse(await saveRequest);
        const reload = await route(fixture.parent);
        await list(fixture.column).waitFor({ timeout: 8000 });
        const savedChildren = await childIds(fixture.column);
        await open(list(fixture.column), fixture.title);
        const saved = list(fixture.column).locator('li[data-mm-original-type="particle"]');
        await saved.locator('a.config-cog').click({ timeout: 8000 });
        const persisted = await page.locator('#g-settings-particle textarea').inputValue({ timeout: 8000 });
        await screenshot(fixture.name + '-saved');
        await page.getByRole('button', { name: 'Cancel', exact: true }).click();
        check(fixture.name + ' Save and reload preserve child and particle',
            save.status === 200 && save.success === true && reload.status() === 200 &&
            same(savedChildren, [fixture.child]) && persisted === marker,
            { saveStatus: save.status, saveUrl: save.url, reloadStatus: reload.status(), persisted });
    }

    if (!checks.every(row => row.passed)) {
        throw new Error(JSON.stringify({ passed: false, checks }));
    }
    return { passed: true, checks };
}
