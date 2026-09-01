<?php
declare(strict_types=1);

/*
 * PHP 8.0 string helpers, for servers still running PHP 7.4.
 * Guarded, so PHP 8 uses its own native implementations.
 */
if (!function_exists('str_contains')) {
    function str_contains(string $haystack, string $needle): bool
    {
        return $needle === '' || strpos($haystack, $needle) !== false;
    }
}
if (!function_exists('str_starts_with')) {
    function str_starts_with(string $haystack, string $needle): bool
    {
        return $needle === '' || strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}
if (!function_exists('str_ends_with')) {
    function str_ends_with(string $haystack, string $needle): bool
    {
        return $needle === '' || substr($haystack, -strlen($needle)) === $needle;
    }
}

/**
 * Minimal, dependency-free .xlsx reader.
 *
 * Needs only the bundled `zip` and `xml` PHP extensions - no Composer,
 * no PhpSpreadsheet. Reads the first worksheet and returns its cells.
 *
 * Returned shape:  [ excelRowNumber => [ zeroBasedColIndex => "value" ] ]
 * Rows and columns that are empty in the file are simply absent from the
 * array, so callers must use `?? ''` when reading a cell.
 */
final class XlsxReader
{
    private const NS_REL = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    /**
     * Reads the first worksheet only.
     *
     * @return array<int,array<int,string>>
     * @throws RuntimeException
     */
    public static function firstSheetRows(string $path, bool $expandMerges = false): array
    {
        foreach (self::allSheets($path, $expandMerges) as $rows) {
            return $rows;
        }

        throw new RuntimeException('The workbook contains no readable worksheet data.');
    }

    /**
     * Reads every worksheet in the workbook.
     *
     * Marks are often split across tabs - one per division, branch or subject -
     * so reading only the first tab would silently drop subjects.
     *
     * With $expandMerges the value of a merged range is copied into every cell
     * it covers. Teaching schemes merge freely - a credit shared by two
     * elective rows lives only in the first - so the caller can ask for that.
     * Marks sheets rely on the opposite behaviour, where only the anchor cell
     * of a merged subject heading carries the text, so this stays off by
     * default.
     *
     * @return array<string,array<int,array<int,string>>>  sheet name => rows
     * @throws RuntimeException
     */
    public static function allSheets(string $path, bool $expandMerges = false): array
    {
        if (!is_readable($path)) {
            throw new RuntimeException('File cannot be read from disk.');
        }

        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new RuntimeException('Not a valid .xlsx file (it is not a readable ZIP archive).');
        }

        try {
            $shared = self::readSharedStrings($zip, $path);
            $sheets = [];

            foreach (self::sheetEntries($zip) as $name => $entry) {
                $reader = self::openEntry($zip, $path, $entry);
                if ($reader === null) {
                    continue;
                }
                $sheets[$name] = self::readSheet($reader, $shared, $expandMerges);
                $reader->close();
            }

            if ($sheets === []) {
                throw new RuntimeException('The workbook contains no readable worksheet data.');
            }

            return $sheets;
        } finally {
            $zip->close();
        }
    }

    /**
     * Opens one zip entry for reading.
     *
     * The zip:// wrapper lets XMLReader pull the worksheet incrementally, so a
     * large sheet is never held in memory as a single string. Falls back to
     * extracting the entry whole if the wrapper is unavailable.
     */
    private static function openEntry(ZipArchive $zip, string $path, string $entry): ?XMLReader
    {
        $reader = new XMLReader();
        if (@$reader->open('zip://' . $path . '#' . $entry) !== false) {
            return $reader;
        }

        $xml = $zip->getFromName($entry);
        if ($xml === false || $xml === '') {
            return null;
        }

        $reader = new XMLReader();

        return @$reader->XML($xml) !== false ? $reader : null;
    }

    /** Quick structural check used before accepting an upload. */
    public static function looksLikeXlsx(string $path): bool
    {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            return false;
        }
        $ok = $zip->locateName('[Content_Types].xml') !== false
            && $zip->locateName('xl/workbook.xml') !== false;
        $zip->close();

        return $ok;
    }

    /* ------------------------------------------------------------------ */

    /**
     * Maps every worksheet's visible tab name to its zip entry, in tab order.
     *
     * Sheet order in workbook.xml is the order shown in Excel's tab bar, which
     * is not necessarily sheet1.xml, sheet2.xml..., so each r:id is resolved
     * through the relationships file rather than guessed.
     *
     * @return array<string,string>
     */
    private static function sheetEntries(ZipArchive $zip): array
    {
        $workbook = $zip->getFromName('xl/workbook.xml');
        $relsXml  = $zip->getFromName('xl/_rels/workbook.xml.rels');
        $entries  = [];

        if ($workbook !== false && $relsXml !== false) {
            $targets = [];

            $r = new XMLReader();
            $r->XML($relsXml);
            while ($r->read()) {
                if ($r->nodeType === XMLReader::ELEMENT && $r->localName === 'Relationship') {
                    $id     = (string) $r->getAttribute('Id');
                    $target = ltrim((string) $r->getAttribute('Target'), '/');
                    if (!str_starts_with($target, 'xl/')) {
                        $target = 'xl/' . $target;
                    }
                    $targets[$id] = $target;
                }
            }
            $r->close();

            $r = new XMLReader();
            $r->XML($workbook);
            while ($r->read()) {
                if ($r->nodeType === XMLReader::ELEMENT && $r->localName === 'sheet') {
                    $name  = (string) ($r->getAttribute('name') ?? '');
                    $rid   = $r->getAttributeNs('id', self::NS_REL) ?: (string) $r->getAttribute('r:id');
                    $state = strtolower((string) ($r->getAttribute('state') ?? ''));

                    if ($state === 'hidden' || $state === 'veryhidden') {
                        continue;
                    }
                    if ($rid !== '' && isset($targets[$rid]) && $zip->locateName($targets[$rid]) !== false) {
                        $entries[$name !== '' ? $name : $targets[$rid]] = $targets[$rid];
                    }
                }
            }
            $r->close();
        }

        if ($entries !== []) {
            return $entries;
        }

        // Fall back to scanning the archive.
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if (str_starts_with($name, 'xl/worksheets/') && str_ends_with($name, '.xml')) {
                $entries[basename($name, '.xml')] = $name;
            }
        }

        if ($entries === []) {
            throw new RuntimeException('No worksheet found inside the workbook.');
        }

        return $entries;
    }

    /** @return array<int,string> */
    private static function readSharedStrings(ZipArchive $zip, string $path): array
    {
        if ($zip->locateName('xl/sharedStrings.xml') === false) {
            return [];
        }

        $r = self::openEntry($zip, $path, 'xl/sharedStrings.xml');
        if ($r === null) {
            return [];
        }

        $strings = [];
        $buffer  = '';
        $inSi    = false;

        while ($r->read()) {
            if ($r->nodeType === XMLReader::ELEMENT) {
                if ($r->localName === 'si') {
                    $buffer = '';
                    $inSi   = true;
                    if ($r->isEmptyElement) {
                        $strings[] = '';
                        $inSi      = false;
                    }
                } elseif ($inSi && $r->localName === 't') {
                    $buffer .= $r->readString();
                }
            } elseif ($r->nodeType === XMLReader::END_ELEMENT && $r->localName === 'si') {
                $strings[] = $buffer;
                $inSi      = false;
            }
        }
        $r->close();

        return $strings;
    }

    /**
     * @param  array<int,string> $shared
     * @return array<int,array<int,string>>
     */
    private static function readSheet(XMLReader $r, array $shared, bool $expandMerges = false): array
    {
        $rows   = [];
        $row    = [];
        $rowNum = 0;
        $col    = 0;
        $type   = '';
        $merges = [];

        while ($r->read()) {
            if ($r->nodeType === XMLReader::ELEMENT) {
                switch ($r->localName) {
                    case 'row':
                        $row    = [];
                        $rowNum = (int) $r->getAttribute('r');
                        break;

                    case 'c':
                        $ref  = (string) $r->getAttribute('r');
                        $col  = self::columnIndex($ref);
                        $type = (string) ($r->getAttribute('t') ?? '');
                        break;

                    case 'v':
                        $raw = $r->readString();
                        if ($type === 's') {
                            $idx = (int) $raw;
                            $row[$col] = $shared[$idx] ?? '';
                        } else {
                            $row[$col] = $raw;
                        }
                        break;

                    case 't':
                        // Inline strings: <c t="inlineStr"><is><t>text</t></is></c>
                        if ($type === 'inlineStr') {
                            $row[$col] = ($row[$col] ?? '') . $r->readString();
                        }
                        break;

                    case 'mergeCell':
                        // Appears after sheetData, so it is applied afterwards.
                        if ($expandMerges) {
                            $ref = (string) $r->getAttribute('ref');
                            if ($ref !== '') {
                                $merges[] = $ref;
                            }
                        }
                        break;
                }
            } elseif ($r->nodeType === XMLReader::END_ELEMENT && $r->localName === 'row') {
                if ($rowNum > 0 && $row !== []) {
                    $rows[$rowNum] = $row;
                }
            }
        }

        return $merges === [] ? $rows : self::applyMerges($rows, $merges);
    }

    /**
     * Copies each merged range's value into every cell it covers.
     *
     * @param  array<int,array<int,string>> $rows
     * @param  array<int,string>            $merges  refs such as "H11:H12"
     * @return array<int,array<int,string>>
     */
    private static function applyMerges(array $rows, array $merges): array
    {
        foreach ($merges as $ref) {
            if (!preg_match('/^([A-Z]+)(\d+):([A-Z]+)(\d+)$/', strtoupper($ref), $m)) {
                continue;
            }

            $fromCol = self::columnIndex($m[1]);
            $fromRow = (int) $m[2];
            $toCol   = self::columnIndex($m[3]);
            $toRow   = (int) $m[4];

            $value = $rows[$fromRow][$fromCol] ?? '';
            if ($value === '') {
                continue;
            }

            for ($r = $fromRow; $r <= $toRow; $r++) {
                for ($c = $fromCol; $c <= $toCol; $c++) {
                    if (($rows[$r][$c] ?? '') === '') {
                        $rows[$r][$c] = $value;
                    }
                }
            }
        }

        return $rows;
    }

    /** "BC12" -> 54 (zero-based). */
    private static function columnIndex(string $ref): int
    {
        $n = 0;
        $len = strlen($ref);
        for ($i = 0; $i < $len; $i++) {
            $ch = $ref[$i];
            if ($ch < 'A' || $ch > 'Z') {
                break;
            }
            $n = $n * 26 + (ord($ch) - 64);
        }

        return $n - 1;
    }
}
