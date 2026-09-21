/**
 * Knygos įgarsinimo registracija → Google Sheets
 *
 * Šis Apps Script turi būti įdėtas į tą patį Google Sheets dokumentą.
 * WordPress siunčia tik JSON užklausas į viešą Web App URL; Google prisijungimas
 * lieka tik dokumento savininko Apps Script diegimo metu.
 */

const HEADERS = [
  'ID',
  'Data',
  'Vardas ir pavardė',
  'El. paštas',
  'Bendruomenė',
  'Skyrius',
  'Skyriaus pavadinimas',
  'Santrauka išsiųsta',
  'Atsiuntė audio',
  'Atnaujinta',
  'Būsena',
];

function doGet() {
  return jsonResponse_(true, 'KIR Google Sheets endpoint is ready.');
}

function doPost(e) {
  try {
    const request = parseRequest_(e);
    if (!sameToken_(request.token, getSecret_())) {
      return jsonResponse_(false, 'Neteisingas tokenas.', 401);
    }

    const sheet = getSheet_();
    ensureHeaders_(sheet);

    if (request.action === 'upsert') {
      const rows = Array.isArray(request.rows) ? request.rows : [];
      rows.forEach(function (row) {
        upsertRow_(sheet, row);
      });
      ensureTableFilter_(sheet);
      return jsonResponse_(true, 'Eilutės išsaugotos.', rows.length);
    }

    if (request.action === 'delete') {
      const ids = Array.isArray(request.ids) ? request.ids : [];
      deleteRows_(sheet, ids);
      ensureTableFilter_(sheet);
      return jsonResponse_(true, 'Eilutės pašalintos.', ids.length);
    }

    return jsonResponse_(false, 'Nežinomas veiksmas.', 400);
  } catch (error) {
    return jsonResponse_(false, error && error.message ? error.message : 'Apps Script klaida.', 500);
  }
}

function parseRequest_(event) {
  const body = event && event.postData && event.postData.contents ? event.postData.contents : '{}';
  return JSON.parse(body);
}

function getSecret_() {
  return PropertiesService.getScriptProperties().getProperty('KIR_SECRET') || '';
}

function getSheet_() {
  const spreadsheetId = PropertiesService.getScriptProperties().getProperty('KIR_SPREADSHEET_ID');
  const spreadsheet = spreadsheetId
    ? SpreadsheetApp.openById(spreadsheetId)
    : SpreadsheetApp.getActiveSpreadsheet();
  if (!spreadsheet) {
    throw new Error('Google Sheets dokumentas nerastas.');
  }

  const sheetName = PropertiesService.getScriptProperties().getProperty('KIR_SHEET_NAME') || 'Registracijos';
  return spreadsheet.getSheetByName(sheetName) || spreadsheet.insertSheet(sheetName);
}

function ensureHeaders_(sheet) {
  if (sheet.getLastRow() === 0) {
    sheet.getRange(1, 1, 1, HEADERS.length).setValues([HEADERS]);
    sheet.setFrozenRows(1);
  } else {
    const current = sheet.getRange(1, 1, 1, HEADERS.length).getValues()[0];
    if (current.join('\u0001') !== HEADERS.join('\u0001')) {
      sheet.getRange(1, 1, 1, HEADERS.length).setValues([HEADERS]);
      sheet.setFrozenRows(1);
    }
  }

  ensureTableFilter_(sheet);
}

function ensureTableFilter_(sheet) {
  const requiredColumns = HEADERS.length;
  const requiredRows = Math.max(sheet.getMaxRows(), sheet.getLastRow(), 2);
  const currentFilter = sheet.getFilter();

  if (currentFilter) {
    const currentRange = currentFilter.getRange();
    if (
      currentRange.getRow() === 1 &&
      currentRange.getColumn() === 1 &&
      currentRange.getNumColumns() === requiredColumns &&
      currentRange.getNumRows() === requiredRows
    ) {
      return;
    }

    // Keep existing column criteria when expanding an older A:F filter to A:K.
    const criteria = [];
    const criteriaColumns = Math.min(currentRange.getNumColumns(), requiredColumns);
    for (let column = 1; column <= criteriaColumns; column += 1) {
      criteria[column] = currentFilter.getColumnFilterCriteria(column);
    }
    currentFilter.remove();

    const expandedFilter = sheet.getRange(1, 1, requiredRows, requiredColumns).createFilter();
    for (let column = 1; column <= criteriaColumns; column += 1) {
      if (criteria[column]) {
        try {
          expandedFilter.setColumnFilterCriteria(column, criteria[column]);
        } catch (error) {
          // An incompatible old criterion should not prevent the table filter
          // from covering all columns.
        }
      }
    }
    return;
  }

  sheet.getRange(1, 1, requiredRows, requiredColumns).createFilter();
}

function upsertRow_(sheet, row) {
  if (!row || !row.id) {
    return;
  }

  const values = [[
    String(row.id),
    row.created_at || '',
    row.full_name || '',
    row.email || '',
    row.congregation || '',
    row.chapter || '',
    row.chapter_title || '',
    row.summary_sent || 'Ne',
    row.audio_sent || 'Ne',
    row.updated_at || '',
    row.status || 'Rezervuota',
  ]];
  const existingRow = findRowById_(sheet, row.id);
  if (existingRow) {
    sheet.getRange(existingRow, 1, 1, HEADERS.length).setValues(values);
  } else {
    sheet.getRange(sheet.getLastRow() + 1, 1, 1, HEADERS.length).setValues(values);
  }
}

function findRowById_(sheet, id) {
  const lastRow = sheet.getLastRow();
  if (lastRow < 2) {
    return 0;
  }

  const values = sheet.getRange(2, 1, lastRow - 1, 1).getValues();
  const wanted = String(id);
  for (let index = 0; index < values.length; index += 1) {
    if (String(values[index][0]) === wanted) {
      return index + 2;
    }
  }
  return 0;
}

function deleteRows_(sheet, ids) {
  const rowNumbers = ids
    .map(function (id) { return findRowById_(sheet, id); })
    .filter(function (rowNumber) { return rowNumber > 1; })
    .sort(function (a, b) { return b - a; });

  rowNumbers.forEach(function (rowNumber) {
    sheet.deleteRow(rowNumber);
  });
}

function sameToken_(received, expected) {
  return typeof received === 'string' && expected !== '' && received === expected;
}

function jsonResponse_(ok, message, count) {
  return ContentService
    .createTextOutput(JSON.stringify({ ok: ok, message: message, count: count || 0 }))
    .setMimeType(ContentService.MimeType.JSON);
}
