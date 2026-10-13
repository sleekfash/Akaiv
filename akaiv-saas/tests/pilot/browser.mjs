import { chromium } from 'playwright';
import assert from 'node:assert/strict';
const base = process.env.PILOT_BASE_URL || 'http://127.0.0.1:8000';
assert.match(base, /^http:\/\/127\.0\.0\.1:\d+$/);
const browser = await chromium.launch({ headless: true, ...(process.env.PILOT_CHROMIUM_PATH ? { executablePath: process.env.PILOT_CHROMIUM_PATH } : {}) });
const errors = [];
let checks = 0;
async function login(name) {
  const context = await browser.newContext();
  const page = await context.newPage();
  page.on('pageerror', error => errors.push(error.message));
  await page.goto(`${base}/admin/login`);
  await page.getByLabel('Email address').fill(`${name}@example.test`);
  await page.getByLabel('Password', { exact: true }).fill('Synthetic-Pilot-2026!');
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
  await page.getByLabel('Title', { exact: true }).fill('Browser Created Synthetic Case');
  await page.getByLabel('Suit number', { exact: true }).fill('BROWSER / 2026 / 1');
  await page.getByLabel('Subject matter', { exact: true }).fill('Browser pilot proof');
  await page.getByRole('button', { name: 'Create', exact: true }).click();
  await page.waitForURL(url => /\/admin\/cases\/\d+\/edit/.test(url.pathname)); checks++;
  await page.getByLabel('Subject matter', { exact: true }).fill('Browser edited metadata');
  await page.getByRole('button', { name: 'Save changes', exact: true }).click();
  await page.getByText('Saved', { exact: true }).waitFor(); checks++;
  for (const resource of ['documents', 'proceedings', 'audits']) {
    const response = await page.goto(`${base}/admin/${resource}`);
    assert.equal(response.status(), 200); checks++;
  }
  await context.close();
  const judge = await login('judge');
  await judge.page.goto(`${base}/admin/cases`);
  await judge.page.getByText('Synthetic Sealed Case', { exact: true }).waitFor(); checks++;
  await judge.context.close();
  const reader = await login('reader');
  await reader.page.goto(`${base}/admin/cases`);
  assert.equal(await reader.page.getByRole('link', { name: 'New case', exact: true }).count(), 0); checks++;
  assert.equal((await reader.page.goto(`${base}/admin/cases/create`)).status(), 403); checks++;
  await reader.context.close();
  const anonymous = await browser.newContext();
  const reset = await anonymous.newPage();
  assert.equal((await reset.goto(`${base}/admin/password-reset/request`)).status(), 200); checks++;
  await anonymous.close();
  assert.deepEqual(errors, []);
  console.log(`Browser pilot passed: ${checks} checks; no JavaScript page errors.`);
} finally { await browser.close(); }
