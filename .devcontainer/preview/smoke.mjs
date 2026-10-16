import { chromium } from 'playwright';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
const logins = JSON.parse(readFileSync('../logins.json', 'utf8'));
const browser = await chromium.launch();
try {
  for (const login of Object.values(logins)) {
    const context = await browser.newContext();
    const page = await context.newPage();
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.goto('http://127.0.0.1:8000/admin/login');
    await page.getByLabel('Email address').fill(login.email);
    await page.locator('input[id="data.password"]').fill(login.password);
    await page.getByRole('button', { name: 'Sign in', exact: true }).click();
    await page.waitForURL(url => url.pathname === '/admin');
    const response = await page.goto('http://127.0.0.1:8000/admin/cases');
    assert.equal(response.status(), 200);
    assert.deepEqual(errors, []);
    await context.close();
  }
  assert.equal((await fetch('http://127.0.0.1:8000/.env')).status, 403);
  assert.equal((await fetch('http://127.0.0.1:9000/akaiv-preview/missing')).status, 403);
  console.log('Preview checks passed: two random logins, protected case pages, hidden environment and private storage.');
} finally { await browser.close(); }
