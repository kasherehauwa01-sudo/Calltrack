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
  let rendered;
  Object.assign(context, {
    window: {calltrackCategoryTree: {render: (element, categories, options) => { rendered = {element, categories, options}; }}},
    epoch: 1, needsSelection: false, clearSummary() {}, applyPurchaseContext: () => true,
    $: id => elements[id] ||= {}, escape: String, money: String, number: String, dateLabel: String,
    api: async () => ({context: {}, summary: {
      revenue: '100.00', sales_count: 4, average_interval: null,
      top_categories: [{category:'A', revenue:'25.00000', quantity:1}, {category:'B', revenue:'25.00000', quantity:1}]
    }})
  });
  vm.runInContext(renderSource, context);
  await context.loadSummary();
  assert.equal(elements.clientSummaryRetry.hidden, true, 'Rendering must not fall into the error handler');
  assert.ok(rendered, 'Summary must invoke the category tree renderer');
  assert.equal(rendered.element, elements.clientTopCategories);
  assert.equal(rendered.options.totalRevenue, '100.00', 'Use lifetime total, not displayed top categories');
  assert.equal(rendered.categories.length, 2);
  for (const category of rendered.categories) {
    assert.equal(rendered.options.share(category.revenue, rendered.options.totalRevenue), '25,00 %');
  }
  assert.equal(rendered.options.isCurrent(), true);
  context.epoch++;
  assert.equal(rendered.options.isCurrent(), false, 'Late category responses must be ignored after client change');
  console.log('client_category_revenue_share_test: OK');
})().catch(error => { console.error(error); process.exitCode = 1; });
