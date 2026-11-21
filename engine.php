<?php
// Shared scanning engine used by process.php (single upload) and batch_api.php (folder batch).
// Nothing uploaded is stored in the project: originals live in the system temp dir for the
// duration of the request; only the cropped previews are written (uploads/previews/).

// First existing interpreter wins. Project-local venvs come first (Linux: bin/python,
// Windows: Scripts/python.exe); the Laragon/XAMPP paths are kept for Windows setups.
$pythonCandidates = [
    __DIR__ . '/.venv/bin/python',
    __DIR__ . '/.venv-kraken/bin/python',
    __DIR__ . '/.venv/Scripts/python.exe',
    __DIR__ . '/.venv-kraken/Scripts/python.exe',
    'C:/xampp/htdocs/crms-laravel-12/ml/.venv-kraken/Scripts/python.exe',
    'C:/laragon/www/crms-laravel-12-main/ml/.venv-kraken/Scripts/python.exe',
];
$krakenPy = 'python';
if (($envPy = getenv('KRAKEN_PYTHON')) && file_exists($envPy)) {
    $pythonCandidates = [$envPy]; // set by the desktop app (bundled Python)
}
foreach ($pythonCandidates as $candidate) {
    if (file_exists($candidate)) {
        $krakenPy = $candidate;
        break;
    }
}
define('KRAKEN_PYTHON', $krakenPy);
define('KRAKEN_SCRIPT', __DIR__ . '/kraken_segment.py');
define('TABLE_SCRIPT', __DIR__ . '/table_cells.py');
define('PDF_SCRIPT', __DIR__ . '/pdf_to_pages.py');

$GLOBALS['__tempFiles'] = [];
register_shutdown_function(function () {
    foreach ($GLOBALS['__tempFiles'] as $t) if (is_file($t)) @unlink($t);
});
function tempFile(string $suffix): string
{
    $p = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'kraken_' . uniqid('', true) . $suffix;
    $GLOBALS['__tempFiles'][] = $p;
    return $p;
}

/**
 * Why a hand-adjusted crop (replace_crop) can't be stored, or null when it can.
 * $dst is the existing preview the crop replaces; $up is $_FILES['image'].
 */
function cropUploadError(string $name, string $dst, ?array $up): ?string
{
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if ($name === '' || !in_array($ext, ['png', 'jpeg', 'webp'], true)) {
        return 'Unknown crop file name "' . $name . '".';
    }
    if (!is_file($dst)) {
        return 'The crop file ' . $name . ' is no longer in uploads/previews/. Previews are cleared when a new '
            . 'scan starts (also from another tab or page), so scan this document again.';
    }
    if (!$up) return 'No image received.';
    return match ($up['error']) {
        UPLOAD_ERR_OK => $up['size'] > 20 * 1024 * 1024 ? 'The new crop is larger than 20 MB.' : null,
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The new crop is larger than the server allows (upload_max_filesize = '
            . ini_get('upload_max_filesize') . ' in php.ini).',
        UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE => 'The server could not store the upload (temp folder missing or not writable).',
        default => 'The upload failed (PHP upload error ' . $up['error'] . ').',
    };
}

/** Run a python script, return [exitCode, stdout+stderr] */
function runPy(string $script, array $args): array
{
    $cmd = escapeshellarg(KRAKEN_PYTHON) . ' ' . escapeshellarg($script);
    foreach ($args as $a) $cmd .= ' ' . escapeshellarg($a);
    exec($cmd . ' 2>&1', $out, $code);
    return [$code, trim(implode("\n", $out))];
}

function jsonFromOutput(string $txt, string $open, string $close)
{
    $s = strpos($txt, $open);
    $e = strrpos($txt, $close);
    if ($s === false || $e === false || $e <= $s) return null;
    return json_decode(substr($txt, $s, $e - $s + 1), true);
}

function loadGd(string $path): ?array
{
    $mime = mime_content_type($path);
    $img = match ($mime) {
        'image/jpeg' => @imagecreatefromjpeg($path),
        'image/png'  => @imagecreatefrompng($path),
        'image/webp' => @imagecreatefromwebp($path),
        default      => false,
    };
    return $img ? [$img, $mime] : null;
}

/** Screen copy of a page (max 1500 px wide JPEG, in memory only) for the viewer. */
function displayCopy(\GdImage $img, int $w, int $h): string
{
    $maxW = 1500;
    $out = $img;
    if ($w > $maxW) {
        $out = imagescale($img, $maxW, (int) round($h * $maxW / $w));
    }
    ob_start();
    imagejpeg($out, null, 82);
    $bin = ob_get_clean();
    if ($out !== $img) unset($out);
    return 'data:image/jpeg;base64,' . base64_encode($bin);
}

/**
 * Moves the edges of a crop rectangle inwards while the outermost pixel row/column is a
 * ruled line (a dark stroke running along most of the edge). Handwriting never fills
 * most of an edge, so words are untouched; only table/form lines are removed.
 * The pixels themselves are never altered - only the crop rectangle changes.
 */
function trimGridLines(\GdImage $img, int $x0, int $y0, int $x1, int $y1): array
{
    $w = $x1 - $x0;
    $h = $y1 - $y0;
    if ($w < 12 || $h < 12) return [$x0, $y0, $x1, $y1];

    $gray = function (int $x, int $y) use ($img): int {
        $c = imagecolorat($img, $x, $y);
        return (int) ((($c >> 16) & 0xFF) * 0.299 + (($c >> 8) & 0xFF) * 0.587 + ($c & 0xFF) * 0.114);
    };
    // paper level = bright end of a sparse sample of the crop
    $samples = [];
    for ($j = 0; $j < 8; $j++) for ($i = 0; $i < 12; $i++) {
        $samples[] = $gray($x0 + (int) (($i + .5) * $w / 12), $y0 + (int) (($j + .5) * $h / 8));
    }
    sort($samples);
    $paper = $samples[(int) (count($samples) * 0.85)];
    $dark = min(150, $paper - 55);
    if ($dark < 30) return [$x0, $y0, $x1, $y1];

    $rowIsLine = function (int $y) use ($gray, $x0, $x1, $dark): bool {
        $n = 0; $d = 0;
        for ($x = $x0; $x < $x1; $x += 2) { $n++; if ($gray($x, $y) < $dark) $d++; }
        return $n > 0 && $d / $n > 0.55;
    };
    $colIsLine = function (int $x) use ($gray, $y0, $y1, $dark): bool {
        $n = 0; $d = 0;
        for ($y = $y0; $y < $y1; $y += 2) { $n++; if ($gray($x, $y) < $dark) $d++; }
        return $n > 0 && $d / $n > 0.55;
    };

    for ($k = 0; $k < 12 && ($y1 - $y0) > 12 && $rowIsLine($y0); $k++) $y0++;
    for ($k = 0; $k < 12 && ($y1 - $y0) > 12 && $rowIsLine($y1 - 1); $k++) $y1--;
    for ($k = 0; $k < 12 && ($x1 - $x0) > 12 && $colIsLine($x0); $k++) $x0++;
    for ($k = 0; $k < 12 && ($x1 - $x0) > 12 && $colIsLine($x1 - 1); $k++) $x1--;
    return [$x0, $y0, $x1, $y1];
}

/**
 * Table pages without ruled rows: the measured cell can straddle two lines of writing (half of
 * one name, half of the next). Re-centres the box on ONE complete text line: looks at the ink
 * profile of a taller region, splits it into lines at the valleys between them, and returns the
 * full band of the line that has most ink inside the original cell.
 */
function alignToTextLine(\GdImage $img, int $x, int $y, int $w, int $h): array
{
    $W = imagesx($img); $H = imagesy($img);
    if ($w < 16 || $h < 12) return [$x, $y, $w, $h];
    $ry0 = max(0, $y - (int) round(0.75 * $h));
    $ry1 = min($H, $y + $h + (int) round(0.75 * $h));
    $rh = $ry1 - $ry0;
    $gray = function (int $px, int $py) use ($img): int {
        $c = imagecolorat($img, $px, $py);
        return (int) ((($c >> 16) & 0xFF) * 0.299 + (($c >> 8) & 0xFF) * 0.587 + ($c & 0xFF) * 0.114);
    };
    $s = [];
    for ($j = 0; $j < 12; $j++) for ($i = 0; $i < 16; $i++) $s[] = $gray(min($W - 1, $x + (int) (($i + .5) * $w / 16)), min($H - 1, $ry0 + (int) (($j + .5) * $rh / 12)));
    sort($s);
    $dark = penThreshold($img, $x, $ry0, $w, $rh);   // pen vs paper (faint ink included)
    if ($dark < 20) return [$x, $y, $w, $h];

    $raw = array_fill(0, $rh, 0);
    for ($r = 0; $r < $rh; $r++) for ($px = $x; $px < $x + $w && $px < $W; $px++) if ($gray($px, $ry0 + $r) < $dark) $raw[$r]++;
    $k = max(2, (int) round($h * 0.06));
    $sm = [];
    for ($r = 0; $r < $rh; $r++) {
        $a = max(0, $r - $k); $b = min($rh - 1, $r + $k);
        $sm[$r] = array_sum(array_slice($raw, $a, $b - $a + 1)) / ($b - $a + 1);
    }
    $max = max($sm);
    if ($max < 2) return [$x, $y, $w, $h];

    // line peaks = local maxima of the smoothed profile
    $win = max(4, (int) round(0.18 * $h));
    $peaks = [];
    for ($r = 0; $r < $rh; $r++) {
        if ($sm[$r] < 0.3 * $max) continue;
        $isPeak = true;
        for ($d = max(0, $r - $win); $d <= min($rh - 1, $r + $win); $d++) {
            if ($sm[$d] > $sm[$r] || ($sm[$d] == $sm[$r] && $d < $r)) { $isPeak = false; break; }
        }
        if ($isPeak) $peaks[] = $r;
    }
    if (count($peaks) < 2) return [$x, $y, $w, $h];     // a single line: nothing to re-centre

    // bands between valleys
    $bands = [];
    $start = 0;
    for ($i = 0; $i < count($peaks); $i++) {
        $end = $rh;
        if ($i + 1 < count($peaks)) {
            $vmin = PHP_FLOAT_MAX; $end = $peaks[$i];
            for ($r = $peaks[$i]; $r <= $peaks[$i + 1]; $r++) if ($sm[$r] < $vmin) { $vmin = $sm[$r]; $end = $r; }
        }
        $bands[] = [$start, $end];
        $start = $end;
    }
    // choose the band with most ink inside the original cell rows
    $c0 = $y - $ry0; $c1 = $c0 + $h;
    $best = null; $bestMass = -1;
    foreach ($bands as [$a, $b]) {
        $m = 0;
        for ($r = max($a, $c0); $r < min($b, $c1); $r++) $m += $raw[$r];
        if ($m > $bestMass) { $bestMass = $m; $best = [$a, $b]; }
    }
    [$a, $b] = $best;
    while ($a < $b && $raw[$a] < 2) $a++;
    while ($b > $a && $raw[$b - 1] < 2) $b--;
    if ($b - $a < 8) return [$x, $y, $w, $h];
    $a = max(0, $a - 2); $b = min($rh, $b + 2);
    // a text line is never much taller than its cell, and it must overlap the cell: otherwise the
    // profile was confused (stains, folds, ruled lines) and the measured cell is kept
    $ny = $ry0 + $a; $nh = $b - $a;
    $overlap = min($ny + $nh, $y + $h) - max($ny, $y);
    if ($nh > 1.45 * $h || $overlap < 0.5 * min($nh, $h)) return [$x, $y, $w, $h];
    return [$x, $ny, $w, $nh];
}

/**
 * A crop must follow the grid: it may hold only ONE column. Looks for vertical ruled lines
 * inside (or at the edge of) the box - columns that are darker than their surroundings on most
 * rows over a tall stretch of the page, which handwriting never is - and clips the box to the
 * segment between two rules that holds the most writing (the name, not the next column).
 */
function clipToColumn(\GdImage $img, int $x, int $y, int $w, int $h): array
{
    $W = imagesx($img); $H = imagesy($img);
    if ($w < 30 || $h < 8) return [$x, $y, $w, $h];
    $ry0 = max(0, $y - 3 * $h); $ry1 = min($H, $y + 4 * $h);
    if ($ry1 - $ry0 < 5 * $h / 2) return [$x, $y, $w, $h];
    $gray = function (int $px, int $py) use ($img, $W): int {
        $px = max(0, min($W - 1, $px));
        $c = imagecolorat($img, $px, $py);
        return (int) ((($c >> 16) & 0xFF) * 0.299 + (($c >> 8) & 0xFF) * 0.587 + ($c & 0xFF) * 0.114);
    };
    $x0 = max(0, $x - 4); $x1 = min($W, $x + $w + 4);
    $isRule = [];
    for ($cx = $x0; $cx < $x1; $cx++) {
        $n = 0; $d = 0;
        for ($ry = $ry0; $ry < $ry1; $ry += 2) {
            $n++;
            $side = ($gray($cx - 7, $ry) + $gray($cx + 7, $ry)) / 2;
            if ($gray($cx, $ry) + 22 < $side) $d++;
        }
        $isRule[$cx] = $n && $d / $n > 0.62;
    }
    // merge adjacent rule columns into one line, remember its edges
    $rules = []; $cur = null;
    for ($cx = $x0; $cx < $x1; $cx++) {
        if ($isRule[$cx]) { if ($cur === null) $cur = [$cx, $cx + 1]; else $cur[1] = $cx + 1; }
        elseif ($cur !== null) { $rules[] = $cur; $cur = null; }
    }
    if ($cur !== null) $rules[] = $cur;
    if (!$rules) return [$x, $y, $w, $h];

    // segments between rules (limits = box edges)
    $cuts = [$x];
    foreach ($rules as [$a, $b]) { $cuts[] = $a; $cuts[] = $b; }
    $cuts[] = $x + $w;
    $segs = [];
    for ($i = 0; $i + 1 < count($cuts); $i += 2) {
        $a = $cuts[$i] + ($i > 0 ? 3 : 0); $b = $cuts[$i + 1] - ($i + 2 < count($cuts) ? 3 : 0);
        if ($b - $a >= 12) $segs[] = [$a, $b];
    }
    if (count($segs) < 1) return [$x, $y, $w, $h];
    // paper level for ink counting
    $smp = [];
    for ($j = 0; $j < 6; $j++) for ($i = 0; $i < 12; $i++) $smp[] = $gray($x + (int) (($i + .5) * $w / 12), $y + (int) (($j + .5) * $h / 6));
    sort($smp);
    $dark = penThreshold($img, $x, $y, $w, $h);
    $best = $segs[0]; $bestM = -1;
    foreach ($segs as [$a, $b]) {
        $m = 0;
        for ($py = $y; $py < $y + $h && $py < $H; $py += 2) for ($px = $a; $px < $b; $px++) if ($gray($px, $py) < $dark) $m++;
        if ($m > $bestM) { $bestM = $m; $best = [$a, $b]; }
    }
    return [$best[0], $y, $best[1] - $best[0], $h];
}

/**
 * A crop must hold ONE entry. If a horizontal ruled line runs through the box (a row border
 * splitting e.g. "5" from "6"), the box is cut at the line and only the segment with the most
 * writing is kept. A rule = a row that is darker than the rows 6px above and below it across
 * most of the width; handwriting never is.
 */
function clipToRow(\GdImage $img, int $x, int $y, int $w, int $h): array
{
    $W = imagesx($img); $H = imagesy($img);
    if ($w < 12 || $h < 20) return [$x, $y, $w, $h];
    $gray = function (int $px, int $py) use ($img, $H): int {
        $py = max(0, min($H - 1, $py));
        $c = imagecolorat($img, $px, $py);
        return (int) ((($c >> 16) & 0xFF) * 0.299 + (($c >> 8) & 0xFF) * 0.587 + ($c & 0xFF) * 0.114);
    };
    $isRule = [];
    for ($ry = $y; $ry < $y + $h; $ry++) {
        $n = 0; $d = 0;
        for ($px = $x; $px < $x + $w; $px += 2) {
            $n++;
            if ($gray($px, $ry) + 22 < ($gray($px, $ry - 6) + $gray($px, $ry + 6)) / 2) $d++;
        }
        $isRule[$ry] = $n && $d / $n > 0.62;
    }
    $rules = []; $cur = null;
    for ($ry = $y; $ry < $y + $h; $ry++) {
        if ($isRule[$ry]) { if ($cur === null) $cur = [$ry, $ry + 1]; else $cur[1] = $ry + 1; }
        elseif ($cur !== null) { $rules[] = $cur; $cur = null; }
    }
    if ($cur !== null) $rules[] = $cur;
    if (!$rules) return [$x, $y, $w, $h];

    // writing that sits ON the line (strokes cross it) belongs together: never cut through it
    $smp0 = [];
    for ($j = 0; $j < 6; $j++) for ($i = 0; $i < 12; $i++) $smp0[] = $gray($x + (int) (($i + .5) * $w / 12), $y + (int) (($j + .5) * $h / 6));
    sort($smp0);
    $dk0 = penThreshold($img, $x, $y, $w, $h);
    $rules = array_values(array_filter($rules, function ($r) use ($gray, $x, $w, $y, $h, $dk0) {
        [$a, $b] = $r;
        $cross = 0;
        for ($px = $x; $px < $x + $w; $px++) {
            $up = false; $dn = false;
            for ($py = max($y, $a - 4); $py < $a; $py++) if ($gray($px, $py) < $dk0) { $up = true; break; }
            if (!$up) continue;
            for ($py = $b; $py < min($y + $h, $b + 4); $py++) if ($gray($px, $py) < $dk0) { $dn = true; break; }
            if ($dn) $cross++;
        }
        return $cross < max(3, (int) round(0.03 * $w));
    }));
    if (!$rules) return [$x, $y, $w, $h];

    $cuts = [$y];
    foreach ($rules as [$a, $b]) { $cuts[] = $a; $cuts[] = $b; }
    $cuts[] = $y + $h;
    $segs = [];
    for ($i = 0; $i + 1 < count($cuts); $i += 2) {
        $a = $cuts[$i] + ($i > 0 ? 3 : 0); $b = $cuts[$i + 1] - ($i + 2 < count($cuts) ? 3 : 0);
        if ($b - $a >= 8) $segs[] = [$a, $b];
    }
    if (!$segs) return [$x, $y, $w, $h];
    $smp = [];
    for ($j = 0; $j < 6; $j++) for ($i = 0; $i < 12; $i++) $smp[] = $gray($x + (int) (($i + .5) * $w / 12), $y + (int) (($j + .5) * $h / 6));
    sort($smp);
    $dark = penThreshold($img, $x, $y, $w, $h);
    $best = $segs[0]; $bestM = -1;
    foreach ($segs as [$a, $b]) {
        $m = 0;
        for ($py = $a; $py < $b; $py++) for ($px = $x; $px < $x + $w; $px += 2) if ($gray($px, $py) < $dark) $m++;
        if ($m > $bestM) { $bestM = $m; $best = [$a, $b]; }
    }
    return [$x, $best[0], $w, $best[1] - $best[0]];
}

/**
 * Grows a tight word box outwards while pen strokes still continue across its edge, so
 * ascenders, descenders and flourishes the segmenter missed end up inside the crop.
 * Growth stops at the first ink-free gap (2px tolerance), at $lim px/side, or at the cell.
 */
function growToInk(\GdImage $img, int $x, int $y, int $w, int $h, int $limX, int $limY, ?array $cell): array
{
    $W = imagesx($img); $H = imagesy($img);
    $x1 = $x + $w; $y1 = $y + $h;
    if ($w < 4 || $h < 4) return [$x, $y, $w, $h];
    $gray = function (int $px, int $py) use ($img): int {
        $c = imagecolorat($img, $px, $py);
        return (int) ((($c >> 16) & 0xFF) * 0.299 + (($c >> 8) & 0xFF) * 0.587 + ($c & 0xFF) * 0.114);
    };
    $samples = [];
    for ($j = 0; $j < 8; $j++) for ($i = 0; $i < 16; $i++) {
        $samples[] = $gray(min($W - 1, $x + (int) (($i + .5) * $w / 16)), min($H - 1, $y + (int) (($j + .5) * $h / 8)));
    }
    sort($samples);
    $paper = $samples[(int) (count($samples) * 0.8)];
    $dark = penThreshold($img, $x, $y, $w, $h);
    if ($dark < 20) return [$x, $y, $w, $h];

    $minX = $cell ? max(0, (int) $cell[0] + 3) : 0;
    $minY = $cell ? max(0, (int) $cell[1] + 3) : 0;
    $maxX = $cell ? min($W, (int) $cell[2] - 3) : $W;
    $maxY = $cell ? min($H, (int) $cell[3] - 3) : $H;

    // 0 = empty, 1 = pen ink, 2 = a ruled line (dark along most of the strip): never grow into lines
    $rowInk = function (int $py, int $a, int $b) use ($gray, $dark): int {
        $n = 0; $t = 0;
        for ($px = $a; $px < $b; $px++) { $t++; if ($gray($px, $py) < $dark) $n++; }
        return $t && $n / $t > 0.55 ? 2 : ($n >= 2 ? 1 : 0);
    };
    $colInk = function (int $px, int $a, int $b) use ($gray, $dark): int {
        $n = 0; $t = 0;
        for ($py = $a; $py < $b; $py++) { $t++; if ($gray($px, $py) < $dark) $n++; }
        return $t && $n / $t > 0.55 ? 2 : ($n >= 2 ? 1 : 0);
    };

    // vertical first (ascenders / descenders), then horizontal with the new height
    $g = 0;
    for ($gap = 0; $y > $minY && $g < $limY && $gap < 3; $g++) {
        $v = $rowInk($y - 1, $x, $x1); if ($v === 2) break;
        $y--; $gap = $v ? 0 : $gap + 1;
    }
    $y += $gap;
    $g = 0;
    for ($gap = 0; $y1 < $maxY && $g < $limY && $gap < 3; $g++) {
        $v = $rowInk($y1, $x, $x1); if ($v === 2) break;
        $y1++; $gap = $v ? 0 : $gap + 1;
    }
    $y1 -= $gap;
    $g = 0;
    for ($gap = 0; $x > $minX && $g < $limX && $gap < 3; $g++) {
        $v = $colInk($x - 1, $y, $y1); if ($v === 2) break;
        $x--; $gap = $v ? 0 : $gap + 1;
    }
    $x += $gap;
    $g = 0;
    for ($gap = 0; $x1 < $maxX && $g < $limX && $gap < 3; $g++) {
        $v = $colInk($x1, $y, $y1); if ($v === 2) break;
        $x1++; $gap = $v ? 0 : $gap + 1;
    }
    $x1 -= $gap;
    return [$x, $y, $x1 - $x, $y1 - $y];
}

/**
 * Pen-ink threshold for a region: Otsu split between pen and paper (so dark paper texture and
 * stains are not mistaken for writing), never lighter than 35 below the paper level.
 */
function penThreshold(\GdImage $img, int $x, int $y, int $w, int $h): int
{
    $W = imagesx($img); $H = imagesy($img);
    $hist = array_fill(0, 256, 0); $n = 0;
    $sx = max(1, (int) ($w / 80)); $sy = max(1, (int) ($h / 40));
    for ($py = max(0, $y); $py < min($H, $y + $h); $py += $sy) for ($px = max(0, $x); $px < min($W, $x + $w); $px += $sx) {
        $c = imagecolorat($img, $px, $py);
        $hist[(int) ((($c >> 16) & 0xFF) * 0.299 + (($c >> 8) & 0xFF) * 0.587 + ($c & 0xFF) * 0.114)]++; $n++;
    }
    if ($n < 20) return 0;
    $sum = 0; for ($i = 0; $i < 256; $i++) $sum += $i * $hist[$i];
    $wB = 0; $sB = 0; $best = 0; $thr = 0;
    for ($t = 0; $t < 256; $t++) {
        $wB += $hist[$t]; if (!$wB) continue;
        $wF = $n - $wB; if (!$wF) break;
        $sB += $t * $hist[$t];
        $d = ($sB / $wB) - (($sum - $sB) / $wF);
        $v = $wB * $wF * $d * $d;
        if ($v > $best) { $best = $v; $thr = $t; }
    }
    $acc = 0; $paper = 255;
    for ($i = 0; $i < 256; $i++) { $acc += $hist[$i]; if ($acc >= 0.8 * $n) { $paper = $i; break; } }
    return min($thr, $paper - 35);
}

/**
 * Keeps ONE word per crop: drops ink clusters that belong to neighbouring text (the cut-off
 * strokes of the line above/below or of the next word that poke into the crop edge).
 * Rows are clustered by empty gaps and the heaviest cluster is kept; then columns, where a
 * cluster is only dropped when it is a small fragment next to the main word.
 * Only the crop rectangle changes - the pixels are never touched.
 */
function isolateWord(\GdImage $img, int $x0, int $y0, int $x1, int $y1): array
{
    $w = $x1 - $x0; $h = $y1 - $y0;
    if ($w < 16 || $h < 12) return [$x0, $y0, $x1, $y1];
    $gray = function (int $x, int $y) use ($img): int {
        $c = imagecolorat($img, $x, $y);
        return (int) ((($c >> 16) & 0xFF) * 0.299 + (($c >> 8) & 0xFF) * 0.587 + ($c & 0xFF) * 0.114);
    };
    $dark = penThreshold($img, $x0, $y0, $w, $h);
    if ($dark < 20) return [$x0, $y0, $x1, $y1];

    // Ink = darker than the pen threshold AND clearly darker than the paper right around it
    // (local mean). Stains, shadows and torn edges darken their surroundings too, so they are
    // not mistaken for writing; thin pen strokes always stand out from their neighbourhood.
    $G = []; $I = array_fill(0, ($w + 1) * ($h + 1), 0);
    for ($yy = 0; $yy < $h; $yy++) {
        $acc = 0;
        for ($xx = 0; $xx < $w; $xx++) {
            $v = $gray($x0 + $xx, $y0 + $yy); $G[$yy * $w + $xx] = $v; $acc += $v;
            $I[($yy + 1) * ($w + 1) + $xx + 1] = $I[$yy * ($w + 1) + $xx + 1] + $acc;
        }
    }
    $Rr = max(5, min(14, (int) round(0.22 * $h)));
    $mean = function (int $xx, int $yy) use ($I, $w, $h, $Rr): float {
        $a0 = max(0, $xx - $Rr); $b0 = max(0, $yy - $Rr); $a1 = min($w, $xx + $Rr + 1); $b1 = min($h, $yy + $Rr + 1);
        $s = $I[$b1 * ($w + 1) + $a1] - $I[$b0 * ($w + 1) + $a1] - $I[$b1 * ($w + 1) + $a0] + $I[$b0 * ($w + 1) + $a0];
        return $s / (($a1 - $a0) * ($b1 - $b0));
    };
    $inkC = []; $looseInkC = []; $ruleCol = [];
    $ink = function (int $xx, int $yy) use (&$inkC, &$ruleCol, $G, $w, $dark, $mean): bool {
        if (isset($ruleCol[$xx])) return false;
        $k = $yy * $w + $xx;
        if (!isset($inkC[$k])) { $v = $G[$k]; $inkC[$k] = $v < $dark && $v < $mean($xx, $yy) - 14; }
        return $inkC[$k];
    };
    $looseInk = function (int $xx, int $yy) use (&$looseInkC, &$ruleCol, $G, $w, $dark, $mean): bool {
        if (isset($ruleCol[$xx])) return false;
        $k = $yy * $w + $xx;
        if (!isset($looseInkC[$k])) { $v = $G[$k]; $looseInkC[$k] = $v < min(215, $dark + 12) && $v < $mean($xx, $yy) - 7; }
        return $looseInkC[$k];
    };
    // a vertical ruled line inside the box (inked on most rows, which writing never is) is not
    // writing: its pixel columns are ignored everywhere below (+-1px for its soft edges)
    for ($xx = 0; $xx < $w; $xx++) {
        $n = 0; for ($yy = 0; $yy < $h; $yy++) if ($ink($xx, $yy)) $n++;
        if ($n > 0.6 * $h) { $ruleCol[$xx] = true; }
    }
    foreach (array_keys($ruleCol) as $xx) { $ruleCol[max(0, $xx - 1)] = true; $ruleCol[min($w - 1, $xx + 1)] = true; }
    $minPx = max(2, (int) round(0.008 * $w));

    // clusters of a 1-D ink profile: [[start, end(excl), mass], ...]
    $clusters = function (array $prof, int $gapMin, int $minV = 2): array {
        $out = []; $cur = null; $gap = 0;
        foreach ($prof as $i => $v) {
            if ($v >= $minV) {
                if ($cur === null) $cur = [$i, $i + 1, $v];
                else { $cur[1] = $i + 1; $cur[2] += $v; }
                $gap = 0;
            } elseif ($cur !== null && ++$gap >= $gapMin) { $out[] = $cur; $cur = null; $gap = 0; }
        }
        if ($cur !== null) $out[] = $cur;
        return $out;
    };

    // rows: ink per row, then ONE text line is picked (a crop holds one word / one row entry)
    $rows = array_fill(0, $h, 0);
    for ($y = 0; $y < $h; $y++) for ($x = 0; $x < $w; $x++) if ($ink($x, $y)) $rows[$y]++;
    // a ruled row line is dark across the whole width: it is not writing, so it must not look
    // like a text line. Its rows get the ink level of the rows just outside it.
    for ($y = 0; $y < $h; $y++) {
        if ($rows[$y] < 0.5 * $w) continue;
        $e = $y; while ($e + 1 < $h && $rows[$e + 1] >= 0.5 * $w) $e++;
        // writing on the line keeps the line's rows "inked" (no false gap); a bare line becomes a gap
        $before = $y - 3 >= 0 ? $rows[$y - 3] : 0; $after = $e + 3 < $h ? $rows[$e + 3] : 0;
        $cross = 0;                                   // pen strokes passing through the line
        for ($x = 0; $x < $w; $x++) {
            $u = false; $dn = false;
            for ($r = max(0, $y - 3); $r < $y; $r++) if ($ink($x, $r)) { $u = true; break; }
            if (!$u) continue;
            for ($r = $e + 1; $r <= min($h - 1, $e + 3); $r++) if ($ink($x, $r)) { $dn = true; break; }
            if ($dn) $cross++;
        }
        $fill = (int) ($cross >= max(3, (int) round(0.04 * $w)) ? max($before, $after) : min($before, $after));
        for ($r = $y; $r <= $e; $r++) $rows[$r] = $fill;
        $y = $e;
    }
    $k = max(1, (int) round(0.04 * $h));
    $sm = [];
    for ($r = 0; $r < $h; $r++) {
        $p = max(0, $r - $k); $q = min($h - 1, $r + $k);
        $sm[$r] = array_sum(array_slice($rows, $p, $q - $p + 1)) / ($q - $p + 1);
    }
    $mx = max($sm);
    if ($mx < $minPx) return [$x0, $y0, $x1, $y1];
    // peaks of the profile, merged unless a clear valley separates them (a real gap between lines)
    $win = max(3, (int) round(0.1 * $h));
    $peaks = [];
    for ($r = 0; $r < $h; $r++) {
        if ($sm[$r] < 0.25 * $mx) continue;   // weak bumps (stray strokes between lines) are not lines
        $top = true;
        for ($d = max(0, $r - $win); $d <= min($h - 1, $r + $win); $d++) {
            if ($sm[$d] > $sm[$r] || ($sm[$d] == $sm[$r] && $d < $r)) { $top = false; break; }
        }
        if ($top) $peaks[] = $r;
    }
    if (!$peaks) return [$x0, $y0, $x1, $y1];
    $groups = [[$peaks[0]]];                       // each group = the peaks of one text line
    for ($i = 1; $i < count($peaks); $i++) {
        $gi = count($groups) - 1; $pb = $peaks[$i];
        $pa = $groups[$gi][0]; foreach ($groups[$gi] as $q) if ($sm[$q] > $sm[$pa]) $pa = $q;   // the line's strongest peak
        $vmin = min(array_slice($sm, $pa, $pb - $pa + 1));
        $lo = min($sm[$pa], $sm[$pb]);
        // a new text line: a deep valley, or a moderate one when the peaks are a line-pitch apart
        // (the two bars of one letter, e.g. an "F", are close together and stay one line)
        $far = ($pb - $pa) >= max(14, (int) round(0.35 * $h));
        if ($vmin < 0.3 * $lo || ($far && $vmin < 0.6 * $lo)) $groups[] = [$pb];
        else $groups[count($groups) - 1][] = $pb;
    }
    // line bands are cut at the deepest valley between neighbouring groups
    $bands = [];
    $startRow = 0;
    for ($i = 0; $i < count($groups); $i++) {
        $endRow = $h;
        if ($i + 1 < count($groups)) {
            $pa = end($groups[$i]); $pb = $groups[$i + 1][0];
            $vm = PHP_FLOAT_MAX; $endRow = $pa;
            for ($r = $pa; $r <= $pb; $r++) if ($sm[$r] < $vm) { $vm = $sm[$r]; $endRow = $r; }
        }
        $gp = $groups[$i][0]; foreach ($groups[$i] as $q) if ($sm[$q] > $sm[$gp]) $gp = $q;
        $bands[] = [$startRow, $endRow, $gp];
        $startRow = $endRow;
    }
    // tight extent (>= 6% of the band's own heaviest row) and ink mass of every band
    $info = [];
    foreach ($bands as [$bs, $be, $core]) {
        // the line's core = its strongest peak in the smoothed profile (a stray ruled line is
        // never mistaken for it); the walk-out threshold uses the raw rows around that core
        $seg = array_slice($rows, $bs, max(1, $be - $bs), true);
        $pk = max(1, max(array_slice($rows, max($bs, $core - 2), min($be, $core + 3) - max($bs, $core - 2))));
        // walk out from the line's core; stop at a 2-row gap, so loose fragments of the
        // neighbouring line (separated by paper) stay out while connected tips stay in
        $thr = max(1, 0.06 * $pk);
        $e0 = $core; while ($e0 - 1 >= $bs && !($rows[$e0 - 1] < $thr && ($e0 - 2 < $bs || $rows[$e0 - 2] < $thr))) $e0--;
        $e1 = $core + 1; while ($e1 < $be && !($rows[$e1] < $thr && ($e1 + 1 >= $be || $rows[$e1 + 1] < $thr))) $e1++;
        $info[] = ['bs' => $bs, 'be' => $be, 'e0' => $e0, 'e1' => $e1, 'core' => $core, 'm' => array_sum(array_slice($rows, $bs, $be - $bs))];
    }
    // the box is centred on the entry it belongs to: among the real lines, take the one nearest the middle
    $mm = max(array_column($info, 'm'));
    $pick = null; $bestD = PHP_FLOAT_MAX;
    foreach ($info as $in) {
        if ($in['m'] < 0.35 * $mm) continue;
        $dd = abs(($in['e0'] + $in['e1']) / 2 - $h / 2);
        if ($dd < $bestD) { $bestD = $dd; $pick = $in; }
    }
    $lim0 = $pick['bs']; $lim1 = $pick['be'];      // never reach into the neighbouring line

    // Height of the word = the pen shapes that belong to it. The line's core zone is where
    // its letters sit (rows >= 35% of its heaviest row). Every separate ink shape that reaches
    // into that zone is part of the word (with its full ascenders/descenders); shapes that do
    // not (bits of the line above/below poking in at the edge) are left out.
    $core = $pick['core'];
    $pk = max(1, max(array_slice($rows, max($lim0, $core - 2), min($lim1, $core + 3) - max($lim0, $core - 2))));
    $z0 = $core; while ($z0 - 1 >= $lim0 && $rows[$z0 - 1] >= 0.35 * $pk) $z0--;
    $z1 = $core; while ($z1 + 1 < $lim1 && $rows[$z1 + 1] >= 0.35 * $pk) $z1++;
    $lab = []; $top = $h; $bot = -1; $mine = [];
    $looseC = min(215, $dark + 12);   // faint strokes still join their letter
    for ($sy = $z0; $sy <= $z1; $sy++) for ($sx = 0; $sx < $w; $sx++) {
        $key = $sy * $w + $sx;
        if (isset($lab[$key]) || !$ink($sx, $sy)) continue;
        $stack = [[$sx, $sy]]; $lab[$key] = 1; $n = 0; $ct = $sy; $cb = $sy; $px = [];
        while ($stack) {
            [$cx, $cy] = array_pop($stack); $n++; $px[] = $cy * $w + $cx;
            if ($cy < $ct) $ct = $cy; if ($cy > $cb) $cb = $cy;
            for ($dy = -1; $dy <= 1; $dy++) for ($dx = -1; $dx <= 1; $dx++) {
                $nx = $cx + $dx; $ny = $cy + $dy;
                if ($nx < 0 || $nx >= $w || $ny < $lim0 || $ny >= $lim1) continue;
                $k2 = $ny * $w + $nx;
                if (isset($lab[$k2])) continue;
                if ($looseInk($nx, $ny)) { $lab[$k2] = 1; $stack[] = [$nx, $ny]; }
            }
        }
        if ($n >= 4) { $top = min($top, $ct); $bot = max($bot, $cb); foreach ($px as $p) $mine[$p] = true; }
    }
    if ($bot < 0) { $top = $pick['e0']; $bot = $pick['e1'] - 1; }
    // room for faint stroke tips, but the margin stops at the first row holding ink that is
    // NOT part of this word (the neighbouring line's ascenders/descenders)
    $mg = max(4, (int) round(0.15 * ($bot - $top + 1)));
    $foreign = function (int $r) use ($w, $ink, &$mine): bool {
        for ($xx = 0; $xx < $w; $xx++) if ($ink($xx, $r) && !isset($mine[$r * $w + $xx])) return true;
        return false;
    };
    $t0 = $top; for ($i = 1; $i <= $mg && $top - $i >= $lim0; $i++) { if ($foreign($top - $i)) break; $t0 = $top - $i; }
    $t1 = $bot; for ($i = 1; $i <= $mg && $bot + $i < $lim1; $i++) { if ($foreign($bot + $i)) break; $t1 = $bot + $i; }
    $ny0 = $y0 + $t0;
    $ny1 = $y0 + $t1 + 1;
    $nh = $ny1 - $ny0;
    if ($nh < 8) return [$x0, $y0, $x1, $y1];

    // columns (inside the kept band)
    $cols = array_fill(0, $w, 0);
    for ($x = 0; $x < $w; $x++) for ($y = $ny0; $y < $ny1; $y++) if ($ink($x, $y - $y0)) $cols[$x]++;
    $cc = $clusters($cols, max(6, (int) round(0.8 * $nh)));
    if (!$cc) return [$x0, $ny0, $x1, $ny1];
    $big = max(array_column($cc, 2));
    $keep = array_values(array_filter($cc, fn($c) => $c[2] >= 0.1 * $big));
    $nx0 = $x0 + max(0, $keep[0][0] - 3);
    $nx1 = $x0 + min($w, end($keep)[1] + 3);

    // ── Completeness: follow the pen. Every stroke connected to the word is pulled inside the
    // crop (long ascenders, tails of g/y, flourishes, the last letter), with a more sensitive
    // ink test than the clustering above, but never past the neighbouring line or word.
    $limL = 0; $limR = $w;
    foreach ($cc as $c) {
        if ($c[2] >= 0.1 * $big) continue;                       // kept clusters are not limits
        if ($c[1] <= $keep[0][0]) $limL = max($limL, $c[1]);
        if ($c[0] >= end($keep)[1]) $limR = min($limR, $c[0]);
    }
    $loose = min(215, $dark + 14);
    $fy0 = max(0, $lim0); $fy1 = min($h, $lim1);                  // rows we may use (relative)
    $fx0 = $limL; $fx1 = $limR;                                   // columns we may use (relative)
    $seen = []; $stack = [];
    for ($y = $ny0 - $y0; $y < $ny1 - $y0; $y++) for ($x = $nx0 - $x0; $x < $nx1 - $x0; $x++) {
        if ($y < $fy0 || $y >= $fy1 || $x < $fx0 || $x >= $fx1) continue;
        if ($ink($x, $y)) { $seen[$y * $w + $x] = 1; $stack[] = [$x, $y]; }
    }
    $bx0 = $w; $bx1 = 0; $by0 = $h; $by1 = 0;
    while ($stack) {
        [$cx, $cy] = array_pop($stack);
        if ($cx < $bx0) $bx0 = $cx; if ($cx > $bx1) $bx1 = $cx;
        if ($cy < $by0) $by0 = $cy; if ($cy > $by1) $by1 = $cy;
        for ($dy = -1; $dy <= 1; $dy++) for ($dx = -1; $dx <= 1; $dx++) {
            $nx = $cx + $dx; $ny = $cy + $dy;
            if ($nx < $fx0 || $nx >= $fx1 || $ny < $fy0 || $ny >= $fy1) continue;
            $k = $ny * $w + $nx;
            if (isset($seen[$k])) continue;
            $seen[$k] = 1;
            if ($looseInk($nx, $ny)) $stack[] = [$nx, $ny];
        }
    }
    if ($bx1 >= $bx0 && $by1 >= $by0) {
        $nx0 = min($nx0, $x0 + max($fx0, $bx0 - 2)); $nx1 = max($nx1, $x0 + min($fx1, $bx1 + 3));
        $ny0 = min($ny0, $y0 + max($fy0, $by0 - 2)); $ny1 = max($ny1, $y0 + min($fy1, $by1 + 3));
    }
    return [$nx0, $ny0, $nx1, $ny1];
}

/**
 * Last step: make the word COMPLETE. Everything the pen drew that is connected to the crop's
 * writing is pulled in, even when it runs past the measured cell (long names that overflow into
 * the next column, trailing letters, tails). Ruled lines are masked out first so the fill can
 * never travel along a table line, and the search window is limited so neighbouring lines and
 * words are not swallowed. Only the crop rectangle changes.
 */
function completeWord(\GdImage $img, int $x0, int $y0, int $x1, int $y1, ?int $yLo = null, ?int $yHi = null, ?int $xLo = null, ?int $xHi = null): array
{
    $W = imagesx($img); $H = imagesy($img);
    $w = $x1 - $x0; $h = $y1 - $y0;
    if ($w < 8 || $h < 8) return [$x0, $y0, $x1, $y1];
    $ex = (int) round(0.6 * $h) + 14;
    $ey = (int) round(0.3 * $h) + 4;
    $wx0 = max(0, $x0 - $ex, $xLo ?? 0); $wx1 = min($W, $x1 + $ex, $xHi ?? $W);
    // vertical growth is capped (a word is one line): never swallow the line above/below
    $wy0 = max(0, $yLo ?? ($y0 - $ey), $y0 - $ey); $wy1 = min($H, $yHi ?? ($y1 + $ey), $y1 + $ey);
    $ww = $wx1 - $wx0; $wh = $wy1 - $wy0;
    $dark = penThreshold($img, $x0, $y0, $w, $h);
    if ($dark < 20) return [$x0, $y0, $x1, $y1];
    $loose = min(215, $dark + 14);

    $g = [];   // gray cache of the window
    for ($y = 0; $y < $wh; $y++) { $row = []; for ($x = 0; $x < $ww; $x++) {
        $c = imagecolorat($img, $wx0 + $x, $wy0 + $y);
        $row[] = (int) ((($c >> 16) & 0xFF) * 0.299 + (($c >> 8) & 0xFF) * 0.587 + ($c & 0xFF) * 0.114);
    } $g[] = $row; }

    // local paper level (integral image): stains/shadows are not pen strokes
    $I = array_fill(0, ($ww + 1) * ($wh + 1), 0);
    for ($yy = 0; $yy < $wh; $yy++) { $acc = 0; for ($xx = 0; $xx < $ww; $xx++) { $acc += $g[$yy][$xx];
        $I[($yy + 1) * ($ww + 1) + $xx + 1] = $I[$yy * ($ww + 1) + $xx + 1] + $acc; } }
    $Rr = max(5, min(14, (int) round(0.22 * $h)));
    $mean = function (int $xx, int $yy) use ($I, $ww, $wh, $Rr): float {
        $a0 = max(0, $xx - $Rr); $b0 = max(0, $yy - $Rr); $a1 = min($ww, $xx + $Rr + 1); $b1 = min($wh, $yy + $Rr + 1);
        return ($I[$b1 * ($ww + 1) + $a1] - $I[$b0 * ($ww + 1) + $a1] - $I[$b1 * ($ww + 1) + $a0] + $I[$b0 * ($ww + 1) + $a0]) / (($a1 - $a0) * ($b1 - $b0));
    };

    // ruled lines inside the window are not writing: vertical rules ...
    $ruleX = []; $ruleY = [];
    $vy0 = max(0, $wy0 - 2 * $h); $vy1 = min($H, $wy1 + 2 * $h);
    for ($x = 0; $x < $ww; $x++) {
        $px = $wx0 + $x; $n = 0; $d = 0;
        for ($ry = $vy0; $ry < $vy1; $ry += 2) {
            $n++;
            $a = imagecolorat($img, max(0, $px - 7), $ry); $b = imagecolorat($img, min($W - 1, $px + 7), $ry); $m = imagecolorat($img, $px, $ry);
            $ga = (($a >> 16 & 255) * .299 + ($a >> 8 & 255) * .587 + ($a & 255) * .114);
            $gb = (($b >> 16 & 255) * .299 + ($b >> 8 & 255) * .587 + ($b & 255) * .114);
            $gm = (($m >> 16 & 255) * .299 + ($m >> 8 & 255) * .587 + ($m & 255) * .114);
            if ($gm + 22 < ($ga + $gb) / 2) $d++;
        }
        if ($n && $d / $n > 0.62) $ruleX[$x] = true;
    }
    // ... and horizontal rules
    for ($y = 0; $y < $wh; $y++) {
        $d = 0; $n = 0;
        for ($x = 0; $x < $ww; $x += 2) {
            $n++;
            $up = $g[max(0, $y - 6)][$x]; $dn = $g[min($wh - 1, $y + 6)][$x];
            if ($g[$y][$x] + 22 < ($up + $dn) / 2) $d++;
        }
        if ($n && $d / $n > 0.62) $ruleY[$y] = true;
    }

    $seen = []; $stack = [];
    for ($y = $y0 - $wy0; $y < $y1 - $wy0; $y++) for ($x = $x0 - $wx0; $x < $x1 - $wx0; $x++) {
        if (isset($ruleX[$x]) || isset($ruleY[$y])) continue;
        if ($g[$y][$x] < $dark && $g[$y][$x] < $mean($x, $y) - 14) { $seen[$y * $ww + $x] = 1; $stack[] = [$x, $y]; }
    }
    $bx0 = $ww; $bx1 = 0; $by0 = $wh; $by1 = 0;
    while ($stack) {
        [$cx, $cy] = array_pop($stack);
        if ($cx < $bx0) $bx0 = $cx; if ($cx > $bx1) $bx1 = $cx;
        if ($cy < $by0) $by0 = $cy; if ($cy > $by1) $by1 = $cy;
        for ($dy = -1; $dy <= 1; $dy++) for ($dx = -1; $dx <= 1; $dx++) {
            $nx = $cx + $dx; $ny = $cy + $dy;
            if ($nx < 0 || $nx >= $ww || $ny < 0 || $ny >= $wh) continue;
            $k = $ny * $ww + $nx;
            if (isset($seen[$k])) continue;
            $seen[$k] = 1;
            if (isset($ruleX[$nx]) || isset($ruleY[$ny])) continue;
            if ($g[$ny][$nx] < $loose && $g[$ny][$nx] < $mean($nx, $ny) - 7) $stack[] = [$nx, $ny];
        }
    }
    if ($bx1 < $bx0 || $by1 < $by0) return [$x0, $y0, $x1, $y1];
    return [min($x0, $wx0 + max(0, $bx0 - 2)), min($y0, $wy0 + max(0, $by0 - 2)),
            max($x1, $wx0 + min($ww, $bx1 + 3)), max($y1, $wy0 + min($wh, $by1 + 3))];
}

/**
 * Table cell walls. The page may be warped, so the column lines are found LOCALLY, right next
 * to this cell: in a strip around each side, the pixel column that is darker than the paper
 * 5px left/right of it on most rows of a 2-row-tall band is the ruled line. Returns the inner
 * x-limits [left, right] the crop may never cross (2px clear of the line).
 */
function localColumnBounds(\GdImage $img, int $x, int $y, int $w, int $h): array
{
    $W = imagesx($img); $H = imagesy($img);
    $by0 = max(0, $y - (int) round(0.5 * $h)); $by1 = min($H, $y + $h + (int) round(0.5 * $h));
    $g = function (int $px, int $py) use ($img, $W): int {
        $c = imagecolorat($img, max(0, min($W - 1, $px)), $py);
        return (int) ((($c >> 16) & 0xFF) * 0.299 + (($c >> 8) & 0xFF) * 0.587 + ($c & 0xFF) * 0.114);
    };
    $frac = function (int $px) use ($g, $by0, $by1): float {
        $n = 0; $d = 0;
        for ($py = $by0; $py < $by1; $py++) {
            $n++;
            $side = max($g($px - 5, $py), $g($px + 5, $py));
            if ($g($px, $py) + 12 < $side) $d++;
        }
        return $n ? $d / $n : 0.0;
    };
    $find = function (int $a, int $b) use ($frac): ?array {
        $f = []; $best = -1; $bx = null;
        for ($px = $a; $px <= $b; $px++) { $f[$px] = $frac($px); if ($f[$px] > $best) { $best = $f[$px]; $bx = $px; } }
        if ($bx === null || $best < 0.45) return null;
        $l = $bx; $r = $bx;
        while ($l - 1 >= $a && $f[$l - 1] >= 0.5 * $best) $l--;
        while ($r + 1 <= $b && $f[$r + 1] >= 0.5 * $best) $r++;
        return [$l, $r];
    };
    $reach = min(16, max(6, (int) round(0.25 * $w)));
    $L = $find(max(0, $x - 16), min($W - 1, $x + $reach));
    $R = $find(max(0, $x + $w - $reach), min($W - 1, $x + $w + 16));
    $left  = $L ? $L[1] + 3 : $x - 3;
    $right = $R ? $R[0] - 2 : $x + $w + 3;
    if ($right - $left < 10) return [$x, $x + $w];
    return [$left, $right];
}

// $cell (x0,y0,x1,y1) keeps a crop inside its grid cell (never over the lines).
function saveCrop(\GdImage $src, int $x, int $y, int $w, int $h, int $padding,
                  int $imgW, int $imgH, string $previewDir, string $outputFormat,
                  string $baseName, ?array $cell = null, ?int $padY = null, bool $grow = false): ?array
{
    // CROP_DEBUG=1 in the environment prints the box after every step (development aid)
    $dbg = getenv('CROP_DEBUG') ? fn(string $t, ...$v) => fprintf(STDERR, "%-10s %s
", $t, implode(' ', $v)) : fn() => null;
    $wallL = $wallR = null;
    if ($padY !== null) {   // word mode (tight ink box): include strokes that stick out of it
        [$x, $y, $w, $h] = growToInk($src, $x, $y, $w, $h, (int) round(0.2 * $h) + 4, (int) round(0.45 * $h) + 4, $cell);
    } elseif ($grow) {      // table cell: centre on one full text line; writing may run slightly past the cell, never into a line
        [$wallL, $wallR] = localColumnBounds($src, $x, $y, $w, $h);   // hard walls: the column lines
        $cellY = $y; $cellH = $h;
        $x = max($x, $wallL); $w = min($x + $w, $wallR) - $x;
        [$x, $y, $w, $h] = alignToTextLine($src, $x, $y, $w, $h); $dbg('align', $x, $y, $w, $h);
        [$x, $y, $w, $h] = clipToColumn($src, $x, $y, $w, $h); $dbg('clipCol', $x, $y, $w, $h);
        [$x, $y, $w, $h] = growToInk($src, $x, $y, $w, $h, (int) round(0.04 * $w) + 6, 2, null);   // sideways only: the line is already found
        // give the word-isolation step room above/below: it keeps only the pen shapes that belong
        // to this line (tall ascenders, deep descenders) and drops the neighbours' fragments
        // (measured from the cell, so a line squeezed by the alignment still gets enough room)
        $room = (int) round(0.35 * max($h, $cellH));
        $top = max(0, min($y, $cellY) - $room);
        $bot = min($imgH, max($y + $h, $cellY + $cellH) + $room);
        $y = $top; $h = $bot - $top;
        $nx0 = max($x, $wallL); $nx1 = min($x + $w, $wallR); $x = $nx0; $w = $nx1 - $nx0; $dbg('grow', $x, $y, $w, $h);
    }
    $padY ??= $padding;
    $x0 = max(0, $x - $padding, $wallL ?? 0);
    $y0 = max(0, $y - $padY);
    $x1 = min($imgW, $x + $w + $padding, $wallR ?? $imgW);
    $y1 = min($imgH, $y + $h + $padY);

    if ($cell && count($cell) === 4) {
        $x0 = max($x0, (int) $cell[0] + 3);
        $y0 = max($y0, (int) $cell[1] + 3);
        $x1 = min($x1, (int) $cell[2] - 3);
        $y1 = min($y1, (int) $cell[3] - 3);
    }

    // Last safety net: shave off any ruled line still hugging a crop edge.
    [$x0, $y0, $x1, $y1] = trimGridLines($src, $x0, $y0, $x1, $y1); $dbg('trimLines', $x0, $y0, $x1-$x0, $y1-$y0);

    // (Ruled row lines through the box are handled inside isolateWord, which picks the one
    //  text line of this entry and ignores the rules.)

    // One word per crop: cut away fragments of neighbouring text at the edges.
    [$x0, $y0, $x1, $y1] = isolateWord($src, $x0, $y0, $x1, $y1); $dbg('isolate', $x0, $y0, $x1-$x0, $y1-$y0);

    // Complete the word: strokes that run past the box edge (long names, trailing letters).
    $capLo = $y0 - (int) round(0.05 * ($y1 - $y0)) - 2;   // total vertical room for ascender/descender tips
    $capHi = $y1 + (int) round(0.05 * ($y1 - $y0)) + 2;
    for ($pass = 0; $pass < 4; $pass++) {   // repeat while the pen keeps running past the edge
        $prev = [$x0, $y0, $x1, $y1];
        [$x0, $y0, $x1, $y1] = completeWord($src, $x0, $y0, $x1, $y1, $capLo, $capHi, $wallL ?? null, $wallR ?? null);
        if ([$x0, $y0, $x1, $y1] === $prev) break; $dbg('complete', $x0, $y0, $x1-$x0, $y1-$y0);
    }

    $cw = $x1 - $x0;
    $ch = $y1 - $y0;
    if ($cw <= 0 || $ch <= 0) return null;

    $croppedImg = imagecrop($src, ['x' => $x0, 'y' => $y0, 'width' => $cw, 'height' => $ch]);
    if ($croppedImg === false) return null;

    $filename = "{$baseName}.{$outputFormat}";
    $path = $previewDir . $filename;
    switch ($outputFormat) {
        case 'jpeg': imagejpeg($croppedImg, $path, 95); break;
        case 'webp': imagewebp($croppedImg, $path, 95); break;
        default:     imagepng($croppedImg, $path);      break;
    }
    unset($croppedImg);

    return ['file' => 'uploads/previews/' . $filename, 'filename' => $filename, 'w' => $cw, 'h' => $ch, 'x' => $x0, 'y' => $y0];
}

/**
 * Scan uploads (images and/or PDFs). Every PDF page / image becomes one "page".
 * $uploads: [['name','tmp','error', 'local'?]]   $prefix is put in front of every crop file name.
 * Returns ['pages'=>[], 'words'=>[], 'tableMode'=>bool, 'warnings'=>[]]
 */
function scanUploads(array $uploads, string $prefix, int $padding, string $outputFormat, string $previewDir): array
{
    $warnings = [];
    $pages = [];
    $words = [];
    $tableMode = false;

    // ── Expand PDFs into one image per page ──
    $expanded = [];
    foreach ($uploads as $up) {
        if (strtolower(pathinfo($up['name'], PATHINFO_EXTENSION)) !== 'pdf') {
            $expanded[] = $up;
            continue;
        }
        if ($up['error'] !== UPLOAD_ERR_OK) {
            $warnings[] = "{$up['name']}: upload failed (error code {$up['error']}).";
            continue;
        }
        $pdfTmp = tempFile('.pdf');
        $moved = !empty($up['local']) ? copy($up['tmp'], $pdfTmp) : move_uploaded_file($up['tmp'], $pdfTmp);
        if (!$moved) {
            $warnings[] = "{$up['name']}: could not read uploaded PDF.";
            continue;
        }
        $pagesDir = tempFile('_pages');
        [$pCode, $pOut] = runPy(PDF_SCRIPT, [$pdfTmp, $pagesDir]);
        $pngs = $pCode === 0 ? jsonFromOutput($pOut, '[', ']') : null;
        if (!$pngs) {
            $warnings[] = "{$up['name']}: could not render PDF pages.";
            continue;
        }
        foreach ($pngs as $n => $png) {
            $GLOBALS['__tempFiles'][] = $png;
            $expanded[] = [
                'name' => pathinfo($up['name'], PATHINFO_FILENAME) . '_p' . ($n + 1) . '.png',
                'tmp' => $png, 'error' => UPLOAD_ERR_OK, 'local' => true,
            ];
        }
    }

    $allowedExt = ['jpg', 'jpeg', 'png', 'webp'];
    foreach ($expanded as $up) {
        $pageNo = count($pages) + 1;   // numbering only counts pages that were processed
        if ($up['error'] !== UPLOAD_ERR_OK) {
            $warnings[] = "{$up['name']}: upload failed (error code {$up['error']}).";
            continue;
        }
        $ext = strtolower(pathinfo($up['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedExt)) {
            $warnings[] = "{$up['name']}: unsupported file type (use PDF, PNG, JPG or WEBP).";
            continue;
        }

        $uploadPath = tempFile('.' . $ext);
        $moved = !empty($up['local']) ? copy($up['tmp'], $uploadPath) : move_uploaded_file($up['tmp'], $uploadPath);
        if (!$moved) {
            $warnings[] = "{$up['name']}: could not read uploaded file.";
            continue;
        }

        // 1) Table / grid pages: crop every cell (row x column) like a spreadsheet.
        $workPath = $uploadPath;
        $boxes = [];
        $deskewPath = tempFile('_desk.png');
        [$tCode, $tOut] = file_exists(TABLE_SCRIPT) ? runPy(TABLE_SCRIPT, [$uploadPath, $deskewPath]) : [1, ''];
        $table = $tCode === 0 ? jsonFromOutput($tOut, '{', '}') : null;
        if ($table && !empty($table['cells']) && count($table['cols']) >= 3 && count($table['rows']) >= 3 && is_file($deskewPath)) {
            $workPath = $deskewPath;   // cells are measured on the straightened page
            $tableMode = true;
            foreach ($table['cells'] as $c) {
                $boxes[] = [
                    'x' => (int) $c['x'], 'y' => (int) $c['y'], 'w' => (int) $c['w'], 'h' => (int) $c['h'],
                    'label' => "P{$pageNo} R{$c['row']}·C{$c['col']}",
                    'base'  => sprintf('%sp%d_r%02d_c%02d', $prefix, $pageNo, $c['row'], $c['col']),
                    'cell'  => null, 'pad' => 0,
                    'row' => (int) $c['row'], 'col' => (int) $c['col'],
                ];
            }
        } else {
            // 2) No grid: fall back to Kraken word-by-word segmentation.
            [$kCode, $kOut] = runPy(KRAKEN_SCRIPT, [$uploadPath]);
            if ($kCode !== 0) {
                $warnings[] = "{$up['name']}: word segmentation failed.";
                continue;
            }
            $kBoxes = jsonFromOutput($kOut, '[', ']') ?? [];
            $n = 0;
            foreach ($kBoxes as $b) {
                $n++;
                $boxes[] = [
                    'x' => (int) $b['x'], 'y' => (int) $b['y'], 'w' => (int) $b['w'], 'h' => (int) $b['h'],
                    'label' => "P{$pageNo} Word #{$n}",
                    'base'  => sprintf('%sp%d_w%03d', $prefix, $pageNo, $n),
                    'cell'  => $b['cell'] ?? null,
                    // tight ink boxes clip ascenders/descenders/pen strokes: the padding grows with
                    // the word size (never below the user's setting) so the whole word is kept
                    'pad'  => max($padding, (int) round(0.15 * (int) $b['h'])),
                    'padY' => max($padding, (int) round(0.25 * (int) $b['h'])),
                    'row' => 0, 'col' => 0,
                ];
            }
        }

        if (!$boxes) {
            $warnings[] = "{$up['name']}: nothing detected. Try a clearer scan.";
            continue;
        }

        $gd = loadGd($workPath);
        if (!$gd) {
            $warnings[] = "{$up['name']}: could not read the image.";
            continue;
        }
        [$sourceImage] = $gd;
        $imgWidth = imagesx($sourceImage);
        $imgHeight = imagesy($sourceImage);

        $page = [
            'no' => $pageNo,
            'name' => $up['name'],
            'w' => $imgWidth,
            'h' => $imgHeight,
            // embedded for on-screen display only (never written to the project)
            'src' => displayCopy($sourceImage, $imgWidth, $imgHeight),
        ];

        foreach ($boxes as $b) {
            $crop = saveCrop($sourceImage, $b['x'], $b['y'], $b['w'], $b['h'], $b['pad'],
                             $imgWidth, $imgHeight, $previewDir, $outputFormat, $b['base'], $b['cell'], $b['padY'] ?? null, true);
            if ($crop === null) continue;
            $words[] = [
                'id' => count($words) + 1,
                'page' => $pageNo,
                'label' => $b['label'],
                'row' => $b['row'],
                'col' => $b['col'],
                'file' => $crop['file'],
                'filename' => $crop['filename'],
                'w' => $crop['w'],
                'h' => $crop['h'],
                'crop' => ['x' => $crop['x'], 'y' => $crop['y']],
                'box' => ['x' => $b['x'], 'y' => $b['y'], 'w' => $b['w'], 'h' => $b['h']],
            ];
        }
        unset($sourceImage);
        $pages[] = $page;
    }

    return ['pages' => $pages, 'words' => $words, 'tableMode' => $tableMode, 'warnings' => $warnings];
}
