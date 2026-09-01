<?php
declare(strict_types=1);

/**
 * Marksheet generator
 * Enter an enrollment number, upload the marks sheets, print the result.
 */

require_once __DIR__ . '/lib/MarksStore.php';

// Fail early and legibly rather than with a fatal error mid-request.
$missing = [];
foreach (['zip', 'xml'] as $extension) {
    if (!extension_loaded($extension)) {
        $missing[] = $extension;
    }
}
if (PHP_VERSION_ID < 70400 || $missing !== []) {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><meta charset="utf-8"><title>Setup needed</title>';
    echo '<div style="max-width:44em;margin:3em auto;font:16px/1.55 system-ui,sans-serif;color:#14203A">';
    echo '<h1 style="font-size:1.4em">This server needs a small change</h1>';
    if (PHP_VERSION_ID < 70400) {
        printf(
            '<p>PHP 7.4 or newer is required. This server runs <b>%s</b>. Please upgrade PHP.</p>',
            htmlspecialchars(PHP_VERSION, ENT_QUOTES)
        );
    }
    if ($missing !== []) {
        printf(
            '<p>The <b>%s</b> extension%s not enabled. Open <code>php.ini</code>, remove the'
            . ' <code>;</code> in front of %s, then restart the server.</p>',
            htmlspecialchars(implode('</b> and <b>', $missing), ENT_QUOTES, 'UTF-8', false),
            count($missing) === 1 ? ' is' : 's are',
            htmlspecialchars(implode(' and ', array_map(
                static function (string $e): string { return 'extension=' . $e; },
                $missing
            )), ENT_QUOTES)
        );
    }
    echo '</div>';
    exit;
}

$config = require __DIR__ . '/config.php';

const DATA_ROOT  = __DIR__ . '/data';
const CACHE_ROOT = __DIR__ . '/cache';

/** Each semester keeps its own sheets, so they can never be mixed up. */
function dataDir(string $sem): string
{
    return DATA_ROOT . '/sem' . $sem;
}

function cacheKey(string $sem): string
{
    return CACHE_ROOT . '/sem' . $sem . '.json';
}

function slotPath(string $sem, string $slot): string
{
    return dataDir($sem) . '/' . $slot . '.xlsx';
}

session_start();
bootstrapDirectories($config['semesters']);

/* --------------------------------------------------------------- semester */

$semesters = $config['semesters'];
$sem       = (string) preg_replace('/[^0-9A-Za-z]/', '', (string) ($_REQUEST['sem'] ?? ''));

// Without a valid semester there is nothing to scope the sheets to, so the
// picker is shown instead of the generator.
if ($sem === '' || !isset($semesters[$sem])) {
    renderSemesterPicker($config);
    exit;
}

$semLabel  = (string) ($semesters[$sem]['label'] ?? ($sem . ' Semester'));
$selfUrl   = strtok((string) $_SERVER['REQUEST_URI'], '?') . '?sem=' . rawurlencode($sem);

$errors     = [];
$notices    = [];
$enrollment = '';
$searched   = false;

/* ---------------------------------------------------------------- actions */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hasValidToken()) {
        $errors[] = 'Your session expired before the form was submitted. Please try again.';
    } elseif (($_POST['action'] ?? '') === 'clear') {
        clearStoredSheets($sem);
        header('Location: ' . $selfUrl . '&cleared=1');
        exit;
    } else {
        foreach (array_keys($config['slots']) as $slot) {
            $result = acceptUpload($sem, $slot);
            if ($result !== null) {
                $errors[] = $result;
            }
        }

        $enrollment = MarksStore::normaliseId((string) ($_POST['enrollment'] ?? ''));
        $searched   = true;

        if ($enrollment === '') {
            $errors[] = 'Enter an enrollment number to generate a result.';
        }
    }
}

if (isset($_GET['cleared'])) {
    $notices[] = 'Stored sheets removed. Upload the sheets again to continue.';
}

/* ------------------------------------------------------------------ data */

$store    = loadStore($sem, $config['slots'], $config['component_overrides'] ?? [], $config['credit_column'] ?? null);
$errors   = array_merge($errors, $store['errors']);
$subjects = resolveSubjects($store, $config, $sem);
$student  = null;
$notFound = false;

if ($enrollment !== '' && $errors === [] && $subjects === []) {
    $errors[] = 'No subject list is available for the ' . $semLabel
        . '. Upload the teaching scheme so the subjects, credits and parts can be read from it.';
}

if ($enrollment !== '' && $errors === []) {
    if ($store['students'] === []) {
        $errors[] = 'No marks are loaded yet. Upload at least one sheet along with the enrollment number.';
    } elseif (isset($store['students'][$enrollment])) {
        $student = $store['students'][$enrollment];
    } else {
        $notFound = true;
    }
}

$sheet     = $student !== null ? buildSheet($student, $store, $config, $subjects) : null;
$institute = $store['institute'] !== '' ? $store['institute'] : $config['institute'];
$idLabel   = $store['id_label']  !== '' ? $store['id_label']  : 'Enrollment No.';
$token     = issueToken();

// The built-in server ignores .htaccess and .user.ini, so a default 2 MB
// upload limit is easy to hit. Say so before a file is rejected.
$uploadIni   = (string) (ini_get('upload_max_filesize') ?: '');
$postIni     = (string) (ini_get('post_max_size') ?: '');
$limitIsLow  = iniBytes($uploadIni) > 0 && iniBytes($uploadIni) < 64 * 1024 * 1024;
$builtInPhp  = PHP_SAPI === 'cli-server';

/* =========================================================================
   Application logic
   ========================================================================= */

function bootstrapDirectories(array $semesters): void
{
    $dirs = [DATA_ROOT, CACHE_ROOT];
    foreach (array_keys($semesters) as $sem) {
        $dirs[] = dataDir((string) $sem);
    }

    foreach ($dirs as $dir) {
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        // Belt and braces: uploaded workbooks must never be web-served.
        $guard = $dir . '/.htaccess';
        if (is_dir($dir) && !file_exists($guard)) {
            @file_put_contents($guard, "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
        }
    }
}

/**
 * The first screen: choose a semester.
 *
 * Each card shows how many sheets that semester already has, so it is obvious
 * which ones are ready to use.
 */
function renderSemesterPicker(array $config): void
{
    $slots = array_keys($config['slots']);
    $cards = [];

    foreach ($config['semesters'] as $sem => $meta) {
        $sem    = (string) $sem;
        $loaded = 0;
        foreach ($slots as $slot) {
            if (is_file(slotPath($sem, $slot))) {
                $loaded++;
            }
        }

        $subjects = 0;
        $cached   = is_file(cacheKey($sem))
            ? json_decode((string) file_get_contents(cacheKey($sem)), true)
            : null;
        if (is_array($cached)) {
            $subjects = count($cached['store']['credits'] ?? []);
        }

        $cards[] = [
            'sem'      => $sem,
            'label'    => (string) ($meta['label'] ?? ($sem . ' Semester')),
            'course'   => (string) ($meta['course_name'] ?? ''),
            'loaded'   => $loaded,
            'total'    => count($slots),
            'subjects' => $subjects,
        ];
    }
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Marksheet generator</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;500&family=IBM+Plex+Sans:wght@400;500;600&family=Source+Serif+4:ital,opsz,wght@0,8..60,400;0,8..60,600;1,8..60,400&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/app.css">
</head>
<body class="picker-page">
<div class="wrap">
    <header class="masthead">
        <h1>Marksheet generator</h1>
        <p>Choose the semester you are generating results for.</p>
    </header>

    <div class="picker">
        <?php foreach ($cards as $card): ?>
            <a class="sem-card" href="?sem=<?= rawurlencode($card['sem']) ?>">
                <span class="sem-no"><?= e($card['sem']) ?></span>
                <span class="sem-body">
                    <span class="sem-label"><?= e($card['label']) ?></span>
                    <?php if ($card['course'] !== ''): ?>
                        <span class="sem-course"><?= e($card['course']) ?></span>
                    <?php endif; ?>
                    <span class="sem-state<?= $card['loaded'] === 0 ? ' is-empty' : '' ?>">
                        <?php if ($card['loaded'] === 0): ?>
                            No sheets uploaded yet
                        <?php else: ?>
                            <?= (int) $card['loaded'] ?>/<?= (int) $card['total'] ?> sheets<?php
                            if ($card['subjects'] > 0): ?> &middot; <?= (int) $card['subjects'] ?> subjects<?php
                            endif; ?>
                        <?php endif; ?>
                    </span>
                </span>
                <span class="sem-go" aria-hidden="true">&rarr;</span>
            </a>
        <?php endforeach; ?>
    </div>

    <p class="picker-note">
        Each semester keeps its own sheets, so they can never be mixed up. Subject
        names, credits and parts are read from that semester's teaching scheme.
    </p>
</div>
</body>
</html>
    <?php
}

function issueToken(): string
{
    if (empty($_SESSION['token'])) {
        $_SESSION['token'] = bin2hex(random_bytes(16));
    }

    return $_SESSION['token'];
}

function hasValidToken(): bool
{
    return !empty($_SESSION['token'])
        && is_string($_POST['token'] ?? null)
        && hash_equals($_SESSION['token'], $_POST['token']);
}

/** Validates and stores one uploaded workbook. Returns an error, or null. */
function acceptUpload(string $sem, string $slot): ?string
{
    if (!isset($_FILES[$slot]) || !is_array($_FILES[$slot])) {
        return null;
    }

    $file = $_FILES[$slot];
    $code = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);

    if ($code === UPLOAD_ERR_NO_FILE) {
        return null; // slot left untouched; any previously stored sheet stays
    }
    if ($code === UPLOAD_ERR_INI_SIZE || $code === UPLOAD_ERR_FORM_SIZE) {
        return sprintf(
            '%s: PHP rejected this file because it is over %s. This is PHP\'s limit, not the'
            . ' app\'s. %s Or copy the file into the data folder as %s.xlsx and reload.',
            $slot,
            ini_get('upload_max_filesize') ?: 'the server limit',
            PHP_SAPI === 'cli-server'
                ? 'Stop the server and run start.bat instead of php -S.'
                : 'Raise upload_max_filesize and post_max_size in php.ini, then restart the server.',
            $slot
        );
    }
    if ($code !== UPLOAD_ERR_OK) {
        return sprintf('%s: the upload did not complete. Try selecting the file again.', $slot);
    }

    $name = (string) ($file['name'] ?? '');
    $ext  = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));
    if (!in_array($ext, ['xlsx', 'xlsm'], true)) {
        return sprintf('%s: "%s" is not an .xlsx file. Save it from Excel as "Excel Workbook (.xlsx)" first.', $slot, $name);
    }
    if (!is_uploaded_file((string) $file['tmp_name']) || !XlsxReader::looksLikeXlsx((string) $file['tmp_name'])) {
        return sprintf('%s: "%s" could not be opened as a workbook. It may be renamed or corrupted.', $slot, $name);
    }
    if (!move_uploaded_file((string) $file['tmp_name'], slotPath($sem, $slot))) {
        return sprintf('%s: the file could not be saved. Check that the data folder is writable.', $slot);
    }

    @file_put_contents(dataDir($sem) . '/' . $slot . '.name', basename($name));

    return null;
}

function clearStoredSheets(string $sem): void
{
    // GLOB_BRACE is not available on every platform, so each pattern is
    // matched separately rather than combined into one brace expression.
    foreach (['xlsx', 'xlsm', 'name'] as $ext) {
        foreach (glob(dataDir($sem) . '/*.' . $ext) ?: [] as $file) {
            @unlink($file);
        }
    }
    @unlink(cacheKey($sem));
}

/**
 * Loads every stored sheet, reusing the parsed cache while the files on disk
 * are unchanged. Reparsing 380 students on each search would be wasteful.
 */
function loadStore(string $sem, array $slots, array $overrides = [], ?string $creditColumn = null): array
{
    $present = [];
    foreach (array_keys($slots) as $slot) {
        $path = slotPath($sem, $slot);
        if (is_file($path)) {
            $present[$slot] = [
                'path'  => $path,
                'mtime' => (int) filemtime($path),
                'size'  => (int) filesize($path),
                'name'  => trim((string) @file_get_contents(dataDir($sem) . '/' . $slot . '.name')) ?: basename($path),
            ];
        }
    }

    $empty = [
        'students' => [], 'subjects' => [], 'slots' => [], 'errors' => [], 'warnings' => [],
        'credits' => [], 'scheme' => null, 'found' => [],
        'institute' => '', 'semester' => '', 'id_label' => '',
    ];

    if ($present === []) {
        return $empty;
    }

    $signature = md5(serialize([
        array_map(static fn (array $f): array => [$f['mtime'], $f['size']], $present),
        $overrides,
        $creditColumn,
    ]));

    $cached = is_file(cacheKey($sem)) ? json_decode((string) file_get_contents(cacheKey($sem)), true) : null;
    if (is_array($cached) && ($cached['signature'] ?? '') === $signature) {
        return $cached['store'];
    }

    $parsed     = [];
    $slotInfo   = [];
    $errors     = [];
    $creditMap  = [];
    $scheme     = null;
    $compMap    = [];

    foreach ($present as $slot => $file) {
        // A teaching scheme is read by a different parser to a marks sheet.
        if (($slots[$slot]['kind'] ?? 'marks') === 'credits') {
            try {
                $sc = MarksStore::parseScheme($file['path'], $creditColumn);

                $credits = [];
                $names   = [];
                foreach ($sc['subjects'] as $code => $info) {
                    $credits[$code] = $info['credits'];
                    $names[$code]   = $info['name'];
                }
                $creditMap = $credits + $creditMap;
                $scheme    = $sc;

                $slotInfo[$slot] = [
                    'name' => $file['name'], 'kind' => 'credits', 'students' => 0,
                    'subjects' => count($credits), 'components' => [],
                    'tabs' => $sc['tabs'], 'detected' => [], 'credits' => $credits,
                    'names' => $names, 'error' => null,
                ];
            } catch (Throwable $e) {
                $slotInfo[$slot] = [
                    'name' => $file['name'], 'kind' => 'credits', 'students' => 0,
                    'subjects' => 0, 'components' => [], 'tabs' => [], 'detected' => [],
                    'credits' => [], 'names' => [], 'error' => $e->getMessage(),
                ];
                $errors[] = sprintf('%s ("%s"): %s', $slot, $file['name'], $e->getMessage());
            }
            continue;
        }

        try {
            $p              = MarksStore::parseFile($file['path'], $overrides);
            $parsed[$slot]  = $p;

            // A sample student reveals the component and maximum per subject.
            $sample   = $p['students'] !== [] ? reset($p['students']) : ['marks' => []];
            $detected = [];
            foreach ($p['subjects'] as $code => $subjectName) {
                $comps = [];
                foreach ($sample['marks'][$code] ?? [] as $comp => $mark) {
                    $comps[$comp] = $mark['max'];
                    // Remember which parts the marks sheets actually supply, in
                    // case the scheme has no Max Marks columns to read them from.
                    $compMap[(string) $code][$comp] = true;
                }
                $detected[] = ['code' => (string) $code, 'name' => $subjectName, 'comps' => $comps];
            }

            $slotInfo[$slot] = [
                'kind'       => 'marks',
                'name'       => $file['name'],
                'students'   => count($p['students']),
                'subjects'   => count($p['subjects']),
                'components' => $p['components'],
                'tabs'       => $p['tabs'] ?? [],
                'detected'   => $detected,
                'error'      => null,
            ];
        } catch (Throwable $e) {
            $slotInfo[$slot] = [
                'kind' => 'marks', 'name' => $file['name'], 'students' => 0, 'subjects' => 0,
                'components' => [], 'tabs' => [], 'detected' => [], 'error' => $e->getMessage(),
            ];
            $errors[] = sprintf('%s ("%s"): %s', $slot, $file['name'], $e->getMessage());
        }
    }

    $merged = $parsed !== []
        ? MarksStore::merge($parsed)
        : ['students' => [], 'subjects' => [], 'warnings' => [], 'institute' => '', 'semester' => '', 'id_label' => ''];

    $store = [
        'students'  => $merged['students'],
        'subjects'  => $merged['subjects'],
        'slots'     => $slotInfo,
        'errors'    => $errors,
        'warnings'  => $merged['warnings'] ?? [],
        'credits'   => $creditMap,
        'scheme'    => $scheme,
        'found'     => $compMap,
        'institute' => $merged['institute'] ?? '',
        'semester'  => $merged['semester']  ?? '',
        'id_label'  => $merged['id_label']  ?? '',
    ];

    @file_put_contents(cacheKey($sem), json_encode(['signature' => $signature, 'store' => $store]));

    return $store;
}

/**
 * Decides the subject list for a semester.
 *
 * The teaching scheme is the source of truth: it supplies each subject's code,
 * title, credits and — through its Max Marks columns — which parts apply. The
 * config list is used only until a scheme has been uploaded.
 *
 * @return array<int,array{code:string,name:string,credits:?float,components:array<int,string>}>
 */
function resolveSubjects(array $store, array $config, string $sem): array
{
    $scheme = $store['scheme'] ?? null;

    if (!is_array($scheme) || ($scheme['subjects'] ?? []) === []) {
        return $config['fallback_subjects'][$sem] ?? [];
    }

    // Components are printed in the order the result columns are configured.
    $columnOrder = array_values($config['result_columns']);
    $subjects    = [];

    foreach ($scheme['order'] as $code) {
        $info       = $scheme['subjects'][$code];
        $components = $info['components'];

        // A scheme without Max Marks columns cannot say which parts apply, so
        // fall back to whichever parts the marks sheets actually carry.
        if ($components === []) {
            $components = array_keys($store['found'][(string) $code] ?? []);
        }

        usort($components, static function (string $a, string $b) use ($columnOrder): int {
            return array_search($a, $columnOrder, true) <=> array_search($b, $columnOrder, true);
        });

        $subjects[] = [
            'code'       => (string) $code,
            'name'       => $info['name'],
            'credits'    => $info['credits'],
            'components' => $components,
        ];
    }

    return $subjects;
}

/**
 * Assembles the result and applies the evaluation scheme.
 *
 * Each component is graded on its own percentage, so CCE, theory and practical
 * each carry a separate letter. A subject's grade point is then the mean of
 * its component points weighted by each component's maximum marks, and SPI is
 * the credit-weighted mean of those subject points.
 */
function buildSheet(array $student, array $store, array $config, array $subjects): array
{
    $order = $subjects;
    if (($config['subject_order'] ?? 'code') === 'code') {
        usort($order, static fn (array $a, array $b): int => strcmp($a['code'], $b['code']));
    }

    $rows        = [];
    $sumObtained = 0.0;
    $sumMax      = 0.0;
    $creditSum   = 0.0;
    $pointSum    = 0.0;
    $incomplete  = 0;
    $failed      = 0;
    $creditGaps  = [];

    // The printed columns, in order, mapped to the internal component codes.
    $columns = $config['result_columns'];

    foreach ($order as $subject) {
        $code = $subject['code'];
        $name = $store['subjects'][$code] ?? $subject['name'];

        $cells     = [];
        $weighted  = 0.0;   // sum of (component maximum x grade point)
        $weightSum = 0.0;   // sum of component maximums
        $obtained  = 0.0;
        $max       = 0.0;
        $usable    = true;
        $anyMarks  = false;

        foreach ($columns as $column => $comp) {
            $blank = ['text' => '-', 'state' => 'none', 'obtained' => null, 'max' => null];

            if (!in_array($comp, $subject['components'], true)) {
                $cells[$column] = $blank;
                continue;
            }

            $mark = $student['marks'][$code][$comp]
                ?? ['obtained' => null, 'max' => null, 'status' => 'missing', 'raw' => ''];

            $status = $mark['status'];
            $cmax   = (float) ($mark['max'] ?? 0);

            if ($status === 'na') {
                $cells[$column] = $blank;
                continue;
            }

            if ($status === 'missing' || $status === 'code') {
                $usable = false;
                $cells[$column] = [
                    'text'     => $status === 'code' ? $mark['raw'] : '-',
                    'state'    => 'pending',
                    'obtained' => null,
                    'max'      => null,
                ];
                continue;
            }

            // A number, or an absent/cancelled paper scoring zero.
            $got     = $status === 'ok' ? (float) $mark['obtained'] : 0.0;
            $percent = $cmax > 0 ? round($got / $cmax * 100, 2) : 0.0;
            $band    = gradeBand($percent, $config['grade_scale']);

            $obtained  += $got;
            $max       += $cmax;
            $weighted  += $cmax * (float) $band['point'];
            $weightSum += $cmax;
            $anyMarks   = true;

            if ($band['grade'] === ($config['fail_grade'] ?? 'F')) {
                $failed++;
            }

            $cells[$column] = [
                'text'     => $band['grade'],
                'state'    => $band['grade'] === ($config['fail_grade'] ?? 'F') ? 'fail' : 'ok',
                'obtained' => $got,
                'max'      => $cmax,
                // AB and UM score zero, so the number alone would hide why.
                'note'     => $status === 'ok' ? '' : ($status === 'absent' ? 'AB' : 'UM'),
            ];
        }

        $sumObtained += $obtained;
        $sumMax      += $max;

        $graded = $usable && $anyMarks && $weightSum > 0;
        $point  = $graded ? $weighted / $weightSum : null;

        // Credits: teaching scheme first, then config, then the marks totals.
        $source  = 'scheme';
        $credits = $store['credits'][$code] ?? null;
        if ($credits === null) {
            $source  = 'config';
            $credits = $subject['credits'] ?? null;
        }
        if ($credits === null && $max > 0) {
            $source  = 'derived';
            $unit    = (float) ($config['credit_unit'] ?: 25);
            $credits = round($max / $unit * 2) / 2;
        }

        if ($graded && $credits > 0) {
            $creditSum += (float) $credits;
            $pointSum  += (float) $credits * $point;
            if ($source !== 'scheme') {
                $creditGaps[] = $name;
            }
        } elseif ($anyMarks || !$usable) {
            $incomplete++;
        }

        // Every part either absent from the subject or marked N/A means the
        // student is not registered for it at all.
        $applicable = false;
        foreach ($cells as $cell) {
            if ($cell['state'] !== 'none') {
                $applicable = true;
                break;
            }
        }

        $rows[] = [
            'applicable' => $applicable,
            'code'     => $code,
            'name'     => $name,
            'cells'    => $cells,
            'credits'  => $credits,
            'point'    => $point,
            'obtained' => $obtained,
            'max'      => $max,
            'percent'  => $max > 0 ? round($obtained / $max * 100, 2) : null,
            'counts'   => $anyMarks,
        ];
    }

    $spi = $creditSum > 0 ? round($pointSum / $creditSum, 2) : null;

    return [
        'rows'       => $rows,
        'columns'    => $columns,
        'obtained'   => $sumObtained,
        'max'        => $sumMax,
        'percentage' => $sumMax > 0 ? round($sumObtained / $sumMax * 100, 2) : null,
        'credits'    => $creditSum,
        'spi'        => $spi,
        'equivalent' => $spi !== null ? round(($spi - 0.5) * 10, 2) : null,
        'class'      => $spi !== null ? classFor($spi, $config['class_bands']) : null,
        'incomplete' => $incomplete,
        'failed'     => $failed,
        'gaps'       => $creditGaps,
    ];
}

/**
 * The identity block above the grade table.
 *
 * @return array<string,string>
 */
function resultFacts(array $student, string $enrollment, string $institute, string $sem, array $config): array
{
    $meta = $config['semesters'][$sem] ?? [];

    return [
        'Student Name'  => $student['name'] !== '' ? $student['name'] : '-',
        'Course Name'   => (string) ($meta['course_name'] ?? ''),
        'College'       => $institute,
        'Enrollment No' => $enrollment,
        'Semester'      => $sem,
        'Passing Year'  => (string) ($meta['passing_year'] ?? ''),
    ];
}

/** @return array{min:float,point:float,grade:string} */
function gradeBand(float $percent, array $scale): array
{
    foreach ($scale as $band) {
        if ($percent >= (float) $band['min']) {
            return $band;
        }
    }

    return end($scale) ?: ['min' => 0, 'point' => 0.0, 'grade' => 'F'];
}

function classFor(float $spi, array $bands): string
{
    foreach ($bands as $band) {
        if ($spi >= (float) $band['min']) {
            return (string) $band['label'];
        }
    }

    return '';
}

/* ------------------------------------------------------------- view utils */

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Converts a php.ini shorthand size such as "512M" into bytes. */
function iniBytes(string $value): int
{
    $value = trim($value);
    if ($value === '') {
        return 0;
    }

    $number = (float) $value;
    // Deliberate fall-through: each unit multiplies by 1024 once more.
    switch (strtolower(substr($value, -1))) {
        case 'g': $number *= 1024;
        // no break
        case 'm': $number *= 1024;
        // no break
        case 'k': $number *= 1024;
    }

    return (int) $number;
}

/** Trims a trailing ".0" so 38.0 prints as 38 but 37.5 survives. */
function num(float $value): string
{
    return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $student !== null ? e($student['name']) . ' — Marksheet' : 'Marksheet generator' ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;500&family=IBM+Plex+Sans:wght@400;500;600&family=Source+Serif+4:ital,opsz,wght@0,8..60,400;0,8..60,600;1,8..60,400&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/app.css">
</head>
<body>
<div class="wrap">

    <header class="masthead no-print">
        <a class="backlink" href="?">&larr; All semesters</a>
        <h1>Marksheet generator <span class="sem-tag"><?= e($semLabel) ?></span></h1>
        <p>Enter an enrollment number, attach the sheets, and generate a printable result.</p>
    </header>

    <form class="panel no-print" method="post" enctype="multipart/form-data" autocomplete="off">
        <input type="hidden" name="token" value="<?= e($token) ?>">
        <input type="hidden" name="sem" value="<?= e($sem) ?>">

        <div class="panel-head">
            <h2>Generate a result</h2>
            <span class="step">
                <?= e($semLabel) ?> ·
                <?= count($store['slots']) ?>/<?= count($config['slots']) ?> sheets loaded<?php
                if ($store['students'] !== []) {
                    echo ' · ' . count($store['students']) . ' students';
                } ?>
            </span>
        </div>

        <div class="field">
            <label for="enrollment">Enrollment number</label>
            <input class="enroll-input" type="text" id="enrollment" name="enrollment"
                   value="<?= e($enrollment) ?>" placeholder="24004501210012"
                   inputmode="numeric" spellcheck="false" autofocus>
            <p class="hint">The number in the <?= e($idLabel) ?> column. Spaces and case are ignored.</p>
        </div>

        <fieldset class="field">
            <legend class="legend">Marks sheets</legend>
            <div class="slots">
                <?php foreach ($config['slots'] as $slot => $meta):
                    $info    = $store['slots'][$slot] ?? null;
                    $classes = 'slot' . ($info === null ? '' : ($info['error'] !== null ? ' is-error' : ' is-loaded'));
                    ?>
                    <label class="<?= $classes ?>">
                        <input type="file" name="<?= e($slot) ?>" accept=".xlsx,.xlsm"
                               data-slot="<?= e($slot) ?>">
                        <span class="slot-body">
                            <span class="slot-name"><?= e($meta['label']) ?></span>
                            <span class="slot-hint"><?= e($meta['hint']) ?></span>
                            <span class="slot-file" data-file-for="<?= e($slot) ?>">
                                <?php if ($info === null): ?>
                                    Choose a file
                                <?php elseif ($info['error'] !== null): ?>
                                    <?= e($info['name']) ?> — could not be read
                                <?php elseif (($info['kind'] ?? 'marks') === 'credits'): ?>
                                    <?= e($info['name']) ?><br>
                                    <?= (int) $info['subjects'] ?> subjects ·
                                    <?= e(num((float) array_sum($info['credits']))) ?> credits
                                <?php else: ?>
                                    <?= e($info['name']) ?><br>
                                    <?= (int) $info['students'] ?> students ·
                                    <?= (int) $info['subjects'] ?> subjects ·
                                    <?= e(implode(', ', $info['components'])) ?><?php
                                    if (count($info['tabs'] ?? []) > 1): ?><br>
                                    <?= count($info['tabs']) ?> tabs: <?= e(implode(', ', $info['tabs'])) ?><?php
                                    endif; ?>
                                <?php endif; ?>
                            </span>
                        </span>
                    </label>
                <?php endforeach; ?>
            </div>
            <p class="hint">
                Sheets stay on the server after the first upload, so later searches only need an
                enrollment number. Attach a file again to replace that sheet.
            </p>

            <?php if ($limitIsLow): ?>
                <div class="limit">
                    <p>
                        <strong>This server accepts only <?= e($uploadIni) ?> per file</strong>
                        (<?= e($postIni) ?> per submission). That is PHP's own limit, not the
                        app's &mdash; the app sets none. Two ways round it:
                    </p>
                    <ol>
                        <?php if ($builtInPhp): ?>
                            <li>
                                Stop the server, then run <code>start.bat</code> in the marksheet
                                folder instead of <code>php -S</code>. It starts PHP with the limit
                                raised to 512M. On Mac or Linux use <code>./start.sh</code>.
                            </li>
                        <?php else: ?>
                            <li>
                                Raise <code>upload_max_filesize</code> and <code>post_max_size</code>
                                in <code>php.ini</code>, then restart the server.
                            </li>
                        <?php endif; ?>
                        <li>
                            Or skip uploading altogether: copy the files into the
                            <code>data</code> folder named
                            <?php foreach (array_keys($config['slots']) as $i => $slot): ?>
                                <?= $i > 0 ? ', ' : '' ?><code><?= e($slot) ?>.xlsx</code><?php
                            endforeach; ?>, then reload this page.
                        </li>
                    </ol>
                </div>
            <?php else: ?>
                <p class="hint">
                    The app sets no size limit of its own; this server accepts
                    <?= e($uploadIni !== '' ? $uploadIni : '?') ?> per file
                    and <?= e($postIni !== '' ? $postIni : '?') ?> per submission.
                </p>
            <?php endif; ?>
        </fieldset>

        <div class="actions">
            <button class="btn btn-primary" type="submit" name="action" value="generate">Generate result</button>
            <?php if ($store['slots'] !== []): ?>
                <button class="btn btn-ghost" type="submit" name="action" value="clear"
                        formnovalidate>Remove stored sheets</button>
            <?php endif; ?>
        </div>
    </form>

    <?php if ($errors !== []): ?>
        <div class="msg msg-error no-print" role="alert">
            <?php if (count($errors) === 1): ?>
                <?= e($errors[0]) ?>
            <?php else: ?>
                Fix the following, then generate the result again:
                <ul><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if ($notFound): ?>
        <div class="msg msg-error no-print" role="alert">
            No student with enrollment number <strong><?= e($enrollment) ?></strong> appears in the loaded
            sheets. Check the number against the <?= e($idLabel) ?> column, or upload the sheet that contains it.
        </div>
    <?php endif; ?>

    <?php if ($subjects === [] && $store['slots'] !== []): ?>
        <div class="msg msg-warn no-print">
            No teaching scheme has been uploaded for the <?= e($semLabel) ?>, so the subject list,
            credits and parts are not known yet. Upload it in the <strong>Teaching scheme</strong>
            slot above.
        </div>
    <?php elseif ($subjects !== [] && ($store['scheme'] ?? null) === null): ?>
        <div class="msg msg-note no-print">
            Using the built-in subject list for the <?= e($semLabel) ?>. Upload the teaching scheme
            to take subject names, credits and parts from it instead.
        </div>
    <?php endif; ?>

    <?php if (($store['warnings'] ?? []) !== []): ?>
        <div class="msg msg-warn no-print">
            Worth checking in the uploaded sheets:
            <ul><?php foreach ($store['warnings'] as $warning): ?><li><?= e($warning) ?></li><?php endforeach; ?></ul>
        </div>
    <?php endif; ?>

    <?php foreach ($notices as $notice): ?>
        <div class="msg msg-note no-print"><?= e($notice) ?></div>
    <?php endforeach; ?>

    <?php if ($store['slots'] !== []): ?>
        <details class="inspect no-print"<?= ($store['warnings'] ?? []) !== [] ? ' open' : '' ?>>
            <summary>What was read from each sheet</summary>
            <div class="inspect-body">
                <p class="inspect-lede">
                    A subject missing from a sheet below prints as <em>Not available</em>. Check that
                    the subject you expect is listed under the sheet that should supply it.
                </p>

                <?php foreach ($store['slots'] as $slot => $info): ?>
                    <div class="inspect-slot">
                        <h3>
                            <?= e($config['slots'][$slot]['label'] ?? $slot) ?>
                            <span class="inspect-file"><?= e($info['name']) ?></span>
                        </h3>

                        <?php if ($info['error'] !== null): ?>
                            <p class="inspect-bad"><?= e($info['error']) ?></p>
                        <?php else: ?>
                            <p class="inspect-meta">
                                <?= count($info['tabs'] ?? []) ?> tab<?= count($info['tabs'] ?? []) === 1 ? '' : 's' ?>
                                (<?= e(implode(', ', $info['tabs'] ?? [])) ?>)<?php
                                if (($info['kind'] ?? 'marks') !== 'credits'): ?> ·
                                <?= (int) $info['students'] ?> students<?php endif; ?>
                            </p>
                            <?php if (($info['kind'] ?? 'marks') === 'credits'): ?>
                                <table class="inspect-table">
                                    <?php foreach ($info['credits'] as $code => $cr): ?>
                                        <tr>
                                            <td class="c"><?= e((string) $code) ?></td>
                                            <td><?= e($info['names'][$code] ?? '') ?></td>
                                            <td class="p"><span class="mx"><?= e(num((float) $cr)) ?> credits</span></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <tr>
                                        <td class="c"></td><td><strong>Total</strong></td>
                                        <td class="p"><span class="mx"><strong><?= e(num((float) array_sum($info['credits']))) ?> credits</strong></span></td>
                                    </tr>
                                </table>
                            <?php elseif (($info['detected'] ?? []) === []): ?>
                                <p class="inspect-bad">No subjects were detected in this sheet.</p>
                            <?php else: ?>
                                <table class="inspect-table">
                                    <?php foreach ($info['detected'] as $d): ?>
                                        <tr>
                                            <td class="c"><?= e($d['code']) ?></td>
                                            <td><?= e($d['name']) ?></td>
                                            <td class="p">
                                                <?php if ($d['comps'] === []): ?>
                                                    <span class="inspect-bad">no part found</span>
                                                <?php else: foreach ($d['comps'] as $comp => $max): ?>
                                                    <span class="tag tag-<?= e($comp) ?>"><?= e($comp) ?></span><span
                                                        class="mx">max <?= $max !== null ? e(num((float) $max)) : '?' ?></span>
                                                <?php endforeach; endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </table>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </details>
    <?php endif; ?>

    <?php if ($sheet !== null && $student !== null): ?>

        <?php
        $facts    = resultFacts($student, $enrollment, $institute, $sem, $config);
        $head     = $config['letterhead'];
        $logoPath = (string) ($head['logo'] ?? '');
        $hasLogo  = $logoPath !== '' && is_file(__DIR__ . '/' . $logoPath);

        // The official marksheet leaves out subjects the student is not
        // registered for, rather than printing a row of dashes.
        $printRows = $config['hide_not_applicable']
            ? array_values(array_filter($sheet['rows'], static function (array $r): bool {
                return $r['applicable'];
            }))
            : $sheet['rows'];

        /** The two-row header shared by both tables. */
        $tableHead = static function (array $config, array $extra = []): void {
            ?>
            <tr>
                <th scope="col" rowspan="2" class="c-code">Subject Code</th>
                <th scope="col" rowspan="2">Subject Name</th>
                <?php if ($config['show_credits']): ?>
                    <th scope="col" rowspan="2" class="g c-credit">Credit</th>
                <?php endif; ?>
                <?php foreach ($config['result_groups'] as $group => $cols): ?>
                    <th scope="col" colspan="<?= count($cols) ?>"<?= count($cols) === 1 ? ' rowspan="2"' : '' ?> class="g">
                        <?= e($group) ?>
                    </th>
                <?php endforeach; ?>
                <?php foreach ($extra as $label): ?>
                    <th scope="col" rowspan="2" class="g"><?= e($label) ?></th>
                <?php endforeach; ?>
            </tr>
            <tr>
                <?php foreach ($config['result_groups'] as $cols): ?>
                    <?php if (count($cols) > 1): ?>
                        <?php foreach ($cols as $col): ?>
                            <th scope="col" class="g"><?= e($col) ?></th>
                        <?php endforeach; ?>
                    <?php endif; ?>
                <?php endforeach; ?>
            </tr>
            <?php
        };
        ?>

        <style>
        /* One rule per document: printing one hides all the others. */
        @media print {
        <?php foreach (array_keys($config['documents']) as $key): ?>
            body[data-print="<?= e($key) ?>"] .doc:not(.doc-<?= e($key) ?>) { display: none !important; }
            body[data-print="<?= e($key) ?>"] .doc { page-break-before: auto; }
        <?php endforeach; ?>
        }
        </style>

        <?php foreach ($config['documents'] as $key => $doc):
            $isLetterhead = ($doc['style'] ?? 'official') === 'letterhead';
            $isMarks      = ($doc['type'] ?? 'grades') === 'marks';
            ?>
            <article class="sheet doc doc-<?= e($key) ?>" id="doc-<?= e($key) ?>">

                <?php if ($isLetterhead): ?>
                    <div class="letterspace" style="height: <?= e((string) $config['letterhead_space']) ?>">
                        <span class="letterspace-note no-print">Blank space for the pre-printed college letterhead</span>
                    </div>
                <?php else: ?>
                    <header class="official-head">
                        <?php if ($hasLogo): ?>
                            <img class="oh-logo" src="<?= e($logoPath) ?>" alt="">
                        <?php endif; ?>
                        <div class="oh-text">
                            <p class="oh-university"><?= e($head['university']) ?></p>
                            <?php if (($head['tagline'] ?? '') !== ''): ?>
                                <p class="oh-tagline"><?= e($head['tagline']) ?></p>
                            <?php endif; ?>
                            <?php if (($head['established'] ?? '') !== ''): ?>
                                <p class="oh-established"><?= e($head['established']) ?></p>
                            <?php endif; ?>
                            <?php if (($head['school'] ?? '') !== ''): ?>
                                <p class="oh-school"><?= e($head['school']) ?></p>
                            <?php endif; ?>
                        </div>
                    </header>
                <?php endif; ?>

                <h2 class="doc-title"><?= e($doc['title'] ?? '') ?></h2>

                <table class="facts-table">
                    <tbody>
                    <?php foreach ($facts as $label => $value): ?>
                        <tr>
                            <th scope="row"><?= e($label) ?></th>
                            <td<?= $label === 'Enrollment No' ? ' class="mono"' : '' ?>><?= e($value !== '' ? $value : '-') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>

                <?php if (!$isMarks): ?>

                    <table class="grades">
                        <thead><?php $tableHead($config); ?></thead>
                        <tbody>
                        <?php foreach ($printRows as $row): ?>
                            <tr>
                                <td class="mono c-code"><?= e($row['code']) ?></td>
                                <td class="subj"><?= e($row['name']) ?></td>
                                <?php if ($config['show_credits']): ?>
                                    <td class="g c-credit"><span class="cr"><?php
                                        echo $row['credits'] !== null
                                            ? number_format((float) $row['credits'], 1)
                                            : '-'; ?></span></td>
                                <?php endif; ?>
                                <?php foreach ($sheet['columns'] as $column => $comp):
                                    $cell = $row['cells'][$column]; ?>
                                    <td class="g"><span class="gr gr-<?= e($cell['state']) ?>"><?= e($cell['text']) ?></span></td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>

                    <?php if (($doc['spi'] ?? true) && $sheet['spi'] !== null): ?>
                        <div class="spi">
                            <p class="spi-value">
                                <span class="spi-label">SPI</span>
                                <span class="mono"><?= number_format((float) $sheet['spi'], 2) ?></span>
                            </p>
                            <?php if ($config['show_class']): ?>
                                <p class="spi-class">
                                    <?= e($sheet['class']) ?> &middot;
                                    equivalent <?= number_format((float) $sheet['equivalent'], 2) ?>%
                                </p>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                <?php else: ?>

                    <table class="grades marks-table">
                        <thead><?php $tableHead($config, ['Total', '%']); ?></thead>
                        <tbody>
                        <?php foreach ($printRows as $row): ?>
                            <tr>
                                <td class="mono c-code"><?= e($row['code']) ?></td>
                                <td class="subj"><?= e($row['name']) ?></td>
                                <?php if ($config['show_credits']): ?>
                                    <td class="g c-credit"><span class="cr"><?php
                                        echo $row['credits'] !== null
                                            ? number_format((float) $row['credits'], 1)
                                            : '-'; ?></span></td>
                                <?php endif; ?>
                                <?php foreach ($sheet['columns'] as $column => $comp):
                                    $cell = $row['cells'][$column]; ?>
                                    <td class="g">
                                        <?php if ($cell['max'] !== null): ?>
                                            <span class="mk<?= ($cell['note'] ?? '') !== '' ? ' is-absent' : '' ?>"><?php
                                                echo ($cell['note'] ?? '') !== ''
                                                    ? e($cell['note'])
                                                    : num((float) $cell['obtained']); ?></span><span
                                                class="mk-of">/<?= num((float) $cell['max']) ?></span>
                                        <?php else: ?>
                                            <span class="gr gr-<?= e($cell['state']) ?>">-</span>
                                        <?php endif; ?>
                                    </td>
                                <?php endforeach; ?>
                                <td class="g">
                                    <?php if ($row['counts'] && $row['max'] > 0): ?>
                                        <span class="mk"><?= num($row['obtained']) ?></span><span
                                            class="mk-of">/<?= num($row['max']) ?></span>
                                    <?php else: ?>
                                        <span class="gr gr-none">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="g">
                                    <?php if ($row['percent'] !== null && $row['counts']): ?>
                                        <span class="mk"><?= num((float) $row['percent']) ?></span>
                                    <?php else: ?>
                                        <span class="gr gr-none">-</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                        <?php if ($sheet['max'] > 0): ?>
                            <tfoot>
                                <tr>
                                    <td colspan="<?= 2 + ($config['show_credits'] ? 1 : 0) + count($sheet['columns']) ?>" class="tfoot-label">Total</td>
                                    <td class="g">
                                        <span class="mk"><?= num($sheet['obtained']) ?></span><span
                                            class="mk-of">/<?= num($sheet['max']) ?></span>
                                    </td>
                                    <td class="g"><span class="mk"><?= num((float) $sheet['percentage']) ?></span></td>
                                </tr>
                            </tfoot>
                        <?php endif; ?>
                    </table>

                <?php endif; ?>

                <div class="notes">
                    <?php foreach ($config['result_notes'] as $note): ?>
                        <p><?= e($note) ?></p>
                    <?php endforeach; ?>
                </div>

                <?php if ($config['signatories'] !== []): ?>
                    <div class="signatures">
                        <?php foreach ($config['signatories'] as $who): ?>
                            <div class="sig-block">
                                <span class="sig-rule"></span>
                                <span class="sig-who"><?= e($who) ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <?php if (!$isLetterhead && ($head['address'] ?? '') !== ''): ?>
                    <footer class="official-foot">
                        <span><?= e($head['address']) ?></span>
                        <span class="oh-contact">
                            <?php if (($head['email'] ?? '') !== ''): ?><?= e($head['email']) ?><?php endif; ?>
                            <?php if (($head['phone'] ?? '') !== ''): ?> &nbsp;&middot;&nbsp; <?= e($head['phone']) ?><?php endif; ?>
                            <?php if (($head['website'] ?? '') !== ''): ?> &nbsp;&middot;&nbsp; <?= e($head['website']) ?><?php endif; ?>
                        </span>
                    </footer>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>

        <div class="printbar no-print">
            <p>
                <strong>Saving as PDF:</strong> choose <em>Save as PDF</em> as the destination, then
                turn off <em>Headers and footers</em> so the browser does not print the page URL and date.
                Signature lines print blank, to be signed by hand.
            </p>
            <div class="printbar-actions">
                <?php foreach ($config['documents'] as $key => $doc): ?>
                    <button class="btn btn-print" type="button" data-print="<?= e($key) ?>">
                        <?= e($doc['label'] ?? $key) ?>
                    </button>
                <?php endforeach; ?>
                <?php if (count($config['documents']) > 1): ?>
                    <button class="btn btn-ghost-light" type="button" data-print="all">All</button>
                <?php endif; ?>
            </div>
        </div>

    <?php endif; ?>

</div>

<script>
// A browser prints the whole page, so the document not being saved is hidden
// for the duration of the print and restored afterwards.
document.querySelectorAll('[data-print]').forEach(function (button) {
    button.addEventListener('click', function () {
        document.body.setAttribute('data-print', button.dataset.print);
        window.print();
    });
});

function clearPrintScope() { document.body.removeAttribute('data-print'); }
window.addEventListener('afterprint', clearPrintScope);
// Safari and some older browsers never fire afterprint.
if (window.matchMedia) {
    window.matchMedia('print').addListener(function (mql) {
        if (!mql.matches) { clearPrintScope(); }
    });
}

// Shows the chosen filename before the form is submitted.
document.querySelectorAll('input[type="file"][data-slot]').forEach(function (input) {
    input.addEventListener('change', function () {
        var target = document.querySelector('[data-file-for="' + input.dataset.slot + '"]');
        if (target && input.files.length) {
            target.textContent = input.files[0].name + ' — ready to upload';
            input.closest('.slot').classList.remove('is-error');
        }
    });
});
</script>
</body>
</html>
