import { NextRequest, NextResponse } from 'next/server';
import { config } from '@/lib/config';
import { issueToken, hasValidToken } from '@/lib/csrf';
import { loadStore, saveSlot, clearSemester } from '@/lib/blobStore';
import { resolveSubjects, buildSheet } from '@/lib/grading';
import { normaliseId } from '@/lib/marksStore';
import { looksLikeXlsx } from '@/lib/xlsxReader';
import { renderSemesterPage } from '@/lib/pages/semester';
import { Student } from '@/lib/marksStore';

function html(body: string): Response {
  return new Response(body, { headers: { 'content-type': 'text/html; charset=utf-8' } });
}

async function acceptUpload(sem: string, slot: string, formData: FormData): Promise<string | null> {
  const file = formData.get(slot);
  if (!(file instanceof File) || file.size === 0 || file.name === '') return null; // left untouched

  const ext = file.name.split('.').pop()?.toLowerCase() ?? '';
  if (!['xlsx', 'xlsm'].includes(ext)) {
    return `${slot}: "${file.name}" is not an .xlsx file. Save it from Excel as "Excel Workbook (.xlsx)" first.`;
  }

  const buffer = Buffer.from(await file.arrayBuffer());
  if (!looksLikeXlsx(buffer)) {
    return `${slot}: "${file.name}" could not be opened as a workbook. It may be renamed or corrupted.`;
  }

  await saveSlot(sem, slot, buffer, file.name);
  return null;
}

async function handle(req: NextRequest, sem: string): Promise<Response> {
  if (!(sem in config.semesters)) {
    return NextResponse.redirect(new URL('/', req.url), 303);
  }

  const semLabel = config.semesters[sem].label ?? `${sem} Semester`;

  let errors: string[] = [];
  const notices: string[] = [];
  let enrollment = '';

  if (req.method === 'POST') {
    const formData = await req.formData();
    const token = formData.get('token');

    if (!(await hasValidToken(typeof token === 'string' ? token : null))) {
      errors.push('Your session expired before the form was submitted. Please try again.');
    } else if (formData.get('action') === 'clear') {
      await clearSemester(sem);
      return NextResponse.redirect(new URL(`/sem/${encodeURIComponent(sem)}?cleared=1`, req.url), 303);
    } else {
      for (const slot of Object.keys(config.slots)) {
        const result = await acceptUpload(sem, slot, formData);
        if (result !== null) errors.push(result);
      }

      enrollment = normaliseId(String(formData.get('enrollment') ?? ''));
      if (enrollment === '') errors.push('Enter an enrollment number to generate a result.');
    }
  }

  if (new URL(req.url).searchParams.get('cleared') !== null) {
    notices.push('Stored sheets removed. Upload the sheets again to continue.');
  }

  const store = await loadStore(sem, config.component_overrides);
  errors = errors.concat(store.errors);
  const subjects = resolveSubjects(store, sem);

  let student: Student | null = null;
  let notFound = false;

  if (enrollment !== '' && errors.length === 0 && subjects.length === 0) {
    errors.push(
      `No subject list is available for the ${semLabel}. Upload the teaching scheme so the subjects, credits and parts can be read from it.`
    );
  }

  if (enrollment !== '' && errors.length === 0) {
    if (Object.keys(store.students).length === 0) {
      errors.push('No marks are loaded yet. Upload at least one sheet along with the enrollment number.');
    } else if (store.students[enrollment]) {
      student = store.students[enrollment];
    } else {
      notFound = true;
    }
  }

  const sheet = student !== null ? buildSheet(student, store, subjects) : null;
  const institute = store.institute !== '' ? store.institute : config.institute;
  const idLabel = store.id_label !== '' ? store.id_label : 'Enrollment No.';
  const token = await issueToken();

  return html(
    renderSemesterPage({
      sem,
      semLabel,
      store,
      subjects,
      student,
      sheet,
      notFound,
      errors,
      notices,
      enrollment,
      token,
      idLabel,
      institute,
    })
  );
}

export async function GET(req: NextRequest, { params }: { params: Promise<{ sem: string }> }) {
  const { sem: rawSem } = await params;
  const sem = rawSem.replace(/[^0-9A-Za-z]/g, '');
  return handle(req, sem);
}

export async function POST(req: NextRequest, { params }: { params: Promise<{ sem: string }> }) {
  const { sem: rawSem } = await params;
  const sem = rawSem.replace(/[^0-9A-Za-z]/g, '');
  return handle(req, sem);
}
