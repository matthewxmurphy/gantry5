/**
 * #2282: native contact radios and labels must remain visible and selectable.
 * https://github.com/gantry/gantry5/issues/2282
 * Copyright (c) 2026 Matthew X Murphy, https://MatthewxMurphy.com.
 * SPDX-License-Identifier: MIT
 * Product/support: https://builtnotbegged.com.
 */
'use strict';
const {root, fs, path, compile, browser, report} = require('./issue-browser');
const nucleus = fs.readFileSync(path.join(root, 'engines/common/nucleus/css-compiled/nucleus.css'), 'utf8');
const native = compile(2282);
const markup = '<form id="contact"><fieldset class="radio btn-group" id="contact-preference">' +
    '<legend>JoomlaMafia contact preference</legend>' +
    '<input type="radio" id="mail" name="preference" value="mail"><label for="mail">Email</label>' +
    '<input type="radio" id="phone" name="preference" value="phone"><label for="phone">Phone</label>' +
    '</fieldset></form>' +
    '<fieldset class="radio btn-group" id="enhanced"><legend>Native enhanced controls</legend>' +
    '<input class="btn-check" type="radio" id="yes" name="enhanced" value="yes" autocomplete="off">' +
    '<label class="btn btn-outline-primary" for="yes">Yes</label>' +
    '<input class="btn-check" type="radio" id="no" name="enhanced" value="no" autocomplete="off">' +
    '<label class="btn btn-outline-primary" for="no">No</label></fieldset>';
const assertions = `
for(const id of ['mail','phone']) {
 const input=document.getElementById(id),label=document.querySelector('label[for="'+id+'"]');
 const bounds=input.getBoundingClientRect(),text=label.getBoundingClientRect();
 const style=getComputedStyle(input),labelStyle=getComputedStyle(label);
 check(id+' native input visible',style.display!=='none'&&style.visibility==='visible'&&bounds.width>0&&bounds.height>0,{display:style.display,width:bounds.width,height:bounds.height});
 check(id+' label text visible',parseFloat(labelStyle.fontSize)>0&&text.width>0&&text.height>0,{fontSize:labelStyle.fontSize,width:text.width});
 check(id+' input has a pointer hit target',document.elementFromPoint(bounds.x+bounds.width/2,bounds.y+bounds.height/2)===input);
 input.focus();check(id+' native control accepts focus',document.activeElement===input&&input.tabIndex===0);
 label.click();check(id+' label activates exactly its native input',input.checked&&document.querySelectorAll('#contact input:checked').length===1);
 check(id+' selected value submitted',new FormData(document.getElementById('contact')).get('preference')===input.value);
}
for(const id of ['yes','no']) {
 const input=document.getElementById(id),label=document.querySelector('label[for="'+id+'"]');
 const bounds=label.getBoundingClientRect();
 check(id+' enhanced button label visible',bounds.width>0&&bounds.height>0&&parseFloat(getComputedStyle(label).fontSize)>0);
 if(modern) {
  const style=getComputedStyle(input);
  check(id+' enhanced button keeps native input clipping',style.position==='absolute'&&style.pointerEvents==='none'&&style.clip!=='auto',{position:style.position,clip:style.clip,pointerEvents:style.pointerEvents});
 }
 label.click();check(id+' enhanced button activates native state',input.checked&&document.querySelectorAll('#enhanced input:checked').length===1);
 input.focus();check(id+' enhanced native input remains focusable',document.activeElement===input);
}
`;
const rows=[];
for(const [build,joomla] of [['source',native],['shipped',fs.readFileSync(path.join(root,'engines/joomla/nucleus/css-compiled/joomla.css'),'utf8')]]) {
    for(const [name,filename] of [['compatibility','assets/joomla/css/bootstrap-gantry.css'],['modern','engines/joomla/nucleus/css-compiled/bootstrap5.css']]) {
        const css=nucleus+'\n'+fs.readFileSync(path.join(root,filename),'utf8')+'\n'+joomla;
        for(const row of browser(2282,css,markup,'const modern='+JSON.stringify(name==='modern')+';'+assertions,1024)) rows.push({...row,name:build+'/'+name+': '+row.name});
    }
}
report(2282,rows);
