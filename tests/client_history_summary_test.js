const fs = require('fs');
const vm = require('vm');
const assert = require('assert');
const html = fs.readFileSync(`${__dirname}/../analizmop/index.html`, 'utf8');
const context = vm.createContext({});
for (const name of ['groupSalesByDate', 'summarizeClientHistory', 'currentClientSales']) {
  const source = html.match(new RegExp(`function ${name}\\([^]*?\\n\\}`));
  assert(source, `Missing ${name}`);
  vm.runInContext(source[0], context);
}
const sale = (id, date, amount) => ({ sale_id: id, sale_date: date, total_amount: amount });
function summary(groups) {
  context.groups = groups;
  return JSON.parse(vm.runInContext('JSON.stringify(summarizeClientHistory(groups))', context));
}
assert.deepStrictEqual(summary([]), { clientCount: 0, saleCount: 0, totalAmount: 0 });
assert.deepStrictEqual(summary([{sales: []}]), { clientCount: 1, saleCount: 0, totalAmount: 0 });
const first = sale(1, '2026-10-01', '100.25');
assert.deepStrictEqual(summary([
  {sales: [first, first, sale(2, '2026-10-01', '200.75'), sale(3, '2026-10-02', 50)]},
  {sales: [sale(4, '2026-10-01', 400)]},
  {sales: []},
]), { clientCount: 3, saleCount: 3, totalAmount: 751 });
// A sale matched through multiple client aliases must not inflate the dashboard.
assert.deepStrictEqual(summary([{sales: [first]}, {sales: [first]}]),
  { clientCount: 2, saleCount: 1, totalAmount: 100.25 });
context.clientSales = [first];
context.clientSalesScope = 'old-manager-and-period';
context.clientSalesScopeKey = () => 'new-manager-and-period';
assert.strictEqual(vm.runInContext('currentClientSales().length', context), 0);
context.clientSalesScope = 'new-manager-and-period';
assert.strictEqual(vm.runInContext('currentClientSales().length', context), 1);
// Parse the entire inline application script, including the updated row template.
for (const match of html.matchAll(/<script\b[^>]*>([\s\S]*?)<\/script>/g)) {
  if (match[1].trim()) new vm.Script(match[1]);
}
console.log('client_history_summary_test: OK');
