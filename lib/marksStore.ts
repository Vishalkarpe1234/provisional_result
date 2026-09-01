import { allSheets, SheetRows } from './xlsxReader';

export interface Mark {
  obtained: number | null;
  max: number | null;
  status: 'ok' | 'absent' | 'unfair' | 'na' | 'code' | 'missing';
  raw: string;
}

export interface Student {
  name: string;
  roll: string;
  marks: Record<string, Record<string, Mark>>;
}

export interface ParsedFile {
  students: Record<string, Student>;
  subjects: Record<string, string>;
  components: string[];
  warnings: string[];
  institute: string;
  semester: string;
  id_label: string;
  tabs?: string[];
}

export interface SchemeSubject {
  name: string;
  credits: number;
  components: string[];
}

export interface ParsedScheme {
  subjects: Record<string, SchemeSubject>;
  order: string[];
  tabs: string[];
}

export class MarksError extends Error {}

const COMPONENT_RULES: Array<[string, RegExp]> = [
  ['CCE', /\bCCE\b|CONTINUOUS|INTERNAL/i],
  ['V', /\bV\b|VIVA|PRACTICAL|\bPR\b|\bP\b/i],
  ['E', /\bE\b|\bESE\b|EXTERNAL|THEORY|\bTH\b/i],
  ['M', /\bMID\b|\bMSE\b/i],
];

const NOT_A_COMPONENT = /^(GRADE|TOTAL\s*OBTAINED|TOTAL|OBTAINED|MARKS|%|REMARKS?|RESULT|SR|SIGN)$/i;
const NOT_APPLICABLE = /^(N\/?A|NOT\s*APPLICABLE|--?|—)$/i;
const ABSENT = /^(AB|ABS|ABSENT)$/i;
const UNFAIR = /^(UM|UFM|MAL|MP|MALPRACTICE)$/i;

/** Collapses newlines and repeated whitespace into single spaces. */
function squash(value: string): string {
  return value.replace(/\s+/gu, ' ').trim();
}

/** Enrollment numbers must compare as text; guards against float-like "24004501210012.0". */
export function normaliseId(raw: string): string {
  let id = squash(raw).toUpperCase();
  id = id.replace(/[^A-Z0-9/\-]/g, '');
  if (id !== '' && /^\d+(?:\.0+)?E?\+?\d*$/.test(id) && id.includes('.')) {
    id = id.replace(/0+$/, '').replace(/\.$/, '');
  }
  return id;
}

function cleanName(raw: string): string {
  const name = raw.replace(/\s*\((?:Active|Inactive|Dropped|Cancelled)\)\s*$/i, '');
  return squash(name);
}

/** A subject code is a run of 8-12 digits inside brackets. */
function subjectCode(value: string): string | null {
  const m = value.match(/\((\d{8,12})\)/);
  return m ? m[1] : null;
}

function subjectName(value: string): string {
  return squash(value.replace(/\(\s*\d{8,12}\s*\)/, ''));
}

function findColumn(header: Record<number, string>, pattern: RegExp): number | null {
  for (const [colStr, value] of Object.entries(header)) {
    if (pattern.test(squash(value))) return Number(colStr);
  }
  return null;
}

/** The header row is the first row carrying two or more subject codes. */
function findHeaderRow(rows: SheetRows): number | null {
  let best: number | null = null;
  let bestCount = 0;

  const rowNums = Object.keys(rows).map(Number).sort((a, b) => a - b).slice(0, 25);
  for (const num of rowNums) {
    let count = 0;
    for (const v of Object.values(rows[num])) {
      if (subjectCode(v) !== null) count++;
    }
    if (count > bestCount) {
      bestCount = count;
      best = num;
    }
  }

  return bestCount >= 1 ? best : null;
}

/** Normalises "CCE exam\n(25)" -> "CCE", "V\n(50)" -> "V". */
function componentOf(label: string, overrides: Record<string, string>): string | null {
  const text = squash(label.replace(/\([^)]*\)/g, ' '));
  if (text === '') return null;

  for (const [needle, comp] of Object.entries(overrides)) {
    if (squash(needle).toLowerCase() === text.toLowerCase()) return comp;
  }

  for (const [comp, pattern] of COMPONENT_RULES) {
    if (pattern.test(text)) return comp;
  }

  return null;
}

/** Pulls the maximum out of "V (50)". */
function maxOf(label: string): number | null {
  const m = label.match(/\(\s*(\d+(?:\.\d+)?)\s*\)/);
  return m ? parseFloat(m[1]) : null;
}

interface Block {
  code: string;
  name: string;
  col: number;
  comp: string;
  max: number | null;
}

interface Unresolved {
  code: string;
  name: string;
  labels: string;
}

/** Maps each subject block to its marks column, component and maximum. */
function findSubjectBlocks(
  header: Record<number, string>,
  subHeader: Record<number, string>,
  overrides: Record<string, string>
): [Block[], Unresolved[]] {
  const starts: Record<number, { code: string; name: string }> = {};
  for (const [colStr, value] of Object.entries(header)) {
    const code = subjectCode(value);
    if (code !== null) {
      starts[Number(colStr)] = { code, name: subjectName(value) };
    }
  }
  const cols = Object.keys(starts).map(Number).sort((a, b) => a - b);
  if (cols.length === 0) return [[], []];

  const allCols = [...Object.keys(header).map(Number), ...Object.keys(subHeader).map(Number)];
  const maxCol = allCols.length > 0 ? Math.max(...allCols) : 0;

  const blocks: Block[] = [];
  const unresolved: Unresolved[] = [];

  cols.forEach((start, i) => {
    const end = i + 1 < cols.length ? cols[i + 1] - 1 : maxCol;
    let matched = false;
    const seen: string[] = [];

    for (let c = start; c <= end; c++) {
      const label = (subHeader[c] ?? '').trim();
      if (label === '' || NOT_A_COMPONENT.test(squash(label))) continue;

      const comp = componentOf(label, overrides);
      if (comp === null) {
        seen.push(squash(label));
        continue;
      }

      blocks.push({ code: starts[start].code, name: starts[start].name, col: c, comp, max: maxOf(label) });
      matched = true;
      break; // one marks column per subject block
    }

    if (!matched) {
      unresolved.push({
        code: starts[start].code,
        name: starts[start].name,
        labels: Array.from(new Set(seen)).join(', '),
      });
    }
  });

  return [blocks, unresolved];
}

/** An unrecognised code is kept verbatim under 'code' rather than treated as missing. */
function readMark(raw: string, max: number | null): Mark {
  const value = squash(raw);

  if (value === '') return { obtained: null, max, status: 'missing', raw: '' };
  if (value !== '' && !isNaN(Number(value)) && /^-?\d+(\.\d+)?$/.test(value)) {
    return { obtained: parseFloat(value), max, status: 'ok', raw: value };
  }
  if (NOT_APPLICABLE.test(value)) return { obtained: null, max: null, status: 'na', raw: value };
  if (ABSENT.test(value)) return { obtained: null, max, status: 'absent', raw: value };
  if (UNFAIR.test(value)) return { obtained: null, max, status: 'unfair', raw: value };

  return { obtained: null, max, status: 'code', raw: value };
}

function textAbove(rows: SheetRows, headerRow: number, offset: number): string {
  const target = offset + 1;
  if (target >= headerRow) return '';
  return squash(rows[target]?.[0] ?? '');
}

function parseRows(rows: SheetRows, overrides: Record<string, string>): Omit<ParsedFile, 'tabs'> {
  const rowNums = Object.keys(rows).map(Number);
  if (rowNums.length === 0) throw new MarksError('The worksheet is empty.');

  const headerRow = findHeaderRow(rows);
  if (headerRow === null) {
    throw new MarksError(
      'No subject codes found. The header row must contain subject names with a code in brackets, e.g. "Operating Systems (150120402)".'
    );
  }

  const header = rows[headerRow];
  const subHeader = rows[headerRow + 1] ?? {};

  const idCol = findColumn(header, /GRN|ENROL{1,2}MENT|ENROL{1,2}\.?\s*(NO|NUM)|SEAT\s*NO|EXAM\s*NO/i);
  const nameCol = findColumn(header, /STUDENT\s*NAME|^NAME$|CANDIDATE/i);
  const rollCol = findColumn(header, /ROLL/i);

  if (idCol === null) {
    throw new MarksError('No enrollment column found. One header cell must read "GRN No.", "Enrollment No." or similar.');
  }

  const [blocks, unresolved] = findSubjectBlocks(header, subHeader, overrides);
  if (blocks.length === 0) {
    throw new MarksError('Subject codes were found but no marks component could be identified beneath them (expected E, V or CCE).');
  }

  const warnings: string[] = [];
  for (const u of unresolved) {
    warnings.push(
      `"${u.name} (${u.code})" was skipped - the heading below it (${u.labels === '' ? 'blank' : `"${u.labels}"`}) was not recognised as E, V or CCE. Add it to component_overrides in config.php.`
    );
  }

  const students: Record<string, Student> = {};
  const subjects: Record<string, string> = {};
  const componentsSeen: Record<string, true> = {};
  const oddCodes: Record<string, number> = {};

  for (const b of blocks) {
    subjects[b.code] = b.name;
    componentsSeen[b.comp] = true;
  }

  const lastRow = Math.max(...rowNums);
  for (let r = headerRow + 2; r <= lastRow; r++) {
    const row = rows[r];
    if (!row) continue;
    const id = normaliseId(row[idCol] ?? '');
    if (id === '') continue;

    const entry: Student = students[id] ?? {
      name: cleanName(nameCol !== null ? row[nameCol] ?? '' : ''),
      roll: (rollCol !== null ? row[rollCol] ?? '' : '').trim(),
      marks: {},
    };

    for (const b of blocks) {
      const mark = readMark(row[b.col] ?? '', b.max);
      if (mark.status === 'code') {
        oddCodes[mark.raw] = (oddCodes[mark.raw] ?? 0) + 1;
      }
      if (!entry.marks[b.code]) entry.marks[b.code] = {};
      entry.marks[b.code][b.comp] = mark;
    }

    students[id] = entry;
  }

  for (const [code, count] of Object.entries(oddCodes)) {
    warnings.push(`The value "${code}" appears in ${count} marks cell${count === 1 ? '' : 's'} and is not a number. It is printed as-is and left out of the total.`);
  }

  return {
    students,
    subjects,
    components: Object.keys(componentsSeen),
    warnings,
    institute: textAbove(rows, headerRow, 0),
    semester: textAbove(rows, headerRow, 1),
    id_label: (header[idCol] ?? 'Enrollment No.').trim(),
  };
}

/** Parses one workbook: every tab is read, not just the first. */
export function parseFile(buffer: Buffer, overrides: Record<string, string> = {}): ParsedFile {
  const sheets = allSheets(buffer, false);
  const parts: Record<string, Omit<ParsedFile, 'tabs'>> = {};
  const failures: Record<string, string> = {};
  let warnings: string[] = [];

  const names = Object.keys(sheets);
  for (const name of names) {
    try {
      const part = parseRows(sheets[name], overrides);
      part.warnings = part.warnings.map((w) => (names.length > 1 ? `tab "${name}": ${w}` : w));
      parts[name] = part;
      warnings = warnings.concat(part.warnings);
    } catch (e) {
      failures[name] = e instanceof Error ? e.message : String(e);
    }
  }

  const partNames = Object.keys(parts);
  if (partNames.length === 0) {
    throw new MarksError(Object.values(failures)[0] ?? 'The worksheet is empty.');
  }

  const merged = merge(Object.fromEntries(partNames.map((n) => [n, parts[n]])));
  merged.warnings = warnings;
  merged.tabs = partNames;

  return merged;
}

/** Merges several parsed workbooks; a later file never overwrites a value an earlier one supplied. */
export function merge(parsed: Record<string, Omit<ParsedFile, 'tabs'> | ParsedFile>): ParsedFile {
  const students: Record<string, Student> = {};
  const subjects: Record<string, string> = {};
  const components: Record<string, true> = {};
  let warnings: string[] = [];
  const meta = { institute: '', semester: '', id_label: '' };

  for (const p of Object.values(parsed)) {
    Object.assign(subjects, p.subjects);
    for (const c of p.components ?? []) components[c] = true;
    warnings = warnings.concat(p.warnings ?? []);
    for (const k of ['institute', 'semester', 'id_label'] as const) {
      if (meta[k] === '' && (p[k] ?? '') !== '') meta[k] = p[k];
    }

    for (const [id, s] of Object.entries(p.students)) {
      if (!students[id]) {
        students[id] = s;
        continue;
      }
      if (students[id].name === '') students[id].name = s.name;
      if (students[id].roll === '') students[id].roll = s.roll;
      for (const [code, comps] of Object.entries(s.marks)) {
        for (const [comp, mark] of Object.entries(comps)) {
          const existing = students[id].marks[code]?.[comp];
          if (!existing || existing.status === 'missing') {
            if (!students[id].marks[code]) students[id].marks[code] = {};
            students[id].marks[code][comp] = mark;
          }
        }
      }
    }
  }

  return {
    students,
    subjects,
    components: Object.keys(components),
    warnings: Array.from(new Set(warnings)),
    ...meta,
  };
}

/* ---------------------------------------------------------- teaching scheme */

/** A subject code, bare or bracketed. */
function anyCode(value: string): string | null {
  const text = squash(value);
  if (text === '') return null;
  let m = text.match(/^\(?(\d{8,12})\)?$/);
  if (m) return m[1];
  m = text.match(/\((\d{8,12})\)/);
  return m ? m[1] : null;
}

/** Locates the credits column, preferring the most explicit wording. */
function creditColumnOf(headers: Record<number, string>, dataRows: SheetRows, forceColumn: string | null): number {
  const candidates: Array<[number, number]> = [];
  for (const [colStr, text] of Object.entries(headers)) {
    const col = Number(colStr);
    if (text === '') continue;
    if (forceColumn && squash(forceColumn).toLowerCase() === text.toLowerCase()) {
      candidates.push([0, col]);
    } else if (/total\s*credits?/i.test(text)) {
      candidates.push([1, col]);
    } else if (/credits?/i.test(text)) {
      candidates.push([2, col]);
    } else if (/^(C|CR|CRD)\.?$/i.test(text)) {
      candidates.push([3, col]);
    }
  }
  candidates.sort((a, b) => a[0] - b[0] || a[1] - b[1]);

  const dataRowList = Object.values(dataRows);
  for (const [, col] of candidates) {
    let valid = 0;
    for (const row of dataRowList) {
      const value = squash(row[col] ?? '');
      const num = Number(value);
      if (value !== '' && !isNaN(num) && num > 0 && num <= 30) valid++;
    }
    if (valid >= Math.max(1, Math.ceil(dataRowList.length * 0.5))) return col;
  }

  const seen = Object.values(headers).filter((t) => t !== '');
  throw new MarksError(
    `${dataRowList.length} subject codes were found but no credit column. Headings seen: ${
      seen.length === 0 ? 'none' : '"' + seen.slice(0, 12).join('", "') + '"'
    }. Rename the credits column to "Credits" or set credit_column in config.php.`
  );
}

/** Finds the Max Marks columns, which reveal a subject's components. */
function maxMarkColumns(headers: Record<number, string>): Record<string, number> {
  const found: Record<string, number> = {};
  for (const [colStr, text] of Object.entries(headers)) {
    const col = Number(colStr);
    if (text === '' || !/max\s*marks?/i.test(text)) continue;

    const tail = squash(text.replace(/.*max\s*marks?/i, ''));
    let comp: string | null = null;
    if (/\b(CEC|CCE|CIE|INTERNAL)\b/i.test(tail)) comp = 'CCE';
    else if (/\b(V|VIVA|PRACTICAL|PR)\b/i.test(tail)) comp = 'V';
    else if (/\b(E|ESE|THEORY|TH)\b/i.test(tail)) comp = 'E';
    else continue;

    if (!(comp in found)) found[comp] = col;
  }
  return found;
}

function schemeFromRows(rows: SheetRows, forceColumn: string | null): Record<string, SchemeSubject> {
  const tally: Record<number, number> = {};
  for (const row of Object.values(rows)) {
    for (const [colStr, value] of Object.entries(row)) {
      if (anyCode(value) !== null) {
        const col = Number(colStr);
        tally[col] = (tally[col] ?? 0) + 1;
      }
    }
  }
  const tallyEntries = Object.entries(tally);
  if (tallyEntries.length === 0) {
    throw new MarksError('No subject codes were found. Each row should carry a code such as 150120402.');
  }
  tallyEntries.sort((a, b) => b[1] - a[1]);
  const codeCol = Number(tallyEntries[0][0]);

  const dataRows: SheetRows = {};
  for (const [numStr, row] of Object.entries(rows)) {
    if (anyCode(row[codeCol] ?? '') !== null) dataRows[Number(numStr)] = row;
  }
  const dataRowNums = Object.keys(dataRows).map(Number);
  const firstData = Math.min(...dataRowNums);

  let maxCol = 0;
  for (const row of Object.values(rows)) {
    for (const colStr of Object.keys(row)) maxCol = Math.max(maxCol, Number(colStr));
  }

  const headers: Record<number, string> = {};
  for (let col = 0; col <= maxCol; col++) {
    const parts: string[] = [];
    for (let r = Math.max(1, firstData - 5); r < firstData; r++) {
      const text = squash(rows[r]?.[col] ?? '');
      if (text !== '') parts.push(text);
    }
    headers[col] = parts.join(' ');
  }

  const creditCol = creditColumnOf(headers, dataRows, forceColumn);
  const nameCol = findColumn(headers, /course\s*title|subject\s*(name|title)|course\s*name|^title$|^name$/i) ?? codeCol + 1;
  const maxCols = maxMarkColumns(headers);

  const subjects: Record<string, SchemeSubject> = {};
  for (const row of Object.values(dataRows)) {
    const code = anyCode(row[codeCol]);
    const value = squash(row[creditCol] ?? '');
    const numValue = Number(value);

    if (code === null || value === '' || isNaN(numValue) || numValue <= 0) continue;

    const components: string[] = [];
    for (const [comp, col] of Object.entries(maxCols)) {
      const mark = squash(row[col] ?? '');
      const numMark = Number(mark);
      if (mark !== '' && !isNaN(numMark) && numMark > 0) components.push(comp);
    }

    subjects[code] = { name: squash(row[nameCol] ?? ''), credits: numValue, components };
  }

  if (Object.keys(subjects).length === 0) {
    throw new MarksError('Subject codes were found but none had a usable credit value.');
  }

  return subjects;
}

/** Reads a whole teaching scheme: subject codes, titles, credits, and which components each subject carries. */
export function parseScheme(buffer: Buffer, forceColumn: string | null = null): ParsedScheme {
  const sheets = allSheets(buffer, true); // merges expanded: a shared elective credit is read for both rows
  const subjects: Record<string, SchemeSubject> = {};
  const order: string[] = [];
  const tabs: string[] = [];
  let reason = '';

  for (const [name, rows] of Object.entries(sheets)) {
    let found: Record<string, SchemeSubject>;
    try {
      found = schemeFromRows(rows, forceColumn);
    } catch (e) {
      reason = reason || (e instanceof Error ? e.message : String(e));
      continue;
    }

    tabs.push(name);
    for (const [code, info] of Object.entries(found)) {
      if (!subjects[code]) {
        subjects[code] = info;
        order.push(code);
      }
    }
  }

  if (Object.keys(subjects).length === 0) {
    throw new MarksError(reason || 'No subject codes with credits were found in this file.');
  }

  return { subjects, order, tabs };
}
