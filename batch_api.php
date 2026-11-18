<?php
// JSON API for the folder-batch workflow (batch.php).
//   action=reset    -> clear leftover previews (start of a session)
//   action=scan     -> scan ONE document (image or PDF); returns pages + cropped cells/words
//   action=save     -> copy the verified crops of one document to output/ and drop its previews
//   action=discard  -> drop a document's previews without saving (skip)
// Uploaded documents are never stored; only verified crops end up in output/.

header('Content-Type: application/json');

$previewDir = __DIR__ . '/uploads/previews/';
$outputDir  = __DIR__ . '/output/';
foreach ([__DIR__ . '/uploads/', $previewDir, $outputDir] as $d) {
    if (!is_dir($d)) mkdir($d, 0777, true);
}

require __DIR__ . '/engine.php';

function out(array $data, int $code = 200): never
{
    http_response_code($code);
    echo json_encode($data, JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

/** Safe file-name prefix for one document: "d<id>_<nodeId>_<rel>_"
 *  The node ID makes prefixes unique per installation so multiple
 *  laptops can produce crops without filename collisions when merged. */
function docPrefix(string $id, string $rel): string
{
    $nodeId = getNodeId();
    $rel = preg_replace('/\.[^.\/\\\\]+$/', '', $rel);                 // drop extension
    $rel = trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', $rel), '-');
    $rel = substr($rel, -60);
    $id  = preg_replace('/[^0-9]/', '', $id);
    return sprintf('d%s_%s_%s_', str_pad($id === '' ? '0' : $id, 3, '0', STR_PAD_LEFT), $nodeId, $rel === '' ? 'doc' : $rel);
}

/** Returns (and lazily creates) the 6-char hex node ID for this installation. */
function getNodeId(): string
{
    $f = __DIR__ . '/uploads/node_id.txt';
    if (is_file($f)) {
        $id = trim((string) file_get_contents($f));
        if (preg_match('/^[A-Za-z0-9]{4,8}$/', $id)) return $id;
    }
    $id = substr(bin2hex(random_bytes(3)), 0, 6);
    @file_put_contents($f, $id);
    return $id;
}

function validPrefix(string $prefix): bool
{
    // new format: d003_a1b2c3_rel_   (node-id segment required)
    // also accept legacy format without node-id so old sessions still save cleanly
    return (bool) preg_match('/^d[0-9]{3,}_([A-Za-z0-9]{4,8}_)?[A-Za-z0-9-]*_$/', $prefix);
}

function dropPreviews(string $previewDir, string $prefix): int
{
    $n = 0;
    foreach (glob($previewDir . $prefix . '*') as $f) {
        if (is_file($f) && @unlink($f)) $n++;
    }
    return $n;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    out(['ok' => false, 'error' => 'POST required'], 405);
}
$action = $_POST['action'] ?? '';

if ($action === 'reset') {
    foreach (glob($previewDir . '*') as $f) {
        if (is_file($f)) @unlink($f);
    }
    out(['ok' => true]);
}

if ($action === 'scan') {
    @set_time_limit(0);
    if (!file_exists(KRAKEN_PYTHON)) {
        out(['ok' => false, 'error' => 'Python executable not found'], 500);
    }
    if (!isset($_FILES['file'])) {
        out(['ok' => false, 'error' => 'No file received (the server may limit upload size).'], 400);
    }
    $f = $_FILES['file'];
    $padding = max(0, min(30, (int) ($_POST['padding'] ?? 4)));
    $fmt = (string) ($_POST['output_format'] ?? 'png');
    if (!in_array($fmt, ['png', 'jpeg', 'webp'], true)) $fmt = 'png';
    $prefix = docPrefix((string) ($_POST['id'] ?? '0'), (string) ($_POST['rel'] ?? $f['name']));

    dropPreviews($previewDir, $prefix);   // re-scan of the same document replaces its crops
    $result = scanUploads(
        [['name' => $f['name'], 'tmp' => $f['tmp_name'], 'error' => $f['error']]],
        $prefix, $padding, $fmt, $previewDir
    );
    if (!$result['words']) {
        out(['ok' => false, 'error' => $result['warnings'] ? implode(' ', $result['warnings']) : 'Nothing detected.']);
    }
    out(['ok' => true, 'prefix' => $prefix] + $result);
}

if ($action === 'save') {
    $prefix = (string) ($_POST['prefix'] ?? '');
    if (!validPrefix($prefix)) out(['ok' => false, 'error' => 'bad prefix'], 400);
    $names = json_decode((string) ($_POST['filenames'] ?? '[]'), true);
    $saved = [];
    if (is_array($names)) {
        foreach ($names as $n) {
            $clean = basename((string) $n);
            if (strncmp($clean, $prefix, strlen($prefix)) !== 0) continue;   // only this document's crops
            $src = $previewDir . $clean;
            if (is_file($src) && copy($src, $outputDir . $clean)) $saved[] = $clean;
        }
    }
    dropPreviews($previewDir, $prefix);   // verified crops are saved; the rest is discarded
    out(['ok' => true, 'count' => count($saved), 'files' => $saved]);
}

if ($action === 'replace_crop') {
    // overwrite ONE crop of this document with a hand-adjusted version
    $prefix = (string) ($_POST['prefix'] ?? '');
    if (!validPrefix($prefix)) out(['ok' => false, 'error' => 'bad prefix'], 400);
    $name = basename((string) ($_POST['filename'] ?? ''));
    $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $dst  = $previewDir . $name;
    $up   = $_FILES['image'] ?? null;
    if (strncmp($name, $prefix, strlen($prefix)) !== 0) out(['ok' => false, 'error' => 'That crop belongs to another document.'], 400);
    if ($err = cropUploadError($name, $dst, $up)) out(['ok' => false, 'error' => $err], 400);
    $img = @imagecreatefromstring((string) file_get_contents($up['tmp_name']));
    if (!$img) out(['ok' => false, 'error' => 'Not a readable image'], 400);
    switch ($ext) {
        case 'jpeg': imagejpeg($img, $dst, 95); break;
        case 'webp': imagewebp($img, $dst, 95); break;
        default:     imagepng($img, $dst);      break;
    }
    $w = imagesx($img); $h = imagesy($img);
    unset($img);
    out(['ok' => true, 'w' => $w, 'h' => $h]);
}

if ($action === 'add_crop') {
    $prefix = (string) ($_POST['prefix'] ?? '');
    if (!validPrefix($prefix)) out(['ok' => false, 'error' => 'bad prefix'], 400);
    $up = $_FILES['image'] ?? null;
    if (!$up || $up['error'] !== UPLOAD_ERR_OK || $up['size'] > 20 * 1024 * 1024) {
        out(['ok' => false, 'error' => 'Bad crop upload'], 400);
    }
    $img = @imagecreatefromstring((string) file_get_contents($up['tmp_name']));
    if (!$img) out(['ok' => false, 'error' => 'Not a readable image'], 400);

    // find a unique filename for this manual crop
    $i = 1;
    do {
        $name = $prefix . 'manual' . $i . '.png';
        $i++;
    } while (file_exists($previewDir . $name) && $i < 10000);

    imagepng($img, $previewDir . $name);
    $w = imagesx($img); $h = imagesy($img);
    unset($img);
    out(['ok' => true, 'filename' => $name, 'file' => 'uploads/previews/' . $name, 'w' => $w, 'h' => $h]);
}

if ($action === 'discard') {
    $prefix = (string) ($_POST['prefix'] ?? '');
    if (!validPrefix($prefix)) out(['ok' => false, 'error' => 'bad prefix'], 400);
    out(['ok' => true, 'dropped' => dropPreviews($previewDir, $prefix)]);
}

out(['ok' => false, 'error' => 'unknown action'], 400);
