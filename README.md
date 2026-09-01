# Marksheet generator

Enter an enrollment number, attach the marks sheets, get a printable marksheet.

No Composer, no database, no PDF library — only PHP's bundled `zip` and `xml`
extensions.

---

## Requirements

- PHP 7.4 or newer (tested on 7.4 and 8.3)
- Extensions: `zip`, `xml` (both ship with every mainstream PHP build)
- A writable `data/` and `cache/` directory

The app checks all of this on startup and shows a plain explanation if
something is missing, rather than a fatal error. To check yourself:

```bash
php -v
php -m
```

On PHP 7.4 the three PHP 8 string helpers (`str_contains`, `str_starts_with`,
`str_ends_with`) are polyfilled at the top of `lib/XlsxReader.php`. The
polyfills are guarded by `function_exists`, so PHP 8 uses its own natives.

## Install

1. Copy the whole folder into your web root, e.g. `htdocs/marksheet/`.
2. Make two directories writable by the web server:

   ```bash
   chmod 775 data cache
   ```

3. Open `http://your-server/marksheet/` in a browser.

The app creates `data/` and `cache/` on first run if they are missing, and drops
an `.htaccess` into each so uploaded workbooks can never be downloaded directly.
If you run nginx (which ignores `.htaccess`), add this to your server block:

```nginx
location ~ ^/marksheet/(data|cache)/ { deny all; }
```

PHP's built-in server (`php -S`) also ignores `.htaccess`, so `data/` is
reachable while you are testing with it. That is fine on `localhost`, but do
not expose a `php -S` instance to a network — use Apache or nginx for anything
shared.

### File sizes

The app imposes no size limit of its own. Worksheets are streamed straight out
of the .xlsx archive rather than loaded whole, so a large sheet costs little
extra memory — 20,000 students across 9 subjects parses in about 2.5 seconds.

What does limit you is PHP itself, and **its default `upload_max_filesize` is
only 2 MB**. If your server is set that low the page says so above the buttons,
with the fix for your setup. To raise it:

**Built-in server (`php -S`)** — it ignores `.htaccess` and `.user.ini`, so run
the bundled launcher instead:

```
start.bat        (Windows — double-click it)
./start.sh       (Mac or Linux)
```

Both start PHP on http://localhost:8000 with the limits at 512 MB. If PHP is
not on your PATH, `start.bat` tells you the XAMPP command to use instead.

**XAMPP or Apache with mod_php** — the bundled `.htaccess` raises the limits
already. If Apache is configured with `AllowOverride None` it is ignored, so
edit `php.ini` instead.

**PHP-FPM or CGI** — the bundled `.user.ini` raises them. It can take a few
minutes to take effect.

**No uploading at all** — copy the files straight into the `data` folder as
`internal.xlsx`, `theory.xlsx`, `viva.xlsx` and `scheme.xlsx`, then reload the
page. This sidesteps PHP's upload limits completely and is the simplest option
for files that rarely change.

In `php.ini` the settings are `upload_max_filesize`, `post_max_size` (must be
at least as large) and `memory_limit`. Restart the server afterwards.

## Using it

1. **Choose the semester** on the first page.
2. Type the enrollment number.
3. Attach the sheets — internal (CCE), external theory (E), external practical
   (V), and that semester's teaching scheme.
4. Press **Generate result**.

Each semester keeps its own sheets under `data/sem2`, `data/sem4` and so on, so
they can never be mixed up. A Semester 4 enrollment number will simply not be
found while Semester 2 is selected.

Sheets are stored on the server after the first upload, so from then on you only
need to type an enrollment number. Attach a file again to replace that one sheet;
**Remove stored sheets** clears them all.

### Saving the PDF

Press **Save as PDF**, choose *Save as PDF* as the destination, and turn off
**Headers and footers** in the print dialog so the browser does not stamp the
page URL and date onto your marksheet. Paper size A4, margins 12 mm are already
set by the stylesheet.

---

## What the sheets must contain

The parser reads your existing exported sheets as-is. It expects:

- A header row where each subject cell contains the subject code in brackets,
  e.g. `Operating Systems (150120402)`
- The row directly beneath it holding the component and its maximum,
  e.g. `V (50)`, `CCE exam (25)`, `E (70)`
- A column headed `GRN No.`, `Enrollment No.`, `Seat No.` or similar
- Optionally `Student Name` and `Roll No.` columns

Nothing is tied to a fixed column letter. Subject blocks are located by their
codes, so inserting or reordering columns will not break anything.

**Maximum marks are always read from the sheet**, never from configuration. If
CCE is out of 25 for one subject and 50 for another, that is handled
automatically. Change a total in Excel and the marksheet follows.

### Multiple worksheet tabs

Every tab in a workbook is read, not just the first. If your theory marks are
split across tabs — one per division, branch or subject — put them all in one
file and upload it once. The slot summary shows how many tabs were found.

### Values that get special treatment

| In the cell | On the marksheet | Counted in the total |
|---|---|---|
| A number | the number, over its maximum | yes |
| `AB`, `ABS`, `ABSENT` | `AB` in red | as 0, maximum still counted |
| `UM`, `UFM`, `MAL`, `MP` | `UM` in red (unfair means) | as 0, maximum still counted |
| `N/A`, `NA`, `-` | *Not applicable* | excluded entirely |
| any other text | printed as-is in red, and reported on screen | excluded entirely |
| empty | *Not available* | excluded entirely |

An unrecognised code is never silently discarded — it is printed exactly as it
appears in the sheet and named in a note above the marksheet, so a real result
can never be mistaken for missing data.

*Not available* means no uploaded sheet supplied that component — usually you
have not uploaded that sheet yet.

---

## When a subject prints blank

Expand **What was read from each sheet** on the main page. It lists every
worksheet tab and every subject the parser found in each sheet, with its part
and maximum. A subject missing from that list is a subject that will print
blank — so if Operating Systems is absent under *External theory (E)*, that
sheet is not supplying it.

The usual cause is a column heading the app does not recognise. It says so
explicitly, naming the exact heading, both on the main page and in the
diagnostics. Map it in `config.php`:

```php
'component_overrides' => [
    'Ext'             => 'E',
    'University Exam' => 'E',
],
```

Match the heading text only and ignore the marks in brackets — a column headed
`Ext (70)` is entered as `'Ext'`. Press **Generate result** again afterwards;
the cache rebuilds automatically when the overrides change.

Headings already recognised without any configuration: `E`, `ESE`, `Theory`,
`Theory exam`, `External`, `V`, `Viva`, `Practical`, `CCE`, `CCE exam`,
`Internal`, `Continuous`.

---

## The result documents

Three documents are produced from the same search, each printing on its own
sheet of paper:

- **Official grade sheet** — the university heading, the ruled identity table,
  a letter grade under CCE, SEE-TH and SEE-PRA, the notes, signature lines and
  the campus address footer. This matches the printed provisional result.
- **Grade sheet for letterhead** — the same document with the heading and
  address footer replaced by blank space, for paper already pre-printed with
  the college header. The dashed outline marking that space is shown on screen
  only and never prints.
- **Marks sheet** — the marks behind those grades, with totals and percentages.

**Signature lines always print blank.** No signature is ever reproduced; the
authorised person signs by hand. Change who signs with `signatories`:

```php
'signatories' => ['HOD', 'Director'],
```

A **Credit** column prints after Subject Name, in the same position as the
university's Semester Performance Report, with credits read from the teaching
scheme. Set `show_credits` to `false` to drop it.

Subjects the student is not registered for are left out rather than printed as
a row of dashes, matching the official sheet. Set `hide_not_applicable` to
`false` to show them.

The heading text, campus address and contact line are in `letterhead`. Save
your logo as `assets/logo.png` and it appears beside the university name; with
no such file the text simply centres. `letterhead_space` sets how much room the
letterhead variant reserves, currently `38mm`.

Use the button named after a document to print it alone; **All** prints them in
order. Add, remove or duplicate entries in `documents` — a letterhead marks
sheet is one copied entry with `'style' => 'letterhead'`.

**Each component is graded on its own percentage** — CCE, theory and practical
each get their own letter. A dash means that component does not apply to the
subject, or the student is not registered for it.

A subject's grade point is the mean of its component grade points, weighted by
each component's maximum marks:

> subject point = Σ(component maximum × grade point) ÷ Σ(component maximum)

SPI is then the credit-weighted mean of those subject points, fixed to two
decimals:

> SPI = Σ(credit × subject point) ÷ Σ(credit)

### The teaching scheme defines the semester

Upload the scheme in the fourth slot. It is read with a different parser to the
marks sheets, because a scheme is laid out one row per subject rather than one
row per student, and it supplies **everything about the semester**:

| From the scheme | Column used |
|---|---|
| Which subjects exist, and their order | Course Code |
| Subject names | Course Title |
| Credits | Credits |
| Which parts each subject has | Max Marks E / CEC / V |

A Max Marks column above zero means that part applies — so a subject with
`E 50, CEC 25, V 0` prints CCE and TH, and nothing under PRA. Nothing about any
particular semester is written into the code.

Nothing is tied to fixed columns either:

- the subject-code column is whichever holds the most code-shaped cells
- the credit column is found by heading — `Total Credits`, `Credits`, or a
  bare `C` sitting under a merged `Teaching Scheme` heading
- if a scheme has no Max Marks columns, the parts fall back to whichever ones
  the uploaded marks sheets actually contain

Subject codes may be bare (`150120402`) or bracketed inside the title
(`Operating Systems (150120402)`). Headings split across merged rows are
handled, and a decoy column such as `Theory Credit` will not beat
`Total Credits`.

If your scheme uses wording none of that catches, the error names every
heading it saw; set the right one in `config.php`:

```php
'credit_column' => 'Weightage',
```

Merged cells are expanded when reading a scheme, so a credit shared by two
elective rows is picked up for both — in the Semester 4 scheme, Spring and
Laravel share one entry of 4 credits, and both need it. Marks sheets are read
without that expansion, since their merged subject headings must stay anchored
to one column.

Expand **What was read from each sheet** to see every credit that was read,
with a total, so you can check it against the original. A scheme listing both
electives totals more than the semester load — each student takes one, so their
own total comes out right (22 for Semester 4).

**Until a scheme is uploaded** the app uses `fallback_subjects` in
`config.php`, and says on screen that it is doing so. Only Semester 4 has a
fallback list; every other semester needs its scheme.

### Adding a semester

Add an entry to `semesters` in `config.php`:

```php
'semesters' => [
    '6' => [
        'label'        => '6th Semester',
        'course_name'  => 'Master of Computer Application (Integrated)',
        'passing_year' => '2027',
    ],
],
```

That is the whole change. It appears on the first page, gets its own
`data/sem6` folder, and takes its subjects, credits and parts from the scheme
you upload to it.

**SPI is wrong if the credits are wrong**, and credits are not proportional to
marks: Programming in Python is 75 marks and 4 credits, while Basic Statistics
is 100 marks and 2. Never infer them from mark totals — use the scheme.

### How each situation is treated

| Situation | Grade shown | SPI |
|---|---|---|
| Normal marks | from that component's percentage | counted |
| `AB` or `UM` | `F` (zero percent) | counted as 0 |
| `N/A` | `-` | excluded |
| Component not in the subject | `-` | excluded |
| Marks still missing | `-` | subject excluded, flagged on screen |

A subject with marks still missing cannot be graded, so it is left out of the
SPI and a note on screen says how many. The note does not print.

### Optional extras

```php
'show_class' => true,   // print the class and equivalent percentage by the SPI
```

Off by default, to match the uploaded format exactly. Equivalent percentage uses the scheme's formula, `(SPI − 0.5) × 10`, and the
class follows the SPI bands: 7.5+ first class with distinction, 6.5+ first
class, 5.5+ higher second, 5.0+ second, 4.0+ pass, below 4.0 fail.

### Changing the document

`result_title`, `course_name`, `passing_year` and `result_notes` are all in
`config.php`. `semester_number` is taken from the sheet unless you set it.

`result_columns` maps each printed column heading to a component, and
`result_groups` controls the spanning heading above them — so renaming `TH` to
`Theory`, or adding a fourth column, needs no code change. `subject_order` is
`code` for ascending subject code, or `config` for the order of the `subjects`
array.

The grade scale, class bands and credit unit are all in `config.php` — nothing
is hardcoded.

---

## Changing things

Everything you are likely to edit is in `config.php`.

**Subjects and print order** — the `subjects` array is the marksheet, row by row.
`components` decides which parts print for that subject:

```php
['code' => '150120402', 'name' => 'Operating Systems', 'components' => ['E', 'CCE']],
```

Subject names printed on the sheet come from the uploaded file when available, so
spelling always matches your official records; the `name` here is only a fallback.

**Next semester** — replace the `subjects` array with the new subject list. That
is the only change required.

**Upload slots** — add, rename or remove entries in `slots`. Each needs a
`kind`: `marks` for a student marks sheet, `credits` for a teaching scheme. A
slot for mid-semester marks needs only a new `marks` entry plus `'M'` in the
relevant subjects' `components`.

**Component labels** — `component_labels` controls what prints. To print `P`
instead of `V`, set `'V' => 'P'`. This changes only the printed label; detection
inside the spreadsheet is unaffected.

**Totals row** — set `show_totals` to `false` to hide it.

---

## File map

```
start.bat / start.sh  launch the built-in server with raised upload limits
.htaccess             raises limits under Apache; .user.ini does so under FPM
config.php            subjects, slots, labels, overrides
index.php             uploads, lookup, marksheet rendering
lib/XlsxReader.php    reads .xlsx via ZipArchive + XMLReader
lib/MarksStore.php    header detection, normalisation, merging
assets/app.css        screen and A4 print styles
data/                 stored workbooks (blocked from the web)
cache/                parsed index, rebuilt when a sheet changes
```

## Notes

- Every worksheet tab in a workbook is parsed, and hidden tabs are skipped.
- Parsed results are cached and reused until a stored file's size or timestamp
  changes, so searches stay fast with several hundred students.
- Enrollment numbers are compared as text. A 14-digit GRN exceeds float
  precision, so treating it as a number would corrupt the last digits.
- When two sheets both contain a value for the same subject and component, the
  first one loaded wins; a later upload cannot silently overwrite existing data.
- Fonts load from Google Fonts. On an offline intranet the page falls back to
  Georgia, a system sans, and a system monospace — the layout is unaffected.
