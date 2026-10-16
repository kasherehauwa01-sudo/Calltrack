const fs = require('fs');
const vm = require('vm');
const assert = require('assert/strict');
const source = fs.readFileSync(`${__dirname}/../analizmop/client-workspace.js`, 'utf8');
const shareSource = source.match(/  function revenueShare\([\s\S]*?\n  \}/)[0];
const renderSource = source.match(/  async function loadSummary\([\s\S]*?\n  \}/)[0];
const context = vm.createContext({});
vm.runInContext(shareSource, context);
const share = context.revenueShare;
assert.equal(share('25.00', '100.00'), '25,00 %');
assert.equal(share('1.00', '3.00'), '33,33 %');
assert.equal(share('2.00', '3.00'), '66,67 %');
assert.equal(share('0.01', '200.00'), '0,01 %');
assert.equal(share('0.00', '100.00'), '0,00 %');
assert.equal(share('1.00', '0.00'), '—');
assert.equal(share('1.00', undefined), '—');
assert.equal(share('invalid', '100.00'), '—');
assert.equal(share('-25.00', '100.00'), '-25,00 %');
assert.equal(share('150.00', '100.00'), '150,00 %');
assert.equal(share('9007199254740993.00', '18014398509481986.00'), '50,00 %');
assert.equal(share('2489695.02000', '4979390.04'), '50,00 %');
assert.equal(share('525861.65000', '1051723.30000'), '50,00 %');
assert.equal(share('0.00001', '0.00004'), '25,00 %');
assert.equal(share('1', '4.00000'), '25,00 %');
assert.equal(share('-0.00001', '0.00004'), '-25,00 %');
assert.equal(share('1.00000', '0.00000'), '—');
new vm.Script(source);

(async () => {
  const elements = {};
  Object.assign(context, {
    epoch: 1, needsSelection: false, clearSummary() {}, applyPurchaseContext: () => true,
    $: id => elements[id] ||= {}, escape: String, money: String, number: String, dateLabel: String,
    api: async () => ({context: {}, summary: {
      revenue: '100.00', sales_count: 4, average_interval: null,
      top_categories: [{category:'A', revenue:'25.00000', quantity:1}, {category:'B', revenue:'25.00000', quantity:1}]
    }})
  });
  vm.runInContext(renderSource, context);
  await context.loadSummary();
  const table = elements.clientTopCategories.innerHTML;
  assert.ok(table.includes('<th>Доля в общей выручке</th>'));
  assert.equal((table.match(/25,00 %/g) || []).length, 2, 'Use lifetime total, not the sum of displayed categories');
  assert.ok(!table.includes('50,00 %'));
  console.log('client_category_revenue_share_test: OK');
})().catch(error => { console.error(error); process.exitCode = 1; });
