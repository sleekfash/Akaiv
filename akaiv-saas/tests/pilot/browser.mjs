import { chromium } from 'playwright';
import assert from 'node:assert/strict';
import { readFileSync, existsSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import { setTimeout as delay } from 'node:timers/promises';
const base = process.env.PILOT_BASE_URL || 'http://127.0.0.1:8000';
assert.match(base, /^http:\/\/127\.0\.0\.1:\d+$/);
const browser = await chromium.launch({ headless: true, ...(process.env.PILOT_CHROMIUM_PATH ? { executablePath: process.env.PILOT_CHROMIUM_PATH } : {}) });
const errors = [];
let checks = 0;
let lastPage;
async function login(name, viewport) {
  const context = await browser.newContext(viewport ? {viewport} : {});
  const page = await context.newPage();
  lastPage = page;
  page.on('pageerror', error => errors.push(error.message));
  page.on('response', async response => {
    if (new URL(response.url()).pathname !== '/livewire/update' || response.status() !== 200) return;
    try {
      const result = await response.json();
      const request = response.request().postDataJSON();
      console.log('Livewire exchange:', JSON.stringify({
        methods: (request.components || []).flatMap(c => (c.calls || []).map(x => x.method)),
        events: (result.components || []).flatMap(c => (c.effects?.dispatches || []).map(e => ({name:e.name, field:e.params?.name, origin:e.params?.payload?.url ? new URL(e.params.payload.url).origin : null})))
      }));
    } catch {}
  });
  page.on('request', request => { if (new URL(request.url()).port === '9000') console.log('Object request:', request.method(), new URL(request.url()).pathname); });
  page.on('console', message => { if(message.type()==='error') console.error('Browser console:', message.text()); });
  page.on('requestfailed', request => console.error('Request failed:', request.method(), new URL(request.url()).origin+new URL(request.url()).pathname, request.failure()?.errorText));
  page.on('response', response => { if(response.status()>=400) console.error('HTTP error:', response.status(), new URL(response.url()).pathname); });
  await page.goto(`${base}/admin/login`);
  await page.getByLabel('Email address').fill(`${name}@example.test`);
  await page.locator('input[id="data.password"]').fill('Synthetic-Pilot-2026!');
  await page.getByRole('button', { name: 'Sign in', exact: true }).click();
  await page.waitForURL(url => url.pathname === '/admin');
  return { page, context };
}
try {
  const { page, context } = await login('clerk');
  await page.goto(`${base}/admin/cases`);
  await page.getByText('Synthetic Pilot Case', { exact: true }).waitFor(); checks++;
  assert.equal(await page.getByText('Synthetic Sealed Case', { exact: true }).count(), 0); checks++;
  assert.equal(await page.getByText('Other Tenant Private Case', { exact: true }).count(), 0); checks++;
  await page.goto(`${base}/admin/cases/create`);
  await page.locator('input[id="data.title"]').fill('Browser Created Synthetic Case');
  await page.locator('input[id="data.suit_number"]').fill('BROWSER / 2026 / 1');
  await page.locator('input[id="data.subject_matter"]').fill('Browser pilot proof');
  if (await page.locator('select[id$=".role"]').count()) {
    await page.locator('select[id$=".role"]').selectOption('Claimant');
    await page.locator('input[id$=".name"]').fill('Synthetic Party');
  }
  await page.getByRole('button', { name: 'Create', exact: true }).click();
  await page.waitForURL(url => /\/admin\/cases\/\d+\/edit/.test(url.pathname)); checks++;
  await page.locator('input[id="data.subject_matter"]').fill('Browser edited metadata');
  await page.getByRole('button', { name: 'Save changes', exact: true }).click();
  await page.getByText('Saved', { exact: true }).waitFor();
  await page.reload();
  assert.equal(await page.locator('input[id="data.subject_matter"]').inputValue(), 'Browser edited metadata'); checks++;
  for (const resource of ['documents', 'proceedings', 'audits']) {
    const response = await page.goto(`${base}/admin/${resource}`);
    assert.equal(response.status(), 200); checks++;
  }
  await page.goto(`${base}/admin/documents`);
  const preview = page.getByRole('link', { name: 'Preview', exact: true }).first();
  const href = await preview.getAttribute('href');
  const file = await context.request.get(href);
  assert.equal(file.status(), 200);
  assert.equal(await file.text(), 'Synthetic private pilot judgment'); checks++;
  assert.equal((await context.request.get(href.replace(/signature=[^&]+/, 'signature=invalid'))).status(), 403); checks++;
  await page.goto(`${base}/admin/documents/create`);
  await page.locator('input[id="data.friendly_name"]').fill('Browser uploaded judgment');
  await page.locator('.choices').filter({has: page.locator('select[id="data.case_id"]')}).locator('.choices__inner').click();
  await page.locator('.choices__input--cloned:visible').pressSequentially('Synthetic Pilot Case', {delay: 30});
  await page.locator('.choices__list--dropdown:visible').getByRole('option', {name: 'Synthetic Pilot Case', exact: true}).click();
  await page.locator('select[id="data.judicial_document_type"]').selectOption('judgment');
  await page.locator('input[id="data.date_delivered"]').fill('2026-10-13');
  await page.locator('input[type="file"]').setInputFiles({name:'browser-judgment.txt',mimeType:'text/plain',buffer:Buffer.from('Synthetic browser-uploaded judgment')});
  await page.locator('.filepond--item[data-filepond-item-state="processing-complete"]').waitFor();
  await page.getByRole('button', {name:'Create',exact:true}).click();
  await page.waitForURL(url => /\/admin\/documents\/[^/]+\/edit/.test(url.pathname));
  for (let attempt=0;attempt<60;attempt++) {
    const state=JSON.parse(execFileSync('php',['tests/pilot/browser-state.php'],{encoding:'utf8'}));
    if(state.scan_state==='clean') break;
    if(state.scan_state==='error'||state.scan_state==='infected') throw new Error('Browser upload failed scanning');
    await delay(1000);
  }
  assert.equal(JSON.parse(execFileSync('php',['tests/pilot/browser-state.php'],{encoding:'utf8'})).scan_state,'clean'); checks++;
  await page.goto(`${base}/admin/documents`);
  const uploaded=page.getByRole('row').filter({hasText:'Browser uploaded judgment'});
  await uploaded.getByRole('button',{name:'Submit',exact:true}).click();
  await page.getByRole('dialog').getByRole('button',{name:'Confirm',exact:true}).click();
  await uploaded.getByText('pending_review',{exact:true}).waitFor(); checks++;
  assert.equal(await uploaded.getByRole('button',{name:'Publish',exact:true}).count(),0); checks++;
  await context.close();
  const judge = await login('judge');
  await judge.page.goto(`${base}/admin/cases`);
  await judge.page.getByText('Synthetic Sealed Case', { exact: true }).waitFor(); checks++;
  await judge.page.goto(`${base}/admin/documents`);
  const review=judge.page.getByRole('row').filter({hasText:'Browser uploaded judgment'});
  await review.getByRole('button',{name:'Publish',exact:true}).click();
  await judge.page.getByRole('dialog').getByRole('button',{name:'Confirm',exact:true}).click();
  await review.getByText('published',{exact:true}).waitFor(); checks++;
  await judge.context.close();
  const reader = await login('reader');
  await reader.page.goto(`${base}/admin/cases`);
  assert.equal(await reader.page.getByRole('link', { name: 'New case', exact: true }).count(), 0); checks++;
  assert.equal((await reader.page.goto(`${base}/admin/cases/create`)).status(), 403); checks++;
  await reader.page.goto(`${base}/admin/documents`);
  assert.equal(await reader.page.getByRole('button', { name: 'Archive', exact: true }).count(), 0); checks++;
  await reader.context.close();
  const mobile=await login('clerk',{width:390,height:844});
  await mobile.page.goto(`${base}/admin/cases`);
  await mobile.page.getByText('Synthetic Pilot Case',{exact:true}).waitFor();
  assert.ok(await mobile.page.evaluate(() => document.documentElement.scrollWidth <= innerWidth+1)); checks++;
  await mobile.context.close();
  const anonymous = await browser.newContext();
  const reset = await anonymous.newPage();
  lastPage = reset;
  assert.equal((await reset.goto(`${base}/admin/password-reset/request`)).status(), 200); checks++;
  await reset.getByLabel('Email address').fill('reader@example.test');
  await reset.getByRole('button', { name: 'Send email', exact: true }).click();
  await reset.getByText('We have emailed your password reset link.', { exact: true }).waitFor();
  for (let attempt = 0; attempt < 30; attempt++) {
    if (existsSync('storage/logs/laravel.log') && readFileSync('storage/logs/laravel.log', 'utf8').includes('/admin/password-reset/reset?')) break;
    await delay(1000);
  }
  const log = readFileSync('storage/logs/laravel.log', 'utf8').replace(/=\r?\n/g, '').replace(/=3D/g, '=');
  const urls = log.match(/http:\/\/127\.0\.0\.1:8000\/admin\/password-reset\/reset\?[^\s<>\"]+/g);
  assert.ok(urls?.length, 'Reset mail must be captured by the isolated log transport');
  await reset.goto(urls.at(-1).replace(/&amp;/g, '&').replace(/\)$/, ''));
  await reset.locator('input[type="password"]').nth(0).fill('Synthetic-Changed-2026!');
  await reset.locator('input[type="password"]').nth(1).fill('Synthetic-Changed-2026!');
  await reset.getByRole('button', { name: 'Reset password', exact: true }).click();
  await reset.waitForURL(url => url.pathname === '/admin/login');
  await reset.getByLabel('Email address').fill('reader@example.test');
  await reset.locator('input[type="password"]').nth(0).fill('Synthetic-Changed-2026!');
  await reset.getByRole('button', { name: 'Sign in', exact: true }).click();
  await reset.waitForURL(url => url.pathname === '/admin'); checks++;
  await reset.getByRole('button', { name: 'User menu', exact: true }).click();
  await reset.getByRole('button', { name: 'Sign out', exact: true }).click();
  await reset.waitForURL(url => url.pathname === '/admin/login'); checks++;
  await reset.goto(`${base}/admin/documents`);
  assert.equal(new URL(reset.url()).pathname, '/admin/login'); checks++;
  await anonymous.close();
  assert.deepEqual(errors, []);
  console.log(`Browser pilot passed: ${checks} checks; no JavaScript page errors.`);
} catch (error) {
  console.error(`Browser pilot stopped after ${checks} checks.`);
  if (lastPage && !lastPage.isClosed()) {
    console.error((await lastPage.locator('body').innerText()).slice(0,6000));
    console.error(await lastPage.evaluate(() => ({width: innerWidth, sheets: [...document.styleSheets].map(s => s.href), sidebar: [...document.querySelectorAll('.fi-sidebar,.fi-main-ctn')].map(el => ({class: el.className, position: getComputedStyle(el).position, display: getComputedStyle(el).display, box: el.getBoundingClientRect().toJSON()}))})));
  }
  throw error;
} finally { await browser.close(); }
