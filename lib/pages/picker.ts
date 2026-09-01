import { config } from '../config';
import { slotCounts } from '../blobStore';
import { e, page } from '../html';

export async function renderPicker(): Promise<string> {
  const slotKeys = Object.keys(config.slots);

  const cards = await Promise.all(
    Object.entries(config.semesters).map(async ([sem, meta]) => {
      const { loaded, subjects } = await slotCounts(sem);
      return { sem, label: meta.label, course: meta.course_name, loaded, total: slotKeys.length, subjects };
    })
  );

  const cardsHtml = cards
    .map(
      (card) => `
            <a class="sem-card" href="/sem/${encodeURIComponent(card.sem)}">
                <span class="sem-no">${e(card.sem)}</span>
                <span class="sem-body">
                    <span class="sem-label">${e(card.label)}</span>
                    ${card.course !== '' ? `<span class="sem-course">${e(card.course)}</span>` : ''}
                    <span class="sem-state${card.loaded === 0 ? ' is-empty' : ''}">
                        ${
                          card.loaded === 0
                            ? 'No sheets uploaded yet'
                            : `${card.loaded}/${card.total} sheets${card.subjects > 0 ? ` &middot; ${card.subjects} subjects` : ''}`
                        }
                    </span>
                </span>
                <span class="sem-go" aria-hidden="true">&rarr;</span>
            </a>`
    )
    .join('');

  const body = `<div class="wrap">
    <header class="masthead">
        <h1>Marksheet generator</h1>
        <p>Choose the semester you are generating results for.</p>
    </header>

    <div class="picker">${cardsHtml}
    </div>

    <p class="picker-note">
        Each semester keeps its own sheets, so they can never be mixed up. Subject
        names, credits and parts are read from that semester's teaching scheme.
    </p>
</div>`;

  return page('Marksheet generator', 'picker-page', body);
}
