import { put, get, del } from '@vercel/blob';
import { config } from './config';
import { parseFile, parseScheme, merge, MarksError } from './marksStore';
import { StoreState, SlotInfo } from './grading';

function slotPath(sem: string, slot: string): string {
  return `sem-${sem}/${slot}.xlsx`;
}

function manifestPath(sem: string): string {
  return `sem-${sem}/manifest.json`;
}

interface Manifest {
  names: Record<string, string>;
}

async function streamToBuffer(stream: ReadableStream<Uint8Array>): Promise<Buffer> {
  const chunks: Uint8Array[] = [];
  const reader = stream.getReader();
  for (;;) {
    const { done, value } = await reader.read();
    if (done) break;
    if (value) chunks.push(value);
  }
  return Buffer.concat(chunks);
}

/** Reads a private blob's content, or null if it does not exist. */
async function readBlob(pathname: string): Promise<Buffer | null> {
  const result = await get(pathname, { access: 'private', useCache: false });
  if (result === null || result.statusCode !== 200) return null;
  return streamToBuffer(result.stream);
}

async function readManifest(sem: string): Promise<Manifest> {
  const buffer = await readBlob(manifestPath(sem));
  if (buffer === null) return { names: {} };
  try {
    return JSON.parse(buffer.toString('utf-8')) as Manifest;
  } catch {
    return { names: {} };
  }
}

async function writeManifest(sem: string, manifest: Manifest): Promise<void> {
  await put(manifestPath(sem), JSON.stringify(manifest), {
    access: 'private',
    contentType: 'application/json',
    addRandomSuffix: false,
    allowOverwrite: true,
  });
}

/** Stores one uploaded workbook, recording its original filename. */
export async function saveSlot(sem: string, slot: string, buffer: Buffer, originalName: string): Promise<void> {
  await put(slotPath(sem, slot), buffer, {
    access: 'private',
    contentType: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    addRandomSuffix: false,
    allowOverwrite: true,
  });

  const manifest = await readManifest(sem);
  manifest.names[slot] = originalName;
  await writeManifest(sem, manifest);
}

/** Removes every stored sheet for a semester. */
export async function clearSemester(sem: string): Promise<void> {
  const paths = [...Object.keys(config.slots).map((slot) => slotPath(sem, slot)), manifestPath(sem)];
  await del(paths);
}

interface PresentFile {
  slot: string;
  buffer: Buffer;
  name: string;
}

async function fetchPresentFiles(sem: string): Promise<PresentFile[]> {
  const manifest = await readManifest(sem);
  const present: PresentFile[] = [];

  for (const slot of Object.keys(config.slots)) {
    const buffer = await readBlob(slotPath(sem, slot));
    if (buffer === null) continue;
    present.push({ slot, buffer, name: manifest.names[slot] ?? `${slot}.xlsx` });
  }
  return present;
}

/** How many slots exist for a semester, for the picker page's summary. */
export async function slotCounts(sem: string): Promise<{ loaded: number; subjects: number }> {
  let loaded = 0;
  let subjects = 0;

  for (const slot of Object.keys(config.slots)) {
    const buffer = await readBlob(slotPath(sem, slot));
    if (buffer === null) continue;
    loaded++;

    if (slot === 'scheme') {
      try {
        const scheme = parseScheme(buffer, config.credit_column);
        subjects = Object.keys(scheme.subjects).length;
      } catch {
        // Left at 0 - the main page reports the actual error.
      }
    }
  }

  return { loaded, subjects };
}

const EMPTY_STORE: StoreState = {
  students: {},
  subjects: {},
  slots: {},
  errors: [],
  warnings: [],
  credits: {},
  scheme: null,
  found: {},
  institute: '',
  semester: '',
  id_label: '',
};

/** Loads every stored sheet for a semester and merges them into one index. */
export async function loadStore(sem: string, overrides: Record<string, string>): Promise<StoreState> {
  const present = await fetchPresentFiles(sem);
  if (present.length === 0) return EMPTY_STORE;

  const parsed: Record<string, ReturnType<typeof parseFile>> = {};
  const slotInfo: Record<string, SlotInfo> = {};
  const errors: string[] = [];
  const creditMap: Record<string, number> = {};
  let scheme: StoreState['scheme'] = null;
  const compMap: Record<string, Record<string, true>> = {};

  for (const file of present) {
    if (config.slots[file.slot].kind === 'credits') {
      try {
        const sc = parseScheme(file.buffer, config.credit_column);
        const credits: Record<string, number> = {};
        const names: Record<string, string> = {};
        for (const [code, info] of Object.entries(sc.subjects)) {
          credits[code] = info.credits;
          names[code] = info.name;
        }
        Object.assign(creditMap, { ...credits, ...creditMap });
        scheme = sc;

        slotInfo[file.slot] = {
          kind: 'credits', name: file.name, students: 0, subjects: Object.keys(credits).length,
          components: [], tabs: sc.tabs, detected: [], credits, names, error: null,
        };
      } catch (e) {
        const message = e instanceof MarksError || e instanceof Error ? e.message : String(e);
        slotInfo[file.slot] = {
          kind: 'credits', name: file.name, students: 0, subjects: 0,
          components: [], tabs: [], detected: [], credits: {}, names: {}, error: message,
        };
        errors.push(`${file.slot} ("${file.name}"): ${message}`);
      }
      continue;
    }

    try {
      const p = parseFile(file.buffer, overrides);
      parsed[file.slot] = p;

      const sample = Object.values(p.students)[0] ?? { marks: {} as Record<string, Record<string, { max: number | null }>> };
      const detected: SlotInfo['detected'] = [];
      for (const [code, subjectName] of Object.entries(p.subjects)) {
        const comps: Record<string, number | null> = {};
        for (const [comp, mark] of Object.entries(sample.marks[code] ?? {})) {
          comps[comp] = mark.max;
          if (!compMap[code]) compMap[code] = {};
          compMap[code][comp] = true;
        }
        detected.push({ code, name: subjectName, comps });
      }

      slotInfo[file.slot] = {
        kind: 'marks', name: file.name, students: Object.keys(p.students).length,
        subjects: Object.keys(p.subjects).length, components: p.components,
        tabs: p.tabs ?? [], detected, credits: {}, names: {}, error: null,
      };
    } catch (e) {
      const message = e instanceof MarksError || e instanceof Error ? e.message : String(e);
      slotInfo[file.slot] = {
        kind: 'marks', name: file.name, students: 0, subjects: 0,
        components: [], tabs: [], detected: [], credits: {}, names: {}, error: message,
      };
      errors.push(`${file.slot} ("${file.name}"): ${message}`);
    }
  }

  const merged = Object.keys(parsed).length > 0
    ? merge(parsed)
    : { students: {}, subjects: {}, components: [], warnings: [], institute: '', semester: '', id_label: '' };

  return {
    students: merged.students,
    subjects: merged.subjects,
    slots: slotInfo,
    errors,
    warnings: merged.warnings ?? [],
    credits: creditMap,
    scheme,
    found: compMap,
    institute: merged.institute ?? '',
    semester: merged.semester ?? '',
    id_label: merged.id_label ?? '',
  };
}
