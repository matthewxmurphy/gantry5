/**
 * #2519: Owl slides fill the available width without overriding measured widths.
 * https://github.com/gantry/gantry5/issues/2519
 * Copyright (c) 2026 Matthew X Murphy, https://MatthewxMurphy.com.
 * SPDX-License-Identifier: MIT
 * Product/support: https://builtnotbegged.com.
 */
'use strict';
const {root,fs,path,compile,browser,report} = require('./issue-browser');
const coreVariants=[
    ['source-core',compile('nucleus')],
    ['shipped-core',fs.readFileSync(path.join(root,'engines/common/nucleus/css-compiled/nucleus.css'),'utf8')],
];
const themeVariants=[
    ['existing-clone',fs.readFileSync(path.join(__dirname,'fixtures/issue-2519-theme-before.css'),'utf8')],
    ['current-theme',compile(2519)],
];
const image='data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 width=%22120%22 height=%2260%22%3E%3Crect width=%22120%22 height=%2260%22 fill=%22%234883b0%22/%3E%3C/svg%3E';
const slide='<div class="g-owlcarousel-item"><div class="g-owlcarousel-item-wrapper"><div class="g-owlcarousel-item-img"><img src="'+image+'" width="120" height="60" alt="JoomlaMafia"></div></div></div>';
const background='<div class="g-owlcarousel-item" style="height:60px;background-image:url('+image+');background-size:cover"></div>';
const assertions=`
const viewport=document.querySelector('.owl-carousel'),item=viewport.querySelector('.owl-item');
const backgroundItem=document.querySelector('#background-slide');
const width=viewport.getBoundingClientRect().width;
check('unmeasured slide fills carousel',Math.abs(item.getBoundingClientRect().width-width)<1,{item:item.getBoundingClientRect().width,viewport:width});
check('intrinsic image fills slide',Math.abs(item.querySelector('img').getBoundingClientRect().width-width)<1,{image:item.querySelector('img').getBoundingClientRect().width,viewport:width});
check('background-image slide fills carousel',Math.abs(backgroundItem.firstElementChild.getBoundingClientRect().width-width)<1,{background:backgroundItem.firstElementChild.getBoundingClientRect().width,viewport:width});
check('drag interaction remains vertical-pan',getComputedStyle(item).touchAction==='pan-y',getComputedStyle(item).touchAction);
item.style.width='173px';
check('native measured inline width remains authoritative',Math.abs(item.getBoundingClientRect().width-173)<1,item.getBoundingClientRect().width);
item.style.width=(width/2)+'px';
backgroundItem.style.width=(width/2)+'px';
check('multi-item inline width is not forced full-width',Math.abs(item.getBoundingClientRect().width-width/2)<1,item.getBoundingClientRect().width);
check('slide image follows measured item width',Math.abs(item.querySelector('img').getBoundingClientRect().width-width/2)<1,item.querySelector('img').getBoundingClientRect().width);
check('measured multi-item slides fit side by side',Math.abs(backgroundItem.getBoundingClientRect().x-item.getBoundingClientRect().x-width/2)<1,{first:item.getBoundingClientRect().x,second:backgroundItem.getBoundingClientRect().x});
`;
const rows=[];
for(const [coreName,core] of coreVariants) {
    for(const [themeName,theme] of themeVariants) {
        for(const width of [320,960]) {
            const markup='<div style="width:'+width+'px" class="g-owlcarousel owl-carousel owl-loaded owl-drag"><div class="owl-stage-outer"><div class="owl-stage"><div class="owl-item">'+slide+'</div><div class="owl-item" id="background-slide">'+background+'</div></div></div></div>';
            for(const row of browser(2519,core+'\n'+theme,markup,assertions,width+80)) rows.push({...row,name:coreName+'/'+themeName+'/'+width+'px: '+row.name});
        }
    }
}
report(2519,rows);
