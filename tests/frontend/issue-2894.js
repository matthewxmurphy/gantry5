/**
 * #2894: native Twig asset admission plus real CSS/webfont and SVG icon rendering.
 * node tests/frontend/issue-2894.js /path/to/vendor/autoload.php
 * Copyright (c) 2026 Matthew X Murphy, https://MatthewxMurphy.com.
 * SPDX-License-Identifier: MIT
 * Product/support: https://builtnotbegged.com.
 */
'use strict';
const {execFileSync, spawn} = require('node:child_process');
const os = require('node:os');
const {root, fs, path, browser, report} = require('./issue-browser');
const autoload = process.argv[2] || path.join(root, 'platforms/joomla/lib_gantry5/vendor/autoload.php');
const data = JSON.parse(execFileSync(process.env.PHP_BIN || 'php', [
    path.join(root, 'tests/php85/issue-2894.php'), autoload, root,
], {encoding: 'utf8', maxBuffer: 4 * 1024 * 1024, stdio: ['ignore', 'pipe', 'pipe']}));
const rows = data.checks;
const temporary = fs.mkdtempSync(path.join(os.tmpdir(), 'gantry-font-requests-'));
const portFile = path.join(temporary, 'port');
const requestFile = path.join(temporary, 'requests.jsonl');
const server = spawn(process.env.PYTHON_BIN || 'python3', ['-u', '-c', `
import http.server,json,pathlib,sys
root,port_file,request_file=sys.argv[1:]
class Handler(http.server.SimpleHTTPRequestHandler):
    def __init__(self,*args,**kwargs):super().__init__(*args,directory=root,**kwargs)
    def end_headers(self):
        self.send_header('Access-Control-Allow-Origin','*')
        self.send_header('Timing-Allow-Origin','*')
        super().end_headers()
    def log_request(self,code='-',size='-'):
        with open(request_file,'a') as stream:stream.write(json.dumps({'path':self.path,'status':int(code)})+'\\n')
    def log_message(self,*args):pass
httpd=http.server.HTTPServer(('127.0.0.1',0),Handler)
pathlib.Path(port_file).write_text(str(httpd.server_address[1]))
httpd.serve_forever()
`, path.join(root, 'assets/common'), portFile, requestFile], {stdio: 'ignore'});
try {
const deadline = Date.now() + 10000;
while (!fs.existsSync(portFile)) {
    if (Date.now() > deadline) throw new Error('Isolated font fixture did not start.');
    Atomics.wait(new Int32Array(new SharedArrayBuffer(4)), 0, 0, 10);
}
const assetBase = 'http://127.0.0.1:' + fs.readFileSync(portFile, 'utf8') + '/';
const icons = '<i id="solid" class="fa-solid fa-person-circle-question"></i>' +
    '<i id="regular" class="fa-regular fa-address-book"></i>' +
    '<i id="brand" class="fa-brands fa-github"></i>' +
    '<i id="legacy-solid" class="fas fa-rocket"></i>' +
    '<i id="legacy-regular" class="far fa-address-book"></i>' +
    '<i id="legacy-brand" class="fab fa-github"></i>';
// Keep genuine font requests. The isolated file fixture has a different base URL.
const cssFrom = cssFile => fs.readFileSync(cssFile, 'utf8').replace(/url\(([^)]+)\)/g, (match, raw) => {
    const value = raw.trim().replace(/^["']|["']$/g, '');
    if (value.startsWith('data:')) return match;
    const filename = path.resolve(path.dirname(cssFile), value.split(/[?#]/)[0]);
    return 'url("' + assetBase + path.relative(path.join(root, 'assets/common'), filename).split(path.sep).join('/') + '")';
});
const css = cssFrom(data.selections.fa5css.css[0]);
rows.push(...browser(2894, css + ' i{font-size:32px;margin:12px}', icons, `
await document.fonts.ready;
const raster=(content,font)=>{const canvas=document.createElement('canvas');canvas.width=80;canvas.height=70;const c=canvas.getContext('2d');c.font=font;c.fillText(content,5,45);return canvas.toDataURL();};
for(const id of ['solid','regular','brand','legacy-solid','legacy-regular','legacy-brand']) {
    const icon=document.getElementById(id),pseudo=getComputedStyle(icon,'::before'),style=getComputedStyle(icon);
    const content=pseudo.content.slice(1,-1),family=style.fontFamily;
    check(id+' CSS icon has a concrete native glyph',content.length>0&&pseudo.content!=='none',pseudo.content);
    check(id+' uses the selected modern font family',family.includes(id.endsWith('brand')?'Font Awesome 6 Brands':'Font Awesome 6 Free'),family);
    check(id+' requested font is loaded',document.fonts.check(style.fontWeight+' 32px '+family),[...document.fonts].filter(f=>f.status==='loaded').length);
    check(id+' renders genuine font pixels rather than fallback',raster(content,style.fontWeight+' 32px '+family)!==raster(content,'32px serif'));
}
const requests=performance.getEntriesByType('resource').filter(e=>e.name.includes('/webfonts/fa6/')&&e.name.endsWith('.woff2'));
check('all three modern font files were actually requested',new Set(requests.map(e=>e.name)).size===3,requests.map(e=>e.name));
`, 1024));
const requests = fs.readFileSync(requestFile, 'utf8').trim().split('\n').map(line => JSON.parse(line));
for (const font of ['fa-solid-900', 'fa-regular-400', 'fa-brands-400']) {
    rows.push({name: font + ' has a recorded successful HTTP font request',
        pass: requests.some(request => request.path === '/webfonts/fa6/' + font + '.woff2' && request.status === 200)});
}
// Exercise the official SDK's completed native conversion without headless RAF races.
const scripts = '<script>window.FontAwesomeConfig={mutateApproach:"sync"};</script>' + data.selections.fa5js.js.map(filename => '<script>' +
    fs.readFileSync(filename, 'utf8').replace(/<\/script/gi, '<\\/script') + '</script>').join('');
rows.push(...browser(2894, 'svg{font-size:32px;margin:12px}', icons + scripts, `
await window.FontAwesome.dom.i2svg();
for(const id of ['solid','regular','brand','legacy-solid','legacy-regular','legacy-brand']) {
    const svg=document.querySelector('#'+id+'[data-fa-i2svg],#'+id+' svg,svg#'+id);
    check(id+' SVG mode replaces the selected modern class',!!svg,svg&&svg.outerHTML.slice(0,180));
    check(id+' SVG contains real icon path data',!!svg&&!!svg.querySelector('path[d]'));
    check(id+' SVG renders with dimensions',!!svg&&svg.getBoundingClientRect().width>0&&svg.getBoundingClientRect().height>0);
}
check('matching official SVG library is loaded',typeof window.FontAwesome==='object'&&typeof window.FontAwesome.icon==='function');
`, 1024));
const pseudoCss = cssFrom(data.selections.fa5js.css[0]) +
    '.pseudo-solid:before{font-family:"Font Awesome 6 Free";font-weight:900;content:"\\e542"}' +
    '.pseudo-regular:before{font-family:"Font Awesome 6 Free";font-weight:400;content:"\\f2b9"}' +
    '.pseudo-brand:before{font-family:"Font Awesome 6 Brands";font-weight:400;content:"\\f09b"}' +
    '[class^="pseudo-"]:before{font-size:32px;display:inline-block;margin:12px}';
rows.push(...browser(2894, pseudoCss,
    '<span id="pseudo-solid" class="pseudo-solid"></span><span id="pseudo-regular" class="pseudo-regular"></span><span id="pseudo-brand" class="pseudo-brand"></span>' + scripts, `
await document.fonts.ready;
const raster=(content,font)=>{const canvas=document.createElement('canvas');canvas.width=80;canvas.height=70;const c=canvas.getContext('2d');c.font=font;c.fillText(content,5,45);return canvas.toDataURL();};
for(const id of ['pseudo-solid','pseudo-regular','pseudo-brand']) {
    const style=getComputedStyle(document.getElementById(id),'::before'),content=style.content.slice(1,-1);
    check(id+' JS-mode pseudo glyph is defined',content.length>0&&style.content!=='none',style.content);
    check(id+' JS-mode matching font is loaded',document.fonts.check(style.fontWeight+' 32px '+style.fontFamily));
    check(id+' JS-mode pseudo draws genuine glyph pixels',raster(content,style.fontWeight+' 32px '+style.fontFamily)!==raster(content,'32px serif'));
}
check('SVG SDK remains active alongside the font fallback',typeof window.FontAwesome==='object');
`, 1024));
const fallbackRequests = fs.readFileSync(requestFile, 'utf8').trim().split('\n').map(line => JSON.parse(line)).slice(requests.length);
for (const font of ['fa-solid-900', 'fa-regular-400', 'fa-brands-400']) {
    rows.push({name: font + ' JS-mode fallback has its own successful HTTP font request',
        pass: fallbackRequests.some(request => request.path === '/webfonts/fa6/' + font + '.woff2' && request.status === 200)});
}
report(2894, rows);
} finally {
    server.kill('SIGTERM');
    fs.rmSync(temporary, {recursive: true, force: true});
}
