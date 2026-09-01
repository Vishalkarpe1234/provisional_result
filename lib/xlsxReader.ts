import * as XLSX from 'xlsx';

/**
 * rows[excelRowNumber][zeroBasedColIndex] = string value
 * Mirrors the PHP XlsxReader's shape so MarksStore's logic ports 1:1.
 */
export type SheetRows = Record<number, Record<number, string>>;

export class XlsxFormatError extends Error {}

function cellText(cell: XLSX.CellObject | undefined): string {
  if (!cell) return '';
  if (cell.w !== undefined) return String(cell.w);
  if (cell.v === undefined || cell.v === null) return '';
  return String(cell.v);
}

/** Reads every worksheet in the workbook, in tab order, skipping hidden tabs. */
export function allSheets(buffer: Buffer, expandMerges = false): Record<string, SheetRows> {
  let workbook: XLSX.WorkBook;
  try {
    workbook = XLSX.read(buffer, { type: 'buffer', cellText: true, cellDates: false });
  } catch {
    throw new XlsxFormatError('Not a valid .xlsx file (it is not a readable ZIP archive).');
  }

  const sheets: Record<string, SheetRows> = {};
  const visible = workbook.Workbook?.Sheets ?? [];

  for (const name of workbook.SheetNames) {
    const meta = visible.find((s) => s.name === name);
    if (meta?.Hidden === 1 || meta?.Hidden === 2) continue; // hidden or very hidden

    const ws = workbook.Sheets[name];
    if (!ws || !ws['!ref']) continue;

    sheets[name] = readSheet(ws, expandMerges);
  }

  if (Object.keys(sheets).length === 0) {
    throw new XlsxFormatError('The workbook contains no readable worksheet data.');
  }

  return sheets;
}

function readSheet(ws: XLSX.WorkSheet, expandMerges: boolean): SheetRows {
  const range = XLSX.utils.decode_range(ws['!ref'] as string);
  const rows: SheetRows = {};

  for (let r = range.s.r; r <= range.e.r; r++) {
    let rowObj: Record<number, string> | null = null;
    for (let c = range.s.c; c <= range.e.c; c++) {
      const cell = ws[XLSX.utils.encode_cell({ r, c })] as XLSX.CellObject | undefined;
      const text = cellText(cell);
      if (text !== '') {
        if (rowObj === null) rowObj = {};
        rowObj[c] = text;
      }
    }
    if (rowObj !== null) {
      rows[r + 1] = rowObj; // 1-based Excel row numbers, like the PHP reader
    }
  }

  if (expandMerges && ws['!merges']) {
    for (const m of ws['!merges']) {
      const value = rows[m.s.r + 1]?.[m.s.c];
      if (!value) continue;
      for (let r = m.s.r; r <= m.e.r; r++) {
        for (let c = m.s.c; c <= m.e.c; c++) {
          const rr = r + 1;
          if (!rows[rr]) rows[rr] = {};
          if (!rows[rr][c]) rows[rr][c] = value;
        }
      }
    }
  }

  return rows;
}

/** Quick structural check used before accepting an upload. */
export function looksLikeXlsx(buffer: Buffer): boolean {
  try {
    const workbook = XLSX.read(buffer, { type: 'buffer', bookSheets: true });
    return workbook.SheetNames.length > 0;
  } catch {
    return false;
  }
}
