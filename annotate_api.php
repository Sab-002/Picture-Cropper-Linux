<?php
// JSON API of the annotation tool (annotate.php).
//   action=list    -> manifest + counts
//   action=save    -> id, text, status(labeled|unreadable|pending)
//   action=export  -> (re)write dataset_labels.xlsx, labels.csv, labels.tsv
header('Content-Type: application/json');
require __DIR__ . '/htr_lib.php';

function out(array $d, int $code = 200): never
{
    http_response_code($code);
    echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function stats(array $items): array
{
    $c = ['total' => count($items), 'labeled' => 0, 'unreadable' => 0, 'pending' => 0];
    foreach ($items as $r) $c[$r['status']] = ($c[$r['status']] ?? 0) + 1;
    return $c;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') out(['ok' => false, 'error' => 'POST required'], 405);
$action = $_POST['action'] ?? '';

// one writer at a time so quick consecutive saves cannot overwrite each other
$lock = fopen(__DIR__ . '/htr_dataset/.lock', 'c');
flock($lock, LOCK_EX);

$items = htrLoadManifest();
if (htrEnsureUids($items)) htrSaveManifest($items);   // content ids make transfers between datasets safe

if ($action === 'list') {
    out(['ok' => true, 'items' => $items, 'stats' => stats($items)]);
}

if ($action === 'save') {
    $id = (int) ($_POST['id'] ?? 0);
    $status = (string) ($_POST['status'] ?? 'labeled');
    $text = trim((string) ($_POST['text'] ?? ''));
    if (!mb_check_encoding($text, 'UTF-8')) out(['ok' => false, 'error' => 'text is not valid UTF-8'], 400);
    $text = mb_substr($text, 0, 300);
    $text = preg_replace('/\s+/u', ' ', $text);
    if (!in_array($status, ['labeled', 'unreadable', 'pending'], true)) out(['ok' => false, 'error' => 'bad status'], 400);
    if ($status === 'labeled' && $text === '') $status = 'pending';   // an empty label is not a label
    if ($status !== 'labeled') $text = $status === 'unreadable' ? '' : $text;
    $found = false;
    foreach ($items as &$r) {
        if ((int) $r['id'] === $id) {
            $r['transcription'] = $text;
            $r['status'] = $status;
            $found = true;
            break;
        }
    }
    unset($r);
    if (!$found) out(['ok' => false, 'error' => 'image not found'], 404);
    htrSaveManifest($items);
    out(['ok' => true, 'status' => $status, 'text' => $text, 'stats' => stats($items)]);
}

if ($action === 'import') {
    $up = $_FILES['file'] ?? null;
    if (!$up || $up['error'] !== UPLOAD_ERR_OK || $up['size'] > 10 * 1024 * 1024) out(['ok' => false, 'error' => 'No file received (max 10 MB).'], 400);
    $overwrite = ($_POST['overwrite'] ?? '0') === '1';
    try {
        $rows = htrParseLabelFile($up['tmp_name'], (string) $up['name']);
    } catch (Throwable $e) {
        out(['ok' => false, 'error' => $e->getMessage()], 400);
    }
    if (!$items) out(['ok' => false, 'error' => 'There is no dataset yet. Run "Batch Process for HTR" first.'], 400);
    $merge = htrMergeLabels($items, $rows, $overwrite);
    if ($merge['updated'] > 0) {
        htrSaveManifest($items);
        htrExportLabels($items);          // keep xlsx / csv / tsv in step with the manifest
    }
    out(['ok' => true, 'merge' => $merge, 'stats' => stats($items)]);
}



/** Reads $_FILES['files'] (multiple) into a flat list of [name, tmp_name, error, size]. */
function uploadedFiles(string $key): array
{
    $f = $_FILES[$key] ?? null;
    if (!$f) return [];
    if (!is_array($f['name'])) return [[$f['name'], $f['tmp_name'], $f['error'], $f['size']]];
    $out = [];
    foreach ($f['name'] as $i => $n) $out[] = [$n, $f['tmp_name'][$i], $f['error'][$i], $f['size'][$i]];
    return $out;
}

if ($action === 'upload_images') {
    // images that were NOT annotated (e.g. cropped elsewhere) join the dataset as "pending"
    global $htrImageDir;
    $files = uploadedFiles('files');
    if (!$files) out(['ok' => false, 'error' => 'No file received (the server may limit the upload size).'], 400);
    $maxId = 0; $have = [];
    foreach ($items as $r) { $maxId = max($maxId, (int) $r['id']); if (!empty($r['uid'])) $have[$r['uid']] = true; }
    $res = ['added' => 0, 'duplicate' => 0, 'invalid' => 0];
    $add = function (string $bytes, string $name) use (&$items, &$maxId, &$have, &$res, $htrImageDir): void {
        $name = basename(str_replace('\\', '/', $name));
        $img = @imagecreatefromstring($bytes);
        if (!$img || imagesx($img) < 2 || imagesy($img) < 2) { $res['invalid']++; return; }
        // PNGs are stored byte for byte (so their content id equals the one in the exported template);
        // JPG / WEBP are converted losslessly to PNG.
        if (strncmp($bytes, chr(137) . 'PNG', 4) !== 0) { ob_start(); imagepng($img, null, 6); $bytes = (string) ob_get_clean(); }
        $uid = substr(sha1($bytes), 0, 20);
        if (isset($have[$uid])) { unset($img); $res['duplicate']++; return; }   // the very same picture is already here
        $id = ++$maxId;
        $fn = sprintf('htr_%04d.png', $id);
        while (is_file($htrImageDir . $fn)) $fn = sprintf('htr_%04d.png', ++$maxId);   // never overwrite an existing image
        $id = $maxId;
        file_put_contents($htrImageDir . $fn, $bytes);
        $items[] = ['id' => $id, 'original_filename' => $name, 'processed_filename' => $fn,
                    'relative_path' => 'htr_dataset/images/' . $fn, 'width' => imagesx($img), 'height' => imagesy($img),
                    'transcription' => '', 'status' => 'pending', 'source' => 'upload', 'uid' => $uid];
        unset($img);
        $have[$uid] = true; $res['added']++;
    };
    $okExt = ['png', 'jpg', 'jpeg', 'webp'];
    $labelFiles = []; $res['labels'] = 0;
    foreach ($files as [$name, $tmp, $err, $size]) {
        if ($err !== UPLOAD_ERR_OK || $size > 200 * 1024 * 1024) { $res['invalid']++; continue; }
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if ($ext === 'zip') {
            if (!class_exists('ZipArchive')) out(['ok' => false, 'error' => 'zip files need the PHP zip extension; upload the images directly instead.'], 400);
            $z = new ZipArchive();
            if ($z->open($tmp) !== true) { $res['invalid']++; continue; }
            for ($i = 0; $i < $z->numFiles; $i++) {
                $st = $z->statIndex($i);
                $en = (string) $st['name'];
                if (in_array(strtolower(basename($en)), ['labels.csv', 'labels.xlsx'], true) && $st['size'] <= 10 * 1024 * 1024) {
                    $lt = tempnam(sys_get_temp_dir(), 'lbl');
                    file_put_contents($lt, (string) $z->getFromIndex($i));
                    $labelFiles[] = [$lt, basename($en)];
                    continue;
                }
                if (substr($en, -1) === '/' || strpos($en, '__MACOSX') !== false || !in_array(strtolower(pathinfo($en, PATHINFO_EXTENSION)), $okExt, true)) continue;
                if ($st['size'] > 20 * 1024 * 1024) { $res['invalid']++; continue; }
                $add((string) $z->getFromIndex($i), $en);
            }
            $z->close();
        } elseif (in_array($ext, $okExt, true)) {
            if ($size > 20 * 1024 * 1024) { $res['invalid']++; continue; }
            $add((string) file_get_contents($tmp), $name);
        } else {
            $res['invalid']++;
        }
    }
    // an annotated.zip also brings its labels: they are merged by uid right away
    foreach ($labelFiles as [$lt, $ln]) {
        try { $res['labels'] += htrMergeLabels($items, htrParseLabelFile($lt, $ln), false)['updated']; } catch (Throwable $e) {}
        @unlink($lt);
    }
    if ($res['added'] > 0 || $res['labels'] > 0) {
        htrSaveManifest($items);
        htrExportLabels($items);
    }
    out(['ok' => true] + $res + ['stats' => stats($items)]);
}

if ($action === 'build_pending_zip') {
    // the images still waiting for a label + a template to fill in (imports back by file name)
    $pending = array_values(array_filter($items, fn($r) => ($r['status'] ?? '') === 'pending'));
    if (!$pending) out(['ok' => false, 'error' => 'Nothing to download: every image is annotated.'], 400);
    $files = []; $rows = [];
    foreach ($pending as $r) {
        $p = __DIR__ . '/htr_dataset/images/' . $r['processed_filename'];
        if (!is_file($p)) continue;
        $files['not_annotated/images/' . $r['processed_filename']] = (string) file_get_contents($p);
        $rows[] = [$r['processed_filename'], '', '', $r['uid'] ?? ''];
    }
    if (!$rows) out(['ok' => false, 'error' => 'The image files are missing.'], 400);
    $csv = "\xEF\xBB\xBF" . "image,text,status\r\n";
    foreach ($rows as $row) $csv .= $row[0] . ",,\r\n";
    $files['not_annotated/labels_template.csv'] = $csv;
    $tmpX = tempnam(sys_get_temp_dir(), 'tpl');
    writeXlsx($tmpX, 'To annotate', ['image', 'text', 'status', 'uid'], $rows);
    $files['not_annotated/labels_template.xlsx'] = (string) file_get_contents($tmpX);
    @unlink($tmpX);
    $files['not_annotated/README.txt'] = "Type the word of every image in the 'text' column of labels_template.csv (or .xlsx).\r\n" .
        "Write 'unreadable' in the 'status' column for an image that cannot be read.\r\n" .
        "Then use 'Import annotated file' in the Word Cropper to merge the labels back (matched by the uid column, so it never mixes up datasets; keep that column).\r\n";
    $dir = __DIR__ . '/htr_dataset/exports/';
    if (!is_dir($dir)) mkdir($dir, 0777, true);
    $zip = $dir . 'not_annotated.zip';
    if (!createMinimalZip($zip, $files)) out(['ok' => false, 'error' => 'Could not write the zip file.'], 500);
    out(['ok' => true, 'count' => count($rows), 'file' => 'htr_dataset/exports/not_annotated.zip', 'size' => filesize($zip)]);
}

if ($action === 'build_annotated_zip') {
    // the finished images together with their labels (value), ready to move to another dataset
    $done = array_values(array_filter($items, fn($r) => ($r['status'] ?? '') === 'labeled' && trim((string) ($r['transcription'] ?? '')) !== ''));
    if (!$done) out(['ok' => false, 'error' => 'Nothing to download yet: no image has been annotated.'], 400);
    $files = []; $rows = [];
    foreach ($done as $r) {
        $p = __DIR__ . '/htr_dataset/images/' . basename((string) $r['processed_filename']);
        if (!is_file($p)) continue;
        $files['annotated/images/' . $r['processed_filename']] = (string) file_get_contents($p);
        $rows[] = [$r['processed_filename'], $r['transcription'], 'labeled', $r['uid'] ?? ''];
    }
    if (!$rows) out(['ok' => false, 'error' => 'The image files are missing.'], 400);
    $fh = fopen('php://temp', 'r+');
    fputcsv($fh, ['image', 'text', 'status', 'uid'], ',', '"', '');
    foreach ($rows as $row) fputcsv($fh, $row, ',', '"', '');
    rewind($fh);
    $files['annotated/labels.csv'] = "\xEF\xBB\xBF" . stream_get_contents($fh);
    fclose($fh);
    $tmpX = tempnam(sys_get_temp_dir(), 'ann');
    writeXlsx($tmpX, 'Annotated', ['image', 'text', 'status', 'uid'], $rows);
    $files['annotated/labels.xlsx'] = (string) file_get_contents($tmpX);
    @unlink($tmpX);
    $files['annotated/README.txt'] = "images/ holds the annotated pictures, labels.csv / labels.xlsx hold their text (the 'uid' column identifies each picture).\r\n" .
        "To add them to another dataset: 'Upload images to annotate' with the images, then 'Import annotated file' with labels.csv or labels.xlsx.\r\n";
    $dir = __DIR__ . '/htr_dataset/exports/';
    if (!is_dir($dir)) mkdir($dir, 0777, true);
    $zip = $dir . 'annotated.zip';
    if (!createMinimalZip($zip, $files)) out(['ok' => false, 'error' => 'Could not write the zip file.'], 500);
    out(['ok' => true, 'count' => count($rows), 'file' => 'htr_dataset/exports/annotated.zip', 'size' => filesize($zip)]);
}

if ($action === 'build_zip') {
    require_once __DIR__ . '/dataset_export.php';
    $presets = ['80-10-10' => [0.8, 0.1, 0.1], '70-15-15' => [0.7, 0.15, 0.15], '60-20-20' => [0.6, 0.2, 0.2]];
    $fr = $presets[(string) ($_POST['split'] ?? '80-10-10')] ?? $presets['80-10-10'];
    htrExportLabels($items);                  // keep the other label files in step first
    $res = dsBuildZip($items, $fr);
    out($res, $res['ok'] ? 200 : 400);
}

if ($action === 'export') {
    $res = htrExportLabels($items);
    out(['ok' => true, 'labeled' => $res['labeled'], 'stats' => stats($items),
         'files' => ['htr_dataset/dataset_labels.xlsx', 'htr_dataset/annotated_labels.xlsx', 'htr_dataset/labels.csv', 'htr_dataset/labels.tsv']]);
}

out(['ok' => false, 'error' => 'unknown action'], 400);
