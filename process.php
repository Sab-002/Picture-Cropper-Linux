<?php
// ── Config ──────────────────────────────────────────────────────────────────
$uploadDir  = __DIR__ . '/uploads/';
$previewDir = __DIR__ . '/uploads/previews/';
$outputDir  = __DIR__ . '/output/';

if (!is_dir($uploadDir))  mkdir($uploadDir, 0777, true);
if (!is_dir($previewDir)) mkdir($previewDir, 0777, true);
if (!is_dir($outputDir))  mkdir($outputDir, 0777, true);

// PHP drops $_POST and $_FILES entirely when post_max_size is exceeded.
// Catch that before the request is mistaken for a missing upload.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $limit = trim((string) ini_get('post_max_size'));
    $unit = strtolower(substr($limit, -1));
    $factor = match ($unit) { 'g' => 1024 ** 3, 'm' => 1024 ** 2, 'k' => 1024, default => 1 };
    $postLimit = (int) ((float) $limit * $factor);
    $requestSize = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($postLimit > 0 && $requestSize > $postLimit) {
        renderError('The upload exceeds PHP post_max_size (' . ini_get('post_max_size') . '). Stop the old server and restart it with ./run-linux.sh.');
    }
}

// ── Handle AJAX Save to output/ ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_to_output') {
    header('Content-Type: application/json');
    $filenames = $_POST['filenames'] ?? [];
    if (is_string($filenames)) {
        $filenames = json_decode($filenames, true) ?: [$filenames];
    }
    $isAppend = !empty($_POST['append']) && $_POST['append'] === '1';

    if (!$isAppend) {
        // Clear output folder so ONLY the currently saved pictures exist in output/
        foreach (glob($outputDir . '*') as $f) {
            if (is_file($f)) unlink($f);
        }
    }

    $saved = [];
    if (is_array($filenames)) {
        foreach ($filenames as $fname) {
            $cleanName = basename($fname);
            $src = $previewDir . $cleanName;
            $dst = $outputDir . $cleanName;
            if (is_file($src)) {
                copy($src, $dst);
                $saved[] = $cleanName;
            }
        }
    }

    echo json_encode([
        'success' => true,
        'count'   => count($saved),
        'files'   => $saved
    ]);
    exit;
}

// ── Handle AJAX replace_crop: overwrite one crop with an adjusted version ───
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'replace_crop') {
    header('Content-Type: application/json');
    require __DIR__ . '/engine.php';
    $name = basename((string) ($_POST['filename'] ?? ''));
    $dst  = $previewDir . $name;
    $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $up   = $_FILES['image'] ?? null;
    if ($err = cropUploadError($name, $dst, $up)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => $err]);
        exit;
    }
    $img = @imagecreatefromstring((string) file_get_contents($up['tmp_name']));
    if (!$img) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Not a readable image']);
        exit;
    }
    switch ($ext) {
        case 'jpeg': imagejpeg($img, $dst, 95); break;
        case 'webp': imagewebp($img, $dst, 95); break;
        default:     imagepng($img, $dst);      break;
    }
    $w = imagesx($img);
    $h = imagesy($img);
    unset($img);
    // a copy already saved to output/ would now be stale: refresh it
    if (is_file($outputDir . $name)) copy($dst, $outputDir . $name);
    echo json_encode(['success' => true, 'w' => $w, 'h' => $h]);
    exit;
}

require __DIR__ . '/engine.php';

// Clear output directory and old previews on new upload scan
foreach (glob($outputDir . '*') as $f) {
    if (is_file($f)) unlink($f);
}
foreach (glob($previewDir . '*') as $f) {
    if (is_file($f)) unlink($f);
}

// ── Redirect if not POST ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_FILES['images'])) {
    header("Location: index.php");
    exit;
}

// ── Read options ─────────────────────────────────────────────────────────────
$padding = max(0, min(30, (int) ($_POST['padding'] ?? 4)));
$outputFormat = in_array($_POST['output_format'] ?? 'png', ['png', 'jpeg', 'webp'])
    ? $_POST['output_format'] : 'png';

// Normalise $_FILES['images'] (multiple pages) into a list of uploads
$uploads = [];
foreach ((array) $_FILES['images']['name'] as $i => $name) {
    if ($_FILES['images']['error'][$i] === UPLOAD_ERR_NO_FILE) continue;
    $uploads[] = [
        'name'  => $name,
        'tmp'   => $_FILES['images']['tmp_name'][$i],
        'error' => $_FILES['images']['error'][$i],
    ];
}
if (!$uploads) {
    renderError('No file was uploaded.');
}
// Keep pages in natural filename order (page1, page2, ... page10)
usort($uploads, fn($a, $b) => strnatcasecmp($a['name'], $b['name']));

if (!file_exists(KRAKEN_PYTHON)) {
    renderError('Python executable not found at ' . KRAKEN_PYTHON);
}
foreach (glob($uploadDir . 'original_*') as $f) {
    if (is_file($f)) unlink($f); // leftovers from older versions
}

$result = scanUploads($uploads, '', $padding, $outputFormat, $previewDir);
$pages = $result['pages'];
$croppedWords = $result['words'];
$tableMode = $result['tableMode'];
$warnings = $result['warnings'];
$wordCount = count($croppedWords);

if ($wordCount === 0) {
    renderError('Nothing could be cropped from the uploaded files.' . ($warnings ? ' ' . implode(' ', $warnings) : ''));
}

// ── Error Renderer ──────────────────────────────────────────────────────────
function renderError(string $msg): never
{
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title>Error — Kraken Cropper</title>
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
        <style>
            *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
            body {
                font-family: 'Inter', sans-serif;
                background: #050507;
                color: #ffffff;
                min-height: 100vh;
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 20px;
            }
            .card {
                background: #0d0d10;
                border: 1px solid #27272a;
                border-radius: 20px;
                padding: 40px;
                max-width: 480px;
                text-align: center;
                box-shadow: 0 20px 50px rgba(0,0,0,0.8);
            }
            .icon { font-size: 3rem; margin-bottom: 16px; filter: grayscale(1); }
            h2 { font-size: 1.4rem; font-weight: 800; margin-bottom: 10px; color: #ffffff; }
            p { color: #8b8b96; font-size: .88rem; line-height: 1.6; margin-bottom: 24px; word-break: break-word; }
            a {
                display: inline-block;
                padding: 12px 28px;
                background: #ffffff;
                color: #000000;
                border-radius: 12px;
                font-weight: 700;
                font-size: .88rem;
                text-decoration: none;
                transition: transform .15s;
            }
            a:hover { transform: translateY(-2px); }
        </style>
    </head>
    <body>
        <div class="card">
            <div class="icon">⚠️</div>
            <h2>Scan Error</h2>
            <p><?= htmlspecialchars($msg) ?></p>
            <a href="index.php">← Try Again</a>
        </div>
    </body>
    </html>
    <?php
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kraken Word Cropper — Side-by-Side Results</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <!-- JSZip for dynamic client-side batch zip creation -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --bg:          #050507;
            --panel:       #0d0d10;
            --card:        #121216;
            --surface:     #18181d;
            --border:      #26262b;
            --border-hover:#404048;
            --text-primary:#ffffff;
            --text-muted:  #8b8b96;
            --text-dim:    #52525b;
            --btn-bg:      #ffffff;
            --btn-text:    #000000;
            --glow:        rgba(255, 255, 255, 0.18);
            --highlight:   rgba(255, 255, 255, 0.95);
        }

        html, body {
            height: 100%;
            background: var(--bg);
            color: var(--text-primary);
            font-family: 'Inter', sans-serif;
            overflow-x: hidden;
        }

        /* ── Top App Bar ── */
        .navbar {
            height: 60px;
            background: var(--panel);
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 24px;
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .nav-brand {
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 1rem;
            font-weight: 800;
            letter-spacing: -.3px;
        }

        .brand-pill {
            padding: 3px 10px;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 999px;
            font-size: .72rem;
            font-weight: 700;
            color: #ffffff;
            text-transform: uppercase;
            letter-spacing: .5px;
        }

        .nav-actions {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .btn-nav {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 7px 14px;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 8px;
            color: var(--text-muted);
            text-decoration: none;
            font-size: .82rem;
            font-weight: 600;
            transition: all .2s;
        }
        .btn-nav:hover {
            color: #ffffff;
            border-color: #ffffff;
        }

        /* ── Main Split Layout (Side-by-Side) ── */
        .workspace {
            display: grid;
            grid-template-columns: 1fr 1.15fr;
            height: calc(100vh - 60px);
            overflow: hidden;
        }

        @media (max-width: 992px) {
            .workspace {
                grid-template-columns: 1fr;
                height: auto;
                overflow: visible;
            }
        }

        /* ── Left Side: Original Image Panel ── */
        .image-pane {
            border-right: 1px solid var(--border);
            background: #020203;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            position: relative;
        }

        .pane-header {
            padding: 14px 20px;
            background: var(--panel);
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-shrink: 0;
        }

        .pane-title {
            font-size: .82rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .8px;
            color: #ffffff;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .pane-tools {
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .tool-btn {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 6px;
            color: var(--text-muted);
            width: 30px;
            height: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: .85rem;
            cursor: pointer;
            transition: all .15s;
        }
        .tool-btn:hover {
            color: #ffffff;
            border-color: #ffffff;
        }

        .image-viewport {
            flex: 1;
            overflow: auto;
            display: flex;
            align-items: flex-start;   /* never centre: a tall document would lose its top */
            justify-content: center;
            padding: 24px;
            background-image: 
                radial-gradient(rgba(255, 255, 255, 0.08) 1px, transparent 1px);
            background-size: 20px 20px;
            user-select: none;
        }

        .image-canvas-wrap {
            position: relative;
            display: inline-block;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.9), 0 0 0 1px var(--border);
            border-radius: 4px;
            transition: transform .2s ease;
            transform-origin: center center;
        }

        .image-canvas-wrap img {
            display: block;
            max-width: 100%;
            height: auto;
            border-radius: 4px;
        }

        /* ── Overlay Bounding Boxes ── */
        .bbox-overlay {
            position: absolute;
            inset: 0;
            pointer-events: auto;
        }

        .bbox {
            position: absolute;
            border: 1.5px solid transparent;
            background: transparent;
            border-radius: 3px;
            cursor: pointer;
            transition: all .15s ease;
        }

        .bbox .bbox-tag {
            position: absolute;
            top: -16px;
            left: -1px;
            background: #ffffff;
            color: #000000;
            font-size: 9px;
            font-weight: 800;
            padding: 1px 4px;
            border-radius: 2px;
            line-height: 1;
            pointer-events: none;
            opacity: 0.8;
            transition: opacity .15s;
        }

        .bbox:hover,
        .bbox.active-hover {
            border-color: #ffffff;
            background: rgba(255, 255, 255, 0.28);
            box-shadow: 0 0 14px rgba(255, 255, 255, 0.9);
            z-index: 10;
        }

        .bbox:hover .bbox-tag,
        .bbox.active-hover .bbox-tag {
            opacity: 1;
        }

        .bbox.selected {
            border: 2px solid #ffffff;
            background: rgba(255, 255, 255, 0.22);
        }

        /* an unticked crop is removed from the page: no box, no hover, no click.
           Tick it again on its card to bring the box back. */
        .bbox:not(.selected) {
            visibility: hidden;
            pointer-events: none;
        }

        /* ── Right Side: Selected Words Panel ── */
        .words-pane {
            display: flex;
            flex-direction: column;
            background: var(--bg);
            overflow: hidden;
        }

        /* ── Action Bar / Selection Controls ── */
        .action-bar {
            padding: 16px 24px;
            background: var(--panel);
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 14px;
            flex-shrink: 0;
        }

        .selection-stat {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .sel-badge {
            background: #ffffff;
            color: #000000;
            font-weight: 800;
            font-size: .82rem;
            padding: 4px 10px;
            border-radius: 8px;
        }

        .sel-text {
            font-size: .85rem;
            color: var(--text-muted);
        }

        .action-buttons {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }

        .btn-action {
            padding: 8px 14px;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 8px;
            color: var(--text-primary);
            font-size: .82rem;
            font-weight: 600;
            font-family: inherit;
            cursor: pointer;
            transition: all .15s;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .btn-action:hover {
            border-color: #ffffff;
        }

        .btn-download-selected {
            padding: 8px 18px;
            background: #ffffff;
            color: #000000;
            border: 1px solid #ffffff;
            border-radius: 8px;
            font-size: .82rem;
            font-weight: 800;
            font-family: inherit;
            cursor: pointer;
            transition: all .2s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            box-shadow: 0 4px 16px rgba(255, 255, 255, 0.25);
        }

        .btn-download-selected:hover {
            transform: translateY(-1px);
            box-shadow: 0 6px 22px rgba(255, 255, 255, 0.4);
        }

        .btn-download-selected:disabled {
            opacity: .4;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }

        /* ── Search & Filter Toolbar ── */
        .search-bar {
            padding: 10px 24px;
            background: var(--card);
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex-shrink: 0;
        }

        .search-input-wrap {
            position: relative;
            flex: 1;
            max-width: 320px;
        }

        .search-input-wrap input {
            width: 100%;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 7px 12px 7px 32px;
            color: #ffffff;
            font-size: .82rem;
            font-family: inherit;
            outline: none;
            transition: border-color .15s;
        }
        .search-input-wrap input:focus {
            border-color: #ffffff;
        }

        .search-icon {
            position: absolute;
            left: 10px;
            top: 50%;
            transform: translateY(-50%);
            font-size: .8rem;
            color: var(--text-dim);
            pointer-events: none;
        }

        .meta-counts {
            font-size: .78rem;
            color: var(--text-muted);
        }

        /* ── Words Grid / Scroll Area ── */
        .words-grid-wrap {
            flex: 1;
            overflow-y: auto;
            padding: 24px;
        }

        .words-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
            gap: 14px;
        }

        /* ── Word Card (Black & White Theme) ── */
        .word-card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 12px;
            display: flex;
            flex-direction: column;
            gap: 10px;
            transition: all .15s ease;
            position: relative;
            cursor: pointer;
        }

        .word-card:hover,
        .word-card.active-hover {
            border-color: #ffffff;
            box-shadow: 0 4px 20px rgba(255, 255, 255, 0.15);
            transform: translateY(-2px);
        }

        .word-card.is-selected {
            border-color: #ffffff;
            background: rgba(255, 255, 255, 0.04);
        }

        .card-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        /* Custom B&W Checkbox */
        .chk-wrap {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: .82rem;
            font-weight: 700;
            color: #ffffff;
            cursor: pointer;
        }

        .custom-chk {
            width: 18px;
            height: 18px;
            border: 1.5px solid var(--border-hover);
            border-radius: 5px;
            background: var(--surface);
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all .15s;
        }

        .custom-chk::after {
            content: '✓';
            font-size: 11px;
            font-weight: 900;
            color: #000000;
            display: none;
        }

        .chk-wrap input[type="checkbox"] {
            display: none;
        }

        .chk-wrap input[type="checkbox"]:checked + .custom-chk {
            background: #ffffff;
            border-color: #ffffff;
        }

        .chk-wrap input[type="checkbox"]:checked + .custom-chk::after {
            display: block;
        }

        .dim-badge {
            font-size: .72rem;
            color: var(--text-dim);
            font-weight: 500;
        }

        .crop-preview-wrap {
            width: 100%;
            height: 80px;
            background: #000000;
            border: 1px solid var(--border);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            padding: 6px;
        }

        .crop-preview-wrap img {
            max-width: 100%;
            max-height: 100%;
            object-fit: contain;
            display: block;
        }

        .card-bottom {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding-top: 4px;
            border-top: 1px solid rgba(255, 255, 255, 0.05);
        }

        .btn-single-dl {
            background: transparent;
            border: 1px solid var(--border);
            border-radius: 6px;
            color: var(--text-muted);
            padding: 4px 8px;
            font-size: .75rem;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
            transition: all .15s;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        .btn-single-dl:hover {
            color: #ffffff;
            border-color: #ffffff;
            background: var(--surface);
        }

        .btn-zoom {
            background: transparent;
            border: none;
            color: var(--text-dim);
            font-size: .78rem;
            cursor: pointer;
            padding: 4px;
        }
        .btn-zoom:hover { color: #ffffff; }

        /* ── Modal Lightbox ── */
        .modal {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.9);
            backdrop-filter: blur(8px);
            z-index: 1000;
            align-items: center;
            justify-content: center;
            padding: 24px;
        }

        .modal.open { display: flex; }

        .modal-content {
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 24px;
            max-width: 90vw;
            max-height: 85vh;
            display: flex;
            flex-direction: column;
            gap: 16px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.9);
        }

        .modal-content img {
            max-width: 100%;
            max-height: 65vh;
            object-fit: contain;
            border-radius: 8px;
            background: #000000;
        }

        .modal-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .modal-close {
            background: transparent;
            border: none;
            color: var(--text-muted);
            font-size: 1.4rem;
            cursor: pointer;
        }
        .modal-close:hover { color: #ffffff; }
    </style>
    <link rel="stylesheet" href="sneat.css">
    <script src="confirm.js"></script>
</head>
<body>

    <!-- Top Navigation Bar -->
    <header class="navbar">
        <div class="nav-brand">
            <span>Word Cropper</span>
            <span class="brand-pill">Kraken Only</span>
        </div>
        <div class="nav-actions">
            <a href="index.php" class="btn-nav">← New Image</a>
        </div>
    </header>

    <?php if ($warnings): ?>
        <div style="background:#fff2d6;border-bottom:1px solid #ffe0a3;color:#a16a00;padding:10px 24px;font-size:.82rem;line-height:1.5;">
            <strong><?= count($warnings) ?> file(s) skipped:</strong>
            <?= htmlspecialchars(implode(' • ', $warnings)) ?>
        </div>
    <?php endif; ?>

    <!-- Side-by-Side Workspace -->
    <main class="workspace">
        
        <!-- Left Side: Original Image with Interactive Bounding Boxes -->
        <section class="image-pane">
            <div class="pane-header">
                <div class="pane-title">
                    <span>Document</span>
                    <span style="color:var(--text-dim); font-weight:400; font-size:.75rem;">(<?= count($pages) ?> page<?= count($pages) > 1 ? 's' : '' ?> • <?= $tableMode ? 'table cells' : 'words' ?>)</span>
                </div>
                <div class="pane-tools">
                    <?php foreach ($pages as $pg): ?>
                        <button type="button" class="tool-btn" style="width:auto;padding:0 10px;" title="Go to page <?= $pg['no'] ?>"
                                onclick="document.getElementById('page-<?= $pg['no'] ?>').scrollIntoView({behavior:'smooth', block:'start'});">P<?= $pg['no'] ?></button>
                    <?php endforeach; ?>
                    <button type="button" class="tool-btn" id="zoomOutBtn" title="Zoom Out">−</button>
                    <button type="button" class="tool-btn" id="zoomResetBtn" title="Reset Zoom">1:1</button>
                    <button type="button" class="tool-btn" id="zoomInBtn" title="Zoom In">+</button>
                </div>
            </div>

            <div class="image-viewport" id="imageViewport">
                <div id="canvasWrap" style="display:flex; flex-direction:column; gap:24px; align-items:center; transform-origin: top center; transition: transform .2s ease; width:100%;">
                <?php foreach ($pages as $pg): ?>
                    <div class="image-canvas-wrap" id="page-<?= $pg['no'] ?>" style="width:100%; margin-top:22px; scroll-margin-top:30px;">
                        <div style="position:absolute; top:-18px; left:0; font-size:.7rem; color:var(--text-muted);">Page <?= $pg['no'] ?> — <?= htmlspecialchars($pg['name']) ?> (<?= $pg['w'] ?> × <?= $pg['h'] ?> px)</div>
                        <img src="<?= htmlspecialchars($pg['src']) ?>" alt="Page <?= $pg['no'] ?>">
                        <div class="bbox-overlay">
                            <?php foreach ($croppedWords as $w):
                                if ($w['page'] !== $pg['no']) continue;
                                $bx = ($w['box']['x'] / $pg['w']) * 100;
                                $by = ($w['box']['y'] / $pg['h']) * 100;
                                $bw = ($w['box']['w'] / $pg['w']) * 100;
                                $bh = ($w['box']['h'] / $pg['h']) * 100;
                            ?>
                                <div class="bbox selected"
                                     id="bbox-<?= $w['id'] ?>"
                                     data-id="<?= $w['id'] ?>"
                                     style="left: <?= $bx ?>%; top: <?= $by ?>%; width: <?= $bw ?>%; height: <?= $bh ?>%;"
                                     title="<?= htmlspecialchars($w['label']) ?> (Click to toggle)">
                                    <span class="bbox-tag"><?= $w['id'] ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
                </div>
            </div>
        </section>

        <!-- Right Side: Detected Words Selection & Download Panel -->
        <section class="words-pane">
            
            <!-- Selection Action Bar -->
            <div class="action-bar">
                <div class="selection-stat">
                    <span class="sel-badge" id="selectedBadge"><?= $wordCount ?></span>
                    <span class="sel-text">of <?= $wordCount ?> <?= $tableMode ? 'cells' : 'words' ?> selected</span>
                </div>
                <div class="action-buttons">
                    <button type="button" class="btn-action" id="selectAllBtn">Select All</button>
                    <button type="button" class="btn-action" id="deselectAllBtn">Deselect All</button>
                    <button type="button" class="btn-download-selected" id="downloadSelectedBtn">
                        <span>💾 Save Selected (<span id="btnSelCount"><?= $wordCount ?></span>)</span>
                    </button>
                </div>
            </div>

            <!-- Search / Filter Bar -->
            <div class="search-bar">
                <div class="search-input-wrap">
                    <span class="search-icon">🔍</span>
                    <input type="text" id="searchInput" placeholder="Filter by # or label (e.g. P1 R3, C2)...">
                </div>
                <div class="meta-counts">
                    Format: <strong><?= strtoupper($outputFormat) ?></strong> • Padding: <strong><?= $padding ?>px</strong>
                </div>
            </div>

            <!-- Words Grid Area -->
            <div class="words-grid-wrap">
                <div class="words-grid" id="wordsGrid">
                    <?php foreach ($croppedWords as $w): ?>
                        <div class="word-card is-selected" 
                             id="card-<?= $w['id'] ?>" 
                             data-id="<?= $w['id'] ?>"
                             data-file="<?= htmlspecialchars($w['file']) ?>"
                             data-filename="<?= htmlspecialchars($w['filename']) ?>"
                             data-label="<?= htmlspecialchars($w['label']) ?>">
                            
                            <div class="card-top">
                                <label class="chk-wrap" onclick="event.stopPropagation();">
                                    <input type="checkbox" class="word-chk" data-id="<?= $w['id'] ?>" checked>
                                    <span class="custom-chk"></span>
                                    <span><?= htmlspecialchars($w['label']) ?></span>
                                </label>
                                <span class="dim-badge" id="dim-<?= $w['id'] ?>"><?= $w['w'] ?>×<?= $w['h'] ?></span>
                            </div>

                            <div class="crop-preview-wrap" title="Click to adjust the crop" style="cursor:pointer" onclick="event.stopPropagation(); openModal(<?= $w['id'] ?>, true)">
                                <img id="thumb-<?= $w['id'] ?>" src="<?= htmlspecialchars($w['file']) ?>" alt="<?= htmlspecialchars($w['label']) ?>" loading="lazy">
                            </div>

                            <div class="card-bottom">
                                <button type="button" class="btn-single-dl" onclick="saveSingleWord(event, '<?= htmlspecialchars($w['filename']) ?>');">
                                    💾 Save
                                </button>
                                <button type="button" class="btn-zoom" title="Inspect" onclick="event.stopPropagation(); openModal(<?= $w['id'] ?>)">
                                    🔍 Zoom
                                </button>
                            </div>

                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

        </section>

    </main>

    <!-- Preview Modal (view + adjust crop) -->
    <div class="modal" id="previewModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 id="modalTitle" style="font-size:1rem; font-weight:700;">Word Preview</h3>
                <div style="display:flex;align-items:center;gap:10px;">
                    <button type="button" class="btn-action" id="adjustBtn">✂ Adjust crop</button>
                    <button type="button" class="modal-close" onclick="closeModal()">×</button>
                </div>
            </div>
            <img id="modalImg" src="" alt="Word Zoom">

            <div id="cropEditor" style="display:none;">
                <div class="ce-hint">Drag on the picture to draw a new crop box (it replaces the old one); drag a handle to fine-tune it. The picture is re-cut from the page.</div>
                <div class="ce-stage" id="ceStage">
                    <canvas id="ceCanvas"></canvas>
                    <div class="ce-rect" id="ceRect">
                        <i data-h="nw"></i><i data-h="n"></i><i data-h="ne"></i><i data-h="e"></i>
                        <i data-h="se"></i><i data-h="s"></i><i data-h="sw"></i><i data-h="w"></i>
                    </div>
                </div>
                <div class="ce-bar">
                    <span id="ceInfo"></span>
                    <span style="display:flex;gap:8px;">
                        <button type="button" class="btn-action" id="ceReset">Reset</button>
                        <button type="button" class="btn-action" id="ceCancel">Cancel</button>
                        <button type="button" class="btn-download-selected" id="ceApply">✓ Apply</button>
                    </span>
                </div>
            </div>
        </div>
    </div>

    <div id="toasts" aria-live="polite"></div>

    <!-- Client-Side Interactive Logic -->
    <script>
        const wordsData = <?= json_encode($croppedWords) ?>;
        const pagesData = <?= json_encode(array_map(fn($p) => ['no' => $p['no'], 'w' => $p['w'], 'h' => $p['h']], $pages)) ?>;
        let selectedIds = new Set(wordsData.map(w => w.id));

        const selectedBadge = document.getElementById('selectedBadge');
        const btnSelCount = document.getElementById('btnSelCount');
        const downloadSelectedBtn = document.getElementById('downloadSelectedBtn');
        const selectAllBtn = document.getElementById('selectAllBtn');
        const deselectAllBtn = document.getElementById('deselectAllBtn');
        const searchInput = document.getElementById('searchInput');

        // Zoom controls for original image
        let currentZoom = 1;
        const canvasWrap = document.getElementById('canvasWrap');
        document.getElementById('zoomInBtn').addEventListener('click', () => {
            currentZoom = Math.min(currentZoom + 0.25, 3.0);
            canvasWrap.style.transform = `scale(${currentZoom})`;
        });
        document.getElementById('zoomOutBtn').addEventListener('click', () => {
            currentZoom = Math.max(currentZoom - 0.25, 0.5);
            canvasWrap.style.transform = `scale(${currentZoom})`;
        });
        document.getElementById('zoomResetBtn').addEventListener('click', () => {
            currentZoom = 1.0;
            canvasWrap.style.transform = `scale(1.0)`;
        });

        // After a successful save, return to the home page (short delay so the message can be read)
        function goHome(count) {
            setTimeout(() => { window.location.href = 'index.php?saved=' + encodeURIComponent(count); }, 1600);
        }

        // Toast message (type: ok | err); links = [{text, href}]
        function toast(msg, type = 'ok', links = [], ms = 6000) {
            const el = document.createElement('div');
            el.className = 'toast ' + type;
            const icon = document.createElement('span');
            icon.className = 'toast-ic';
            icon.textContent = type === 'ok' ? '✓' : '!';
            const body = document.createElement('div');
            body.className = 'toast-body';
            const t = document.createElement('div');
            t.textContent = msg;
            body.appendChild(t);
            if (links.length) {
                const row = document.createElement('div');
                row.className = 'toast-links';
                links.forEach(l => {
                    const a = document.createElement('a');
                    a.href = l.href; a.textContent = l.text;
                    row.appendChild(a);
                });
                body.appendChild(row);
            }
            const x = document.createElement('button');
            x.type = 'button'; x.className = 'toast-x'; x.textContent = '×';
            x.onclick = () => el.remove();
            el.append(icon, body, x);
            document.getElementById('toasts').appendChild(el);
            setTimeout(() => el.remove(), ms);
        }

        // Update selection UI state
        function updateSelectionState() {
            const count = selectedIds.size;
            selectedBadge.textContent = count;
            btnSelCount.textContent = count;
            downloadSelectedBtn.disabled = (count === 0);

            // Update cards and bounding boxes classes
            wordsData.forEach(w => {
                const card = document.getElementById('card-' + w.id);
                const bbox = document.getElementById('bbox-' + w.id);
                const chk = card ? card.querySelector('.word-chk') : null;

                const isSelected = selectedIds.has(w.id);
                if (card) card.classList.toggle('is-selected', isSelected);
                if (bbox) bbox.classList.toggle('selected', isSelected);
                if (chk) chk.checked = isSelected;
            });
        }

        // Toggle single word
        function toggleWord(id) {
            if (selectedIds.has(id)) {
                selectedIds.delete(id);
            } else {
                selectedIds.add(id);
            }
            updateSelectionState();
        }

        // Checkbox events
        document.querySelectorAll('.word-chk').forEach(chk => {
            chk.addEventListener('change', (e) => {
                const id = parseInt(e.target.dataset.id);
                if (e.target.checked) selectedIds.add(id);
                else selectedIds.delete(id);
                updateSelectionState();
            });
        });

        // Clicking card toggles selection
        document.querySelectorAll('.word-card').forEach(card => {
            card.addEventListener('click', () => {
                const id = parseInt(card.dataset.id);
                toggleWord(id);
            });
        });

        // Clicking bounding box on image toggles selection
        document.querySelectorAll('.bbox').forEach(bbox => {
            bbox.addEventListener('click', (e) => {
                e.stopPropagation();
                const id = parseInt(bbox.dataset.id);
                toggleWord(id);

                // Scroll right card into view
                const card = document.getElementById('card-' + id);
                if (card) {
                    card.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    card.classList.add('active-hover');
                    setTimeout(() => card.classList.remove('active-hover'), 1200);
                }
            });
        });

        // Synchronized Hover Effects (Image BBox <-> Card)
        document.querySelectorAll('.word-card').forEach(card => {
            const id = card.dataset.id;
            const bbox = document.getElementById('bbox-' + id);
            card.addEventListener('mouseenter', () => {
                if (bbox) bbox.classList.add('active-hover');
            });
            card.addEventListener('mouseleave', () => {
                if (bbox) bbox.classList.remove('active-hover');
            });
        });

        document.querySelectorAll('.bbox').forEach(bbox => {
            const id = bbox.dataset.id;
            const card = document.getElementById('card-' + id);
            bbox.addEventListener('mouseenter', () => {
                if (card) card.classList.add('active-hover');
            });
            bbox.addEventListener('mouseleave', () => {
                if (card) card.classList.remove('active-hover');
            });
        });

        // Select All / Deselect All
        selectAllBtn.addEventListener('click', () => {
            wordsData.forEach(w => selectedIds.add(w.id));
            updateSelectionState();
        });

        deselectAllBtn.addEventListener('click', () => {
            selectedIds.clear();
            updateSelectionState();
        });

        // Search Filter
        searchInput.addEventListener('input', (e) => {
            const q = e.target.value.trim().toLowerCase();
            document.querySelectorAll('.word-card').forEach(card => {
                const id = card.dataset.id;
                const label = (card.dataset.label || '').toLowerCase();
                const match = !q || id.includes(q) || label.includes(q);
                card.style.display = match ? 'flex' : 'none';
            });
        });

        // Save Single Word into output/ folder only
        async function saveSingleWord(e, filename) {
            e.preventDefault();
            e.stopPropagation();
            const btn = e.currentTarget;
            if (!await askConfirm({ title: 'Save this picture?', message: `${filename} will be added to the output/ folder.`, confirmText: 'Save' })) return;
            const origHtml = btn.innerHTML;
            btn.innerHTML = '💾...';

            try {
                const formData = new FormData();
                formData.append('action', 'save_to_output');
                formData.append('filenames', JSON.stringify([filename]));
                formData.append('append', '1');

                const res = await fetch('process.php', { method: 'POST', body: formData });
                const data = await res.json();
                if (!data.success || data.count < 1) throw new Error('file not found on the server');

                btn.innerHTML = '✓ Saved in output/';
                toast(`Added ${filename} to output/. Going back to the home page…`, 'ok');
                goHome(1);
                setTimeout(() => { btn.innerHTML = origHtml; }, 2000);
            } catch (err) {
                toast('Could not save the picture: ' + err.message, 'err');
                btn.innerHTML = origHtml;
            }
        }

        // Batch Save Selected into output/ folder only
        downloadSelectedBtn.addEventListener('click', async () => {
            if (selectedIds.size === 0) return;
            if (!await askConfirm({ title: `Save ${selectedIds.size} selected picture(s)?`,
                message: 'They are saved to the output/ folder and replace everything that is in it now.', confirmText: 'Save', danger: true })) return;

            downloadSelectedBtn.disabled = true;
            const origHtml = downloadSelectedBtn.innerHTML;
            downloadSelectedBtn.innerHTML = '<span>💾 Saving to output/...</span>';

            try {
                const selectedItems = wordsData.filter(w => selectedIds.has(w.id));
                const filenames = selectedItems.map(w => w.filename);

                // Put ONLY the saved selected pictures into output/ folder on server
                const formData = new FormData();
                formData.append('action', 'save_to_output');
                formData.append('filenames', JSON.stringify(filenames));
                formData.append('append', '0');

                const res = await fetch('process.php', { method: 'POST', body: formData });
                const data = await res.json();
                if (!data.success) throw new Error('server refused the save');

                downloadSelectedBtn.innerHTML = `<span>✓ Saved (${data.count} in output/)</span>`;
                toast(`${data.count} picture(s) saved to output/ (replaces what was there before). Going back to the home page…`, 'ok');
                goHome(data.count);
                setTimeout(() => {
                    downloadSelectedBtn.disabled = false;
                    downloadSelectedBtn.innerHTML = origHtml;
                }, 2000);
            } catch (err) {
                toast('Could not save: ' + err.message, 'err');
                downloadSelectedBtn.disabled = false;
                downloadSelectedBtn.innerHTML = origHtml;
            }
        });

        // Modal Lightbox + crop editor
        let modalWord = null;
        const $m = (id) => document.getElementById(id);
        const modalTitleFor = (w) => `${w.label} (${w.w}×${w.h} px)`;

        function openModal(id, edit = false) {
            modalWord = wordsData.find(w => w.id === id);
            if (!modalWord) return;
            endEdit();
            $m('modalImg').src = modalWord.file + '?v=' + (modalWord.v || 0);
            $m('modalTitle').textContent = modalTitleFor(modalWord);
            $m('previewModal').classList.add('open');
            if (edit) startEdit();   // clicking the picture goes straight into the crop editor
        }

        function closeModal() {
            endEdit();
            $m('previewModal').classList.remove('open');
        }

        // close only on a real click on the dark backdrop: a drag that starts in the editor and is
        // released outside it also produces a "click" on the backdrop, which used to throw the edit away
        let modalDownOnBackdrop = false;
        $m('previewModal').addEventListener('pointerdown', (e) => { modalDownOnBackdrop = e.target === e.currentTarget; }, true);
        $m('previewModal').addEventListener('click', (e) => {
            if (e.target === e.currentTarget && modalDownOnBackdrop) closeModal();
        });

        /** Parse a JSON reply; a PHP error page or an over-size upload otherwise surfaces as a cryptic JSON error. */
        async function readJson(res) {
            const txt = await res.text();
            try { return JSON.parse(txt); }
            catch (_) {
                const msg = txt.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 200);
                throw new Error(`server replied ${res.status}: ${msg || 'empty response (upload too large?)'}`);
            }
        }

        const ed = { rect: null, orig: null, region: null, k: 1, s: 1, img: null, page: null };

        function startEdit() {
            const w = modalWord;
            if (!w || !w.crop) return;
            const pg = pagesData.find(p => p.no === w.page);
            const img = document.querySelector('#page-' + w.page + ' img');
            if (!pg || !img || !img.naturalWidth) return;
            ed.img = img; ed.page = pg; ed.s = img.naturalWidth / pg.w;
            ed.orig = { x: w.crop.x, y: w.crop.y, w: w.w, h: w.h };
            ed.rect = { ...ed.orig };
            // show the crop with plenty of surrounding page so a new box can be drawn around it
            const m = Math.max(60, Math.round(Math.max(w.w, w.h)));
            const rx = Math.max(0, w.crop.x - m), ry = Math.max(0, w.crop.y - m);
            ed.region = {
                x: rx, y: ry,
                w: Math.min(pg.w, w.crop.x + w.w + m) - rx,
                h: Math.min(pg.h, w.crop.y + w.h + m) - ry
            };
            ed.k = Math.min(720 / ed.region.w, 380 / ed.region.h, 6);
            const cv = $m('ceCanvas');
            cv.width = Math.round(ed.region.w * ed.k);
            cv.height = Math.round(ed.region.h * ed.k);
            cv.getContext('2d').drawImage(img, ed.region.x * ed.s, ed.region.y * ed.s, ed.region.w * ed.s, ed.region.h * ed.s,
                                          0, 0, cv.width, cv.height);
            $m('modalImg').style.display = 'none';
            $m('cropEditor').style.display = 'block';
            $m('adjustBtn').style.display = 'none';
            drawRect();
        }

        function endEdit() {
            $m('cropEditor').style.display = 'none';
            $m('modalImg').style.display = '';
            $m('adjustBtn').style.display = '';
            ed.rect = null;
        }

        function drawRect() {
            const r = ed.rect, el = $m('ceRect');
            el.style.left = (r.x - ed.region.x) * ed.k + 'px';
            el.style.top = (r.y - ed.region.y) * ed.k + 'px';
            el.style.width = r.w * ed.k + 'px';
            el.style.height = r.h * ed.k + 'px';
            $m('ceInfo').textContent = `${Math.round(r.w)}×${Math.round(r.h)} px` +
                (ed.s < 1 ? ' · page preview is downscaled, so the new crop has a lower resolution' : '');
        }

        // drag a handle to fine-tune the box (anything else bubbles up to the stage: draw a new box)
        $m('ceRect').addEventListener('pointerdown', (e) => {
            const handle = e.target.dataset.h;
            if (!handle) return;
            e.preventDefault(); e.stopPropagation();
            const start = { px: e.clientX, py: e.clientY, ...ed.rect };
            const R = ed.region, MIN = 4;
            const move = (ev) => {
                const dx = (ev.clientX - start.px) / ed.k, dy = (ev.clientY - start.py) / ed.k;
                let x0 = start.x, y0 = start.y, x1 = start.x + start.w, y1 = start.y + start.h;
                if (handle.includes('w')) x0 = Math.min(Math.max(start.x + dx, R.x), x1 - MIN);
                if (handle.includes('e')) x1 = Math.max(Math.min(x1 + dx, R.x + R.w), x0 + MIN);
                if (handle.includes('n')) y0 = Math.min(Math.max(start.y + dy, R.y), y1 - MIN);
                if (handle.includes('s')) y1 = Math.max(Math.min(y1 + dy, R.y + R.h), y0 + MIN);
                ed.rect = { x: x0, y: y0, w: x1 - x0, h: y1 - y0 };
                drawRect();
            };
            const up = () => {
                window.removeEventListener('pointermove', move);
                window.removeEventListener('pointerup', up);
            };
            window.addEventListener('pointermove', move);
            window.addEventListener('pointerup', up);
        });

        // drag on the picture to draw a brand-new crop box that replaces the old one
        $m('ceStage').addEventListener('pointerdown', (e) => {
            if (e.button !== 0 || !ed.rect) return;
            e.preventDefault(); e.stopPropagation();
            const R = ed.region, MIN = 4, prev = { ...ed.rect };
            const cv = $m('ceCanvas'), cr = cv.getBoundingClientRect();
            const toPage = (ev) => ({
                x: Math.min(Math.max(R.x + (ev.clientX - cr.left) * (cv.width / cr.width) / ed.k, R.x), R.x + R.w),
                y: Math.min(Math.max(R.y + (ev.clientY - cr.top) * (cv.height / cr.height) / ed.k, R.y), R.y + R.h),
            });
            const p0 = toPage(e);
            const move = (ev) => {
                const p = toPage(ev);
                ed.rect = { x: Math.min(p0.x, p.x), y: Math.min(p0.y, p.y), w: Math.abs(p.x - p0.x), h: Math.abs(p.y - p0.y) };
                drawRect();
            };
            const up = () => {
                window.removeEventListener('pointermove', move);
                window.removeEventListener('pointerup', up);
                // a click (or a tiny drag) is not a new box: keep the previous one
                if (ed.rect && (ed.rect.w < MIN || ed.rect.h < MIN)) { ed.rect = prev; drawRect(); }
            };
            window.addEventListener('pointermove', move);
            window.addEventListener('pointerup', up);
        });

        $m('adjustBtn').addEventListener('click', startEdit);
        $m('ceCancel').addEventListener('click', endEdit);
        $m('ceReset').addEventListener('click', () => { ed.rect = { ...ed.orig }; drawRect(); });

        $m('ceApply').addEventListener('click', async () => {
            const w = modalWord, r = ed.rect, btn = $m('ceApply');
            if (!await askConfirm({ title: 'Apply this crop?', message: `The picture of ${w.label} is replaced by the new crop.`, confirmText: 'Apply' })) return;
            const ow = Math.max(1, Math.round(r.w * ed.s)), oh = Math.max(1, Math.round(r.h * ed.s));
            const out = document.createElement('canvas');
            out.width = ow; out.height = oh;
            out.getContext('2d').drawImage(ed.img, r.x * ed.s, r.y * ed.s, r.w * ed.s, r.h * ed.s, 0, 0, ow, oh);
            btn.disabled = true;
            try {
                const blob = await new Promise(res => out.toBlob(res, 'image/png'));
                const fd = new FormData();
                fd.append('action', 'replace_crop');
                fd.append('filename', w.filename);
                fd.append('image', blob, 'crop.png');
                const res = await fetch('process.php', { method: 'POST', body: fd });
                const data = await readJson(res);
                if (!data.success) throw new Error(data.error || 'save failed');

                // update the data model, the card and the box drawn on the page
                w.w = data.w; w.h = data.h;
                w.crop = { x: Math.round(r.x), y: Math.round(r.y) };
                w.box = { x: w.crop.x, y: w.crop.y, w: Math.round(r.w), h: Math.round(r.h) };
                w.v = Date.now();
                $m('thumb-' + w.id).src = w.file + '?v=' + w.v;
                $m('dim-' + w.id).textContent = `${w.w}×${w.h}`;
                const pg = ed.page, bb = $m('bbox-' + w.id);
                bb.style.left = w.box.x / pg.w * 100 + '%';
                bb.style.top = w.box.y / pg.h * 100 + '%';
                bb.style.width = w.box.w / pg.w * 100 + '%';
                bb.style.height = w.box.h / pg.h * 100 + '%';
                $m('modalImg').src = w.file + '?v=' + w.v;
                $m('modalTitle').textContent = modalTitleFor(w);
                endEdit();
                toast(`Crop of ${w.label} updated (${w.w}×${w.h} px). Press Save to put it in output/.`, 'ok');
            } catch (err) {
                toast('Could not adjust the crop: ' + err.message, 'err');
            } finally {
                btn.disabled = false;
            }
        });

        document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeModal(); });
    </script>
</body>
</html>
