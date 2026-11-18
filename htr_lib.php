<?php
// ── HTR Dataset Preparation (paths) ─────────────────────────────────────────
$outputDir = __DIR__ . '/output/';          // word crops saved from the cropper UI
$htrDir = __DIR__ . '/htr_dataset/';     // batch-processed, HTR-ready dataset
$htrImageDir = $htrDir . 'images/';
$htrExcelRel = 'htr_dataset/dataset_labels.xlsx';
$htrExcelAbs = __DIR__ . '/' . $htrExcelRel;

if (!is_dir($outputDir))
    mkdir($outputDir, 0777, true);
if (!is_dir($htrImageDir))
    mkdir($htrImageDir, 0777, true);

/**
 * Returns the saved word-crop UNCHANGED (original pixels): no grayscale, no
 * trimming, no contrast change, no resizing, no margin. It is only copied into a
 * lossless truecolor image so it can be written out as PNG.
 */
function prepareImageForHTR(\GdImage $src, int $targetHeight = 0, int $margin = 0): ?\GdImage
{
    $w = imagesx($src);
    $h = imagesy($src);
    if ($w < 2 || $h < 2) {
        return null;
    }
    $out = imagecreatetruecolor($w, $h);
    imagealphablending($out, false);
    imagesavealpha($out, true);
    imagecopy($out, $src, 0, 0, 0, 0, $w, $h);
    return $out;
}

/** manifest.json = the annotation state of the dataset (one entry per image). */
function htrManifestPath(): string
{
    return __DIR__ . '/htr_dataset/manifest.json';
}

function htrLoadManifest(): array
{
    $p = htrManifestPath();
    if (!is_file($p)) return [];
    $d = json_decode((string) file_get_contents($p), true);
    return is_array($d) ? $d : [];
}

function htrSaveManifest(array $items): void
{
    $tmp = htrManifestPath() . '.tmp';
    $json = json_encode(array_values($items), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false) {
        throw new RuntimeException('manifest could not be encoded');   // never overwrite it with garbage
    }
    file_put_contents($tmp, $json);
    rename($tmp, htrManifestPath());
}

/**
 * Gives every image a content-based id (sha1 of the stored file). Unlike htr_0005.png it means the
 * same thing on every machine, so labels can be merged without mixing datasets up.
 * Returns true when something was added (the manifest then needs saving).
 */
function htrEnsureUids(array &$items): bool
{
    $changed = false;
    foreach ($items as &$r) {
        if (!empty($r['uid'])) continue;
        $p = __DIR__ . '/htr_dataset/images/' . basename((string) ($r['processed_filename'] ?? ''));
        if (is_file($p)) { $r['uid'] = substr(sha1_file($p), 0, 20); $changed = true; }
    }
    unset($r);
    return $changed;
}

/**
 * Writes the label files used for training from the annotation state:
 *   dataset_labels.xlsx  - spreadsheet (ID, image, path, size, transcription, status)
 *   labels.csv           - image,text   (UTF-8)
 *   labels.tsv           - "images/htr_0001.png<TAB>text"  (the common HTR layout)
 * Only images that were labelled (not skipped as unreadable) go into csv/tsv.
 */
function htrExportLabels(array $items): array
{
    $dir = __DIR__ . '/htr_dataset/';
    writeXlsx(
        $dir . 'dataset_labels.xlsx',
        'HTR Dataset',
        ['ID', 'Image Filename', 'Relative Path', 'Width', 'Height', 'Transcription', 'Status', 'UID'],
        array_map(fn($r) => [
            $r['id'], $r['processed_filename'], $r['relative_path'], $r['width'], $r['height'],
            $r['transcription'], $r['status'], $r['uid'] ?? '',
        ], $items)
    );

    // only the finished (labeled) images: this is the file offered for download
    writeXlsx(
        $dir . 'annotated_labels.xlsx',
        'Annotated',
        ['ID', 'Image Filename', 'Relative Path', 'Width', 'Height', 'Transcription', 'Status', 'UID'],
        array_values(array_map(fn($r) => [
            $r['id'], $r['processed_filename'], $r['relative_path'], $r['width'], $r['height'],
            $r['transcription'], $r['status'], $r['uid'] ?? '',
        ], array_filter($items, fn($r) => ($r['status'] ?? '') === 'labeled')))
    );

    $csv = fopen($dir . 'labels.csv', 'w');
    fwrite($csv, "\xEF\xBB\xBF");   // BOM so Excel reads UTF-8
    fputcsv($csv, ['image', 'text']);
    $tsv = '';
    $n = 0;
    foreach ($items as $r) {
        if ($r['status'] !== 'labeled' || trim($r['transcription']) === '') continue;
        fputcsv($csv, [$r['processed_filename'], $r['transcription']]);
        $tsv .= 'images/' . $r['processed_filename'] . "\t" . str_replace(["\t", "\r", "\n"], ' ', $r['transcription']) . "\n";
        $n++;
    }
    fclose($csv);
    file_put_contents($dir . 'labels.tsv', $tsv);
    return ['labeled' => $n];
}

/**
 * Batch-processes every verified word image in output/ into HTR-ready training
 * data (see prepareImageForHTR) saved under htr_dataset/images/ and registers them in
 * manifest.json for annotation. Re-running keeps the labels already typed: an image is
 * recognised by its original file name, so nothing has to be annotated twice.
 *
 * @return array{success:bool, processed:int, skipped:int, new:int, manifest:array}
 */
function batchProcessForHTR(string $outputDir, string $htrImageDir, string $htrExcelAbs, int $targetHeight = 64): array
{
    $allowedExt = ['png', 'jpg', 'jpeg', 'webp'];
    $files = glob($outputDir . '*') ?: [];
    natsort($files);

    $old = [];
    $maxId = 0;
    foreach (htrLoadManifest() as $r) {
        $old[$r['original_filename']] = $r;
        $maxId = max($maxId, (int) $r['id']);
    }

    $manifest = [];
    $processed = 0;
    $skipped = 0;
    $new = 0;
    $seen = [];

    foreach ($files as $path) {
        if (!is_file($path)) continue;
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedExt, true)) { $skipped++; continue; }

        $orig = basename($path);
        $seen[$orig] = true;

        // already prepared (and maybe labelled): keep it as it is
        if (isset($old[$orig]) && is_file($htrImageDir . $old[$orig]['processed_filename'])) {
            $manifest[] = $old[$orig];
            $processed++;
            continue;
        }

        $mime = mime_content_type($path);
        $src = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($path),
            'image/png'  => @imagecreatefrompng($path),
            'image/webp' => @imagecreatefromwebp($path),
            default => null,
        };
        if (!$src) { $skipped++; continue; }

        $prepared = prepareImageForHTR($src, $targetHeight);
        unset($src);
        if (!$prepared) { $skipped++; continue; }

        $id = isset($old[$orig]) ? (int) $old[$orig]['id'] : ++$maxId;
        $newFilename = sprintf('htr_%04d.png', $id);
        imagepng($prepared, $htrImageDir . $newFilename, 6);
        $finalW = imagesx($prepared);
        $finalH = imagesy($prepared);
        unset($prepared);

        $manifest[] = [
            'id' => $id,
            'original_filename' => $orig,
            'processed_filename' => $newFilename,
            'relative_path' => 'htr_dataset/images/' . $newFilename,
            'width' => $finalW,
            'height' => $finalH,
            'transcription' => $old[$orig]['transcription'] ?? '',
            'status' => $old[$orig]['status'] ?? 'pending',   // pending | labeled | unreadable
        ];
        $processed++;
        $new++;
    }

    // images whose source left output/: keep them only if they already carry work
    foreach ($old as $orig => $r) {
        if (isset($seen[$orig])) continue;
        if (($r['status'] !== 'pending' || ($r['source'] ?? '') === 'upload') && is_file($htrImageDir . $r['processed_filename'])) {
            $manifest[] = $r;   // labelled work, or an image that was uploaded (it has no source in output/)
        } elseif (is_file($htrImageDir . $r['processed_filename'])) {
            @unlink($htrImageDir . $r['processed_filename']);
        }
    }

    usort($manifest, fn($a, $b) => $a['id'] <=> $b['id']);
    htrSaveManifest($manifest);
    htrExportLabels($manifest);

    return [
        'success' => true,
        'processed' => $processed,
        'skipped' => $skipped,
        'new' => $new,
        'manifest' => $manifest,
    ];
}

/**
 * Writes a minimal single-sheet Excel (.xlsx) file using a hand-rolled ZIP
 * container (see createMinimalZip) so it works even when the ext-zip
 * extension is disabled — no Composer/PhpSpreadsheet dependency required.
 * Cell values are written as inline strings so no shared-strings part is
 * needed, keeping the workbook small and dependency-free.
 */
function writeXlsx(string $path, string $sheet, array $headers, array $rows): bool
{
    $sheetXml = xlsxBuildSheetXml(array_merge([$headers], $rows));
    $sheetNameEsc = htmlspecialchars($sheet, ENT_XML1 | ENT_QUOTES, 'UTF-8');

    $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
        '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">' .
        '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>' .
        '<Default Extension="xml" ContentType="application/xml"/>' .
        '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>' .
        '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>' .
        '</Types>';

    $rootRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
        '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
        '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>' .
        '</Relationships>';

    $workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
        '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" ' .
        'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">' .
        '<sheets><sheet name="' . $sheetNameEsc . '" sheetId="1" r:id="rId1"/></sheets>' .
        '</workbook>';

    $workbookRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
        '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
        '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>' .
        '</Relationships>';

    return createMinimalZip($path, [
        '[Content_Types].xml' => $contentTypes,
        '_rels/.rels' => $rootRels,
        'xl/workbook.xml' => $workbook,
        'xl/_rels/workbook.xml.rels' => $workbookRels,
        'xl/worksheets/sheet1.xml' => $sheetXml,
    ]);
}

/**
 * Creates a ZIP archive from an in-memory map of entries without requiring
 * the ext-zip extension — only PHP's always-available zlib functions
 * (gzdeflate) and crc32() are used. This keeps Excel export working even on
 * PHP installs where ext-zip is disabled (a common default on XAMPP/Laragon).
 *
 * @param array<string,string> $files Map of in-archive path => raw file contents
 */
function createMinimalZip(string $zipPath, array $files): bool
{
    $dir = dirname($zipPath);
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }

    $localParts = [];
    $centralParts = [];
    $offset = 0;
    $dosTime = 0;
    $dosDate = (1 << 5) | 1; // Jan 1, 1980 — timestamp is irrelevant for this manifest

    foreach ($files as $name => $contents) {
        $nameEnc = str_replace('\\', '/', $name);
        $crc = crc32($contents);
        $uncompressedSize = strlen($contents);
        $compressed = gzdeflate($contents, 6);
        $compressedSize = strlen($compressed);

        $localHeader = pack(
            'VvvvvvVVVvv',
            0x04034b50,
            20,
            0,
            8,
            $dosTime,
            $dosDate,
            $crc,
            $compressedSize,
            $uncompressedSize,
            strlen($nameEnc),
            0
        ) . $nameEnc . $compressed;

        $localParts[] = $localHeader;

        $centralParts[] = pack(
            'VvvvvvvVVVvvvvvVV',
            0x02014b50,
            20,
            20,
            0,
            8,
            $dosTime,
            $dosDate,
            $crc,
            $compressedSize,
            $uncompressedSize,
            strlen($nameEnc),
            0,
            0,
            0,
            0,
            0x81A40000,
            $offset
        ) . $nameEnc;
        $offset += strlen($localHeader);
    }

    $localData = implode('', $localParts);
    $centralData = implode('', $centralParts);

    $endRecord = pack(
        'VvvvvVVv',
        0x06054b50,
        0,
        0,
        count($files),
        count($files),
        strlen($centralData),
        strlen($localData),
        0
    );

    if (file_exists($zipPath)) {
        @unlink($zipPath);
    }

    return file_put_contents($zipPath, $localData . $centralData . $endRecord) !== false;
}

/** Builds <sheetData> XML for a 2D array of rows using inline strings. */
function xlsxBuildSheetXml(array $rows): string
{
    $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
        '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' .
        '<sheetData>';

    foreach ($rows as $rowIdx => $row) {
        $r = $rowIdx + 1;
        $xml .= '<row r="' . $r . '">';
        foreach (array_values($row) as $colIdx => $value) {
            $cellRef = xlsxColumnLetter($colIdx) . $r;
            if (is_numeric($value) && $value !== '') {
                $xml .= '<c r="' . $cellRef . '"><v>' . htmlspecialchars((string) $value, ENT_XML1) . '</v></c>';
            } else {
                $escaped = htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
                $xml .= '<c r="' . $cellRef . '" t="inlineStr"><is><t xml:space="preserve">' . $escaped . '</t></is></c>';
            }
        }
        $xml .= '</row>';
    }

    $xml .= '</sheetData></worksheet>';
    return $xml;
}

/** Converts a 0-based column index to an Excel column letter (0 -> A, 26 -> AA, ...). */
function xlsxColumnLetter(int $index): string
{
    $letter = '';
    $index++;
    while ($index > 0) {
        $mod = ($index - 1) % 26;
        $letter = chr(65 + $mod) . $letter;
        $index = (int) (($index - $mod) / 26);
    }
    return $letter;
}


/* ── Importing an already-annotated file and merging it into the manifest ───────── */

/** Excel column letters ("A", "AB") -> 0-based index. */
function htrColIndex(string $ref): int
{
    $n = 0;
    foreach (str_split(strtoupper(preg_replace('/[^A-Za-z]/', '', $ref))) as $ch) $n = $n * 26 + (ord($ch) - 64);
    return max(0, $n - 1);
}

/** Reads the first sheet of an .xlsx into rows of plain strings (shared + inline strings supported). */
function htrReadXlsxRows(string $path): array
{
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) throw new RuntimeException('not a valid .xlsx file');
    $read = function (string $name) use ($zip): ?string {
        $st = $zip->statName($name);
        if (!$st || $st['size'] > 30 * 1024 * 1024) return null;     // missing or suspiciously large
        $d = $zip->getFromName($name);
        return $d === false ? null : preg_replace('/ xmlns="[^"]*"/', '', $d, 1);
    };
    $shared = [];
    if (($xml = $read('xl/sharedStrings.xml')) !== null) {
        $sx = @simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NONET);
        if ($sx) foreach ($sx->si as $si) {
            $t = '';
            if (isset($si->t)) $t = (string) $si->t;
            else foreach ($si->r as $run) $t .= (string) $run->t;
            $shared[] = $t;
        }
    }
    $sheetXml = $read('xl/worksheets/sheet1.xml');
    if ($sheetXml === null) {
        for ($i = 0; $i < $zip->numFiles && $sheetXml === null; $i++) {
            $n = $zip->getNameIndex($i);
            if (preg_match('#^xl/worksheets/[^/]+\.xml$#', $n)) $sheetXml = $read($n);
        }
    }
    $zip->close();
    if ($sheetXml === null) throw new RuntimeException('no worksheet found in the .xlsx file');
    $sx = @simplexml_load_string($sheetXml, 'SimpleXMLElement', LIBXML_NONET);
    if (!$sx || !isset($sx->sheetData)) throw new RuntimeException('worksheet could not be read');

    $rows = [];
    foreach ($sx->sheetData->row as $row) {
        $cells = [];
        foreach ($row->c as $c) {
            $idx = htrColIndex((string) $c['r']);
            $t = (string) $c['t'];
            if ($t === 's') $val = $shared[(int) $c->v] ?? '';
            elseif ($t === 'inlineStr') {
                $val = '';
                if (isset($c->is->t)) $val = (string) $c->is->t;
                else foreach ($c->is->r as $run) $val .= (string) $run->t;
            } else $val = (string) $c->v;
            $cells[$idx] = $val;
        }
        if ($cells) {
            $max = max(array_keys($cells));
            $line = [];
            for ($i = 0; $i <= $max; $i++) $line[] = $cells[$i] ?? '';
            $rows[] = $line;
        }
    }
    return $rows;
}

/**
 * Reads an annotated file (.xlsx / .csv / .tsv / manifest .json) into
 * [['file' => basename, 'text' => string, 'status' => labeled|unreadable|pending|''], ...]
 */
function htrParseLabelFile(string $path, string $origName): array
{
    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    $table = [];                       // list of string rows

    if ($ext === 'json') {
        $d = json_decode((string) file_get_contents($path), true);
        if (!is_array($d)) throw new RuntimeException('the .json file is not a manifest');
        $out = [];
        foreach ($d as $r) {
            if (!is_array($r)) continue;
            $f = (string) ($r['processed_filename'] ?? $r['original_filename'] ?? '');
            if ($f === '') continue;
            $out[] = ['file' => basename($f), 'text' => (string) ($r['transcription'] ?? ''), 'status' => (string) ($r['status'] ?? ''),
                      'orig' => (string) ($r['original_filename'] ?? ''), 'uid' => (string) ($r['uid'] ?? '')];
        }
        return $out;
    }

    if ($ext === 'xlsx') {
        $table = htrReadXlsxRows($path);
    } elseif ($ext === 'csv' || $ext === 'tsv' || $ext === 'txt') {
        $raw = (string) file_get_contents($path);
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
        if (!mb_check_encoding($raw, 'UTF-8')) throw new RuntimeException('the file is not UTF-8 text');
        $delim = $ext === 'csv' ? ',' : "\t";
        $fh = fopen('php://temp', 'r+');
        fwrite($fh, $raw);
        rewind($fh);
        while (($row = fgetcsv($fh, 0, $delim, '"', '')) !== false) {
            if ($row === [null]) continue;
            $table[] = array_map('strval', $row);
        }
        fclose($fh);
    } else {
        throw new RuntimeException('use an .xlsx, .csv, .tsv or .json file');
    }
    if (!$table) return [];

    // header row? find the columns by name, otherwise assume: image, text
    $fi = 0; $ti = 1; $si = -1; $ui = -1; $start = 0;
    $head = array_map(fn($h) => strtolower(trim((string) $h)), $table[0]);
    $hasHead = false;
    foreach ($head as $i => $h) {
        if (in_array($h, ['image', 'image filename', 'filename', 'file', 'processed_filename'], true)) { $fi = $i; $hasHead = true; }
        elseif (in_array($h, ['text', 'transcription', 'label', 'word'], true)) { $ti = $i; $hasHead = true; }
        elseif ($h === 'status') { $si = $i; $hasHead = true; }
        elseif (in_array($h, ['uid', 'image_id'], true)) { $ui = $i; $hasHead = true; }
    }
    if ($hasHead) $start = 1;

    $out = [];
    for ($i = $start; $i < count($table); $i++) {
        $r = $table[$i];
        $f = trim((string) ($r[$fi] ?? ''));
        if ($f === '') continue;
        $out[] = ['file' => basename(str_replace('\\', '/', $f)), 'text' => (string) ($r[$ti] ?? ''),
                  'status' => $si >= 0 ? strtolower(trim((string) ($r[$si] ?? ''))) : '', 'orig' => '',
                  'uid' => $ui >= 0 ? strtolower(trim((string) ($r[$ui] ?? ''))) : ''];
    }
    return $out;
}

/**
 * Merges parsed rows into $items (matched by processed_filename, then original_filename).
 * Without $overwrite only images that are still "pending" are filled in; with it the
 * imported label replaces an existing different one.
 */
function htrMergeLabels(array &$items, array $rows, bool $overwrite): array
{
    // A row that carries a uid (content id) is matched on that alone: file names such as htr_0005.png
    // are only counters and mean a different picture in another dataset.
    $byProc = []; $byOrig = []; $byUid = [];
    foreach ($items as $k => $r) {
        $byProc[$r['processed_filename']] = $k;
        $byOrig[$r['original_filename']][] = $k;
        if (!empty($r['uid'])) $byUid[$r['uid']][] = $k;
    }
    $st = ['rows' => count($rows), 'updated' => 0, 'same' => 0, 'kept' => 0, 'missing' => 0, 'empty' => 0, 'conflict' => 0, 'by_name' => 0];
    foreach ($rows as $row) {
        $text = preg_replace('/\s+/u', ' ', trim(mb_substr($row['text'], 0, 300)));
        $status = $row['status'];
        if (!in_array($status, ['labeled', 'unreadable', 'pending'], true)) $status = $text !== '' ? 'labeled' : '';
        if ($status === 'labeled' && $text === '') $status = '';
        if ($status === '' || $status === 'pending') { $st['empty']++; continue; }   // nothing to bring over
        if ($status === 'unreadable') $text = '';

        $uid = (string) ($row['uid'] ?? '');
        if ($uid !== '') {
            $ks = $byUid[$uid] ?? [];
            if (!$ks) { $st['missing']++; continue; }
        } else {
            $st['by_name']++;
            $k = $byProc[$row['file']] ?? null;
            $orig = (string) ($row['orig'] ?? '');
            if ($k !== null && $orig !== '' && $items[$k]['original_filename'] !== $orig) {
                // same counter name, different picture: only trust an unambiguous match on the original name
                $k = count($byOrig[$orig] ?? []) === 1 ? $byOrig[$orig][0] : null;
                if ($k === null) { $st['conflict']++; continue; }
            } elseif ($k === null && $orig !== '' && count($byOrig[$orig] ?? []) === 1) {
                $k = $byOrig[$orig][0];
            }
            if ($k === null) { $st['missing']++; continue; }
            $ks = [$k];
        }

        foreach ($ks as $k) {
            $cur = $items[$k];
            if ($cur['status'] === $status && $cur['transcription'] === $text) { $st['same']++; continue; }
            if ($cur['status'] !== 'pending' && !$overwrite) { $st['kept']++; continue; }
            $items[$k]['status'] = $status;
            $items[$k]['transcription'] = $text;
            $st['updated']++;
        }
    }
    return $st;
}
