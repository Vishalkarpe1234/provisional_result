import { config } from '../config';
import { e, page } from '../html';
import { StoreState, ResolvedSubject, Sheet, resultFacts, num } from '../grading';
import { Student } from '../marksStore';

export interface SemesterPageInput {
  sem: string;
  semLabel: string;
  store: StoreState;
  subjects: ResolvedSubject[];
  student: Student | null;
  sheet: Sheet | null;
  notFound: boolean;
  errors: string[];
  notices: string[];
  enrollment: string;
  token: string;
  idLabel: string;
  institute: string;
}

function tableHead(extra: string[] = []): string {
  const groupCols = Object.entries(config.result_groups)
    .map(
      ([group, cols]) =>
        `<th scope="col" colspan="${cols.length}"${cols.length === 1 ? ' rowspan="2"' : ''} class="g">${e(group)}</th>`
    )
    .join('');

  const subCols = Object.values(config.result_groups)
    .map((cols) => (cols.length > 1 ? cols.map((col) => `<th scope="col" class="g">${e(col)}</th>`).join('') : ''))
    .join('');

  const extraCols = extra.map((label) => `<th scope="col" rowspan="2" class="g">${e(label)}</th>`).join('');

  return `<tr>
                <th scope="col" rowspan="2" class="c-code">Subject Code</th>
                <th scope="col" rowspan="2">Subject Name</th>
                ${config.show_credits ? '<th scope="col" rowspan="2" class="g c-credit">Credit</th>' : ''}
                ${groupCols}
                ${extraCols}
            </tr>
            <tr>
                ${subCols}
            </tr>`;
}

function slotsFieldset(store: StoreState): string {
  const items = Object.entries(config.slots)
    .map(([slot, meta]) => {
      const info = store.slots[slot] ?? null;
      const classes = 'slot' + (info === null ? '' : info.error !== null ? ' is-error' : ' is-loaded');

      let fileText: string;
      if (info === null) {
        fileText = 'Choose a file';
      } else if (info.error !== null) {
        fileText = `${e(info.name)} — could not be read`;
      } else if (info.kind === 'credits') {
        const total = Object.values(info.credits).reduce((a, b) => a + b, 0);
        fileText = `${e(info.name)}<br>${info.subjects} subjects &middot; ${e(num(total))} credits`;
      } else {
        const tabsExtra =
          (info.tabs?.length ?? 0) > 1 ? `<br>${info.tabs!.length} tabs: ${e(info.tabs!.join(', '))}` : '';
        fileText = `${e(info.name)}<br>${info.students} students &middot; ${info.subjects} subjects &middot; ${e(
          info.components.join(', ')
        )}${tabsExtra}`;
      }

      return `<label class="${classes}">
                        <input type="file" name="${e(slot)}" accept=".xlsx,.xlsm" data-slot="${e(slot)}">
                        <span class="slot-body">
                            <span class="slot-name">${e(meta.label)}</span>
                            <span class="slot-hint">${e(meta.hint)}</span>
                            <span class="slot-file" data-file-for="${e(slot)}">${fileText}</span>
                        </span>
                    </label>`;
    })
    .join('');

  return `<fieldset class="field">
            <legend class="legend">Marks sheets</legend>
            <div class="slots">${items}</div>
            <p class="hint">
                Sheets stay on the server after the first upload, so later searches only need an
                enrollment number. Attach a file again to replace that sheet.
            </p>
            <p class="hint">The app accepts .xlsx files up to a few megabytes each - well beyond a typical semester sheet.</p>
        </fieldset>`;
}

function inspectDetails(store: StoreState): string {
  if (Object.keys(store.slots).length === 0) return '';

  const slotsHtml = Object.entries(store.slots)
    .map(([slot, info]) => {
      const label = config.slots[slot]?.label ?? slot;
      let body: string;

      if (info.error !== null) {
        body = `<p class="inspect-bad">${e(info.error)}</p>`;
      } else {
        const tabCount = info.tabs?.length ?? 0;
        const meta = `<p class="inspect-meta">${tabCount} tab${tabCount === 1 ? '' : 's'} (${e(
          (info.tabs ?? []).join(', ')
        )})${info.kind !== 'credits' ? ` &middot; ${info.students} students` : ''}</p>`;

        let table: string;
        if (info.kind === 'credits') {
          const total = Object.values(info.credits).reduce((a, b) => a + b, 0);
          const rows = Object.entries(info.credits)
            .map(
              ([code, cr]) =>
                `<tr><td class="c">${e(code)}</td><td>${e(info.names[code] ?? '')}</td><td class="p"><span class="mx">${e(
                  num(cr)
                )} credits</span></td></tr>`
            )
            .join('');
          table = `<table class="inspect-table">${rows}<tr><td class="c"></td><td><strong>Total</strong></td><td class="p"><span class="mx"><strong>${e(
            num(total)
          )} credits</strong></span></td></tr></table>`;
        } else if (info.detected.length === 0) {
          table = '<p class="inspect-bad">No subjects were detected in this sheet.</p>';
        } else {
          const rows = info.detected
            .map((d) => {
              const comps =
                Object.keys(d.comps).length === 0
                  ? '<span class="inspect-bad">no part found</span>'
                  : Object.entries(d.comps)
                      .map(
                        ([comp, max]) =>
                          `<span class="tag tag-${e(comp)}">${e(comp)}</span><span class="mx">max ${
                            max !== null ? e(num(max)) : '?'
                          }</span>`
                      )
                      .join('');
              return `<tr><td class="c">${e(d.code)}</td><td>${e(d.name)}</td><td class="p">${comps}</td></tr>`;
            })
            .join('');
          table = `<table class="inspect-table">${rows}</table>`;
        }

        body = meta + table;
      }

      return `<div class="inspect-slot">
                        <h3>${e(label)} <span class="inspect-file">${e(info.name)}</span></h3>
                        ${body}
                    </div>`;
    })
    .join('');

  const hasWarnings = store.warnings.length > 0;

  return `<details class="inspect no-print"${hasWarnings ? ' open' : ''}>
            <summary>What was read from each sheet</summary>
            <div class="inspect-body">
                <p class="inspect-lede">
                    A subject missing from a sheet below prints as <em>Not available</em>. Check that
                    the subject you expect is listed under the sheet that should supply it.
                </p>
                ${slotsHtml}
            </div>
        </details>`;
}

function documentsSection(input: SemesterPageInput): string {
  const { store, student, sheet, enrollment, sem, institute } = input;
  if (sheet === null || student === null) return '';

  const facts = resultFacts(student, enrollment, institute, sem);
  const head = config.letterhead;

  const printRows = config.hide_not_applicable ? sheet.rows.filter((r) => r.applicable) : sheet.rows;

  const printCss = Object.keys(config.documents)
    .map(
      (key) =>
        `body[data-print="${e(key)}"] .doc:not(.doc-${e(key)}) { display: none !important; }
            body[data-print="${e(key)}"] .doc { page-break-before: auto; }`
    )
    .join('\n');

  const factsRows = Object.entries(facts)
    .map(
      ([label, value]) =>
        `<tr><th scope="row">${e(label)}</th><td${label === 'Enrollment No' ? ' class="mono"' : ''}>${e(
          value !== '' ? value : '-'
        )}</td></tr>`
    )
    .join('');

  const docsHtml = Object.entries(config.documents)
    .map(([key, doc]) => {
      const isLetterhead = (doc.style ?? 'official') === 'letterhead';
      const isMarks = (doc.type ?? 'grades') === 'marks';

      // The university heading/logo block is never printed - only the
      // dedicated "letterhead" style still reserves blank space for it, for
      // paper that already has the college header pre-printed. Every other
      // document starts straight at the title; the address footer below is
      // unaffected and still follows the style as before.
      const headerHtml = isLetterhead
        ? `<div class="letterspace" style="height: ${e(config.letterhead_space)}">
                        <span class="letterspace-note no-print">Blank space for the pre-printed college letterhead</span>
                    </div>`
        : '';

      let tableHtml: string;
      if (!isMarks) {
        const bodyRows = printRows
          .map((row) => {
            const creditCell = config.show_credits
              ? `<td class="g c-credit"><span class="cr">${row.credits !== null ? row.credits.toFixed(1) : '-'}</span></td>`
              : '';
            const cells = Object.keys(sheet.columns)
              .map((column) => {
                const cell = row.cells[column];
                return `<td class="g"><span class="gr gr-${e(cell.state)}">${e(cell.text)}</span></td>`;
              })
              .join('');
            return `<tr><td class="mono c-code">${e(row.code)}</td><td class="subj">${e(
              row.name
            )}</td>${creditCell}${cells}</tr>`;
          })
          .join('');

        const spiBlock =
          (doc.spi ?? true) && sheet.spi !== null
            ? `<div class="spi">
                            <p class="spi-value">
                                <span class="spi-label">SPI</span>
                                <span class="mono">${sheet.spi.toFixed(2)}</span>
                            </p>
                            ${
                              config.show_class
                                ? `<p class="spi-class">${e(sheet.class)} &middot; equivalent ${(sheet.equivalent as number).toFixed(2)}%</p>`
                                : ''
                            }
                        </div>`
            : '';

        tableHtml = `<table class="grades">
                        <thead>${tableHead()}</thead>
                        <tbody>${bodyRows}</tbody>
                    </table>
                    ${spiBlock}`;
      } else {
        const bodyRows = printRows
          .map((row) => {
            const creditCell = config.show_credits
              ? `<td class="g c-credit"><span class="cr">${row.credits !== null ? row.credits.toFixed(1) : '-'}</span></td>`
              : '';
            const cells = Object.keys(sheet.columns)
              .map((column) => {
                const cell = row.cells[column];
                if (cell.max !== null) {
                  const noteText = cell.note ?? '';
                  return `<td class="g">${
                    noteText !== ''
                      ? `<span class="mk is-absent">${e(noteText)}</span>`
                      : `<span class="mk">${num(cell.obtained as number)}</span>`
                  }<span class="mk-of">/${num(cell.max)}</span></td>`;
                }
                return `<td class="g"><span class="gr gr-${e(cell.state)}">-</span></td>`;
              })
              .join('');
            const totalCell =
              row.counts && row.max > 0
                ? `<td class="g"><span class="mk">${num(row.obtained)}</span><span class="mk-of">/${num(row.max)}</span></td>`
                : '<td class="g"><span class="gr gr-none">-</span></td>';
            const pctCell =
              row.percent !== null && row.counts
                ? `<td class="g"><span class="mk">${num(row.percent)}</span></td>`
                : '<td class="g"><span class="gr gr-none">-</span></td>';
            return `<tr><td class="mono c-code">${e(row.code)}</td><td class="subj">${e(
              row.name
            )}</td>${creditCell}${cells}${totalCell}${pctCell}</tr>`;
          })
          .join('');

        const tfoot =
          sheet.max > 0
            ? `<tfoot><tr>
                                <td colspan="${2 + (config.show_credits ? 1 : 0) + Object.keys(sheet.columns).length}" class="tfoot-label">Total</td>
                                <td class="g"><span class="mk">${num(sheet.obtained)}</span><span class="mk-of">/${num(sheet.max)}</span></td>
                                <td class="g"><span class="mk">${num(sheet.percentage as number)}</span></td>
                            </tr></tfoot>`
            : '';

        tableHtml = `<table class="grades marks-table">
                        <thead>${tableHead(['Total', '%'])}</thead>
                        <tbody>${bodyRows}</tbody>
                        ${tfoot}
                    </table>`;
      }

      const notesHtml = config.result_notes.map((note) => `<p>${e(note)}</p>`).join('');
      const signaturesHtml =
        config.signatories.length > 0
          ? `<div class="signatures">${config.signatories
              .map((who) => `<div class="sig-block"><span class="sig-rule"></span><span class="sig-who">${e(who)}</span></div>`)
              .join('')}</div>`
          : '';

      const footerHtml =
        !isLetterhead && head.address
          ? `<footer class="official-foot">
                        <span>${e(head.address)}</span>
                        <span class="oh-contact">
                            ${head.email ? e(head.email) : ''}
                            ${head.phone ? ` &nbsp;&middot;&nbsp; ${e(head.phone)}` : ''}
                            ${head.website ? ` &nbsp;&middot;&nbsp; ${e(head.website)}` : ''}
                        </span>
                    </footer>`
          : '';

      return `<article class="sheet doc doc-${e(key)}" id="doc-${e(key)}">
                ${headerHtml}
                <h2 class="doc-title">${e(doc.title ?? '')}</h2>
                <table class="facts-table"><tbody>${factsRows}</tbody></table>
                ${tableHtml}
                <div class="notes">${notesHtml}</div>
                ${signaturesHtml}
                ${footerHtml}
            </article>`;
    })
    .join('');

  const printButtons = Object.entries(config.documents)
    .map(([key, doc]) => `<button class="btn btn-print" type="button" data-print="${e(key)}">${e(doc.label ?? key)}</button>`)
    .join('');
  const allButton =
    Object.keys(config.documents).length > 1
      ? '<button class="btn btn-ghost-light" type="button" data-print="all">All</button>'
      : '';

  return `<style>
        @media print {
        ${printCss}
        }
        </style>
        ${docsHtml}
        <div class="printbar no-print">
            <p>
                <strong>Saving as PDF:</strong> choose <em>Save as PDF</em> as the destination, then
                turn off <em>Headers and footers</em> so the browser does not print the page URL and date.
                Signature lines print blank, to be signed by hand.
            </p>
            <div class="printbar-actions">${printButtons}${allButton}</div>
        </div>`;
}

export function renderSemesterPage(input: SemesterPageInput): string {
  const { sem, semLabel, store, subjects, notFound, errors, notices, enrollment, token, idLabel } = input;

  const stepText = `${e(semLabel)} &middot; ${Object.keys(store.slots).length}/${Object.keys(config.slots).length} sheets loaded${
    Object.keys(store.students).length > 0 ? ` &middot; ${Object.keys(store.students).length} students` : ''
  }`;

  const errorsHtml =
    errors.length > 0
      ? `<div class="msg msg-error no-print" role="alert">
            ${
              errors.length === 1
                ? e(errors[0])
                : `Fix the following, then generate the result again:
                <ul>${errors.map((err) => `<li>${e(err)}</li>`).join('')}</ul>`
            }
        </div>`
      : '';

  const notFoundHtml = notFound
    ? `<div class="msg msg-error no-print" role="alert">
            No student with enrollment number <strong>${e(enrollment)}</strong> appears in the loaded
            sheets. Check the number against the ${e(idLabel)} column, or upload the sheet that contains it.
        </div>`
    : '';

  let subjectsMsg = '';
  if (subjects.length === 0 && Object.keys(store.slots).length > 0) {
    subjectsMsg = `<div class="msg msg-warn no-print">
            No teaching scheme has been uploaded for the ${e(semLabel)}, so the subject list,
            credits and parts are not known yet. Upload it in the <strong>Teaching scheme</strong>
            slot above.
        </div>`;
  } else if (subjects.length > 0 && store.scheme === null) {
    subjectsMsg = `<div class="msg msg-note no-print">
            Using the built-in subject list for the ${e(semLabel)}. Upload the teaching scheme
            to take subject names, credits and parts from it instead.
        </div>`;
  }

  const warningsHtml =
    store.warnings.length > 0
      ? `<div class="msg msg-warn no-print">
            Worth checking in the uploaded sheets:
            <ul>${store.warnings.map((w) => `<li>${e(w)}</li>`).join('')}</ul>
        </div>`
      : '';

  const noticesHtml = notices.map((n) => `<div class="msg msg-note no-print">${e(n)}</div>`).join('');

  const body = `<div class="wrap">

    <header class="masthead no-print">
        <a class="backlink" href="/">&larr; All semesters</a>
        <h1>Marksheet generator <span class="sem-tag">${e(semLabel)}</span></h1>
        <p>Enter an enrollment number, attach the sheets, and generate a printable result.</p>
    </header>

    <form class="panel no-print" method="post" action="/sem/${e(sem)}" enctype="multipart/form-data" autocomplete="off">
        <input type="hidden" name="token" value="${e(token)}">
        <input type="hidden" name="sem" value="${e(sem)}">

        <div class="panel-head">
            <h2>Generate a result</h2>
            <span class="step">${stepText}</span>
        </div>

        <div class="field">
            <label for="enrollment">Enrollment number</label>
            <input class="enroll-input" type="text" id="enrollment" name="enrollment"
                   value="${e(enrollment)}" placeholder="24004501210012"
                   inputmode="numeric" spellcheck="false" autofocus>
            <p class="hint">The number in the ${e(idLabel)} column. Spaces and case are ignored.</p>
        </div>

        ${slotsFieldset(store)}

        <div class="actions">
            <button class="btn btn-primary" type="submit" name="action" value="generate">Generate result</button>
            ${
              Object.keys(store.slots).length > 0
                ? '<button class="btn btn-ghost" type="submit" name="action" value="clear" formnovalidate>Remove stored sheets</button>'
                : ''
            }
        </div>
    </form>

    ${errorsHtml}
    ${notFoundHtml}
    ${subjectsMsg}
    ${warningsHtml}
    ${noticesHtml}
    ${inspectDetails(store)}
    ${documentsSection(input)}

</div>

<script>
document.querySelectorAll('[data-print]').forEach(function (button) {
    button.addEventListener('click', function () {
        document.body.setAttribute('data-print', button.dataset.print);
        window.print();
    });
});

function clearPrintScope() { document.body.removeAttribute('data-print'); }
window.addEventListener('afterprint', clearPrintScope);
if (window.matchMedia) {
    window.matchMedia('print').addListener(function (mql) {
        if (!mql.matches) { clearPrintScope(); }
    });
}

document.querySelectorAll('input[type="file"][data-slot]').forEach(function (input) {
    input.addEventListener('change', function () {
        var target = document.querySelector('[data-file-for="' + input.dataset.slot + '"]');
        if (target && input.files.length) {
            target.textContent = input.files[0].name + ' — ready to upload';
            input.closest('.slot').classList.remove('is-error');
        }
    });
});
</script>`;

  const title = input.student !== null ? `${input.student.name} — Marksheet` : 'Marksheet generator';
  return page(title, '', body);
}
