import { config, FallbackSubject, GradeBand, ClassBand } from './config';
import { Student, ParsedScheme } from './marksStore';

export interface StoreState {
  students: Record<string, Student>;
  subjects: Record<string, string>;
  slots: Record<string, SlotInfo>;
  errors: string[];
  warnings: string[];
  credits: Record<string, number>;
  scheme: ParsedScheme | null;
  found: Record<string, Record<string, true>>;
  institute: string;
  semester: string;
  id_label: string;
}

export interface SlotInfo {
  kind: 'marks' | 'credits';
  name: string;
  students: number;
  subjects: number;
  components: string[];
  tabs: string[];
  detected: Array<{ code: string; name: string; comps: Record<string, number | null> }>;
  credits: Record<string, number>;
  names: Record<string, string>;
  error: string | null;
}

export interface ResolvedSubject {
  code: string;
  name: string;
  credits: number | null;
  components: string[];
}

export function gradeBand(percent: number, scale: GradeBand[]): GradeBand {
  for (const band of scale) {
    if (percent >= band.min) return band;
  }
  return scale[scale.length - 1] ?? { min: 0, point: 0, grade: 'F' };
}

export function classFor(spi: number, bands: ClassBand[]): string {
  for (const band of bands) {
    if (spi >= band.min) return band.label;
  }
  return '';
}

/** The teaching scheme is the source of truth once uploaded; the config list is a fallback until then. */
export function resolveSubjects(store: StoreState, sem: string): ResolvedSubject[] {
  const scheme = store.scheme;

  if (!scheme || Object.keys(scheme.subjects).length === 0) {
    const fallback: FallbackSubject[] = config.fallback_subjects[sem] ?? [];
    return fallback.map((f) => ({ code: f.code, name: f.name, credits: f.credits, components: f.components }));
  }

  const columnOrder = Object.values(config.result_columns);
  const subjects: ResolvedSubject[] = [];

  for (const code of scheme.order) {
    const info = scheme.subjects[code];
    let components = info.components;

    if (components.length === 0) {
      components = Object.keys(store.found[code] ?? {});
    }

    components = [...components].sort((a, b) => columnOrder.indexOf(a) - columnOrder.indexOf(b));

    subjects.push({ code, name: info.name, credits: info.credits, components });
  }

  return subjects;
}

export interface Cell {
  text: string;
  state: 'none' | 'pending' | 'ok' | 'fail';
  obtained: number | null;
  max: number | null;
  note?: string;
}

export interface SheetRow {
  applicable: boolean;
  code: string;
  name: string;
  cells: Record<string, Cell>;
  credits: number | null;
  point: number | null;
  obtained: number;
  max: number;
  percent: number | null;
  counts: boolean;
}

export interface Sheet {
  rows: SheetRow[];
  columns: Record<string, string>;
  obtained: number;
  max: number;
  percentage: number | null;
  credits: number;
  spi: number | null;
  equivalent: number | null;
  class: string | null;
  incomplete: number;
  failed: number;
  gaps: string[];
}

/** Each component is graded on its own percentage; SPI is the credit-weighted mean of subject points. */
export function buildSheet(student: Student, store: StoreState, subjects: ResolvedSubject[]): Sheet {
  const order = [...subjects];
  if (config.subject_order === 'code') {
    order.sort((a, b) => a.code.localeCompare(b.code));
  }

  const rows: SheetRow[] = [];
  let sumObtained = 0;
  let sumMax = 0;
  let creditSum = 0;
  let pointSum = 0;
  let incomplete = 0;
  let failed = 0;
  const creditGaps: string[] = [];

  const columns = config.result_columns;

  for (const subject of order) {
    const code = subject.code;
    const name = store.subjects[code] ?? subject.name;

    const cells: Record<string, Cell> = {};
    let weighted = 0;
    let weightSum = 0;
    let obtained = 0;
    let max = 0;
    let usable = true;
    let anyMarks = false;

    for (const [column, comp] of Object.entries(columns)) {
      const blank: Cell = { text: '-', state: 'none', obtained: null, max: null };

      if (!subject.components.includes(comp)) {
        cells[column] = blank;
        continue;
      }

      const mark = student.marks[code]?.[comp] ?? { obtained: null, max: null, status: 'missing' as const, raw: '' };
      const status = mark.status;
      const cmax = mark.max ?? 0;

      if (status === 'na') {
        cells[column] = blank;
        continue;
      }

      if (status === 'missing' || status === 'code') {
        usable = false;
        cells[column] = { text: status === 'code' ? mark.raw : '-', state: 'pending', obtained: null, max: null };
        continue;
      }

      const got = status === 'ok' ? (mark.obtained as number) : 0;
      const percent = cmax > 0 ? Math.round((got / cmax) * 100 * 100) / 100 : 0;
      const band = gradeBand(percent, config.grade_scale);

      obtained += got;
      max += cmax;
      weighted += cmax * band.point;
      weightSum += cmax;
      anyMarks = true;

      if (band.grade === config.fail_grade) failed++;

      cells[column] = {
        text: band.grade,
        state: band.grade === config.fail_grade ? 'fail' : 'ok',
        obtained: got,
        max: cmax,
        note: status === 'ok' ? '' : status === 'absent' ? 'AB' : 'UM',
      };
    }

    sumObtained += obtained;
    sumMax += max;

    const graded = usable && anyMarks && weightSum > 0;
    const point = graded ? weighted / weightSum : null;

    let source: 'scheme' | 'config' | 'derived' = 'scheme';
    let credits: number | null = store.credits[code] ?? null;
    if (credits === null) {
      source = 'config';
      credits = subject.credits ?? null;
    }
    if (credits === null && max > 0) {
      source = 'derived';
      const unit = config.credit_unit || 25;
      credits = Math.round((max / unit) * 2) / 2;
    }

    if (graded && credits !== null && credits > 0) {
      creditSum += credits;
      pointSum += credits * (point as number);
      if (source !== 'scheme') creditGaps.push(name);
    } else if (anyMarks || !usable) {
      incomplete++;
    }

    let applicable = false;
    for (const cell of Object.values(cells)) {
      if (cell.state !== 'none') {
        applicable = true;
        break;
      }
    }

    rows.push({
      applicable,
      code,
      name,
      cells,
      credits,
      point,
      obtained,
      max,
      percent: max > 0 ? Math.round((obtained / max) * 100 * 100) / 100 : null,
      counts: anyMarks,
    });
  }

  // A student with any F is a fail outright - no SPI is computed for a
  // failing result, only for a student who has cleared every component.
  const spi = failed === 0 && creditSum > 0 ? Math.round((pointSum / creditSum) * 100) / 100 : null;

  return {
    rows,
    columns,
    obtained: sumObtained,
    max: sumMax,
    percentage: sumMax > 0 ? Math.round((sumObtained / sumMax) * 100 * 100) / 100 : null,
    credits: creditSum,
    spi,
    equivalent: spi !== null ? Math.round((spi - 0.5) * 10 * 100) / 100 : null,
    class: spi !== null ? classFor(spi, config.class_bands) : null,
    incomplete,
    failed,
    gaps: creditGaps,
  };
}

export function resultFacts(
  student: Student,
  enrollment: string,
  institute: string,
  sem: string
): Record<string, string> {
  const meta = config.semesters[sem] ?? ({} as (typeof config.semesters)[string]);
  return {
    'Student Name': student.name !== '' ? student.name : '-',
    'Course Name': meta.course_name ?? '',
    College: institute,
    'Enrollment No': enrollment,
    Semester: sem,
    'Passing Year': meta.passing_year ?? '',
  };
}

/** Trims a trailing ".0" so 38.0 prints as 38 but 37.5 survives. */
export function num(value: number): string {
  return value.toFixed(2).replace(/0+$/, '').replace(/\.$/, '');
}
