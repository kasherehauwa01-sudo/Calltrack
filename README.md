# Calltrack (Corporate Dialer)

Android-приложение для корпоративных устройств отдела продаж:
- выполняет звонки как dialer;
- отслеживает входящие/исходящие состояния звонков;
- собирает аналитику (тип, длительность, заметка);
- отправляет события в Google Sheets через webhook;
- при отсутствии сети откладывает отправку (Room-очередь).

## Технологии
- Kotlin, Android SDK
- MVVM + Repository
- Room (локальная очередь)
- Retrofit + OkHttp (webhook)
- DataStore (флаг onboarding)
- Foreground Service
- TelephonyCallback (Android 12+) + PhoneStateListener fallback
- Activity Result API

---

## 1. Как собрать проект
1. Откройте проект в **Android Studio Hedgehog+** (или новее).
2. Дождитесь Gradle Sync.
3. Проверьте `minSdk=26`, `targetSdk=35` в `app/build.gradle`.
4. Выполните сборку:
   - через меню: **Build → Make Project**;
   - или в терминале: `./gradlew :app:assembleDebug`.
5. Готовый APK: `app/build/outputs/apk/debug/app-debug.apk`.

Для APK обновления обязательно задавайте версию самой сборки, а не только имя файла
или запись в админ-панели:

```bash
./gradlew :app:assembleDebug -PcalltrackVersionCode=13 -PcalltrackVersionName=1.0.13
```

`versionCode` новой сборки должен быть больше установленного и совпадать с версией,
которую публикует сервер обновлений. Для повторной сборки одной версии используйте
тот же ключ подписи, которым подписано установленное приложение.
Gradle создаст файл `calltrack_v1.0.13_13.apk`; админ-панель читает серверную
версию из этого имени и больше не присваивает APK произвольную версию отдельно от сборки.

---

## 2. Как установить APK
1. Скопируйте `app-debug.apk` на устройство.
2. На устройстве разрешите установку из неизвестных источников:
   - **Settings → Security / Apps → Install unknown apps**.
3. Установите APK через файловый менеджер.
4. После установки откройте приложение `Calltrack`.

---

## 3. Как настроить приложение
При первом запуске откроется onboarding:
1. Экран приветствия.
2. Выдача разрешений:
   - `READ_PHONE_STATE`
   - `READ_CALL_LOG`
   - `CALL_PHONE`
   - `RECORD_AUDIO`
   - `INTERNET`
3. Экран **Авторизация**: введите обязательное поле `ФИО` и нажмите `Ок`.
4. Завершение и вход в основной экран.

### Важно для корпоративных устройств
- Отключите энергосбережение для приложения:
  - **Settings → Battery → Unrestricted / Don't optimize**.
- Разрешите автозапуск приложения (если есть политика производителя).
- Разрешите уведомления (для Foreground Service).

---

## 4. Настройка Google Sheets интеграции

### Шаг 1. Создать Google Apps Script
1. Откройте Google Sheets.
2. **Extensions → Apps Script**.

### Шаг 2. Вставить webhook-код
```javascript
var CALLTRACK_SPREADSHEET_ID = "PASTE_CALLTRACK_SPREADSHEET_ID_HERE";
var CALLS_SHEET_NAME = "Calls";

function getCallsSheet() {
  var ss = CALLTRACK_SPREADSHEET_ID && CALLTRACK_SPREADSHEET_ID !== "PASTE_CALLTRACK_SPREADSHEET_ID_HERE"
    ? SpreadsheetApp.openById(CALLTRACK_SPREADSHEET_ID)
    : SpreadsheetApp.getActiveSpreadsheet();

  var sheet = ss.getSheetByName(CALLS_SHEET_NAME);
  if (!sheet) throw new Error("Лист не найден: " + CALLS_SHEET_NAME);
  return sheet;
}

function doPost(e) {
  var sheet = getCallsSheet();
  var data = JSON.parse(e.postData.contents);
  var comment = data.comment || data.note || "";
  var reminderText = data.reminder_text || data.reminderText || "";
  var callId = String(data.call_id || "");

  // Сопоставляем данные по названиям колонок, а не по фиксированному порядку.
  // Это защищает от "съезда" значений по колонкам.
  var header = sheet.getRange(1, 1, 1, sheet.getLastColumn()).getValues()[0];
  var headerIndex = {};
  for (var h = 0; h < header.length; h++) {
    headerIndex[String(header[h]).trim()] = h;
  }

  var rowValues = new Array(header.length).fill("");
  function put(colName, value) {
    var idx = headerIndex[colName];
    if (idx === undefined) return;
    rowValues[idx] = value;
  }

  put("Дата", data.date || "");
  put("Время", data.time || "");
  put("Номер телефона", data.phone || "");
  put("Тип звонка", data.type || "");
  put("Длительность", data.duration || "");
  put("Менеджер", data.manager || "");
  put("Комментарий", comment);
  put("Тег", data.tag || "");
  put("Напоминание", data.reminder || "");
  put("Текст напоминания", reminderText);
  put("Клиент", data.client || "");
  put("ID", callId);
  put("Номер телефона пользователя", data.user_phone || data.userPhone || data.manager_phone || "");

  // Ищем уже существующую строку по call_id в колонке L (12-я колонка).
  // Это позволяет обновлять ту же строку после заполнения "Результат звонка",
  // а не добавлять новую.
  var lastRow = sheet.getLastRow();
  var targetRow = 0;
  var idColumn = (headerIndex["ID"] || 11) + 1;
  if (callId && callId !== "" && lastRow > 1) {
    var ids = sheet.getRange(2, idColumn, lastRow - 1, 1).getValues();
    for (var i = 0; i < ids.length; i++) {
      if (String(ids[i][0]) === callId) {
        targetRow = i + 2;
        break;
      }
    }
  }

  Logger.log("callId=" + callId + ", targetRow=" + targetRow);

  if (targetRow > 0 && callId !== "") {
    sheet.getRange(targetRow, 1, 1, rowValues.length).setValues([rowValues]);
  } else {
    sheet.appendRow(rowValues);
  }

  return ContentService.createTextOutput("ok");
}


// --- Почасовая сверка пустых "Клиент" в Calltrack ---
var DIRECTORY_SPREADSHEET_IDS = [
  "1Wl4UXI_x0a7A0iPYuW_ZRlrf3xEdVKMnOALi9p6J_Mc",
  "1ysEVeWSw96UgrQ1_4dEO5cSyv-18DSdr_VWPj3rUlhM"
];

function normalizePhone(value) {
  var digits = String(value || "").replace(/\D+/g, "");
  return digits.length >= 10 ? digits.slice(-10) : digits;
}

function buildDirectoryMap() {
  var map = {};
  DIRECTORY_SPREADSHEET_IDS.forEach(function (spreadsheetId) {
    var ss = SpreadsheetApp.openById(spreadsheetId);
    ss.getSheets().forEach(function (sheet) {
      var values = sheet.getDataRange().getValues();
      if (!values || values.length < 2) return;
      var header = values[0].map(function (h) { return String(h || "").trim(); });
      var phoneIdx = header.indexOf("Телефон");
      var clientIdx = header.indexOf("Клиент");
      if (phoneIdx === -1 || clientIdx === -1) return;

      for (var r = 1; r < values.length; r++) {
        var key = normalizePhone(values[r][phoneIdx]);
        var client = String(values[r][clientIdx] || "").trim();
        if (!key || !client) continue;
        if (!map[key]) map[key] = client;
      }
    });
  });
  return map;
}

function fillEmptyClientsInCalltrack() {
  var sheet = getCallsSheet();
  var values = sheet.getDataRange().getValues();
  if (!values || values.length < 2) return;

  var header = values[0].map(function (h) { return String(h || "").trim(); });
  var phoneIdx = header.indexOf("Номер телефона");
  var clientIdx = header.indexOf("Клиент");
  if (phoneIdx === -1 || clientIdx === -1) return;

  var directoryMap = buildDirectoryMap();
  var updated = 0;

  for (var i = 1; i < values.length; i++) {
    var currentClient = String(values[i][clientIdx] || "").trim();
    if (currentClient) continue;

    var normalizedPhone = normalizePhone(values[i][phoneIdx]);
    if (!normalizedPhone) continue;

    var foundClient = directoryMap[normalizedPhone];
    if (!foundClient) continue;

    sheet.getRange(i + 1, clientIdx + 1).setValue(foundClient);
    updated++;
  }

  Logger.log("fillEmptyClientsInCalltrack: updated=" + updated);
}

function installHourlyClientBackfillTrigger() {
  // Запускается один раз вручную: создаёт триггер раз в час.
  var fnName = "fillEmptyClientsInCalltrack";
  var existing = ScriptApp.getProjectTriggers().some(function (t) {
    return t.getHandlerFunction() === fnName;
  });
  if (existing) return;

  ScriptApp.newTrigger(fnName)
    .timeBased()
    .everyHours(1)
    .create();
}

```

### Шаг 3. Опубликовать
1. **Deploy → New deployment**.
2. Тип: **Web App**.
3. Доступ: **Anyone**.
4. Скопируйте URL web app.
5. Один раз вручную выполните функцию `installHourlyClientBackfillTrigger()` в редакторе Apps Script, чтобы создать почасовой триггер заполнения пустых значений в колонке "Клиент".
6. После правок обязательно нажмите **Deploy → Manage deployments → Edit → Deploy** (новая версия), иначе будет работать старый код и запись может уходить не в `Calls`.

### Шаг 4. Вставить URL в приложение
В `app/build.gradle` замените:
```gradle
buildConfigField "String", "WEBHOOK_URL", '"https://script.google.com/macros/s/AKfycbyUtYmL4-L1Ldzhrrn3kgst_gODDdu2lBqkk1_qtf6-IwWXoXizhP_J-AoJbYE7U2Zq1w/exec"'
```
на ваш реальный URL.

### Шаг 5. Подключить справочник проекта `clients`
Единым источником наименований клиентов для Android-приложения и web-панели служит проект `clients`.
CallTrack читает колонки **Наименование** и **Телефоны** через JSON API проекта, заданный
константой `CLIENTS_API_URL` в `api/config.local.php` (для локального контейнера по умолчанию:
`http://127.0.0.1:8015/api/get_clients.php`). В колонке **Телефоны** может находиться
несколько номеров: каждый номер нормализуется до последних 10 цифр и связывается со значением
из колонки **Наименование**.

Для большого справочника тайм-аут тестового запроса задаётся константой
`CLIENTS_API_TIMEOUT` или переменной окружения `CALLTRACK_CLIENTS_API_TIMEOUT` и по умолчанию
составляет 120 секунд. CallTrack запрашивает сжатый HTTP-ответ, если Clients его поддерживает.
Кнопка тестирования не удерживает запрос браузера: при отсутствии локального кэша CallTrack
запускает загрузку справочника отдельным CLI-процессом, а интерфейс опрашивает результат.

Если проекты используют одну базу и в ней доступна таблица `clients`, сервер сначала читает её.
В противном случае он обращается к `CLIENTS_API_URL`. Результат внешнего API кэшируется на 5 минут.
Android-приложение получает уже нормализованный справочник через `api/client_directory.php`, а
`api/get_calls.php` применяет тот же справочник к данным вкладки **Звонки клиентам**.

### Шаг 6. Отдельный Google Apps Script для базы `Calltrack_mop` (личные контакты и восстановление)

Ниже — готовый скрипт для таблицы:
`https://docs.google.com/spreadsheets/d/1PEkVdGOJmYmzDmVCaIqFYl7holzkt6ohCHMIeLlAJks/edit`

Он делает 2 вещи:
1. **POST** — сохраняет/обновляет личные контакты (по паре: номер пользователя + номер контакта).
2. **GET** — отдаёт записи для восстановления:
   - по `manager_phone` — все записи пользователя;
   - по `manager_phone + contact_phone` — точечная запись.

```javascript
/***** НАСТРОЙКИ *****/
var SPREADSHEET_ID = "1PEkVdGOJmYmzDmVCaIqFYl7holzkt6ohCHMIeLlAJks";
var SHEET_NAME = "Личные контакты";

/***** СЛУЖЕБНЫЕ *****/
function getSheet() {
  var ss = SpreadsheetApp.openById(SPREADSHEET_ID);
  var sheet = ss.getSheetByName(SHEET_NAME);
  if (!sheet) throw new Error("Лист не найден: " + SHEET_NAME);
  return sheet;
}

function json(data) {
  return ContentService
    .createTextOutput(JSON.stringify(data))
    .setMimeType(ContentService.MimeType.JSON);
}

function normPhone(v) {
  return String(v || "").replace(/\D/g, "").slice(-10);
}

function getHeaderMap(header) {
  var map = {};
  header.forEach(function(h, i) {
    map[String(h || "").trim()] = i;
  });
  return map;
}

function ensureColumns(sheet) {
  var required = [
    "Дата обновления",
    "Номер телефона пользователя",
    "Менеджер",
    "Личные номера",
    "Признак личного"
  ];

  var lastCol = sheet.getLastColumn();
  if (lastCol === 0) {
    sheet.getRange(1, 1, 1, required.length).setValues([required]);
    return required;
  }

  var header = sheet.getRange(1, 1, 1, lastCol).getValues()[0];
  var changed = false;
  required.forEach(function(col) {
    if (header.indexOf(col) === -1) {
      header.push(col);
      changed = true;
    }
  });
  if (changed) {
    sheet.getRange(1, 1, 1, header.length).setValues([header]);
  }
  return header;
}

/***** POST: сохранить/обновить личный контакт *****/
function doPost(e) {
  try {
    if (!e || !e.postData || !e.postData.contents) {
      throw new Error("Нет JSON в POST");
    }

    var payload = JSON.parse(e.postData.contents);
    var sheet = getSheet();
    var header = ensureColumns(sheet);
    var map = getHeaderMap(header);

    var managerPhone = normPhone(payload.manager_phone || payload.user_phone || payload.managerPhone);
    var managerName = String(payload.manager_name || payload.manager || payload.managerName || "").trim();
    var contactPhone = normPhone(payload.contact_phone || payload.phone || payload.contactPhone);
    var isPersonal = String(payload.is_personal || payload.personal || "").toLowerCase();

    if (!managerPhone) throw new Error("Пустой manager_phone");
    if (!contactPhone) throw new Error("Пустой contact_phone");
    if (!managerName) managerName = "Не указан";

    // по умолчанию считаем личным, если не передан явный false
    var personalFlag = (isPersonal === "false" || isPersonal === "0" || isPersonal === "no") ? "0" : "1";

    var row = new Array(header.length).fill("");
    row[map["Дата обновления"]] = Utilities.formatDate(new Date(), "Europe/Moscow", "yyyy-MM-dd HH:mm:ss");
    row[map["Номер телефона пользователя"]] = managerPhone;
    row[map["Менеджер"]] = managerName;
    row[map["Личные номера"]] = contactPhone;
    row[map["Признак личного"]] = personalFlag;

    var lastRow = sheet.getLastRow();
    var updated = false;
    if (lastRow > 1) {
      var data = sheet.getRange(2, 1, lastRow - 1, header.length).getValues();
      for (var i = 0; i < data.length; i++) {
        var r = data[i];
        var mp = normPhone(r[map["Номер телефона пользователя"]]);
        var cp = normPhone(r[map["Личные номера"]]);
        if (mp === managerPhone && cp === contactPhone) {
          sheet.getRange(i + 2, 1, 1, row.length).setValues([row]);
          updated = true;
          break;
        }
      }
    }

    if (!updated) sheet.appendRow(row);

    return json({
      status: updated ? "updated" : "inserted",
      manager_phone: managerPhone,
      contact_phone: contactPhone
    });
  } catch (err) {
    return json({ status: "error", message: err.message });
  }
}

/***** GET: получить данные для восстановления *****/
function doGet(e) {
  try {
    var sheet = getSheet();
    var header = ensureColumns(sheet);
    var map = getHeaderMap(header);
    var lastRow = sheet.getLastRow();
    if (lastRow < 2) return json([]);

    var managerPhone = normPhone((e.parameter && e.parameter.manager_phone) || "");
    var contactPhone = normPhone((e.parameter && e.parameter.contact_phone) || "");

    var data = sheet.getRange(2, 1, lastRow - 1, header.length).getValues();
    var result = [];

    data.forEach(function(r) {
      var mp = normPhone(r[map["Номер телефона пользователя"]]);
      var cp = normPhone(r[map["Личные номера"]]);
      var personal = String(r[map["Признак личного"]] || "0");
      if (personal !== "1") return;
      if (managerPhone && mp !== managerPhone) return;
      if (contactPhone && cp !== contactPhone) return;

      result.push({
        updated_at: r[map["Дата обновления"]] || "",
        manager_phone: mp,
        manager_name: String(r[map["Менеджер"]] || "").trim(),
        contact_phone: cp,
        is_personal: true
      });
    });

    return json(result);
  } catch (err) {
    return json({ status: "error", message: err.message });
  }
}
```

#### Как вызывать этот скрипт из приложения

- При установке метки «Личный контакт» отправляйте POST:
```json
{
  "manager_phone": "79990001122",
  "manager_name": "Иванов Иван Иванович",
  "contact_phone": "79995554433",
  "is_personal": true
}
```

- Для полной подгрузки (ежедневно в 11:00 МСК) используйте:
`GET .../exec?manager_phone=79990001122`

- Для точечной подгрузки (карточка/вызов):
`GET .../exec?manager_phone=79990001122&contact_phone=79995554433`

---

## 5. Формат данных
POST JSON:
```json
{
  "call_id": "12345_1714300000000",
  "date": "20.04.26",
  "time": "14:35",
  "phone": "+79999999999",
  "type": "Исходящий",
  "duration": 120,
  "manager": "Иванов Иван",
  "note": "",
  "tag": "",
  "reminder": "",
  "reminder_text": "",
  "client": "ООО Ромашка"
}
```

Поля:
- `call_id` — уникальный идентификатор звонка в формате `<id>_<timestamp>`. Используется скриптом для обновления **той же строки** вместо добавления новой.
- `date` — дата звонка в формате `дд.мм.гг`.
- `time` — время звонка в формате `чч:мм`.
- `phone` — номер телефона.
- `type` — тип звонка (`Входящий`, `Исходящий`, `Пропущенный`, `Неотвеченный`).
- `duration` — длительность в секундах.
- `manager` — ФИО менеджера (сохраняется при первом запуске на экране «Авторизация»).
- `note` — комментарий.
- `tag` — тег.
- `reminder` — напоминание.
- `reminder_text` — текст напоминания.
- `client` — клиент из справочника.

---

## Структура проекта
- `ui/` — экраны (onboarding, dial pad, история)
- `service/CallTrackingService` — фоновое слежение
- `telephony/CallStateTracker` — callback/fallback API
- `data/local` — Room
- `data/remote` — Retrofit webhook
- `data/repository` — репозиторий + DataStore
- `utils/CallUtils` — helper-функции

---

## Примечания по продакшену
Для реального deployment рекомендуется добавить:
- `WorkManager` для периодического ретрая синка;
- шифрование локальной БД;
- MDM-политику для фиксирования default dialer;
- более точную классификацию звонков через CallLog-парсинг.

---

## Полный код скриптов (готово для копирования)

Ниже два **полных** скрипта, которые можно целиком вставить в Apps Script без сборки по кускам.

### 1) Полный скрипт для таблицы **Calltrack** (звонки + почасовой backfill клиента)

```javascript
var CALLTRACK_SPREADSHEET_ID = "PASTE_CALLTRACK_SPREADSHEET_ID_HERE";
var CALLS_SHEET_NAME = "Calls";

function getCallsSheet() {
  var ss = CALLTRACK_SPREADSHEET_ID && CALLTRACK_SPREADSHEET_ID !== "PASTE_CALLTRACK_SPREADSHEET_ID_HERE"
    ? SpreadsheetApp.openById(CALLTRACK_SPREADSHEET_ID)
    : SpreadsheetApp.getActiveSpreadsheet();

  var sheet = ss.getSheetByName(CALLS_SHEET_NAME);
  if (!sheet) throw new Error("Лист не найден: " + CALLS_SHEET_NAME);
  return sheet;
}

function doPost(e) {
  var sheet = getCallsSheet();
  var data = JSON.parse(e.postData.contents);
  var comment = data.comment || data.note || "";
  var reminderText = data.reminder_text || data.reminderText || "";
  var callId = String(data.call_id || "");

  var header = sheet.getRange(1, 1, 1, sheet.getLastColumn()).getValues()[0];
  var headerIndex = {};
  for (var h = 0; h < header.length; h++) {
    headerIndex[String(header[h]).trim()] = h;
  }

  var rowValues = new Array(header.length).fill("");
  function put(colName, value) {
    var idx = headerIndex[colName];
    if (idx === undefined) return;
    rowValues[idx] = value;
  }

  put("Дата", data.date || "");
  put("Время", data.time || "");
  put("Номер телефона", data.phone || "");
  put("Тип звонка", data.type || "");
  put("Длительность", data.duration || "");
  put("Менеджер", data.manager || "");
  put("Комментарий", comment);
  put("Тег", data.tag || "");
  put("Напоминание", data.reminder || "");
  put("Текст напоминания", reminderText);
  put("Клиент", data.client || "");
  put("ID", callId);
  put("Номер телефона пользователя", data.user_phone || data.userPhone || data.manager_phone || "");

  var lastRow = sheet.getLastRow();
  var targetRow = 0;
  var idColumn = (headerIndex["ID"] || 11) + 1;
  if (callId && callId !== "" && lastRow > 1) {
    var ids = sheet.getRange(2, idColumn, lastRow - 1, 1).getValues();
    for (var i = 0; i < ids.length; i++) {
      if (String(ids[i][0]) === callId) {
        targetRow = i + 2;
        break;
      }
    }
  }

  if (targetRow > 0 && callId !== "") {
    sheet.getRange(targetRow, 1, 1, rowValues.length).setValues([rowValues]);
  } else {
    sheet.appendRow(rowValues);
  }

  return ContentService.createTextOutput("ok");
}

var DIRECTORY_SPREADSHEET_IDS = [
  "1Wl4UXI_x0a7A0iPYuW_ZRlrf3xEdVKMnOALi9p6J_Mc",
  "1ysEVeWSw96UgrQ1_4dEO5cSyv-18DSdr_VWPj3rUlhM"
];

function normalizePhone(value) {
  var digits = String(value || "").replace(/\D+/g, "");
  return digits.length >= 10 ? digits.slice(-10) : digits;
}

function buildDirectoryMap() {
  var map = {};
  DIRECTORY_SPREADSHEET_IDS.forEach(function (spreadsheetId) {
    var ss = SpreadsheetApp.openById(spreadsheetId);
    ss.getSheets().forEach(function (sheet) {
      var values = sheet.getDataRange().getValues();
      if (!values || values.length < 2) return;
      var header = values[0].map(function (h) { return String(h || "").trim(); });
      var phoneIdx = header.indexOf("Телефон");
      var clientIdx = header.indexOf("Клиент");
      if (phoneIdx === -1 || clientIdx === -1) return;

      for (var r = 1; r < values.length; r++) {
        var key = normalizePhone(values[r][phoneIdx]);
        var client = String(values[r][clientIdx] || "").trim();
        if (!key || !client) continue;
        if (!map[key]) map[key] = client;
      }
    });
  });
  return map;
}

function fillEmptyClientsInCalltrack() {
  var sheet = getCallsSheet();
  var values = sheet.getDataRange().getValues();
  if (!values || values.length < 2) return;

  var header = values[0].map(function (h) { return String(h || "").trim(); });
  var phoneIdx = header.indexOf("Номер телефона");
  var clientIdx = header.indexOf("Клиент");
  if (phoneIdx === -1 || clientIdx === -1) return;

  var directoryMap = buildDirectoryMap();

  for (var i = 1; i < values.length; i++) {
    var currentClient = String(values[i][clientIdx] || "").trim();
    if (currentClient) continue;

    var normalizedPhone = normalizePhone(values[i][phoneIdx]);
    if (!normalizedPhone) continue;

    var foundClient = directoryMap[normalizedPhone];
    if (!foundClient) continue;

    sheet.getRange(i + 1, clientIdx + 1).setValue(foundClient);
  }
}

function installHourlyClientBackfillTrigger() {
  var fnName = "fillEmptyClientsInCalltrack";
  var existing = ScriptApp.getProjectTriggers().some(function (t) {
    return t.getHandlerFunction() === fnName;
  });
  if (existing) return;

  ScriptApp.newTrigger(fnName)
    .timeBased()
    .everyHours(1)
    .create();
}
```

### 2) Полный скрипт для таблицы **Calltrack_mop** (личные контакты)

```javascript
var SHEET_NAME = "Личные контакты";

function json(data) {
  return ContentService
    .createTextOutput(JSON.stringify(data))
    .setMimeType(ContentService.MimeType.JSON);
}

function normPhone(v) {
  var digits = String(v || "").replace(/\D+/g, "");
  return digits.length >= 10 ? digits.slice(-10) : digits;
}

function getSheet() {
  var ss = SpreadsheetApp.getActiveSpreadsheet();
  var sh = ss.getSheetByName(SHEET_NAME);
  if (!sh) {
    sh = ss.insertSheet(SHEET_NAME);
  }
  return sh;
}

function getHeaderMap(header) {
  var map = {};
  for (var i = 0; i < header.length; i++) {
    map[String(header[i]).trim()] = i;
  }
  return map;
}

function ensureColumns(sheet) {
  var required = [
    "Дата обновления",
    "Номер телефона пользователя",
    "Менеджер",
    "Личные номера",
    "Признак личного"
  ];

  var lastCol = sheet.getLastColumn();
  if (lastCol === 0) {
    sheet.getRange(1, 1, 1, required.length).setValues([required]);
    return required;
  }

  var header = sheet.getRange(1, 1, 1, lastCol).getValues()[0];
  var changed = false;
  required.forEach(function(col) {
    if (header.indexOf(col) === -1) {
      header.push(col);
      changed = true;
    }
  });
  if (changed) {
    sheet.getRange(1, 1, 1, header.length).setValues([header]);
  }
  return header;
}

function doPost(e) {
  try {
    if (!e || !e.postData || !e.postData.contents) {
      throw new Error("Нет JSON в POST");
    }

    var payload = JSON.parse(e.postData.contents);
    var sheet = getSheet();
    var header = ensureColumns(sheet);
    var map = getHeaderMap(header);

    var managerPhone = normPhone(payload.manager_phone || payload.user_phone || payload.managerPhone);
    var managerName = String(payload.manager_name || payload.manager || payload.managerName || "").trim();
    var contactPhone = normPhone(payload.contact_phone || payload.phone || payload.contactPhone);
    var isPersonal = String(payload.is_personal || payload.personal || "").toLowerCase();

    if (!managerPhone) throw new Error("Пустой manager_phone");
    if (!contactPhone) throw new Error("Пустой contact_phone");
    if (!managerName) managerName = "Не указан";

    var personalFlag = (isPersonal === "false" || isPersonal === "0" || isPersonal === "no") ? "0" : "1";

    var row = new Array(header.length).fill("");
    row[map["Дата обновления"]] = Utilities.formatDate(new Date(), "Europe/Moscow", "yyyy-MM-dd HH:mm:ss");
    row[map["Номер телефона пользователя"]] = managerPhone;
    row[map["Менеджер"]] = managerName;
    row[map["Личные номера"]] = contactPhone;
    row[map["Признак личного"]] = personalFlag;

    var lastRow = sheet.getLastRow();
    var updated = false;
    if (lastRow > 1) {
      var data = sheet.getRange(2, 1, lastRow - 1, header.length).getValues();
      for (var i = 0; i < data.length; i++) {
        var r = data[i];
        var mp = normPhone(r[map["Номер телефона пользователя"]]);
        var cp = normPhone(r[map["Личные номера"]]);
        if (mp === managerPhone && cp === contactPhone) {
          sheet.getRange(i + 2, 1, 1, row.length).setValues([row]);
          updated = true;
          break;
        }
      }
    }

    if (!updated) sheet.appendRow(row);

    return json({
      status: updated ? "updated" : "inserted",
      manager_phone: managerPhone,
      contact_phone: contactPhone,
      is_personal: personalFlag
    });
  } catch (err) {
    return json({ status: "error", message: err.message });
  }
}

function doGet(e) {
  try {
    var sheet = getSheet();
    var header = ensureColumns(sheet);
    var map = getHeaderMap(header);
    var lastRow = sheet.getLastRow();
    if (lastRow < 2) return json([]);

    var managerPhone = normPhone((e.parameter && e.parameter.manager_phone) || "");
    var contactPhone = normPhone((e.parameter && e.parameter.contact_phone) || "");

    var data = sheet.getRange(2, 1, lastRow - 1, header.length).getValues();
    var result = [];

    data.forEach(function(r) {
      var mp = normPhone(r[map["Номер телефона пользователя"]]);
      var cp = normPhone(r[map["Личные номера"]]);
      var personal = String(r[map["Признак личного"]] || "0");
      if (managerPhone && mp !== managerPhone) return;
      if (contactPhone && cp !== contactPhone) return;

      result.push({
        updated_at: r[map["Дата обновления"]] || "",
        manager_phone: mp,
        manager_name: String(r[map["Менеджер"]] || "").trim(),
        contact_phone: cp,
        is_personal: personal === "1"
      });
    });

    return json(result);
  } catch (err) {
    return json({ status: "error", message: err.message });
  }
}
```

---

## 5. Веб-дашборд и серверный API

Веб-часть теперь хранится внутри этого репозитория и не требует отдельного `Calltrack_analizmop1`:

- `analizmop/index.html` — страница реестра звонков.
- `analizmop/api.js` — клиентские запросы к PHP API.
- `api/get_calls.php` — получение списка звонков для дашборда.
- `api/delete_call.php` — удаление одного звонка.
- `api/delete_calls.php` — массовое удаление звонков.
- `api/get_personal_contacts.php` — получение SQL-таблицы `personal_contacts` для вкладки «Признаки контакта».

Относительные пути рассчитаны на размещение каталогов `analizmop` и `api` на одном уровне в корне сайта:

```text
public_html/
├── api/
│   ├── config.php
│   ├── get_calls.php
│   ├── delete_call.php
│   ├── delete_calls.php
│   └── get_personal_contacts.php
└── analizmop/
    ├── index.html
    └── api.js
```

`analizmop/index.html` подключает скрипт как `api.js`, а `analizmop/api.js` обращается к API через `../api/`.

### Delta-синхронизация кэша Clients

Рабочим локальным форматом Clients является `storage/cache/clients/clients.sqlite`: таблица `clients` хранит полный JSON-снимок карточки, `client_phones` — нормализованные телефоны и код shard, а `sync_state` — транзакционный cursor. Файл `storage/cache/clients/sync_state.json` содержит межпроцессный cursor и статистику для админ-панели. Старые `clients.json`, `phone_index.json` и shards по-прежнему создаются полным потоковым refresh для совместимости; delta изменяет только строки SQLite и затронутые shards.

Полный refresh сначала читает `GET /vr/clients/api/clients/changes/state`, затем потоково строит новый кэш в `storage/cache/clients/`, публикует его и применяет все страницы `GET /vr/clients/api/clients/changes?after_id=...&limit=500` от snapshot cursor. Поэтому изменения во время долгой загрузки не теряются. Delta применяет страницы идемпотентно: upsert заменяет карточку и все её телефонные связи, delete удаляет карточку каскадно. Cursor записывается лишь после успешных SQLite/shard изменений. Оба режима используют `storage/clients_cache_refresh.lock`.

Установка cron идемпотентно создаёт delta ежедневно в 04:00 и full по воскресеньям в 03:00 в `Europe/Moscow`:

```bash
cd /var/www/html/vr/calltrack
git pull
sudo bash scripts/install_clients_cache_cron.sh /var/www/html/vr/calltrack
sudo crontab -u www-data -l | sed -n '/CALLTRACK_CLIENTS_CACHE_REFRESH/,/CALLTRACK_CLIENTS_CACHE_REFRESH END/p'
```

Первичное/восстановительное полное обновление и обычный ручной delta-тест:

```bash
cd /var/www/html/vr/calltrack
sudo -u www-data /usr/bin/php api/refresh_clients_cache.php --source=manual --mode=full
sudo -u www-data /usr/bin/php api/refresh_clients_cache.php --source=manual --mode=delta
```

Просмотр cursor, полного состояния и журнала:

```bash
jq '.last_change_id' storage/cache/clients/sync_state.json
jq . storage/cache/clients/sync_state.json
jq . storage/clients_cache_refresh.status.json
tail -n 100 storage/logs/clients_cache_refresh.log
```

Временная ошибка Clients оставляет кэш и cursor без изменений. Ошибка контракта, порядка событий или invalid cursor отмечается сообщением «Требуется полное обновление кэша»; автоматический fallback на full намеренно отсутствует. `update_calltrack.sh` обновление кэша не запускает.

### Изменённые файлы после объединения

- `analizmop/index.html`
- `analizmop/api.js`
- `api/get_calls.php`
- `api/delete_call.php`
- `api/delete_calls.php`
- `api/get_personal_contacts.php`
- `README.md`

### Инструкция по деплою на сервер

1. Соберите актуальную версию репозитория локально или на CI:
   ```bash
   git pull
   ./gradlew :app:assembleDebug
   ```
2. Скопируйте на сервер каталог `analizmop` в корень сайта, например в `public_html/analizmop`.
3. Скопируйте на сервер каталог `api` в корень сайта, например в `public_html/api`.
4. Настройте подключение к MySQL/MariaDB в `api/config.php` через переменные окружения хостинга:
   - `CALLTRACK_DB_HOST`
   - `CALLTRACK_DB_NAME`
   - `CALLTRACK_DB_USER`
   - `CALLTRACK_DB_PASS`
   - `CALLTRACK_DB_CHARSET` при необходимости
5. Создайте или обновите таблицы базы данных SQL-скриптами из каталога `database`.
6. Проверьте доступность API в браузере или через `curl`:
   ```bash
   curl 'https://your-domain.example/api/get_calls.php?period=today'
   ```
7. Откройте дашборд:
   ```text
   https://your-domain.example/analizmop/index.html
   ```
8. Если сервер отдаёт дашборд из подкаталога, сохраните структуру `analizmop` и `api` как соседние каталоги. Иначе относительный путь `../api/` в `analizmop/api.js` нужно будет заменить на фактический URL API.
