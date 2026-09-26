const fs = require('fs');
const vm = require('vm');

const html = fs.readFileSync(`${__dirname}/../analizmop/index.html`, 'utf8');
const groupingSource = html.match(/function groupSalesByDate\(sales\)\{[\s\S]*?\n\}/)?.[0];
if (!groupingSource) throw new Error('Функция groupSalesByDate не найдена');
const sortingSource = html.match(/function sortClientTimelineEvents\(events\)\{[^\n]+\}/)?.[0];
if (!sortingSource) throw new Error('Функция sortClientTimelineEvents не найдена');
const context = {};
vm.runInNewContext(`${groupingSource}\n${sortingSource}`, context);

function group(input) {
  context.input = input;
  vm.runInContext('result=groupSalesByDate(input);', context);
  return JSON.parse(JSON.stringify(context.result));
}
function assert(condition, message) {
  if (!condition) throw new Error(message);
}

let result = group([{ sale_id: 1, sale_date: '2026-09-25', total_amount: '2125.58' }]);
assert(result.length === 1 && result[0].sales.length === 1, 'Одна продажа должна оставаться одиночной');

result = group([
  { sale_id: 141953, sale_date: '2026-09-25', total_amount: '2125.58' },
  { sale_id: 141954, sale_date: '2026-09-25', total_amount: '848.10' },
  { sale_id: 141955, sale_date: '2026-09-25', total_amount: '14201.38' },
]);
assert(result.length === 1 && result[0].sales.length === 3, 'Продажи одного дня не объединены в одну группу');
assert(Math.abs(result[0].totalAmount - 17175.06) < 0.001, 'Неверная сумма группы продаж');

result = group([
  { sale_id: 1, sale_date: '2026-09-25', total_amount: 1 },
  { sale_id: 2, sale_date: '2026-09-25', total_amount: 2 },
  { sale_id: 3, sale_date: '2026-09-25', total_amount: 3 },
  { sale_id: 4, sale_date: '2026-09-26', total_amount: 4 },
  { sale_id: 5, sale_date: '2026-09-26', total_amount: 5 },
]);
assert(result.length === 2 && result[0].sales.length === 3 && result[1].sales.length === 2, 'Продажи разных дат сгруппированы неверно');

result = group([
  { sale_id: 7, sale_date: '2026-09-25', total_amount: 10 },
  { sale_id: 7, sale_date: '2026-09-25', total_amount: 10 },
]);
assert(result.length === 1 && result[0].sales.length === 1 && result[0].totalAmount === 10, 'Дубли sale_id попали в группу повторно');

context.events = [
  { html: 'Продажа', day: '2026-09-25', time: 0, saleLast: 1, type: 0, id: 3 },
  { html: 'Звонок', day: '2026-09-25', time: 18, saleLast: 0, type: 1, id: 2 },
  { html: 'Email', day: '2026-09-25', time: 10, saleLast: 0, type: 2, id: 1 },
];
vm.runInContext('sorted=sortClientTimelineEvents(events);', context);
assert(Array.from(context.sorted, (event) => event.html).join(',') === 'Email,Звонок,Продажа', 'Продажа должна быть последней среди событий одной даты');

for (const marker of [
  'data-sale-group=',
  'data-sale-detail-id=',
  'openSaleCard(sale.dataset.saleDetailId,activeSaleGroupKey)',
  '← К списку продаж',
  'width:100vw',
  'height:100vh',
  'sortClientTimelineEvents(events)',
  'isSalesJournalDetailAllowed',
]) {
  const source = marker === 'isSalesJournalDetailAllowed'
    ? fs.readFileSync(`${__dirname}/../api/sale_detail.php`, 'utf8')
    : html;
  assert(source.includes(marker), `Не найден обязательный маркер: ${marker}`);
}
for (const removedField of ['Исходные данные', 'Социальная продажа', 'Номер строки']) {
  assert(!html.includes(removedField), `Удалённое поле осталось в карточке: ${removedField}`);
}

console.log('client_sales_grouping_test: OK');
