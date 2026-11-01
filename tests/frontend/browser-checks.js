/**
 * Real-browser regression and keyboard checks for Gantry's built frontend.
 * Copyright 2026 Matthew C. Murphy, https://MatthewxMurphy.com. MIT.
 * Run with Playwright CLI run-code --filename after opening the local fixture.
 */
async (page) => {
  let baseURL = page.url();
  const address = new URL(baseURL);
  if (!['127.0.0.1','localhost','[::1]'].includes(address.hostname)) throw new Error('Use the owned local frontend fixture.');
  baseURL = address.origin + '/';
  const regressions = async (page) => {
  const base = baseURL;
  const bundle = 'candidate';
  const rows = [];
  const record = (name, pass, observed) => rows.push({name, pass:!!pass, observed});
  const load = async (extra='') => {
    await page.goto(base+'?bundle='+bundle+extra);
    await page.waitForFunction(() => window.G5 && G5.menu && G5.offcanvas && G5.offcanvas.attached);
  };
  await page.setViewportSize({width:1440,height:900});
  await load();
  const breakpoints = await page.evaluate(() => {
    const result={};
    for(const v of ['47.9375rem','60.5rem','75.5em','48','48px']) {
      try { result[v]=G5.menu._calculateBreakpoint(v); } catch(e){result[v]=e.name;}
    }
    return result;
  });
  for(const [v,expected] of Object.entries({'47.9375rem':'47.8755rem','60.5rem':'60.438rem','75.5em':'75.438em','48':'47px','48px':'47px'})) record('breakpoint '+v,breakpoints[v]===expected,breakpoints[v]);
  const closed = await page.evaluate(async () => {
    G5.offcanvas.open();await new Promise(r=>setTimeout(r,60));G5.offcanvas.close();
    await new Promise(r=>setTimeout(r,500));
    return {opened:G5.offcanvas.opened,aria:document.querySelector('#g-offcanvas').getAttribute('aria-expanded')};
  });
  record('quick open/close leaves consistent state',!closed.opened&&closed.aria==='false',closed);
  const reopened = await page.evaluate(async () => {
    G5.offcanvas.open();await new Promise(r=>setTimeout(r,350));G5.offcanvas.close();
    await new Promise(r=>setTimeout(r,60));G5.offcanvas.open();await new Promise(r=>setTimeout(r,500));
    return {opened:G5.offcanvas.opened,visible:document.documentElement.classList.contains('g-offcanvas-open')};
  });
  record('quick close/open keeps the panel visible',reopened.opened&&reopened.visible,reopened);
  await load('&fixture=observer');
  const observers=await page.evaluate(async()=>{
    const content=document.querySelector('#g-offcanvas .g-content'),saved=content.innerHTML;
    for(let i=0;i<3;i++){content.innerHTML='';await new Promise(r=>setTimeout(r,80));content.innerHTML=saved;await new Promise(r=>setTimeout(r,80));}
    return {...window.fixtureObservers,active:window.fixtureObservers.made-window.fixtureObservers.disconnected};
  });
  record('one active observer after empty/restore cycles',observers.active===1,observers);
  await load('&touch=1');
  const touch=await page.evaluate(()=>{
    G5.offcanvas.moved=true;
    const event=new TouchEvent('touchmove',{bubbles:true,cancelable:true});
    document.body.dispatchEvent(event);G5.offcanvas.moved=false;
    return {prevented:event.defaultPrevented,dragging:G5.offcanvas.dragging};
  });
  record('registered body touch handler can cancel movement',touch.prevented,touch);
  await load('&mode=smooth');
  await page.evaluate(()=>{document.scrollingElement.scrollTo({top:5000,behavior:'instant'});document.querySelector('#g-totop').click();});
  await page.waitForTimeout(1800);
  const smooth=await page.evaluate(()=>document.scrollingElement.scrollTop);
  record('back-to-top finishes with smooth CSS',smooth<=1,smooth);
  await load('&mode=body-scroll');
  await page.evaluate(()=>{document.body.scrollTop=5000;document.querySelector('#g-totop').click();});
  await page.waitForTimeout(1800);
  const bodyScroll=await page.evaluate(()=>document.body.scrollTop);
  record('back-to-top uses a body scroll container',bodyScroll<=1,bodyScroll);
  await page.emulateMedia({reducedMotion:'reduce'});
  await load('&mode=smooth');
  await page.evaluate(()=>{document.scrollingElement.scrollTo({top:5000,behavior:'instant'});document.querySelector('#g-totop').click();});
  await page.waitForTimeout(80);
  const reduced=await page.evaluate(()=>document.scrollingElement.scrollTop);
  record('reduced motion overrides smooth CSS immediately',reduced<=1,reduced);
  await page.emulateMedia({reducedMotion:'no-preference'});
  await load();
  record('fresh page has no uncaught error',(await page.evaluate(()=>window.fixtureErrors)).length===0,await page.evaluate(()=>window.fixtureErrors));
  return {bundle,checks:rows.length,passed:rows.filter(x=>x.pass).length,failed:rows.filter(x=>!x.pass).length,rows};
};
  const keyboard = async (page) => {
  const base=baseURL+'?bundle=candidate', rows=[];
  const record=(name,pass,observed)=>rows.push({name,pass:!!pass,observed});
  const state=()=>page.evaluate(()=>({opened:G5.offcanvas.opened,focus:document.activeElement.id,expanded:document.querySelector('#menu-toggle').getAttribute('aria-expanded'),hidden:document.querySelector('#g-offcanvas').getAttribute('aria-hidden')}));
  await page.setViewportSize({width:1440,height:900});await page.goto(base);await page.waitForFunction(()=>G5.offcanvas&&G5.menu);
  const semantics=await page.locator('#menu-toggle').evaluate(el=>({role:el.getAttribute('role'),tabIndex:el.tabIndex,controls:el.getAttribute('aria-controls')}));
  record('offcanvas control is keyboard-reachable disclosure',semantics.role==='button'&&semantics.tabIndex===0&&semantics.controls==='g-offcanvas',semantics);
  await page.locator('#menu-toggle').focus();await page.keyboard.press('Enter');await page.waitForTimeout(350);
  let s=await state();record('Enter opens and moves focus into drawer',s.opened&&s.focus==='panel-close'&&s.expanded==='true'&&s.hidden==='false',s);
  const outline=await page.locator('#panel-close').evaluate(el=>({style:getComputedStyle(el).outlineStyle,width:parseFloat(getComputedStyle(el).outlineWidth)}));
  record('focused drawer control has a visible outline',outline.style!=='none'&&outline.width>0,outline);
  
  await page.keyboard.press('Shift+Tab');s=await state();record('Shift+Tab wraps to final visible drawer link',s.focus==='panel-last',s);
  await page.keyboard.press('Tab');s=await state();record('Tab wraps to first drawer control',s.focus==='panel-close',s);
  await page.keyboard.press('Escape');await page.waitForTimeout(350);s=await state();record('Escape closes and restores trigger focus',!s.opened&&s.focus==='menu-toggle'&&s.expanded==='false'&&s.hidden==='true',s);
  await page.keyboard.press('Space');await page.waitForTimeout(350);s=await state();record('Space opens a non-native trigger',s.opened&&s.focus==='panel-close',s);
  await page.keyboard.press('Enter');await page.waitForTimeout(350);s=await state();record('native close button activates once',!s.opened&&s.focus==='menu-toggle',s);
  await page.locator('#products-toggle').focus();await page.keyboard.press('ArrowDown');
  await page.waitForFunction(()=>document.activeElement.id==='product-one');
  let menu=await page.evaluate(()=>({focus:document.activeElement.id,expanded:document.querySelector('#products-toggle').getAttribute('aria-expanded')}));
  record('ArrowDown discloses and focuses first menu link',menu.focus==='product-one'&&menu.expanded==='true',menu);
  await page.keyboard.press('ArrowDown');record('ArrowDown moves between disclosed links',(await state()).focus==='product-two',await state());
  await page.keyboard.press('Home');record('Home selects first disclosed link',(await state()).focus==='product-one',await state());
  await page.keyboard.press('End');record('End selects final disclosed link',(await state()).focus==='product-two',await state());
  await page.keyboard.press('Escape');
  menu=await page.evaluate(()=>({focus:document.activeElement.id,expanded:document.querySelector('#products-toggle').getAttribute('aria-expanded')}));
  record('Escape closes disclosure and restores its control',menu.focus==='products-toggle'&&menu.expanded==='false',menu);
  await page.keyboard.press('Space');record('Space activates a non-link menu parent',(await page.locator('#products-toggle').getAttribute('aria-expanded'))==='true');
  await page.locator('#outside-button').focus();record('leaving navigation closes its disclosure',(await page.locator('#products-toggle').getAttribute('aria-expanded'))==='false');
  await page.setViewportSize({width:390,height:844});await page.waitForTimeout(100);
  record('mobile breakpoint moves real menu into offcanvas',await page.locator('#g-mobilemenu-container #products-toggle').count()===1);
  await page.locator('#menu-toggle').focus();await page.keyboard.press('Enter');await page.waitForTimeout(350);
  await page.locator('#products-toggle').focus();await page.keyboard.press('ArrowDown');await page.keyboard.press('Escape');
  s=await state();record('first mobile Escape closes submenu and keeps drawer open',s.opened&&(await page.locator('#products-toggle').getAttribute('aria-expanded'))==='false',s);
  await page.keyboard.press('Escape');await page.waitForTimeout(350);s=await state();record('second mobile Escape closes drawer and returns focus',!s.opened&&s.focus==='menu-toggle',s);
  await page.emulateMedia({reducedMotion:'reduce'});
  const motion=await page.evaluate(()=>{G5.offcanvas.open();return document.querySelector('#g-page-surround').style.transition;});
  record('reduced motion disables drawer transform animation',motion==='none',motion);
  await page.waitForTimeout(40);
  await page.emulateMedia({reducedMotion:'no-preference'});
  const context=await page.context().browser().newContext({hasTouch:true,isMobile:true,viewport:{width:390,height:844}});
  try{
    const mobile=await context.newPage();await mobile.goto(base);await mobile.waitForFunction(()=>G5.offcanvas&&G5.menu);
    await mobile.locator('#menu-toggle').tap();await mobile.waitForTimeout(350);
    record('real emulated tap opens once',await mobile.evaluate(()=>G5.offcanvas.opened));
    await mobile.locator('#panel-close').tap();await mobile.waitForTimeout(350);
    record('real emulated tap closes once',!(await mobile.evaluate(()=>G5.offcanvas.opened)));
    record('touch context has no uncaught errors',(await mobile.evaluate(()=>window.fixtureErrors)).length===0,await mobile.evaluate(()=>window.fixtureErrors));
  }finally{await context.close();}
  record('keyboard context has no uncaught errors',(await page.evaluate(()=>window.fixtureErrors)).length===0,await page.evaluate(()=>window.fixtureErrors));
  return {checks:rows.length,passed:rows.filter(x=>x.pass).length,failed:rows.filter(x=>!x.pass).length,rows};
};
  const phase = address.searchParams.get('phase');
  if (phase && !['regressions', 'keyboard'].includes(phase)) throw new Error('Unknown check phase.');
  const results = [];
  if (phase !== 'keyboard') results.push(await regressions(page));
  if (phase !== 'regressions') results.push(await keyboard(page));
  const rows = results.flatMap(result => result.rows);
  return {checks:rows.length,passed:rows.filter(row=>row.pass).length,failed:rows.filter(row=>!row.pass).length,rows};
}
