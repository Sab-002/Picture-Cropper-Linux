<?php
require __DIR__ . '/htr_lib.php';

// ── AJAX: run the HTR batch-processing job and return a JSON summary ───────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'batch_process_htr') {
    header('Content-Type: application/json');
    $result = batchProcessForHTR($outputDir, $htrImageDir, $htrExcelAbs);
    echo json_encode([
        'success' => $result['success'],
        'processed' => $result['processed'],
        'skipped' => $result['skipped'],
        'new' => $result['new'],
        'total' => count($result['manifest']),
        'labeled' => count(array_filter($result['manifest'], fn($r) => ($r['status'] ?? '') === 'labeled')),
        'unreadable' => count(array_filter($result['manifest'], fn($r) => ($r['status'] ?? '') === 'unreadable')),
        'excel_file' => $htrExcelRel,
        'images_dir' => 'htr_dataset/images/',
    ]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kraken Word Cropper</title>
    <meta name="description"
        content="Automatic single-word scanner and cropper powered by Kraken BLLA neural segmentation.">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap"
        rel="stylesheet">
    <style>
        *,
        *::before,
        *::after {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        :root {
            --bg: #050507;
            --kc-card: #0d0d10;
            --kc-surface: #141418;
            --kc-border: #26262b;
            --kc-muted: #8b8b96;
            --kc-text: #ffffff;
            --kc-btn-bg: #ffffff;
            --kc-btn-text: #000000;
            --glow: rgba(255, 255, 255, 0.18);
        }

        html,
        body {
            min-height: 100vh;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: var(--bg);
            color: var(--kc-text);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 48px 20px 80px;
            background-image:
                radial-gradient(circle at 50% 0%, rgba(255, 255, 255, 0.05) 0%, transparent 60%),
                linear-gradient(to bottom, #070709, #030304);
            background-attachment: fixed;
        }

        .header {
            text-align: center;
            margin-bottom: 32px;
        }

        .engine-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 6px 16px;
            background: var(--kc-surface);
            border: 1px solid var(--kc-border);
            border-radius: 999px;
            font-size: .78rem;
            font-weight: 700;
            letter-spacing: .8px;
            text-transform: uppercase;
            color: var(--kc-text);
            margin-bottom: 16px;
        }

        .engine-badge .pulse {
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: #ffffff;
            box-shadow: 0 0 8px #ffffff;
            animation: pulse 2s infinite;
        }

        @keyframes pulse {

            0%,
            100% {
                opacity: 1;
                transform: scale(1);
            }

            50% {
                opacity: .4;
                transform: scale(.85);
            }
        }

        h1 {
            font-size: 2.2rem;
            font-weight: 900;
            letter-spacing: -1px;
            color: #ffffff;
            line-height: 1.15;
            margin-bottom: 8px;
        }

        .subtitle {
            color: var(--kc-muted);
            font-size: .92rem;
            max-width: 480px;
            line-height: 1.5;
            margin: 0 auto;
        }

        .kc {
            width: 100%;
            max-width: 580px;
        }

        .kc-form {
            background: var(--kc-card);
            border: 1px solid var(--kc-border);
            border-radius: 24px;
            padding: 36px;
            display: flex;
            flex-direction: column;
            gap: 24px;
            box-shadow: 0 20px 50px rgba(0, 0, 0, 0.6), 0 0 0 1px rgba(255, 255, 255, 0.03);
        }

        .kc-drop {
            border: 2px dashed var(--kc-border);
            border-radius: 18px;
            padding: 38px 24px;
            text-align: center;
            cursor: pointer;
            background: var(--kc-surface);
            position: relative;
            transition: all .25s ease;
        }

        .kc-drop:hover,
        .kc-drop.dragover {
            border-color: #ffffff;
            background: rgba(255, 255, 255, .04);
            box-shadow: 0 0 24px var(--glow);
        }

        .kc-drop input[type=file] {
            position: absolute;
            inset: 0;
            opacity: 0;
            cursor: pointer;
            width: 100%;
            height: 100%;
        }

        .kc-drop-icon {
            font-size: 2.4rem;
            margin-bottom: 12px;
            filter: grayscale(1);
        }

        .kc-drop-title {
            font-weight: 700;
            font-size: 1rem;
            margin-bottom: 4px;
        }

        .kc-drop-sub {
            font-size: .8rem;
            color: var(--kc-muted);
        }

        .kc-preview {
            display: none;
            position: relative;
            border-radius: 14px;
            overflow: hidden;
            border: 1px solid var(--kc-border);
            background: #000;
            max-height: 220px;
        }

        .kc-preview img {
            width: 100%;
            max-height: 220px;
            object-fit: contain;
            display: block;
        }

        .kc-meta {
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            background: rgba(0, 0, 0, .85);
            backdrop-filter: blur(8px);
            padding: 8px 14px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: .75rem;
            border-top: 1px solid var(--kc-border);
        }

        .kc-meta span {
            font-weight: 600;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 280px;
        }

        .kc-remove {
            background: transparent;
            border: none;
            color: #f87171;
            cursor: pointer;
            font-size: .8rem;
            font-weight: 600;
            padding: 2px 6px;
        }

        .kc-remove:hover {
            text-decoration: underline;
        }

        .kc-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }

        .kc-field {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .kc-field label {
            font-size: .78rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .6px;
            color: var(--kc-muted);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .kc-badge {
            color: #ffffff;
            background: var(--kc-surface);
            border: 1px solid var(--kc-border);
            border-radius: 6px;
            padding: 2px 8px;
            font-size: .75rem;
        }

        .kc-slider {
            background: var(--kc-surface);
            border: 1px solid var(--kc-border);
            border-radius: 12px;
            padding: 10px 14px;
            display: flex;
            align-items: center;
        }

        .kc-slider input {
            width: 100%;
            height: 4px;
            background: var(--kc-border);
            border-radius: 2px;
            accent-color: #ffffff;
            cursor: pointer;
        }

        .kc select {
            width: 100%;
            background: var(--kc-surface);
            border: 1px solid var(--kc-border);
            border-radius: 12px;
            color: #ffffff;
            font: inherit;
            font-size: .85rem;
            padding: 11px 14px;
            outline: none;
            cursor: pointer;
            transition: border-color .2s;
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' fill='%23ffffff' viewBox='0 0 16 16'%3E%3Cpath d='M7.247 11.14 2.451 5.658C1.885 5.013 2.345 4 3.204 4h9.592a1 1 0 0 1 .753 1.659l-4.796 5.48a1 1 0 0 1-1.506 0z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 14px center;
        }

        .kc select:focus {
            border-color: #ffffff;
        }

        .kc-submit {
            width: 100%;
            padding: 16px;
            background: var(--kc-btn-bg);
            color: var(--kc-btn-text);
            border: none;
            border-radius: 14px;
            font: inherit;
            font-size: .95rem;
            font-weight: 800;
            letter-spacing: .5px;
            text-transform: uppercase;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            transition: all .2s ease;
            box-shadow: 0 4px 24px rgba(255, 255, 255, 0.2);
        }

        .kc-submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 32px rgba(255, 255, 255, 0.35);
        }

        .kc-submit:active {
            transform: translateY(0);
        }

        .kc-submit:disabled {
            opacity: .4;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }

        .kc-spinner {
            display: none;
            width: 18px;
            height: 18px;
            border: 2px solid #000000;
            border-top-color: transparent;
            border-radius: 50%;
            animation: kc-spin .7s linear infinite;
        }

        @keyframes kc-spin {
            to {
                transform: rotate(360deg);
            }
        }
    </style>
    <link rel="stylesheet" href="sneat.css">
    <script src="confirm.js?v=<?= @filemtime(__DIR__ . '/confirm.js') ?>"></script>
    <script src="labels-io.js?v=<?= @filemtime(__DIR__ . '/labels-io.js') ?>"></script>
</head>

<body>

    <?php $savedCount = isset($_GET['saved']) ? max(0, (int) $_GET['saved']) : 0; ?>
    <?php if ($savedCount > 0): ?>
        <div class="kc-alert" id="kcAlert">
            <span class="kc-alert-ic">✓</span>
            <span><b><?= $savedCount ?></b> picture<?= $savedCount === 1 ? '' : 's' ?> saved to <code>output/</code>. You can upload another document below.</span>
            <button type="button" onclick="this.parentNode.remove()" aria-label="Close">×</button>
        </div>
    <?php endif; ?>

    <div class="header">
        <div class="engine-badge">
            <div class="pulse"></div>
            Kraken BLLA Engine
        </div>
        <h1>Word Cropper</h1>
        <p class="subtitle">Upload document pages: table grids are cropped cell by cell, other text word by word (Kraken).</p>
    </div>

    <div class="kc">
        <form class="kc-form" id="kcForm" action="process.php" method="POST" enctype="multipart/form-data">
            <div class="kc-drops" id="kcDrops">
            <div class="kc-drop" id="kcDrop">
                <input type="file" name="images[]" id="kcFile" accept="application/pdf,.pdf,image/png,image/jpeg,image/webp" multiple required>
                <div class="kc-drop-icon">📄</div>
                <div class="kc-drop-title">Single: drop pages here or click to browse</div>
                <div class="kc-drop-sub">PDF, or one or many page images (PNG, JPG, WEBP) • tables are cropped cell by cell</div>
            </div>
            <a class="kc-drop kc-batch" href="batch.php">
                <div class="kc-drop-icon">📁</div>
                <div class="kc-drop-title">Batch: scan a whole folder</div>
                <div class="kc-drop-sub">Every file and sub-folder is scanned, then you verify and save one by one</div>
            </a>
            </div>

            <div class="kc-preview" id="kcPreview">
                <img id="kcImg" src="" alt="Upload preview">
                <div class="kc-meta">
                    <span id="kcName"></span>
                    <button type="button" class="kc-remove" id="kcRemove">Remove</button>
                </div>
            </div>

            <div class="kc-grid">
                <div class="kc-field">
                    <label for="kcPad">Crop Padding <span class="kc-badge" id="kcPadVal">4 px</span></label>
                    <div class="kc-slider">
                        <input type="range" name="padding" id="kcPad" min="0" max="24" value="4">
                    </div>
                </div>
                <div class="kc-field">
                    <label for="kcFmt">Output Format</label>
                    <select name="output_format" id="kcFmt">
                        <option value="png" selected>PNG (Lossless)</option>
                        <option value="webp">WEBP (Optimized)</option>
                        <option value="jpeg">JPEG (Standard)</option>
                    </select>
                </div>
            </div>

            <button type="submit" class="kc-submit" id="kcSubmit">
                <span class="kc-spinner" id="kcSpin"></span>
                <span id="kcBtnText">Scan &amp; Crop Document</span>
            </button>
        </form>
    </div>

    <div class="kc" style="margin-top:24px;">
        <div class="kc-form" id="htrForm">
            <div>
                <div class="kc-drop-title" style="margin-bottom:4px;">Prepare HTR Training Dataset</div>
                <div class="kc-drop-sub">Batch-processes every image saved in <code>output/</code> into
                    model-ready crops (original pixels, unmodified) and exports an Excel manifest
                    (<code>htr_dataset/dataset_labels.xlsx</code>). Then open the annotation tool to type the word of each image.</div>
            </div>
            <button type="button" class="kc-submit" id="htrSubmit">
                <span class="kc-spinner" id="htrSpin"></span>
                <span id="htrBtnText">⚙️ Batch Process for HTR</span>
            </button>
            <a class="kc-submit" href="annotate.php" style="text-decoration:none;">✍️ Open annotation tool</a>
            <div class="kc-io">
                <div class="kc-io-title">Annotated dataset</div>
                <div class="kc-result-btns" style="margin-top:8px;">
                    <button type="button" class="kc-btn" id="dlAnnotatedZip">⬇ Download annotated dataset (.zip)</button>
                    <button type="button" class="kc-btn" id="dlLabels">⬇ Download annotated only (.xlsx)</button>
                    <button type="button" class="kc-btn" id="dlCsv">⬇ labels.csv</button>
                    <button type="button" class="kc-btn" id="impLabels">⬆ Import annotated file</button>
                </div>
                <div class="kc-drop-sub" style="margin-top:8px;">Import an .xlsx, .csv, .tsv or manifest .json that was annotated elsewhere: its labels are merged into this dataset by each image's unique uid. The .zip holds the annotated images together with their labels (<code>labels.csv</code> / <code>labels.xlsx</code>).</div>
                <div id="ioMsg" class="kc-io-msg"></div>
            </div>
            <div class="kc-io">
                <div class="kc-io-title">Images to annotate (not annotated yet)</div>
                <div class="kc-result-btns" style="margin-top:8px;">
                    <button type="button" class="kc-btn" id="dlPending">⬇ Download not-annotated images (.zip)</button>
                    <button type="button" class="kc-btn" id="upPending">⬆ Upload images to annotate</button>
                </div>
                <div class="kc-drop-sub" style="margin-top:8px;">The .zip holds every image that still has no label plus a <code>labels_template</code> to fill in; import it back with "Import annotated file". Upload PNG / JPG / WEBP pictures (or a .zip of them) that were cropped elsewhere to add them to the annotation tool, with their pixels unchanged.</div>
                <div id="pendMsg" class="kc-io-msg"></div>
            </div>
            <div class="kc-io">
                <div class="kc-io-title">Training dataset (.zip)</div>
                <div class="kc-drop-sub" style="margin-top:4px;">Packs every annotated image into <code>dataset_NNN.zip</code> with <code>train/</code>, <code>val/</code>, <code>test/</code>, <code>labels.csv</code>, <code>manifest.csv</code> and the validation / statistics files, ready to train the model.</div>
                <div class="kc-result-btns" style="margin-top:10px;align-items:center;">
                    <label class="kc-split">Split train / val / test
                        <select id="zipSplit">
                            <option value="80-10-10" selected>80 / 10 / 10</option>
                            <option value="70-15-15">70 / 15 / 15</option>
                            <option value="60-20-20">60 / 20 / 20</option>
                        </select>
                    </label>
                    <button type="button" class="kc-btn primary" id="buildZip">📦 Build training dataset (.zip)</button>
                </div>
                <div id="zipMsg" class="kc-io-msg"></div>
            </div>
            <div id="htrResult" style="display:none;font-size:.85rem;color:var(--kc-muted);line-height:1.6;"></div>
        </div>
    </div>

    <script>
        (function () {
            const $ = (id) => document.getElementById(id);
            const drop = $('kcDrop');
            const file = $('kcFile');
            const MAX_FILES = <?= (int) ini_get('max_file_uploads') ?: 20 ?>;
            const preview = $('kcPreview');

            $('kcPad').addEventListener('input', (e) => {
                $('kcPadVal').textContent = e.target.value + ' px';
            });

            ['dragenter', 'dragover'].forEach(name => {
                drop.addEventListener(name, (e) => {
                    e.preventDefault();
                    drop.classList.add('dragover');
                });
            });

            ['dragleave', 'drop'].forEach(name => {
                drop.addEventListener(name, (e) => {
                    e.preventDefault();
                    drop.classList.remove('dragover');
                });
            });

            file.addEventListener('change', () => {
                const f = file.files[0];
                if (!f) return;
                const total = file.files.length;
                if (total > MAX_FILES) {
                    alert('This server accepts at most ' + MAX_FILES + ' files per upload (' + total +
                          ' selected).\nPut the pages into one PDF (a PDF can have any number of pages) or upload in groups.');
                    file.value = '';
                    return;
                }
                const names = total > 1 ? total + ' files selected' : f.name + ' (' + (f.size / 1024).toFixed(1) + ' KB)';
                const reader = new FileReader();
                reader.onload = (e) => {
                    $('kcImg').src = f.type === 'application/pdf'
                        ? 'data:image/svg+xml;utf8,' + encodeURIComponent('<svg xmlns="http://www.w3.org/2000/svg" width="240" height="140"><rect width="100%" height="100%" fill="#e7e7ff"/><text x="50%" y="55%" fill="#696cff" font-size="28" font-family="sans-serif" text-anchor="middle">PDF</text></svg>')
                        : e.target.result;
                    $('kcName').textContent = names;
                    $('kcDrops').style.display = 'none';
                    preview.style.display = 'block';
                };
                reader.readAsDataURL(f);
            });

            LabelsIO.wireZip({ btn: 'buildZip', split: 'zipSplit', msg: 'zipMsg' });
            LabelsIO.wire({
                download: ['dlLabels', 'dlCsv'], zipBtn: 'dlAnnotatedZip', importBtn: 'impLabels', msg: 'ioMsg',
                onMerged: () => {}
            });
            LabelsIO.wirePending({ download: 'dlPending', upload: 'upPending', msg: 'pendMsg' });

            $('kcRemove').addEventListener('click', () => {
                file.value = '';
                $('kcImg').src = '';
                preview.style.display = 'none';
                $('kcDrops').style.display = 'grid';
            });

            let scanConfirmed = false;
            $('kcForm').addEventListener('submit', async (e) => {
                if (!scanConfirmed) {
                    e.preventDefault();
                    const ok = await askConfirm({ title: 'Scan and crop this document?',
                        message: 'A new scan clears the pictures currently in the output/ folder. Save anything you need first.',
                        confirmText: 'Scan', danger: true });
                    if (!ok) return;
                    scanConfirmed = true;
                    $('kcForm').requestSubmit();
                    return;
                }
                $('kcSubmit').disabled = true;
                $('kcSpin').style.display = 'inline-block';
                $('kcBtnText').textContent = 'Scanning pages...';
            });

            // Batch-process everything saved in output/ into an HTR-ready dataset
            $('htrSubmit').addEventListener('click', async () => {
                const btn = $('htrSubmit');
                const spin = $('htrSpin');
                const text = $('htrBtnText');
                const result = $('htrResult');

                if (!await askConfirm({ title: 'Prepare the HTR dataset?',
                    message: 'Every image in output/ is processed and the dataset files (Excel, CSV, TSV) are rewritten.', confirmText: 'Run' })) return;

                btn.disabled = true;
                spin.style.display = 'inline-block';
                text.textContent = 'Processing...';
                result.style.display = 'none';

                try {
                    const formData = new FormData();
                    formData.append('action', 'batch_process_htr');

                    const res = await fetch('index.php', { method: 'POST', body: formData });
                    const data = await res.json();

                    if (data.success) {
                        const done = (data.labeled || 0) + (data.unreadable || 0);
                        const left = Math.max(0, data.total - done);
                        result.innerHTML = data.total > 0
                            ? (data.processed > 0
                                ? `✓ Processed ${data.processed} image(s) into <code>${data.images_dir}</code> (${data.skipped} skipped).<br>`
                                : `✓ No new images in <code>output/</code>. The existing annotation set has ${data.total} image(s).<br>`) +
                            `<div class="kc-result-btns">` +
                            `<a class="kc-btn primary" href="annotate.php">✍️ Open annotation tool (${data.total} images)` +
                            `<span class="kc-prog ${left === 0 ? 'done' : ''}">${left === 0 ? '✓ All annotated' : done + ' annotated · ' + left + ' not done'}</span></a>` +
                            `<a class="kc-btn" href="${data.excel_file}" download>⬇ dataset_labels.xlsx</a>` +
                            `</div>`
                            : 'No images found in output/ yet. Save some cropped words first.';
                    } else {
                        result.textContent = 'Batch processing failed.';
                    }
                    result.style.display = 'block';
                } catch (err) {
                    result.textContent = 'Error: ' + err.message;
                    result.style.display = 'block';
                } finally {
                    btn.disabled = false;
                    spin.style.display = 'none';
                    text.textContent = '⚙️ Batch Process for HTR';
                }
            });
        })();
    </script>
</body>

</html>
