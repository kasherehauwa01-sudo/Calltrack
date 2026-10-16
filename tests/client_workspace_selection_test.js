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
        const keys=input.client_keys ?? candidates.map(c=>c.key);
        const context = {candidates, selected_client_keys: keys, selection_required: false, found_in_1c: true};
        if (input.action === 'context') return {context};
        const amount = String((keys.includes('client:a')?11:0)+(keys.includes('client:b')?22:0))+'.00';
        if (input.action === 'summary') {const result={context, summary: {sales_count: 1, revenue: amount, top_categories: [{category:'Тест',revenue:'1083078.53500',quantity:'1'}], average_interval: null, days_without_purchase: null}};if(window.holdSummary&&keys.length===1)return await new Promise(resolve=>{window.releaseSummary=()=>resolve(result);});return result;}
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
    const tags=page.locator('#clientIdentityStatus button');
    await page.waitForFunction(() => document.getElementById('clientSummaryKpis').textContent.includes('33,00 ₽'));
    assert.equal(await page.locator('#clientIdentityStatus select').count(),0);
    assert.equal(await tags.count(),2);
    assert.equal(await tags.nth(1).textContent(),'Бета <не HTML>');
    assert.equal(await tags.nth(1).locator('*').count(),0);
    assert.equal(await tags.nth(0).getAttribute('aria-pressed'),'true');
    assert.equal(await tags.nth(1).getAttribute('aria-pressed'),'true');
    assert.match(await page.locator('#clientTopCategories').textContent(),/1 083 078,54 ₽/);
    await tags.nth(0).click();
    await page.waitForFunction(() => document.getElementById('clientSummaryKpis').textContent.includes('22,00 ₽'));
    await page.locator('#clientPurchasesTab').click();
    await page.waitForFunction(() => document.getElementById('purchaseKpis').textContent.includes('22,00 ₽'));
    await tags.nth(1).click();
    await page.waitForFunction(() => document.getElementById('purchaseKpis').textContent.includes('0,00 ₽'));
    assert.deepEqual(await page.evaluate(() => requests.at(-1).client_keys),[]);
    await tags.nth(0).click();
    await page.waitForFunction(() => document.getElementById('purchaseKpis').textContent.includes('11,00 ₽'));
    await tags.nth(1).click();
    await page.waitForFunction(() => document.getElementById('purchaseKpis').textContent.includes('33,00 ₽'));
    await page.evaluate(() => window.clientWorkspace.open({call_id:4,client_name:'Альфа'}));
    await page.waitForFunction(() => document.getElementById('clientSummaryKpis').textContent.includes('33,00 ₽'));
    assert.equal(await tags.nth(0).getAttribute('aria-pressed'),'true');
    assert.equal(await tags.nth(1).getAttribute('aria-pressed'),'true');
    await page.evaluate(() => {window.holdSummary=true;});
    await tags.nth(0).click();
    await page.waitForFunction(() => typeof window.releaseSummary==='function');
    await tags.nth(1).click();
    await page.waitForFunction(() => document.getElementById('clientSummaryKpis').textContent.includes('0,00 ₽'));
    await page.evaluate(() => window.releaseSummary());
    await page.waitForTimeout(100);
    assert.match(await page.locator('#clientSummaryKpis').textContent(),/0,00 ₽/);
    assert.equal(await tags.nth(0).getAttribute('aria-pressed'),'false');
    assert.equal(await tags.nth(1).getAttribute('aria-pressed'),'false');
    console.log('client_workspace_selection_test: all-active tags, toggles, empty selection, both tabs, rounding, safe labels OK');
  } finally {
    await browser.close();
  }
})().catch(error => {console.error(error); process.exitCode = 1;});
