// Isolated browser regression: exercise the real workspace script with controlled API responses.
const fs = require('fs');
const path = require('path');
const assert = require('assert/strict');
const { chromium } = require('playwright');

(async () => {
  const browser = await chromium.launch({executablePath: process.env.CHROMIUM_PATH || '/usr/bin/chromium', headless: true, args: ['--no-sandbox']});
  try {
    const page = await browser.newPage();
    const root = path.join(__dirname, '..');
    await page.setContent(fs.readFileSync(path.join(root, 'analizmop/index.html'), 'utf8').replace(/<script\b[^>]*>[\s\S]*?<\/script>/gi, '').replace(/<link\b[^>]*>/gi, ''));
    await page.evaluate(() => {
      window.requests = [];
      window.calltrackApi = {requestJson: async (url, options) => {
        const input = JSON.parse(options.body); window.requests.push(input);
        const candidates = [{key: 'client:a', name: 'Альфа', id: 'a'}, {key: 'client:b', name: 'Бета <не HTML>', id: 'b'}];
        const key = input.client_key || (input.client_name === 'Альфа' ? 'client:a' : '');
        const context = {candidates, selected_client_key: key, selection_required: !key, found_in_1c: true};
        if (input.action === 'context' || !key) return {context};
        const amount = key === 'client:a' ? '11.00' : '22.00';
        if (input.action === 'summary') return {context, summary: {sales_count: 1, revenue: amount, top_categories: [], average_interval: null, days_without_purchase: null}};
        return {context, tree: [], analytics: {metric: 'revenue', current_total: amount, previous_total: '0.00', absolute_change: amount, percentage_change: null, buckets: []}};
      }};
    });
    await page.evaluate(() => {
      document.getElementById('appShell').hidden = false;
      document.getElementById('authScreen').hidden = true;
      document.getElementById('clientHistoryModal').classList.add('open');
    });
    await page.addScriptTag({path: path.join(root, 'analizmop/client-workspace.js')});
    await page.evaluate(() => window.clientWorkspace.open({call_id: 4, client_name: 'Альфа / Бета'}));
    const select = page.locator('#clientIdentityStatus select');
    await select.waitFor();
    assert.equal(await select.locator('option').count(), 3);
    assert.equal(await select.locator('option').nth(2).textContent(), 'Бета <не HTML>');
    assert.equal(await page.locator('#clientIdentityStatus option *').count(), 0);
    assert.equal(await page.evaluate(() => requests.some(r => r.action === 'summary')), false);
    await select.selectOption('client:b');
    await page.waitForFunction(() => document.getElementById('clientSummaryKpis').textContent.includes('22 ₽'));
    assert.equal(await page.evaluate(() => requests.find(r => r.action === 'summary').client_key), 'client:b');
    await page.locator('#clientPurchasesTab').click();
    await page.waitForFunction(() => document.getElementById('purchaseKpis').textContent.includes('22 ₽'));
    // An exact opened name continues to resolve automatically, without an extra chooser.
    await page.evaluate(() => window.clientWorkspace.open({call_id: 4, client_name: 'Альфа'}));
    await page.waitForFunction(() => document.getElementById('clientSummaryKpis').textContent.includes('11 ₽'));
    assert.equal(await select.count(), 0);
    // Choosing from the purchases tab refreshes both panels for the same identity.
    await page.evaluate(() => window.clientWorkspace.open({call_id: 4, client_name: 'Альфа / Бета'}));
    await select.waitFor();
    await page.locator('#clientPurchasesTab').click();
    await select.selectOption('client:a');
    await page.waitForFunction(() => document.getElementById('clientSummaryKpis').textContent.includes('11 ₽') && document.getElementById('purchaseKpis').textContent.includes('11 ₽'));
    console.log('client_workspace_selection_test: shared contact, exact name, both tabs, safe labels OK');
  } finally {
    await browser.close();
  }
})().catch(error => {console.error(error); process.exitCode = 1;});
