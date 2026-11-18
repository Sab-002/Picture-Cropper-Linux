<?php
// Builds a ready-to-train dataset ZIP (train/ val/ test/ + label and metadata files)
// from the annotated images in htr_dataset/. Layout follows dataset_005:
//
//   dataset_NNN.zip
//   └── dataset_NNN/
//       ├── train/ val/ test/            images (only finished, labeled ones)
//       ├── labels.csv                   filename,label,split
//       ├── manifest.csv                 filename,label,split,source,field_type,font,sample_mode,writer_id,schema_version
//       ├── evaluation-annotations.csv   per-image evaluation info
//       ├── dataset-validation.json      checks + statistics
//       ├── image-statistics.json        pixel statistics
//       └── run-metadata.json            how the dataset was built
require_once __DIR__ . '/htr_lib.php';

const DS_SCHEMA = 1;

function dsExportDir(): string
{
    $d = __DIR__ . '/htr_dataset/exports/';
    if (!is_dir($d)) mkdir($d, 0777, true);
    return $d;
}

function dsNextName(): string
{
    $max = 0;
    foreach (glob(dsExportDir() . 'dataset_*.zip') ?: [] as $f) {
        if (preg_match('/dataset_(\d+)\.zip$/', $f, $m)) $max = max($max, (int) $m[1]);
    }
    return sprintf('dataset_%03d', $max + 1);
}

/** Deterministic split of $n items into [train, val, test] counts. */
function dsSplitCounts(int $n, float $valFrac, float $testFrac): array
{
    $val = (int) round($n * $valFrac);
    $test = (int) round($n * $testFrac);
    if ($n >= 3) {                         // every split gets at least one image when possible
        if ($valFrac > 0) $val = max(1, $val);
        if ($testFrac > 0) $test = max(1, $test);
    }
    while ($n - $val - $test < 1 && ($val + $test) > 0) {   // keep at least one training image
        if ($val >= $test && $val > 0) $val--; elseif ($test > 0) $test--;
    }
    return [$n - $val - $test, $val, $test];
}

function dsPercentile(array $sorted, float $p): float
{
    $n = count($sorted);
    if ($n === 0) return 0.0;
    $i = ($n - 1) * $p;
    $lo = (int) floor($i); $hi = (int) ceil($i);
    return $sorted[$lo] + ($sorted[$hi] - $sorted[$lo]) * ($i - $lo);
}

function dsSummary(array $v): array
{
    $n = count($v);
    if ($n === 0) return ['count' => 0, 'maximum' => null, 'mean' => null, 'minimum' => null, 'stddev' => null];
    $mean = array_sum($v) / $n;
    $var = 0.0;
    foreach ($v as $x) $var += ($x - $mean) ** 2;
    return ['count' => $n, 'maximum' => max($v), 'mean' => $mean, 'minimum' => min($v), 'stddev' => sqrt($var / $n)];
}

function dsPngMode(string $bytes): string
{
    $t = ord($bytes[25] ?? "\x02");
    return [0 => 'L', 2 => 'RGB', 3 => 'P', 4 => 'LA', 6 => 'RGBA'][$t] ?? 'RGB';
}

/** Pixel statistics of one image (luminance 0-255). */
function dsPixelStats(string $bytes): ?array
{
    $im = @imagecreatefromstring($bytes);
    if (!$im) return null;
    $w = imagesx($im); $h = imagesy($im);
    $lum = []; $sum = 0.0; $ink = 0;
    for ($y = 0; $y < $h; $y++) {
        for ($x = 0; $x < $w; $x++) {
            $c = imagecolorat($im, $x, $y);
            $l = imageistruecolor($im)
                ? (0.299 * (($c >> 16) & 255) + 0.587 * (($c >> 8) & 255) + 0.114 * ($c & 255))
                : (function () use ($im, $c) { $a = imagecolorsforindex($im, $c); return 0.299 * $a['red'] + 0.587 * $a['green'] + 0.114 * $a['blue']; })();
            $lum[$y * $w + $x] = $l;
            $sum += $l;
            if ($l < 128) $ink++;
        }
    }
    unset($im);
    $n = max(1, $w * $h);
    $mean = $sum / $n;
    $var = 0.0; $edges = 0;
    foreach ($lum as $i => $l) {
        $var += ($l - $mean) ** 2;
        $x = $i % $w;
        $gx = $x + 1 < $w ? abs($lum[$i + 1] - $l) : 0;
        $gy = $i + $w < $n ? abs($lum[$i + $w] - $l) : 0;
        if ($gx + $gy > 32) $edges++;
    }
    $s = $lum; sort($s);
    return [
        'width' => $w, 'height' => $h, 'aspect_ratio' => $h > 0 ? $w / $h : 0,
        'mean_luminance' => $mean, 'luminance_std' => sqrt($var / $n),
        'ink_coverage' => $ink / $n, 'edge_density' => $edges / $n,
        'robust_contrast' => dsPercentile($s, 0.95) - dsPercentile($s, 0.05),
    ];
}

function dsCsv(array $header, array $rows): string
{
    $fh = fopen('php://temp', 'r+');
    fputcsv($fh, $header, ',', '"', '');
    foreach ($rows as $r) fputcsv($fh, $r, ',', '"', '');
    rewind($fh);
    $s = stream_get_contents($fh);
    fclose($fh);
    return str_replace("\r\n", "\n", $s);
}

function dsJson($v): string
{
    return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION) . "\n";
}

/**
 * @param array $items       manifest items (only status "labeled" are used)
 * @param array $fractions   [train, val, test] fractions that add up to 1
 * @return array{ok:bool, error?:string, name?:string, file?:string, size?:int, counts?:array, warnings?:array}
 */
function dsBuildZip(array $items, array $fractions, int $seed = 42): array
{
    $labeled = array_values(array_filter($items, fn($r) => ($r['status'] ?? '') === 'labeled' && trim((string) $r['transcription']) !== ''));
    if (!$labeled) return ['ok' => false, 'error' => 'No image has been annotated yet.'];

    // deterministic shuffle, then cut into splits
    mt_srand($seed);
    usort($labeled, fn($a, $b) => strcmp($a['processed_filename'], $b['processed_filename']));
    for ($i = count($labeled) - 1; $i > 0; $i--) {
        $j = mt_rand(0, $i);
        [$labeled[$i], $labeled[$j]] = [$labeled[$j], $labeled[$i]];
    }
    [$nTrain, $nVal, $nTest] = dsSplitCounts(count($labeled), $fractions[1], $fractions[2]);
    $splitOf = [];
    foreach ($labeled as $i => $r) $splitOf[$r['processed_filename']] = $i < $nTrain ? 'train' : ($i < $nTrain + $nVal ? 'val' : 'test');
    usort($labeled, fn($a, $b) => strcmp($a['processed_filename'], $b['processed_filename']));

    $errors = []; $warnings = [];
    $files = [];          // in-archive path => bytes
    $rowsLabels = []; $rowsManifest = []; $trainLabels = [];
    $seen = [];
    $stats = ['aspect_ratio' => [], 'width' => [], 'height' => [], 'mean_luminance' => [], 'luminance_std' => [],
              'ink_coverage' => [], 'edge_density' => [], 'robust_contrast' => []];
    $modes = []; $imgHashes = []; $sampled = 0; $bySplit = ['train' => 0, 'val' => 0, 'test' => 0];

    foreach ($labeled as $r) {
        $fn = $r['processed_filename'];
        $label = (string) $r['transcription'];
        $split = $splitOf[$fn];
        $path = __DIR__ . '/htr_dataset/images/' . basename($fn);
        if (isset($seen[$fn])) { $errors[] = "$split/$fn: duplicate file name"; continue; }
        $seen[$fn] = true;
        if (!is_file($path)) { $errors[] = "$split/$fn: image file is missing"; continue; }
        $bytes = (string) file_get_contents($path);
        $im = @getimagesizefromstring($bytes);
        if (!$im) { $errors[] = "$split/$fn: not a readable image"; continue; }
        if ($label !== trim($label) || $label === '') { $errors[] = "$split/$fn: label is empty or has surrounding spaces"; continue; }

        $files[$split . '/' . $fn] = $bytes;
        $bySplit[$split]++;
        $rowsLabels[] = [$fn, $label, $split];
        $rowsManifest[] = [$fn, $label, $split, 'real', '', '', '', '', DS_SCHEMA];
        if ($split === 'train') $trainLabels[$label] = true;
        $imgHashes[] = $split . '/' . $fn . ':' . hash('sha256', $bytes);
        $modes[dsPngMode($bytes)] = ($modes[dsPngMode($bytes)] ?? 0) + 1;

        $ratio = $im[1] > 0 ? $im[0] / $im[1] : 0;
        if ($ratio > 12) $warnings[] = "$split/$fn: extreme aspect ratio (" . round($ratio, 2) . ':1)';
        if ($im[0] < 16 || $im[1] < 16) $warnings[] = "$split/$fn: very small image ({$im[0]}x{$im[1]})";
        if (mb_strlen($label) > 100) $warnings[] = "$split/$fn: very long label";

        if ($sampled < 512) {
            $px = dsPixelStats($bytes);
            if ($px) { $sampled++; foreach ($stats as $k => $_) $stats[$k][] = $px[$k]; }
        }
    }
    if ($errors) return ['ok' => false, 'error' => 'Dataset check failed: ' . implode('; ', array_slice($errors, 0, 5)) . (count($errors) > 5 ? ' …' : '')];
    if (!$files) return ['ok' => false, 'error' => 'No usable annotated images were found.'];
    foreach ($bySplit as $s => $c) if ($c === 0) $warnings[] = "The $s split is empty (too few annotated images).";
    if (count($files) < 30) $warnings[] = 'Only ' . count($files) . ' annotated image(s): too few for reliable training and evaluation.';

    $name = dsNextName();
    $eval = [];
    foreach ($rowsLabels as [$fn, $label, $split]) {
        $eval[] = [$fn, $split, ['train' => 'real_training', 'val' => 'real_in_distribution', 'test' => 'real_held_out'][$split],
                   'base', 'default', isset($trainLabels[$label]) ? 'True' : 'False', 'True', 'True'];
    }
    $labelsCsv = dsCsv(['filename', 'label', 'split'], $rowsLabels);
    $manifestCsv = dsCsv(['filename', 'label', 'split', 'source', 'field_type', 'font', 'sample_mode', 'writer_id', 'schema_version'], $rowsManifest);
    $evalCsv = dsCsv(['filename', 'split', 'evaluation_condition', 'format_profile', 'format_id', 'label_seen_in_train', 'font_seen_in_train', 'format_seen_in_train'], $eval);

    $total = count($files);
    $now = gmdate('c');
    $imagesSha = hash('sha256', implode("\n", $imgHashes));
    $manifestSha = hash('sha256', $manifestCsv);
    $evalSha = hash('sha256', $evalCsv);
    $sorted = fn($k) => (function () use ($stats, $k) { $v = $stats[$k]; sort($v); return $v; })();
    $ar = $sorted('aspect_ratio'); $wd = $sorted('width'); $ht = $sorted('height');
    $ink = $sorted('ink_coverage'); $rc = $sorted('robust_contrast');

    $validation = [
        'dataset' => $name,
        'diagnostics' => ['error_count' => 0, 'suppressed_error_count' => 0, 'suppressed_warning_count' => 0, 'warning_count' => count($warnings)],
        'errors' => [],
        'images_sha256' => $imagesSha,
        'manifest_sha256' => $manifestSha,
        'schema_version' => DS_SCHEMA,
        'statistics' => [
            'aspect_ratio' => ['max' => $ar ? max($ar) : null, 'p50' => dsPercentile($ar, .5), 'p95' => dsPercentile($ar, .95)],
            'by_source' => ['real' => $total],
            'by_split' => ['test' => $bySplit['test'], 'train' => $bySplit['train'], 'val' => $bySplit['val']],
            'contrast' => ['min' => $rc ? min($rc) : null],
            'image_height' => ['max' => $ht ? max($ht) : null, 'min' => $ht ? min($ht) : null, 'p50' => dsPercentile($ht, .5), 'p95' => dsPercentile($ht, .95)],
            'image_modes' => $modes,
            'image_width' => ['max' => $wd ? max($wd) : null, 'min' => $wd ? min($wd) : null, 'p50' => dsPercentile($wd, .5), 'p95' => dsPercentile($wd, .95)],
            'images' => $total,
            'ink_coverage' => ['min' => $ink ? min($ink) : null],
            'manifest_rows' => $total,
        ],
        'valid' => true,
        'validated_at' => $now,
        'warnings' => array_slice($warnings, 0, 200),
    ];
    $imageStats = [
        'comparison_available' => false,
        'generated' => [
            'image_count' => $sampled,
            'metrics' => array_map('dsSummary', $stats),
            'statistics_schema_version' => 1,
        ],
        'interpretation' => 'Descriptive statistics of the real annotated word images (up to 512 sampled); no reference set was compared.',
        'real' => null,
        'sample_limit_per_group' => 512,
        'statistics_schema_version' => 1,
    ];
    $meta = [
        'generated_by' => 'Kraken Word Cropper (cropper-miro)',
        'generation_started_at' => $now,
        'generation_completed_at' => gmdate('c'),
        'image_count' => $total,
        'row_count' => $total,
        'images_sha256' => $imagesSha,
        'manifest_sha256' => $manifestSha,
        'manifest_schema_version' => (string) DS_SCHEMA,
        'metadata_schema_version' => 1,
        'evaluation_annotations' => 'evaluation-annotations.csv',
        'evaluation_annotations_sha256' => $evalSha,
        'image_statistics' => 'image-statistics.json',
        'validation_report' => 'dataset-validation.json',
        'seed' => $seed,
        'split_counts' => ['test' => $bySplit['test'], 'train' => $bySplit['train'], 'val' => $bySplit['val']],
        'effective_selection' => ['split_fractions' => [$fractions[0], $fractions[1], $fractions[2]], 'split_method' => 'seeded shuffle by file name'],
        'source' => 'real',
        'preprocessing' => ['id' => 'gray-trim-h64-v1', 'aspect_preserving' => true, 'grayscale' => true, 'target_height' => 64, 'trimmed_to_ink' => true, 'margin_px' => 6],
        'dependencies' => ['php' => PHP_VERSION],
    ];

    $root = $name . '/';
    $zipFiles = [];
    foreach ($files as $p => $bytes) $zipFiles[$root . $p] = $bytes;
    $zipFiles[$root . 'labels.csv'] = $labelsCsv;
    $zipFiles[$root . 'manifest.csv'] = $manifestCsv;
    $zipFiles[$root . 'evaluation-annotations.csv'] = $evalCsv;
    $zipFiles[$root . 'dataset-validation.json'] = dsJson($validation);
    $zipFiles[$root . 'image-statistics.json'] = dsJson($imageStats);
    $zipFiles[$root . 'run-metadata.json'] = dsJson($meta);

    $zipPath = dsExportDir() . $name . '.zip';
    $tmp = $zipPath . '.tmp';
    @unlink($tmp);
    if (class_exists('ZipArchive') && !defined('DS_NO_ZIP')) {
        $z = new ZipArchive();
        if ($z->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) return ['ok' => false, 'error' => 'Could not create the zip file.'];
        foreach ($zipFiles as $p => $bytes) $z->addFromString($p, $bytes);
        $ok = $z->close();
    } else {
        $ok = createMinimalZip($tmp, $zipFiles);      // works without ext-zip
    }
    if (!$ok || !is_file($tmp) || !rename($tmp, $zipPath)) { @unlink($tmp); return ['ok' => false, 'error' => 'Could not write the zip file.']; }

    return ['ok' => true, 'name' => $name, 'file' => 'htr_dataset/exports/' . $name . '.zip', 'size' => filesize($zipPath),
            'counts' => ['train' => $bySplit['train'], 'val' => $bySplit['val'], 'test' => $bySplit['test'], 'total' => $total,
                         'skipped' => count($items) - $total],
            'warnings' => array_slice($warnings, 0, 5)];
}
