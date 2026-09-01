<?php
declare(strict_types=1);

require_once __DIR__ . '/XlsxReader.php';

/**
 * Turns a marks workbook into a normalised, enrollment-keyed index.
 *
 * Nothing about column positions is hardcoded. The parser locates the header
 * row by looking for subject codes, then reads each subject block's component
 * label AND its maximum marks straight out of the sub-header - so a sheet
 * where CCE is out of 25 for one subject and 50 for another is handled with
 * no configuration at all.
 */
final class MarksStore
{
    /** Recognised marks components, in the order they are probed. */
    private const COMPONENT_RULES = [
        'CCE' => '/\bCCE\b|CONTINUOUS|INTERNAL/i',
        'V'   => '/\bV\b|VIVA|PRACTICAL|\bPR\b|\bP\b/i',
        'E'   => '/\bE\b|\bESE\b|EXTERNAL|THEORY|\bTH\b/i',
        'M'   => '/\bMID\b|\bMSE\b/i',
    ];

    /** Sub-header cells that are decoration, never a marks column. */
    private const NOT_A_COMPONENT = '/^(GRADE|TOTAL\s*OBTAINED|TOTAL|OBTAINED|MARKS|%|REMARKS?|RESULT|SR|SIGN)$/i';

    /** Cell values meaning "this subject does not apply to this student". */
    private const NOT_APPLICABLE = '/^(N\/?A|NOT\s*APPLICABLE|--?|—)$/i';

    /** Cell values meaning "student was absent". */
    private const ABSENT = '/^(AB|ABS|ABSENT)$/i';

    /** Cell values meaning the result was cancelled for unfair means. */
    private const UNFAIR = '/^(UM|UFM|MAL|MP|MALPRACTICE)$/i';

    /**
     * Parses one workbook.
     *
     * @return array{
     *   students: array<string, array{name:string, roll:string, marks:array<string,array<string,array{obtained:?float,max:?float,status:string}>>}>,
     *   subjects: array<string, string>,
     *   components: array<int,string>,
     *   institute: string,
     *   semester: string,
     *   id_label: string
     * }
     */
    public static function parseFile(string $path, array $overrides = []): array
    {
        $sheets   = XlsxReader::allSheets($path);
        $parts    = [];
        $warnings = [];
        $failures = [];

        foreach ($sheets as $name => $rows) {
            try {
                $part            = self::parseRows($rows, $overrides);
                $part['warnings'] = array_map(
                    static fn (string $w): string => count($sheets) > 1 ? sprintf('tab "%s": %s', $name, $w) : $w,
                    $part['warnings']
                );
                $parts[$name] = $part;
                $warnings     = array_merge($warnings, $part['warnings']);
            } catch (RuntimeException $e) {
                $failures[$name] = $e->getMessage();
            }
        }

        if ($parts === []) {
            throw new RuntimeException(reset($failures) ?: 'The worksheet is empty.');
        }

        $merged             = self::merge($parts);
        $merged['warnings'] = $warnings;
        $merged['tabs']     = array_keys($parts);

        return $merged;
    }

    /**
     * Parses a single worksheet's rows.
     *
     * @param array<string,string> $overrides  raw sub-header text => component
     */
    private static function parseRows(array $rows, array $overrides = []): array
    {
        if ($rows === []) {
            throw new RuntimeException('The worksheet is empty.');
        }

        $headerRow = self::findHeaderRow($rows);
        if ($headerRow === null) {
            throw new RuntimeException(
                'No subject codes found. The header row must contain subject names with a code in brackets, e.g. "Operating Systems (150120402)".'
            );
        }

        $header    = $rows[$headerRow];
        $subHeader = $rows[$headerRow + 1] ?? [];

        $idCol   = self::findColumn($header, '/GRN|ENROL{1,2}MENT|ENROL{1,2}\.?\s*(NO|NUM)|SEAT\s*NO|EXAM\s*NO/i');
        $nameCol = self::findColumn($header, '/STUDENT\s*NAME|^NAME$|CANDIDATE/i');
        $rollCol = self::findColumn($header, '/ROLL/i');

        if ($idCol === null) {
            throw new RuntimeException(
                'No enrollment column found. One header cell must read "GRN No.", "Enrollment No." or similar.'
            );
        }

        [$blocks, $unresolved] = self::findSubjectBlocks($header, $subHeader, $overrides);
        if ($blocks === []) {
            throw new RuntimeException(
                'Subject codes were found but no marks component could be identified beneath them (expected E, V or CCE).'
            );
        }

        // A subject that carries a code but no recognisable component would
        // otherwise vanish from the marksheet without explanation.
        $warnings = [];
        foreach ($unresolved as $u) {
            $warnings[] = sprintf(
                '"%s (%s)" was skipped - the heading below it (%s) was not recognised as E, V or CCE. Add it to component_overrides in config.php.',
                $u['name'],
                $u['code'],
                $u['labels'] === '' ? 'blank' : '"' . $u['labels'] . '"'
            );
        }

        $students   = [];
        $subjects   = [];
        $components = [];
        $oddCodes   = [];

        foreach ($blocks as $b) {
            $subjects[$b['code']]   = $b['name'];
            $components[$b['comp']] = true;
        }

        $lastRow = max(array_keys($rows));
        for ($r = $headerRow + 2; $r <= $lastRow; $r++) {
            if (!isset($rows[$r])) {
                continue;
            }
            $row = $rows[$r];
            $id  = self::normaliseId((string) ($row[$idCol] ?? ''));
            if ($id === '') {
                continue;
            }

            $entry = $students[$id] ?? [
                'name'  => self::cleanName((string) ($nameCol !== null ? ($row[$nameCol] ?? '') : '')),
                'roll'  => trim((string) ($rollCol !== null ? ($row[$rollCol] ?? '') : '')),
                'marks' => [],
            ];

            foreach ($blocks as $b) {
                $mark = self::readMark((string) ($row[$b['col']] ?? ''), $b['max']);
                if ($mark['status'] === 'code') {
                    $oddCodes[$mark['raw']] = ($oddCodes[$mark['raw']] ?? 0) + 1;
                }
                $entry['marks'][$b['code']][$b['comp']] = $mark;
            }

            $students[$id] = $entry;
        }

        foreach ($oddCodes as $code => $count) {
            $warnings[] = sprintf(
                'The value "%s" appears in %d marks cell%s and is not a number. It is printed as-is and left out of the total.',
                $code, $count, $count === 1 ? '' : 's'
            );
        }

        return [
            'students'   => $students,
            'subjects'   => $subjects,
            'components' => array_keys($components),
            'warnings'   => $warnings,
            'institute'  => self::textAbove($rows, $headerRow, 0),
            'semester'   => self::textAbove($rows, $headerRow, 1),
            'id_label'   => trim((string) ($header[$idCol] ?? 'Enrollment No.')),
        ];
    }

    /**
     * Merges several parsed workbooks into one index.
     *
     * A later file never overwrites a value an earlier file already supplied,
     * so re-uploading overlapping sheets cannot silently destroy data.
     *
     * @param  array<string,array> $parsed  slot => parseFile() result
     */
    public static function merge(array $parsed): array
    {
        $students   = [];
        $subjects   = [];
        $components = [];
        $warnings   = [];
        $meta       = ['institute' => '', 'semester' => '', 'id_label' => ''];

        foreach ($parsed as $p) {
            $subjects += $p['subjects'];
            foreach ($p['components'] ?? [] as $c) {
                $components[$c] = true;
            }
            $warnings = array_merge($warnings, $p['warnings'] ?? []);
            foreach (['institute', 'semester', 'id_label'] as $k) {
                if ($meta[$k] === '' && ($p[$k] ?? '') !== '') {
                    $meta[$k] = $p[$k];
                }
            }

            foreach ($p['students'] as $id => $s) {
                if (!isset($students[$id])) {
                    $students[$id] = $s;
                    continue;
                }
                if ($students[$id]['name'] === '') {
                    $students[$id]['name'] = $s['name'];
                }
                if ($students[$id]['roll'] === '') {
                    $students[$id]['roll'] = $s['roll'];
                }
                foreach ($s['marks'] as $code => $comps) {
                    foreach ($comps as $comp => $mark) {
                        if (!isset($students[$id]['marks'][$code][$comp])
                            || $students[$id]['marks'][$code][$comp]['status'] === 'missing') {
                            $students[$id]['marks'][$code][$comp] = $mark;
                        }
                    }
                }
            }
        }

        return [
            'students'   => $students,
            'subjects'   => $subjects,
            'components' => array_keys($components),
            'warnings'   => array_values(array_unique($warnings)),
        ] + $meta;
    }

    /**
     * Reads a whole teaching scheme: subject codes, titles, credits, and which
     * components each subject carries.
     *
     * A scheme is laid out one row per subject, not one row per student, so it
     * needs its own reader. Nothing is tied to fixed columns: the subject-code
     * column is found by counting code-shaped cells, the credit column by
     * heading, and the components by which of the Max Marks columns are above
     * zero.
     *
     * @return array{subjects:array<string,array{name:string,credits:float,components:array<int,string>}>,order:array<int,string>,tabs:array<int,string>}
     */
    public static function parseScheme(string $path, ?string $forceColumn = null): array
    {
        // Merges are expanded so a credit shared by two elective rows is read
        // for both, not just the first.
        $sheets   = XlsxReader::allSheets($path, true);
        $subjects = [];
        $order    = [];
        $tabs     = [];
        $reason   = '';

        foreach ($sheets as $name => $rows) {
            try {
                $found = self::schemeFromRows($rows, $forceColumn);
            } catch (RuntimeException $e) {
                $reason = $reason !== '' ? $reason : $e->getMessage();
                continue;
            }

            $tabs[] = $name;
            foreach ($found as $code => $info) {
                if (!isset($subjects[$code])) {
                    $subjects[$code] = $info;
                    $order[]         = (string) $code;
                }
            }
        }

        if ($subjects === []) {
            throw new RuntimeException(
                $reason !== '' ? $reason : 'No subject codes with credits were found in this file.'
            );
        }

        return ['subjects' => $subjects, 'order' => $order, 'tabs' => $tabs];
    }

    /**
     * @return array<string,array{name:string,credits:float,components:array<int,string>}>
     * @throws RuntimeException
     */
    private static function schemeFromRows(array $rows, ?string $forceColumn = null): array
    {
        // The subject-code column is whichever holds the most code-shaped cells.
        $tally = [];
        foreach ($rows as $row) {
            foreach ($row as $col => $value) {
                if (self::anyCode((string) $value) !== null) {
                    $tally[$col] = ($tally[$col] ?? 0) + 1;
                }
            }
        }
        if ($tally === []) {
            throw new RuntimeException('No subject codes were found. Each row should carry a code such as 150120402.');
        }
        arsort($tally);
        $codeCol = (int) array_key_first($tally);

        $dataRows = [];
        foreach ($rows as $num => $row) {
            if (self::anyCode((string) ($row[$codeCol] ?? '')) !== null) {
                $dataRows[$num] = $row;
            }
        }
        $firstData = min(array_keys($dataRows));

        // Headings can be split over several rows when cells are merged, so
        // each column's heading is the joined text of the rows above the data.
        $maxCol = 0;
        foreach ($rows as $row) {
            foreach (array_keys($row) as $col) {
                $maxCol = max($maxCol, (int) $col);
            }
        }

        $headers = [];
        for ($col = 0; $col <= $maxCol; $col++) {
            $parts = [];
            for ($r = max(1, $firstData - 5); $r < $firstData; $r++) {
                $text = self::squash((string) ($rows[$r][$col] ?? ''));
                if ($text !== '') {
                    $parts[] = $text;
                }
            }
            $headers[$col] = implode(' ', $parts);
        }

        $creditCol = self::creditColumn($headers, $dataRows, $forceColumn);
        $nameCol   = self::findColumn($headers, '/course\s*title|subject\s*(name|title)|course\s*name|^title$|^name$/i')
            ?? $codeCol + 1;
        $maxCols   = self::maxMarkColumns($headers);

        $subjects = [];
        foreach ($dataRows as $row) {
            $code  = self::anyCode((string) $row[$codeCol]);
            $value = self::squash((string) ($row[$creditCol] ?? ''));

            if ($code === null || !is_numeric($value) || (float) $value <= 0) {
                continue;
            }

            $components = [];
            foreach ($maxCols as $comp => $col) {
                $mark = self::squash((string) ($row[$col] ?? ''));
                if (is_numeric($mark) && (float) $mark > 0) {
                    $components[] = $comp;
                }
            }

            $subjects[$code] = [
                'name'       => self::squash((string) ($row[$nameCol] ?? '')),
                'credits'    => (float) $value,
                'components' => $components,
            ];
        }

        if ($subjects === []) {
            throw new RuntimeException('Subject codes were found but none had a usable credit value.');
        }

        return $subjects;
    }

    /**
     * Locates the credits column, preferring the most explicit wording.
     *
     * @throws RuntimeException
     */
    private static function creditColumn(array $headers, array $dataRows, ?string $forceColumn): int
    {
        $candidates = [];
        foreach ($headers as $col => $text) {
            if ($text === '') {
                continue;
            }
            // An explicit heading from config outranks every built-in rule.
            if ($forceColumn !== null && $forceColumn !== ''
                && strcasecmp(self::squash($forceColumn), $text) === 0) {
                $candidates[] = [0, $col];
            } elseif (preg_match('/total\s*credits?/i', $text)) {
                $candidates[] = [1, $col];
            } elseif (preg_match('/credits?/i', $text)) {
                $candidates[] = [2, $col];
            } elseif (preg_match('/^(C|CR|CRD)\.?$/i', $text)) {
                $candidates[] = [3, $col];
            }
        }
        usort($candidates, static function (array $a, array $b): int {
            return [$a[0], $a[1]] <=> [$b[0], $b[1]];
        });

        foreach ($candidates as $candidate) {
            $col   = $candidate[1];
            $valid = 0;
            foreach ($dataRows as $row) {
                $value = self::squash((string) ($row[$col] ?? ''));
                if (is_numeric($value) && (float) $value > 0 && (float) $value <= 30) {
                    $valid++;
                }
            }
            // Accept only if the column reads as credits for most rows.
            if ($valid >= max(1, (int) ceil(count($dataRows) * 0.5))) {
                return $col;
            }
        }

        $seen = array_values(array_filter($headers, static function (string $t): bool { return $t !== ''; }));
        throw new RuntimeException(sprintf(
            '%d subject codes were found but no credit column. Headings seen: %s. Rename the credits column to "Credits" or set credit_column in config.php.',
            count($dataRows),
            $seen === [] ? 'none' : '"' . implode('", "', array_slice($seen, 0, 12)) . '"'
        ));
    }

    /**
     * Finds the Max Marks columns, which reveal a subject's components.
     *
     * Passing Marks columns are worded almost identically, so only headings
     * carrying "max" are considered.
     *
     * @return array<string,int>  component code => column index
     */
    private static function maxMarkColumns(array $headers): array
    {
        $found = [];
        foreach ($headers as $col => $text) {
            if ($text === '' || !preg_match('/max\s*marks?/i', $text)) {
                continue;
            }

            $tail = self::squash((string) preg_replace('/.*max\s*marks?/i', '', $text));

            if (preg_match('/\b(CEC|CCE|CIE|INTERNAL)\b/i', $tail)) {
                $comp = 'CCE';
            } elseif (preg_match('/\b(V|VIVA|PRACTICAL|PR)\b/i', $tail)) {
                $comp = 'V';
            } elseif (preg_match('/\b(E|ESE|THEORY|TH)\b/i', $tail)) {
                $comp = 'E';
            } else {
                continue;
            }

            if (!isset($found[$comp])) {
                $found[$comp] = (int) $col;
            }
        }

        return $found;
    }

    /** A subject code, bare or bracketed. */
    private static function anyCode(string $value): ?string
    {
        $text = self::squash($value);
        if ($text === '') {
            return null;
        }
        if (preg_match('/^\(?(\d{8,12})\)?$/', $text, $m)) {
            return $m[1];
        }
        if (preg_match('/\((\d{8,12})\)/', $text, $m)) {
            return $m[1];
        }

        return null;
    }

    /* ------------------------------------------------------------------ */
    /* Detection helpers                                                    */
    /* ------------------------------------------------------------------ */

    /** The header row is the first row carrying two or more subject codes. */
    private static function findHeaderRow(array $rows): ?int
    {
        $best = null;
        $bestCount = 0;

        foreach (array_slice($rows, 0, 25, true) as $num => $row) {
            $count = 0;
            foreach ($row as $v) {
                if (self::subjectCode((string) $v) !== null) {
                    $count++;
                }
            }
            if ($count > $bestCount) {
                $bestCount = $count;
                $best      = $num;
            }
        }

        return $bestCount >= 1 ? $best : null;
    }

    /**
     * Maps each subject block to its marks column, component and maximum.
     *
     * Subject headers are merged cells, so only the block's first column
     * carries the name. The component sub-header is searched across the whole
     * block rather than assumed to sit in the first column.
     *
     * @return array{0:array<int,array{code:string,name:string,col:int,comp:string,max:?float}>,1:array<int,array{code:string,name:string,labels:string}>}
     */
    private static function findSubjectBlocks(array $header, array $subHeader, array $overrides = []): array
    {
        $starts = [];
        foreach ($header as $col => $value) {
            $code = self::subjectCode((string) $value);
            if ($code !== null) {
                $starts[$col] = ['code' => $code, 'name' => self::subjectName((string) $value)];
            }
        }
        if ($starts === []) {
            return [[], []];
        }

        ksort($starts);
        $cols       = array_keys($starts);
        $maxCol     = max(array_merge(array_keys($header), array_keys($subHeader) ?: [0]));
        $blocks     = [];
        $unresolved = [];

        foreach ($cols as $i => $start) {
            $end     = isset($cols[$i + 1]) ? $cols[$i + 1] - 1 : $maxCol;
            $matched = false;
            $seen    = [];

            for ($c = $start; $c <= $end; $c++) {
                $label = trim((string) ($subHeader[$c] ?? ''));
                if ($label === '' || preg_match(self::NOT_A_COMPONENT, self::squash($label))) {
                    continue;
                }

                $comp = self::componentOf($label, $overrides);
                if ($comp === null) {
                    $seen[] = self::squash($label);
                    continue;
                }

                $blocks[] = [
                    'code' => $starts[$start]['code'],
                    'name' => $starts[$start]['name'],
                    'col'  => $c,
                    'comp' => $comp,
                    'max'  => self::maxOf($label),
                ];
                $matched = true;
                break; // one marks column per subject block
            }

            if (!$matched) {
                $unresolved[] = [
                    'code'   => $starts[$start]['code'],
                    'name'   => $starts[$start]['name'],
                    'labels' => implode(', ', array_unique($seen)),
                ];
            }
        }

        return [$blocks, $unresolved];
    }

    /** Normalises "CCE exam\n(25)" -> "CCE", "V\n(50)" -> "V". */
    private static function componentOf(string $label, array $overrides = []): ?string
    {
        $text = self::squash(preg_replace('/\([^)]*\)/', ' ', $label) ?? $label);
        if ($text === '') {
            return null;
        }

        // An exact override in config.php always wins over the built-in rules.
        foreach ($overrides as $needle => $comp) {
            if (strcasecmp(self::squash((string) $needle), $text) === 0) {
                return (string) $comp;
            }
        }

        foreach (self::COMPONENT_RULES as $comp => $pattern) {
            if (preg_match($pattern, $text)) {
                return $comp;
            }
        }

        return null;
    }

    /** Pulls the maximum out of "V (50)". */
    private static function maxOf(string $label): ?float
    {
        if (preg_match('/\(\s*(\d+(?:\.\d+)?)\s*\)/', $label, $m)) {
            return (float) $m[1];
        }

        return null;
    }

    /** A subject code is a run of 8-12 digits inside brackets. */
    private static function subjectCode(string $value): ?string
    {
        if (preg_match('/\((\d{8,12})\)/', $value, $m)) {
            return $m[1];
        }

        return null;
    }

    private static function subjectName(string $value): string
    {
        $name = preg_replace('/\(\s*\d{8,12}\s*\)/', '', $value) ?? $value;

        return self::squash($name);
    }

    private static function findColumn(array $header, string $pattern): ?int
    {
        foreach ($header as $col => $value) {
            if (preg_match($pattern, self::squash((string) $value))) {
                return $col;
            }
        }

        return null;
    }

    /* ------------------------------------------------------------------ */
    /* Value helpers                                                        */
    /* ------------------------------------------------------------------ */

    /**
     * @return array{obtained:?float,max:?float,status:string,raw:string}
     *         status: ok | absent | unfair | na | code | missing
     *
     * An unrecognised code is kept verbatim under 'code' rather than being
     * treated as missing - printing "Not available" for a real result on the
     * sheet is worse than printing something unexpected.
     */
    private static function readMark(string $raw, ?float $max): array
    {
        $value = self::squash($raw);

        if ($value === '') {
            return ['obtained' => null, 'max' => $max, 'status' => 'missing', 'raw' => ''];
        }
        if (is_numeric($value)) {
            return ['obtained' => (float) $value, 'max' => $max, 'status' => 'ok', 'raw' => $value];
        }
        if (preg_match(self::NOT_APPLICABLE, $value)) {
            return ['obtained' => null, 'max' => null, 'status' => 'na', 'raw' => $value];
        }
        if (preg_match(self::ABSENT, $value)) {
            return ['obtained' => null, 'max' => $max, 'status' => 'absent', 'raw' => $value];
        }
        if (preg_match(self::UNFAIR, $value)) {
            return ['obtained' => null, 'max' => $max, 'status' => 'unfair', 'raw' => $value];
        }

        return ['obtained' => null, 'max' => $max, 'status' => 'code', 'raw' => $value];
    }

    /**
     * Enrollment numbers must compare as text - a 14-digit GRN exceeds float
     * precision, and leading zeros in other formats are significant.
     */
    public static function normaliseId(string $raw): string
    {
        $id = strtoupper(self::squash($raw));
        $id = preg_replace('/[^A-Z0-9\/\-]/', '', $id) ?? '';

        if ($id !== '' && preg_match('/^\d+(?:\.0+)?[Ee]?\+?\d*$/', $id) && str_contains($id, '.')) {
            $id = rtrim(rtrim($id, '0'), '.'); // guards against "24004501210012.0"
        }

        return $id;
    }

    private static function cleanName(string $raw): string
    {
        // Strips the roster status suffix, e.g. "PATEL RAVI (Active)".
        $name = preg_replace('/\s*\((?:Active|Inactive|Dropped|Cancelled)\)\s*$/i', '', $raw) ?? $raw;

        return self::squash($name);
    }

    private static function textAbove(array $rows, int $headerRow, int $offset): string
    {
        $target = $offset + 1;
        if ($target >= $headerRow) {
            return '';
        }

        return self::squash((string) ($rows[$target][0] ?? ''));
    }

    /** Collapses newlines and repeated whitespace into single spaces. */
    private static function squash(string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }
}
