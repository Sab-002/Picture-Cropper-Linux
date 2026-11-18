<?php
$maxFiles = (int) ini_get('max_file_uploads');
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Folder Batch — Kraken Cropper</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        :root {
            --bg:#050507; --panel:#0d0d10; --card:#121216; --surface:#18181d;
            --border:#26262b; --border-hover:#404048; --text:#fff; --muted:#8b8b96; --dim:#52525b;
            --ok:#4ade80; --warn:#fbbf24; --err:#f87171; --info:#60a5fa;
        }
        html, body { height:100%; background:var(--bg); color:var(--text); font-family:'Inter',sans-serif; }
        body { display:flex; flex-direction:column; overflow:hidden; }
        button { font-family:inherit; }

        .navbar { height:56px; flex:0 0 56px; background:var(--panel); border-bottom:1px solid var(--border);
            display:flex; align-items:center; justify-content:space-between; padding:0 20px; }
        .brand { display:flex; align-items:center; gap:10px; font-weight:800; }
        .pill { padding:3px 10px; background:var(--surface); border:1px solid var(--border); border-radius:999px;
            font-size:.7rem; font-weight:700; text-transform:uppercase; letter-spacing:.5px; }
        .btn { display:inline-flex; align-items:center; gap:6px; padding:8px 14px; background:var(--surface);
            border:1px solid var(--border); border-radius:8px; color:var(--text); font-size:.82rem; font-weight:600;
            cursor:pointer; text-decoration:none; transition:all .15s; }
        .btn:hover { border-color:#fff; }
        .btn:disabled { opacity:.4; cursor:not-allowed; border-color:var(--border); }
        .btn.primary { background:#fff; color:#000; border-color:#fff; font-weight:800; }
        .btn.primary:disabled { background:#fff; }

        /* ── setup ── */
        #setup { max-width:760px; margin:40px auto; padding:0 20px; width:100%; overflow:auto; }
        #setup h1 { font-size:1.5rem; font-weight:800; margin-bottom:6px; }
        #setup p.sub { color:var(--muted); font-size:.9rem; margin-bottom:22px; line-height:1.5; }
        .picker { display:grid; grid-template-columns:1fr 1fr; gap:14px; margin-bottom:16px; }
        .drop { border:1.5px dashed var(--border-hover); border-radius:16px; padding:28px 16px; text-align:center;
            background:var(--card); cursor:pointer; transition:all .15s; }
        .drop:hover, .drop.over { border-color:#fff; background:var(--surface); }
        .drop .ic { font-size:2rem; margin-bottom:8px; }
        .drop .t { font-weight:700; font-size:.95rem; }
        .drop .s { color:var(--muted); font-size:.78rem; margin-top:4px; }
        .opts { display:flex; gap:18px; flex-wrap:wrap; align-items:center; margin:16px 0; color:var(--muted); font-size:.85rem; }
        .opts select, .opts input[type=range] { accent-color:#fff; }
        .opts select { background:var(--surface); color:#fff; border:1px solid var(--border); border-radius:8px; padding:6px 10px; }
        #selSummary { color:var(--muted); font-size:.85rem; margin-bottom:14px; min-height:1.2em; }
        input[type=file] { display:none; }

        /* ── session ── */
        #session { flex:1; display:none; min-height:0; flex-direction:column; }
        .progress-bar { flex:0 0 auto; background:var(--panel); border-bottom:1px solid var(--border); padding:10px 20px;
            display:flex; align-items:center; gap:16px; flex-wrap:wrap; }
        .meter { flex:1; min-width:160px; height:8px; background:var(--surface); border-radius:99px; overflow:hidden; }
        .meter > i { display:block; height:100%; width:0; background:#fff; transition:width .25s; }
        .stat { font-size:.8rem; color:var(--muted); white-space:nowrap; }
        .stat b { color:#fff; }

        .layout { flex:1; min-height:0; display:grid; grid-template-columns:260px 1fr; }
        @media (max-width: 900px) { .layout { grid-template-columns:1fr; } #queue { max-height:140px; } }
        #queue { border-right:1px solid var(--border); background:var(--panel); overflow:auto; }
        .q-item { padding:9px 14px; border-bottom:1px solid var(--border); font-size:.8rem; cursor:default; display:flex; gap:8px; align-items:center; }
        .q-item.clickable { cursor:pointer; }
        .q-item.clickable:hover { background:var(--surface); }
        .q-item.current { background:var(--surface); box-shadow:inset 3px 0 0 #fff; }
        .q-name { flex:1; min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
        .chip { font-size:.66rem; font-weight:700; padding:2px 7px; border-radius:99px; border:1px solid var(--border); color:var(--muted); white-space:nowrap; }
        .chip.scanning { color:var(--info); border-color:var(--info); }
        .chip.ready { color:var(--warn); border-color:var(--warn); }
        .chip.saved { color:var(--ok); border-color:var(--ok); }
        .chip.error { color:var(--err); border-color:var(--err); }

        #review { min-width:0; min-height:0; display:flex; flex-direction:column; }
        #waiting, #done { margin:auto; text-align:center; max-width:460px; padding:24px; color:var(--muted); line-height:1.6; }
        #waiting h2, #done h2 { color:#fff; margin-bottom:8px; }
        #reviewBody { flex:1; min-height:0; display:none; grid-template-columns:1fr 1.15fr; }
        @media (max-width: 1100px) { #reviewBody { grid-template-columns:1fr; overflow:auto; } }

        .pane-head { padding:10px 16px; background:var(--panel); border-bottom:1px solid var(--border); display:flex; align-items:center; justify-content:space-between; gap:10px; flex-wrap:wrap; }
        .pane-title { font-size:.78rem; font-weight:800; text-transform:uppercase; letter-spacing:.6px; }
        .viewer { border-right:1px solid var(--border); background:#020203; display:flex; flex-direction:column; min-height:0; }
        .viewport { flex:1; overflow:auto; padding:22px; display:flex; flex-direction:column; gap:24px; align-items:center; }
        .page { position:relative; width:100%; flex:0 0 auto; margin:18px auto 0; border-radius:4px; box-shadow:0 10px 40px rgba(0,0,0,.9), 0 0 0 1px var(--border); }
        .page > .cap { position:absolute; top:-17px; left:0; font-size:.68rem; color:var(--muted); }
        .page img { display:block; width:100%; height:auto; border-radius:4px; }
        .overlay { position:absolute; inset:0; }
        .bbox { position:absolute; border:1.5px solid transparent; background:transparent; border-radius:2px; cursor:pointer; transition:all .12s; }
        .bbox.sel { border:1.5px solid rgba(74,222,128,.9); background:rgba(74,222,128,.12); }
        .bbox:hover, .bbox.hl { border-color:#fff; background:rgba(255,255,255,.28); z-index:5; }

        .words { display:flex; flex-direction:column; min-height:0; background:var(--bg); }
        .actions { padding:12px 16px; background:var(--panel); border-bottom:1px solid var(--border); display:flex; align-items:center; justify-content:space-between; gap:10px; flex-wrap:wrap; }
        .grid-wrap { flex:1; overflow:auto; padding:16px; }
        .grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(170px, 1fr)); gap:12px; }
        .wcard { background:var(--card); border:1px solid var(--border); border-radius:10px; padding:10px; display:flex; flex-direction:column; gap:8px; cursor:pointer; transition:all .12s; }
        .wcard:hover, .wcard.hl { border-color:#fff; }
        .wcard.sel { border-color:rgba(74,222,128,.7); background:rgba(74,222,128,.05); }
        .wcard .top { display:flex; justify-content:space-between; align-items:center; font-size:.76rem; font-weight:700; }
        .wcard .top .dim { color:var(--dim); font-weight:500; font-size:.7rem; }
        .chk { width:16px; height:16px; accent-color:#4ade80; }
        .thumb { height:70px; background:#000; border:1px solid var(--border); border-radius:6px; display:flex; align-items:center; justify-content:center; padding:4px; overflow:hidden; }
        .thumb img { max-width:100%; max-height:100%; object-fit:contain; }
        .foot { padding:12px 16px; background:var(--panel); border-top:1px solid var(--border); display:flex; align-items:center; justify-content:space-between; gap:10px; flex-wrap:wrap; }
        .foot .hint { font-size:.76rem; color:var(--muted); }

        .modal { display:none; position:fixed; inset:0; background:rgba(0,0,0,.9); z-index:50; align-items:center; justify-content:center; padding:24px; }
        .modal.open { display:flex; }
        .modal .mbox { background:var(--panel); border:1px solid var(--border); border-radius:14px; padding:18px; max-width:92vw; max-height:90vh; overflow:auto; display:flex; flex-direction:column; gap:12px; }
        .modal .mhead { display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap; }
        #zoomWrap { overflow:auto; max-height:65vh; border-radius:8px; background:#000; display:flex; align-items:flex-start; justify-content:center; }
        .modal #modalImg { max-width:100%; max-height:65vh; object-fit:contain; display:block; }
        .zoom-controls { display:flex; align-items:center; gap:4px; }
        #zoomLabel { font-size:.78rem; color:var(--muted); min-width:3.2em; text-align:center; user-select:none; }
        .modal .ce-hint, .modal .ce-bar { color:var(--muted); }
        .modal .ce-stage { background:#000; border-color:var(--border); }
        .ce-rect i[data-h="rot"] { left:calc(50% - 8px); top:-36px; width:16px; height:16px; border-radius:50%; background:#fbbf24; border-color:#fff; cursor:grab; }
        .ce-rect i[data-h="rot"]::before { content:''; position:absolute; left:50%; top:100%; width:2px; height:16px; margin-left:-1px; background:#fbbf24; }

        /* ── manual crop ── */
        #manualBtn.active { background:#fbbf24; color:#000; border-color:#fbbf24; font-weight:800; }
        .manual-overlay { position:absolute; inset:0; cursor:crosshair; z-index:20; border-radius:4px; }
        .manual-sel { position:absolute; border:2px dashed #fbbf24; background:rgba(251,191,36,.12); pointer-events:none; z-index:21; border-radius:2px; }
        .bbox.manual.sel { border-color:rgba(251,191,36,.95) !important; background:rgba(251,191,36,.18) !important; }
        .wcard.manual { border-color:rgba(251,191,36,.5); }
        .wcard.manual .top span { color:var(--warn); }
    </style>
    <link rel="stylesheet" href="sneat.css">
    <script src="confirm.js"></script>
</head>
<body>
    <header class="navbar">
        <div class="brand"><span>Word Cropper</span><span class="pill">Folder batch</span></div>
        <a class="btn" href="index.php">← Single upload</a>
    </header>

    <!-- ── 1. choose a folder ── -->
    <section id="setup">
        <h1>Scan a whole folder</h1>
        <p class="sub">Every document in the folder (images and PDFs, sub-folders included) is scanned and cropped first.
            Then you check each file one by one: <b>Save</b> keeps the cropped images you ticked in <code>output/</code>,
            and the next file opens. The scanned documents themselves are never stored.</p>

        <div class="picker">
            <div class="drop" id="pickFolder"><div class="ic">📁</div><div class="t">Choose a folder</div><div class="s">all sub-folders are included</div></div>
            <div class="drop" id="pickFiles"><div class="ic">📄</div><div class="t">Choose files</div><div class="s">PDF · PNG · JPG · WEBP</div></div>
        </div>
        <input type="file" id="folderInput" webkitdirectory directory multiple>
        <input type="file" id="filesInput" multiple accept="application/pdf,.pdf,image/png,image/jpeg,image/webp">

        <div id="selSummary"></div>

        <div class="opts">
            <label>Crop padding <b id="padVal">4 px</b> <input type="range" id="pad" min="0" max="24" value="4"></label>
            <label>Format
                <select id="fmt">
                    <option value="png" selected>PNG (lossless)</option>
                    <option value="webp">WEBP</option>
                    <option value="jpeg">JPEG</option>
                </select>
            </label>
        </div>
        <button class="btn primary" id="startBtn" disabled>▶ Scan &amp; crop all documents</button>
    </section>

    <!-- ── 2. scan queue + verification ── -->
    <section id="session">
        <div class="progress-bar">
            <div class="stat">Scanned <b id="stScanned">0</b>/<b id="stTotal">0</b></div>
            <div class="meter"><i id="meter"></i></div>
            <div class="stat">Saved <b id="stSaved">0</b> files · <b id="stCrops">0</b> crops</div>
            <div class="stat">Skipped <b id="stSkipped">0</b> · Errors <b id="stErr">0</b></div>
        </div>
        <div class="layout">
            <aside id="queue"></aside>
            <div id="review">
                <div id="waiting"><h2>Scanning…</h2><div>The first document will open here as soon as it is ready.</div></div>
                <div id="done" style="display:none"></div>
                <div id="reviewBody">
                    <section class="viewer">
                        <div class="pane-head">
                            <div class="pane-title" id="docTitle"></div>
                            <div class="zoom-controls">
                                <button type="button" class="btn" id="vpZoomOut" style="padding:4px 10px" title="Zoom out (mouse wheel down)">−</button>
                                <span id="vpZoomLabel" style="font-size:.78rem;color:var(--muted);min-width:3.2em;text-align:center">100%</span>
                                <button type="button" class="btn" id="vpZoomIn" style="padding:4px 10px" title="Zoom in (mouse wheel up)">+</button>
                                <button type="button" class="btn" id="vpZoomReset" style="padding:4px 10px" title="Reset zoom">↺</button>
                            </div>
                            <div id="pageBtns" style="display:flex;gap:6px"></div>
                        </div>
                        <div class="viewport" id="viewport"></div>
                    </section>
                    <section class="words">
                        <div class="actions">
                            <div class="stat"><b id="selCount">0</b> of <b id="allCount">0</b> <span id="kind">cells</span> ticked</div>
                            <div style="display:flex;gap:8px;flex-wrap:wrap">
                                <button class="btn" id="selAll">Select all</button>
                                <button class="btn" id="selNone">Deselect all</button>
                                <button class="btn" id="manualBtn" title="Draw a rectangle on the page to add a missed crop">✏ Add Crop</button>
                            </div>
                        </div>
                        <div class="grid-wrap"><div class="grid" id="grid"></div></div>
                        <div class="foot">
                            <div class="hint" id="warnHint"></div>
                            <div style="display:flex;gap:8px;flex-wrap:wrap">
                                <button class="btn" id="prevBtn">← Previous</button>
                                <button class="btn" id="skipBtn">Skip file →</button>
                                <button class="btn primary" id="saveBtn">💾 Save ticked &amp; next →</button>
                            </div>
                        </div>
                    </section>
                </div>
            </div>
        </div>
    </section>

    <div class="modal" id="modal">
        <div class="mbox" id="mbox">
            <div class="mhead">
                <b id="modalCap" style="font-size:.9rem"></b>
                <span style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                    <button type="button" class="btn" id="adjustBtn">✂ Adjust crop</button>
                    <span class="zoom-controls">
                        <button type="button" class="btn" id="zoomOutBtn" title="Zoom out (scroll down)">−</button>
                        <span id="zoomLabel">100%</span>
                        <button type="button" class="btn" id="zoomInBtn" title="Zoom in (scroll up)">+</button>
                        <button type="button" class="btn" id="zoomResetBtn" title="Reset zoom">↺</button>
                    </span>
                    <button type="button" class="btn" id="modalClose">×</button>
                </span>
            </div>
            <div id="zoomWrap"><img id="modalImg" alt=""></div>
            <div id="cropEditor" style="display:none">
                <div class="ce-hint">Drag on the picture to draw a new crop box (it replaces the old one); drag a handle to fine-tune or tilt it. The picture is re-cut from the page.</div>
                <div class="ce-stage" id="ceStage">
                    <canvas id="ceCanvas"></canvas>
                    <div class="ce-rect" id="ceRect">
                        <i data-h="nw"></i><i data-h="n"></i><i data-h="ne"></i><i data-h="e"></i>
                        <i data-h="se"></i><i data-h="s"></i><i data-h="sw"></i><i data-h="w"></i>
                        <i data-h="rot" title="Drag to tilt the box"></i>
                    </div>
                </div>
                <div class="ce-bar">
                    <span id="ceInfo"></span>
                    <span style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                        <label style="display:flex;gap:6px;align-items:center;margin-right:6px" title="Tilt the crop box to follow slanted handwriting">Tilt
                            <input type="range" id="ceTilt" min="-45" max="45" step="0.1" value="0" style="width:120px">
                            <b id="ceTiltVal" style="min-width:3.2em;text-align:right">0°</b>
                        </label>
                        <button type="button" class="btn" id="ceReset">Reset</button>
                        <button type="button" class="btn" id="ceCancel">Cancel</button>
                        <button type="button" class="btn" id="ceApply" style="background:#4ade80;color:#052e16;border-color:#4ade80">✓ Apply</button>
                    </span>
                </div>
            </div>
        </div>
    </div>

<script>
(() => {
    const $ = (id) => document.getElementById(id);
    const esc = (s) => String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const API = 'batch_api.php';
    const EXT = /\.(pdf|png|jpe?g|webp)$/i;

    let docs = [];          // {id, file, rel, status, res, sel:Set, saved}
    let cur = -1;           // index of the document under review
    let scanning = false;
    let started = false;

    /* ───────── choose files / folder ───────── */
    const natural = (a, b) => a.rel.localeCompare(b.rel, undefined, {numeric: true, sensitivity: 'base'});

    function setSelection(fileList) {
        const all = Array.from(fileList);
        const ok = all.filter(f => EXT.test(f.name));
        docs = ok.map((f, i) => ({file: f, rel: f.webkitRelativePath || f.name}))
                 .sort(natural)
                 .map((d, i) => ({...d, id: i + 1, status: 'queued', res: null, sel: new Set(), saved: 0}));
        const ignored = all.length - ok.length;
        $('selSummary').textContent = docs.length
            ? docs.length + ' document(s) ready to scan' + (ignored ? ' · ' + ignored + ' other file(s) ignored' : '')
            : (all.length ? 'No PDF or image files found there.' : '');
        $('startBtn').disabled = docs.length === 0;
    }
    $('pickFolder').onclick = () => $('folderInput').click();
    $('pickFiles').onclick = () => $('filesInput').click();
    $('folderInput').onchange = (e) => setSelection(e.target.files);
    $('filesInput').onchange = (e) => setSelection(e.target.files);
    $('pad').oninput = (e) => $('padVal').textContent = e.target.value + ' px';

    // dropping a folder / files on the page
    ['dragenter','dragover'].forEach(n => document.addEventListener(n, e => { e.preventDefault(); }));
    document.addEventListener('drop', async (e) => {
        e.preventDefault();
        if (started) return;
        const items = e.dataTransfer && e.dataTransfer.items;
        if (!items || !items.length || !items[0].webkitGetAsEntry) { setSelection(e.dataTransfer.files); return; }
        const files = [];
        const walk = (entry, path) => new Promise(res => {
            if (entry.isFile) {
                entry.file(f => { Object.defineProperty(f, 'webkitRelativePath', {value: path + f.name}); files.push(f); res(); }, res);
            } else if (entry.isDirectory) {
                const reader = entry.createReader(); const all = [];
                const read = () => reader.readEntries(async list => {
                    if (!list.length) { await Promise.all(all.map(en => walk(en, path + entry.name + '/'))); res(); return; }
                    all.push(...list); read();
                }, res);
                read();
            } else res();
        });
        const entries = Array.from(items).map(i => i.webkitGetAsEntry()).filter(Boolean);
        await Promise.all(entries.map(en => walk(en, '')));
        setSelection(files);
    });

    /* ───────── scan queue ───────── */
    $('startBtn').onclick = async () => {
        if (!docs.length) return;
        if (!await askConfirm({ title: `Scan ${docs.length} file(s)?`, message: 'Every file is scanned and cropped. You then check and save them one by one.', confirmText: 'Start scan' })) return;
        started = true;
        $('setup').style.display = 'none';
        $('session').style.display = 'flex';
        $('stTotal').textContent = docs.length;
        renderQueue();
        const fd = new FormData(); fd.append('action', 'reset');
        try { await fetch(API, {method: 'POST', body: fd}); } catch (e) {}
        scanAll();
    };

    async function scanAll() {
        scanning = true;
        for (const d of docs) {
            if (d.status !== 'queued') continue;
            d.status = 'scanning'; renderQueue(); updateStats();
            try {
                const fd = new FormData();
                fd.append('action', 'scan');
                fd.append('id', d.id);
                fd.append('rel', d.rel);
                fd.append('padding', $('pad').value);
                fd.append('output_format', $('fmt').value);
                fd.append('file', d.file, d.file.name);
                const res = await fetch(API, {method: 'POST', body: fd});
                const txt = await res.text();
                let j; try { j = JSON.parse(txt); } catch (e) { throw new Error('Unexpected server reply'); }
                if (!j.ok) throw new Error(j.error || 'Scan failed');
                d.res = j; d.sel = new Set(j.words.map(w => w.id)); d.status = 'ready';
            } catch (err) {
                d.status = 'error'; d.error = err.message;
            }
            renderQueue(); updateStats();
            if (cur === -1 && d.status === 'ready') openDoc(docs.indexOf(d));
            else if (isWaiting) advance();   // review was idle, waiting for the next scan
        }
        scanning = false;
        updateStats();
        if (isWaiting) advance();
    }

    /* ───────── queue + stats ───────── */
    function renderQueue() {
        const label = {queued: 'Queued', scanning: 'Scanning…', ready: 'To verify', saved: 'Saved', skipped: 'Skipped', error: 'Error'};
        $('queue').innerHTML = docs.map((d, i) => {
            const cls = d.status === 'saved' ? 'saved' : d.status;
            const clickable = d.status === 'ready' ? ' clickable' : '';
            const extra = d.status === 'saved' ? ' (' + d.saved + ')' : '';
            return `<div class="q-item${clickable}${i === cur ? ' current' : ''}" data-i="${i}" title="${esc(d.rel)}${d.error ? ' — ' + esc(d.error) : ''}">
                <span class="q-name">${esc(d.rel)}</span><span class="chip ${cls}">${label[d.status]}${extra}</span></div>`;
        }).join('');
        $('queue').querySelectorAll('.q-item.clickable').forEach(el => el.onclick = () => openDoc(+el.dataset.i));
    }
    function updateStats() {
        const n = (s) => docs.filter(d => d.status === s).length;
        const scanned = docs.filter(d => !['queued', 'scanning'].includes(d.status)).length;
        $('stScanned').textContent = scanned;
        $('meter').style.width = (docs.length ? scanned / docs.length * 100 : 0) + '%';
        $('stSaved').textContent = n('saved');
        $('stCrops').textContent = docs.reduce((a, d) => a + d.saved, 0);
        $('stSkipped').textContent = n('skipped');
        $('stErr').textContent = n('error');
    }

    /* ───────── review one document ───────── */
    let isWaiting = false;
    const show = (id, on, disp = 'block') => $(id).style.display = on ? disp : 'none';

    function openDoc(i) {
        const d = docs[i];
        if (!d || d.status !== 'ready') return;
        cur = i; isWaiting = false;
        show('waiting', false); show('done', false); show('reviewBody', true, 'grid');
        renderQueue();
        const r = d.res;
        $('docTitle').textContent = d.rel + ' — ' + r.pages.length + ' page' + (r.pages.length > 1 ? 's' : '');
        $('kind').textContent = r.tableMode ? 'cells' : 'words';
        $('warnHint').textContent = (r.warnings || []).join(' • ');
        $('pageBtns').innerHTML = r.pages.map(p => `<button class="btn" style="padding:4px 10px" data-p="${p.no}">P${p.no}</button>`).join('');
        $('pageBtns').querySelectorAll('button').forEach(b => b.onclick = () =>
            document.getElementById('pg-' + b.dataset.p).scrollIntoView({behavior: 'smooth', block: 'start'}));

        $('viewport').innerHTML = r.pages.map(p => {
            const boxes = r.words.filter(w => w.page === p.no).map(w => {
                const b = w.box;
                return `<div class="bbox${d.sel.has(w.id) ? ' sel' : ''}" id="bb-${w.id}" data-id="${w.id}"
                    style="left:${b.x / p.w * 100}%;top:${b.y / p.h * 100}%;width:${b.w / p.w * 100}%;height:${b.h / p.h * 100}%"
                    title="${esc(w.label)}"></div>`;
            }).join('');
            return `<div class="page" id="pg-${p.no}"><div class="cap">Page ${p.no} — ${esc(p.name)}</div>
                <img src="${p.src}" alt="Page ${p.no}"><div class="overlay">${boxes}</div></div>`;
        }).join('');
        $('viewport').scrollTop = 0;
        applyViewZoom();

        $('grid').innerHTML = r.words.map(w => `
            <div class="wcard${d.sel.has(w.id) ? ' sel' : ''}" id="wc-${w.id}" data-id="${w.id}">
                <div class="top"><label style="display:flex;gap:6px;align-items:center" onclick="event.stopPropagation()">
                    <input type="checkbox" class="chk" data-id="${w.id}"${d.sel.has(w.id) ? ' checked' : ''}><span>${esc(w.label)}</span></label>
                    <span class="dim" id="dim-${w.id}">${w.w}×${w.h}</span></div>
                <div class="thumb"><img id="th-${w.id}" src="${esc(w.file)}?v=${w.v || 0}" alt="${esc(w.label)}" loading="lazy"></div>
                <div style="text-align:right;display:flex;gap:6px;justify-content:flex-end"><button class="btn" style="padding:3px 8px;font-size:.72rem" data-edit="${w.id}">✂ Adjust</button><button class="btn" style="padding:3px 8px;font-size:.72rem" data-zoom="${w.id}">🔍 Zoom</button></div>
            </div>`).join('');

        const toggle = (id) => { d.sel.has(id) ? d.sel.delete(id) : d.sel.add(id); sync(d); };
        $('grid').querySelectorAll('.wcard').forEach(c => {
            c.onclick = () => toggle(+c.dataset.id);
            c.onmouseenter = () => { const b = $('bb-' + c.dataset.id); b && b.classList.add('hl'); };
            c.onmouseleave = () => { const b = $('bb-' + c.dataset.id); b && b.classList.remove('hl'); };
        });
        // the tick box itself: it used to look ticked without changing the selection
        $('grid').querySelectorAll('.chk').forEach(k => k.onchange = () => {
            const id = +k.dataset.id; k.checked ? d.sel.add(id) : d.sel.delete(id); sync(d);
        });
        // clicking the cropped picture itself opens the viewer, where the crop can be adjusted
        $('grid').querySelectorAll('.thumb').forEach(t => { t.style.cursor = 'zoom-in'; t.title = 'Click to adjust the crop';
            t.onclick = (e) => { e.stopPropagation(); openModal(+t.closest('.wcard').dataset.id, true); }; });
        $('grid').querySelectorAll('[data-zoom]').forEach(b => b.onclick = (e) => { e.stopPropagation(); openModal(+b.dataset.zoom, false); });
        $('grid').querySelectorAll('[data-edit]').forEach(b => b.onclick = (e) => { e.stopPropagation(); openModal(+b.dataset.edit, true); });
        $('viewport').querySelectorAll('.bbox').forEach(b => {
            b.onclick = () => {
                const id = +b.dataset.id; toggle(id);
                const c = $('wc-' + id); if (c) { c.scrollIntoView({behavior: 'smooth', block: 'center'}); c.classList.add('hl'); setTimeout(() => c.classList.remove('hl'), 1000); }
            };
            b.onmouseenter = () => { const c = $('wc-' + b.dataset.id); c && c.classList.add('hl'); };
            b.onmouseleave = () => { const c = $('wc-' + b.dataset.id); c && c.classList.remove('hl'); };
        });
        sync(d);
        if (manualMode) addManualOverlays();
    }

    function sync(d) {
        d.res.words.forEach(w => {
            const on = d.sel.has(w.id);
            const c = $('wc-' + w.id), b = $('bb-' + w.id);
            if (c) { c.classList.toggle('sel', on); c.querySelector('.chk').checked = on; }
            if (b) b.classList.toggle('sel', on);
        });
        $('selCount').textContent = d.sel.size;
        $('allCount').textContent = d.res.words.length;
        $('saveBtn').textContent = '💾 Save ' + d.sel.size + ' & next →';
        $('saveBtn').disabled = d.sel.size === 0;
    }
    $('selAll').onclick = () => { const d = docs[cur]; d.res.words.forEach(w => d.sel.add(w.id)); sync(d); };
    $('selNone').onclick = () => { const d = docs[cur]; d.sel.clear(); sync(d); };

    /* ───────── manual crop ───────── */
    let manualMode = false;
    let manualCount = 0;

    $('manualBtn').onclick = () => {
        manualMode = !manualMode;
        $('manualBtn').classList.toggle('active', manualMode);
        $('manualBtn').title = manualMode
            ? 'Manual crop ON — draw a rectangle on the page. Click again to turn off.'
            : 'Draw a rectangle on the page to add a missed crop';
        if (manualMode) addManualOverlays();
        else removeManualOverlays();
    };

    function addManualOverlays() {
        document.querySelectorAll('#viewport .page').forEach(pageEl => {
            if (pageEl.querySelector('.manual-overlay')) return;
            const ov = document.createElement('div');
            ov.className = 'manual-overlay';
            pageEl.appendChild(ov);
            wireManualDraw(ov, pageEl);
        });
    }

    function removeManualOverlays() {
        document.querySelectorAll('.manual-overlay, .manual-sel').forEach(el => el.remove());
    }

    function wireManualDraw(ov, pageEl) {
        let active = false, sx = 0, sy = 0, selEl = null;

        ov.addEventListener('pointerdown', (e) => {
            e.preventDefault();
            ov.setPointerCapture(e.pointerId);
            active = true;
            const r = ov.getBoundingClientRect();
            sx = e.clientX - r.left; sy = e.clientY - r.top;
            selEl = document.createElement('div');
            selEl.className = 'manual-sel';
            selEl.style.cssText = `left:${sx}px;top:${sy}px;width:0;height:0`;
            pageEl.appendChild(selEl);
        });

        ov.addEventListener('pointermove', (e) => {
            if (!active || !selEl) return;
            const r = ov.getBoundingClientRect();
            const cx = e.clientX - r.left, cy = e.clientY - r.top;
            selEl.style.left   = Math.min(sx, cx) + 'px';
            selEl.style.top    = Math.min(sy, cy) + 'px';
            selEl.style.width  = Math.abs(cx - sx) + 'px';
            selEl.style.height = Math.abs(cy - sy) + 'px';
        });

        ov.addEventListener('pointerup', async (e) => {
            if (!active) return;
            active = false;
            const r = ov.getBoundingClientRect();
            const cx = e.clientX - r.left, cy = e.clientY - r.top;
            const dX = Math.min(sx, cx), dY = Math.min(sy, cy);
            const dW = Math.abs(cx - sx), dH = Math.abs(cy - sy);
            if (selEl) { selEl.remove(); selEl = null; }
            if (dW < 5 || dH < 5) return;   // too small — ignore accidental clicks

            const pageNo = parseInt(pageEl.id.replace('pg-', ''), 10);
            const d = docs[cur];
            const pg = d && d.res && d.res.pages && d.res.pages.find(p => p.no === pageNo);
            if (!pg) return;

            // convert display pixels → page coordinates
            const dispScale = pageEl.clientWidth / pg.w;
            const pX = Math.max(0, Math.round(dX / dispScale));
            const pY = Math.max(0, Math.round(dY / dispScale));
            const pW = Math.max(1, Math.round(dW / dispScale));
            const pH = Math.max(1, Math.round(dH / dispScale));

            // crop from the page image element via canvas
            const imgEl = pageEl.querySelector('img');
            const iScale = imgEl.naturalWidth / pg.w;
            const cv = document.createElement('canvas');
            cv.width  = Math.max(1, Math.round(pW * iScale));
            cv.height = Math.max(1, Math.round(pH * iScale));
            cv.getContext('2d').drawImage(imgEl, pX * iScale, pY * iScale, pW * iScale, pH * iScale, 0, 0, cv.width, cv.height);

            // show a "saving" indicator while we upload
            const spinner = document.createElement('div');
            spinner.className = 'manual-sel';
            spinner.style.cssText = `left:${dX}px;top:${dY}px;width:${dW}px;height:${dH}px;` +
                'display:flex;align-items:center;justify-content:center;font-size:.7rem;color:#fbbf24;pointer-events:none';
            spinner.textContent = 'Saving…';
            pageEl.appendChild(spinner);

            try {
                const blob = await new Promise(res => cv.toBlob(res, 'image/png'));
                const fd = new FormData();
                fd.append('action', 'add_crop');
                fd.append('prefix', d.res.prefix);
                fd.append('page_no', pageNo);
                fd.append('x', pX); fd.append('y', pY);
                fd.append('w', pW); fd.append('h', pH);
                fd.append('image', blob, 'crop.png');
                const json = await (await fetch(API, { method: 'POST', body: fd })).json();
                if (!json.ok) throw new Error(json.error || 'Server error');

                manualCount++;
                const newId = (d.res.words.length ? Math.max(...d.res.words.map(x => x.id)) : 0) + 1;
                const nw = {
                    id: newId, label: 'manual-' + manualCount,
                    file: json.file, filename: json.filename,
                    page: pageNo, w: json.w, h: json.h,
                    box: { x: pX, y: pY, w: pW, h: pH },
                    crop: { x: pX, y: pY }, v: Date.now(), manual: true
                };
                d.res.words.push(nw);
                d.sel.add(newId);

                // add bbox overlay on the page image
                const overlayEl = pageEl.querySelector('.overlay');
                if (overlayEl) {
                    const bb = document.createElement('div');
                    bb.className = 'bbox sel manual';
                    bb.id = 'bb-' + newId; bb.dataset.id = newId;
                    bb.style.cssText = `left:${pX/pg.w*100}%;top:${pY/pg.h*100}%;width:${pW/pg.w*100}%;height:${pH/pg.h*100}%`;
                    bb.title = nw.label;
                    overlayEl.appendChild(bb);
                    bb.onclick = () => { d.sel.has(newId) ? d.sel.delete(newId) : d.sel.add(newId); sync(d); };
                    bb.onmouseenter = () => { const c = $('wc-' + newId); if (c) c.classList.add('hl'); };
                    bb.onmouseleave = () => { const c = $('wc-' + newId); if (c) c.classList.remove('hl'); };
                }

                // add card to the grid
                const card = document.createElement('div');
                card.className = 'wcard sel manual'; card.id = 'wc-' + newId; card.dataset.id = newId;
                card.innerHTML = `
                    <div class="top">
                        <label style="display:flex;gap:6px;align-items:center" onclick="event.stopPropagation()">
                            <input type="checkbox" class="chk" data-id="${newId}" checked>
                            <span>${esc(nw.label)}</span>
                        </label>
                        <span class="dim" id="dim-${newId}">${json.w}×${json.h}</span>
                    </div>
                    <div class="thumb" style="cursor:zoom-in" title="Click to adjust the crop">
                        <img id="th-${newId}" src="${esc(nw.file)}?v=${nw.v}" alt="${esc(nw.label)}" loading="lazy">
                    </div>
                    <div style="text-align:right;display:flex;gap:6px;justify-content:flex-end">
                        <button class="btn" style="padding:3px 8px;font-size:.72rem" data-edit="${newId}">&#x2702; Adjust</button>
                        <button class="btn" style="padding:3px 8px;font-size:.72rem" data-zoom="${newId}">&#x1F50D; Zoom</button>
                    </div>`;
                card.onclick = () => { d.sel.has(newId) ? d.sel.delete(newId) : d.sel.add(newId); sync(d); };
                card.onmouseenter = () => { const b = $('bb-' + newId); if (b) b.classList.add('hl'); };
                card.onmouseleave = () => { const b = $('bb-' + newId); if (b) b.classList.remove('hl'); };
                card.querySelector('.chk').onchange = (ev) => { ev.target.checked ? d.sel.add(newId) : d.sel.delete(newId); sync(d); };
                card.querySelector('.thumb').onclick = (ev) => { ev.stopPropagation(); openModal(newId, true); };
                card.querySelector('[data-zoom]').onclick = (ev) => { ev.stopPropagation(); openModal(newId, false); };
                card.querySelector('[data-edit]').onclick = (ev) => { ev.stopPropagation(); openModal(newId, true); };
                $('grid').appendChild(card);
                card.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                sync(d);
            } catch (err) {
                alert('Could not add crop: ' + err.message);
            } finally { spinner.remove(); }
        });
    }

    /* ───────── main page viewer zoom (mouse wheel) ───────── */
    let viewZoom = 1;
    const VZ_STEP = 1.15, VZ_MIN = 0.3, VZ_MAX = 6;

    function applyViewZoom() {
        document.querySelectorAll('#viewport .page').forEach(p => { p.style.width = (viewZoom * 100) + '%'; });
        $('vpZoomLabel').textContent = Math.round(viewZoom * 100) + '%';
    }
    // zoom keeping the point under (cx, cy) (viewport-relative px) fixed
    function setViewZoom(z, cx, cy) {
        const vp = $('viewport');
        z = Math.min(VZ_MAX, Math.max(VZ_MIN, z));
        if (z === viewZoom) return;
        const ratio = z / viewZoom;
        if (cx === undefined) { cx = vp.clientWidth / 2; cy = vp.clientHeight / 2; }
        const px = vp.scrollLeft + cx, py = vp.scrollTop + cy;
        viewZoom = z;
        applyViewZoom();
        vp.scrollLeft = px * ratio - cx;
        vp.scrollTop = py * ratio - cy;
    }
    $('viewport').addEventListener('wheel', (e) => {
        e.preventDefault();
        const r = $('viewport').getBoundingClientRect();
        setViewZoom(viewZoom * (e.deltaY < 0 ? VZ_STEP : 1 / VZ_STEP), e.clientX - r.left, e.clientY - r.top);
    }, { passive: false });
    $('vpZoomIn').onclick = () => setViewZoom(viewZoom * VZ_STEP);
    $('vpZoomOut').onclick = () => setViewZoom(viewZoom / VZ_STEP);
    $('vpZoomReset').onclick = () => setViewZoom(1);

    /* ───────── modal image zoom ───────── */
    let modalZoom = 1;
    const ZOOM_STEP = 0.25, ZOOM_MIN = 0.25, ZOOM_MAX = 8;

    function applyZoom() {
        const img = $('modalImg'), wrap = $('zoomWrap');
        if (modalZoom === 1) {
            img.style.width = ''; img.style.height = '';
            img.style.maxWidth = '100%'; img.style.maxHeight = '65vh';
        } else {
            const natW = img.naturalWidth || 1, natH = img.naturalHeight || 1;
            const wW = wrap.clientWidth || 600, wH = Math.round(window.innerHeight * 0.65);
            const scale = Math.min(wW / natW, wH / natH);
            img.style.maxWidth = 'none'; img.style.maxHeight = 'none';
            img.style.width  = Math.round(natW * scale * modalZoom) + 'px';
            img.style.height = Math.round(natH * scale * modalZoom) + 'px';
        }
        $('zoomLabel').textContent = Math.round(modalZoom * 100) + '%';
    }

    function zoomIn()    { modalZoom = Math.min(ZOOM_MAX, parseFloat((modalZoom + ZOOM_STEP).toFixed(2))); applyZoom(); }
    function zoomOut()   { modalZoom = Math.max(ZOOM_MIN, parseFloat((modalZoom - ZOOM_STEP).toFixed(2))); applyZoom(); }
    function resetZoom() { modalZoom = 1; applyZoom(); }

    $('zoomInBtn').onclick    = (e) => { e.stopPropagation(); zoomIn(); };
    $('zoomOutBtn').onclick   = (e) => { e.stopPropagation(); zoomOut(); };
    $('zoomResetBtn').onclick = (e) => { e.stopPropagation(); resetZoom(); };

    $('zoomWrap').addEventListener('wheel', (e) => {
        if (!$('modalImg').src) return;
        e.preventDefault();
        e.deltaY < 0 ? zoomIn() : zoomOut();
    }, { passive: false });

    /* ───────── view + adjust an already cropped image ───────── */
    let modalWord = null;
    const modalTitleFor = (w) => `${w.label} (${w.w}×${w.h} px)`;
    const ed = { rect: null, orig: null, region: null, k: 1, s: 1, img: null, page: null, angle: 0 };
    function setTilt(a) {
        ed.angle = Math.max(-45, Math.min(45, Math.round(a * 10) / 10));
        $('ceTilt').value = ed.angle;
        $('ceTiltVal').textContent = ed.angle + '°';
        if (ed.rect) drawRect();
    }
    $('ceTilt').oninput = (e) => setTilt(parseFloat(e.target.value) || 0);
    $('ceTilt').ondblclick = () => setTilt(0);

    function openModal(id, edit) {
        const d = docs[cur]; if (!d || !d.res || !d.res.words) return;
        modalWord = d.res.words.find(x => x.id === id);
        if (!modalWord) return;
        endEdit(); resetZoom();
        $('modalImg').src = modalWord.file + '?v=' + (modalWord.v || 0);
        $('modalCap').textContent = modalTitleFor(modalWord);
        $('modal').classList.add('open');
        if (edit) startEdit();
    }
    function closeModal() { endEdit(); resetZoom(); $('modal').classList.remove('open'); }

    function startEdit() {
        const w = modalWord, d = docs[cur];
        if (!w || !w.crop || !d) return;
        const pg = d.res.pages.find(p => p.no === w.page);
        const img = document.querySelector('#pg-' + w.page + ' img');
        if (!pg || !img || !img.naturalWidth) return;
        ed.img = img; ed.page = pg; ed.s = img.naturalWidth / pg.w;
        ed.orig = { x: w.crop.x, y: w.crop.y, w: w.w, h: w.h };
        ed.rect = { ...ed.orig };
        ed.angle = 0; $('ceTilt').value = 0; $('ceTiltVal').textContent = '0°';
        // plenty of surrounding page so a new box can be drawn around the old crop
        const m = Math.max(60, Math.round(Math.max(w.w, w.h)));
        const rx = Math.max(0, w.crop.x - m), ry = Math.max(0, w.crop.y - m);
        ed.region = { x: rx, y: ry, w: Math.min(pg.w, w.crop.x + w.w + m) - rx, h: Math.min(pg.h, w.crop.y + w.h + m) - ry };
        ed.k = Math.min(720 / ed.region.w, 380 / ed.region.h, 6);
        const cv = $('ceCanvas');
        cv.width = Math.round(ed.region.w * ed.k); cv.height = Math.round(ed.region.h * ed.k);
        cv.getContext('2d').drawImage(img, ed.region.x * ed.s, ed.region.y * ed.s, ed.region.w * ed.s, ed.region.h * ed.s, 0, 0, cv.width, cv.height);
        $('modalImg').style.display = 'none';
        $('cropEditor').style.display = 'block';
        $('adjustBtn').style.display = 'none';
        drawRect();
    }
    function endEdit() {
        $('cropEditor').style.display = 'none';
        $('modalImg').style.display = '';
        $('adjustBtn').style.display = '';
        ed.rect = null;
    }
    function drawRect() {
        const r = ed.rect, el = $('ceRect');
        el.style.left = (r.x - ed.region.x) * ed.k + 'px';
        el.style.top = (r.y - ed.region.y) * ed.k + 'px';
        el.style.width = r.w * ed.k + 'px';
        el.style.height = r.h * ed.k + 'px';
        el.style.transform = ed.angle ? `rotate(${ed.angle}deg)` : '';
        $('ceInfo').textContent = `${Math.round(r.w)}×${Math.round(r.h)} px` + (ed.s < 1 ? ' · page preview is downscaled, so the new crop has a lower resolution' : '');
    }
    // drag a handle to fine-tune / tilt the box (anything else bubbles up to the stage: draw a new box)
    $('ceRect').addEventListener('pointerdown', (e) => {
        const handle = e.target.dataset.h;
        if (!handle) return;
        e.preventDefault(); e.stopPropagation();
        const start = { px: e.clientX, py: e.clientY, ...ed.rect };
        const R = ed.region, MIN = 4;
        const box = $('ceRect').getBoundingClientRect();
        const scx = box.left + box.width / 2, scy = box.top + box.height / 2;
        const move = (ev) => {
            if (handle === 'rot') {
                let a = Math.atan2(ev.clientX - scx, -(ev.clientY - scy)) * 180 / Math.PI;
                if (ev.shiftKey) a = Math.round(a / 5) * 5;
                setTilt(a);
                return;
            }
            const dx = (ev.clientX - start.px) / ed.k, dy = (ev.clientY - start.py) / ed.k;
            const th = ed.angle * Math.PI / 180, cos = Math.cos(th), sin = Math.sin(th);
            const cx0 = start.x + start.w / 2, cy0 = start.y + start.h / 2;
            // resize in the box's own (tilted) frame, relative to its centre
            const ldx = dx * cos + dy * sin, ldy = -dx * sin + dy * cos;
            let l = -start.w / 2, r = start.w / 2, t = -start.h / 2, b = start.h / 2;
            if (handle.includes('w')) l = Math.min(l + ldx, r - MIN);
            if (handle.includes('e')) r = Math.max(r + ldx, l + MIN);
            if (handle.includes('n')) t = Math.min(t + ldy, b - MIN);
            if (handle.includes('s')) b = Math.max(b + ldy, t + MIN);
            if (!ed.angle) {
                l = Math.max(l, R.x - cx0); r = Math.min(r, R.x + R.w - cx0);
                t = Math.max(t, R.y - cy0); b = Math.min(b, R.y + R.h - cy0);
            }
            const lcx = (l + r) / 2, lcy = (t + b) / 2;
            const cx = cx0 + lcx * cos - lcy * sin, cy = cy0 + lcx * sin + lcy * cos;
            ed.rect = { x: cx - (r - l) / 2, y: cy - (b - t) / 2, w: r - l, h: b - t };
            drawRect();
        };
        const up = () => { window.removeEventListener('pointermove', move); window.removeEventListener('pointerup', up); };
        window.addEventListener('pointermove', move); window.addEventListener('pointerup', up);
    });
    // drag on the picture to draw a brand-new crop box that replaces the old one (drawn straight; tilt it afterwards)
    $('ceStage').addEventListener('pointerdown', (e) => {
        if (e.button !== 0 || !ed.rect) return;
        e.preventDefault(); e.stopPropagation();
        const R = ed.region, MIN = 4, prev = { ...ed.rect }, prevAngle = ed.angle;
        const cv = $('ceCanvas'), cr = cv.getBoundingClientRect();
        const toPage = (ev) => ({
            x: Math.min(Math.max(R.x + (ev.clientX - cr.left) * (cv.width / cr.width) / ed.k, R.x), R.x + R.w),
            y: Math.min(Math.max(R.y + (ev.clientY - cr.top) * (cv.height / cr.height) / ed.k, R.y), R.y + R.h),
        });
        const p0 = toPage(e);
        let drawing = false;
        const move = (ev) => {
            const p = toPage(ev);
            if (!drawing) { drawing = true; setTilt(0); }
            ed.rect = { x: Math.min(p0.x, p.x), y: Math.min(p0.y, p.y), w: Math.abs(p.x - p0.x), h: Math.abs(p.y - p0.y) };
            drawRect();
        };
        const up = () => {
            window.removeEventListener('pointermove', move); window.removeEventListener('pointerup', up);
            // a click (or a tiny drag) is not a new box: keep the previous one
            if (ed.rect && (ed.rect.w < MIN || ed.rect.h < MIN)) { ed.rect = prev; setTilt(prevAngle); }
        };
        window.addEventListener('pointermove', move); window.addEventListener('pointerup', up);
    });
    $('adjustBtn').onclick = startEdit;
    $('ceCancel').onclick = endEdit;
    $('ceReset').onclick = () => { ed.rect = { ...ed.orig }; setTilt(0); };
    $('ceApply').onclick = async () => {
        const w = modalWord, r = ed.rect, btn = $('ceApply');
        if (!w || !r) return;
        const ow = Math.max(1, Math.round(r.w * ed.s)), oh = Math.max(1, Math.round(r.h * ed.s));
        const out = document.createElement('canvas'); out.width = ow; out.height = oh;
        const octx = out.getContext('2d');
        if (ed.angle) {
            // sample the tilted box from the page and straighten it
            octx.fillStyle = '#fff'; octx.fillRect(0, 0, ow, oh);
            octx.translate(ow / 2, oh / 2);
            octx.rotate(-ed.angle * Math.PI / 180);
            octx.drawImage(ed.img, -(r.x + r.w / 2) * ed.s, -(r.y + r.h / 2) * ed.s);
        } else {
            octx.drawImage(ed.img, r.x * ed.s, r.y * ed.s, r.w * ed.s, r.h * ed.s, 0, 0, ow, oh);
        }
        // axis-aligned bounds of the (possibly tilted) box, for the overlay on the page
        const ath = Math.abs(ed.angle) * Math.PI / 180;
        const bw = r.w * Math.cos(ath) + r.h * Math.sin(ath), bh = r.w * Math.sin(ath) + r.h * Math.cos(ath);
        const bx = r.x + r.w / 2 - bw / 2, by = r.y + r.h / 2 - bh / 2;
        btn.disabled = true;
        try {
            const blob = await new Promise(res => out.toBlob(res, 'image/png'));
            const fd = new FormData();
            fd.append('action', 'replace_crop'); fd.append('prefix', docs[cur].res.prefix);
            fd.append('filename', w.filename); fd.append('image', blob, 'crop.png');
            const res = await fetch(API, { method: 'POST', body: fd });
            const txt = await res.text();
            let data;
            try { data = JSON.parse(txt); }
            catch (_) {   // a PHP error page or an over-size upload instead of JSON
                const msg = txt.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 200);
                throw new Error(`server replied ${res.status}: ${msg || 'empty response (upload too large?)'}`);
            }
            if (!data.ok) throw new Error(data.error || 'save failed');
            w.w = data.w; w.h = data.h;
            w.crop = { x: Math.round(r.x), y: Math.round(r.y) };
            w.box = { x: Math.round(bx), y: Math.round(by), w: Math.round(bw), h: Math.round(bh) };
            w.v = Date.now();
            const th = $('th-' + w.id); if (th) th.src = w.file + '?v=' + w.v;
            const dm = $('dim-' + w.id); if (dm) dm.textContent = `${w.w}×${w.h}`;
            const pg = ed.page, bb = $('bb-' + w.id);
            if (bb) { bb.style.left = w.box.x / pg.w * 100 + '%'; bb.style.top = w.box.y / pg.h * 100 + '%'; bb.style.width = w.box.w / pg.w * 100 + '%'; bb.style.height = w.box.h / pg.h * 100 + '%'; }
            $('modalImg').src = w.file + '?v=' + w.v;
            $('modalCap').textContent = modalTitleFor(w);
            endEdit();
        } catch (err) {
            alert('Could not adjust the crop: ' + err.message);
        } finally { btn.disabled = false; }
    };
    // close only on a real click on the backdrop: a drag that starts in the editor and is released
    // outside it also produces a "click" on the backdrop, which used to throw the edit away
    let modalDownOnBackdrop = false;
    $('modal').addEventListener('pointerdown', (e) => { modalDownOnBackdrop = e.target === $('modal'); }, true);
    $('modal').onclick = (e) => { if (e.target === $('modal') && modalDownOnBackdrop) closeModal(); };
    $('modalClose').onclick = closeModal;
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeModal(); });

    /* ───────── save / skip / navigate ───────── */
    async function post(data) {
        const fd = new FormData(); Object.entries(data).forEach(([k, v]) => fd.append(k, v));
        const res = await fetch(API, {method: 'POST', body: fd});
        return res.json();
    }

    $('saveBtn').onclick = async () => {
        const d = docs[cur]; if (!d || !d.sel.size) return;
        if (!await askConfirm({ title: `Save ${d.sel.size} ticked image(s)?`, message: 'They are kept in the output/ folder and the next file opens.', confirmText: 'Save' })) return;
        $('saveBtn').disabled = true; $('saveBtn').textContent = 'Saving…';
        try {
            const names = d.res.words.filter(w => d.sel.has(w.id)).map(w => w.filename);
            const j = await post({action: 'save', prefix: d.res.prefix, filenames: JSON.stringify(names)});
            if (!j.ok) throw new Error(j.error || 'Save failed');
            d.saved = j.count; d.status = 'saved'; d.res = {prefix: d.res.prefix};   // free memory
        } catch (err) {
            alert('Could not save: ' + err.message);
            sync(d); return;
        }
        renderQueue(); updateStats(); advance();
    };

    $('skipBtn').onclick = async () => {
        const d = docs[cur]; if (!d) return;
        if (!await askConfirm({ title: 'Skip this file?', message: 'Its cropped images will be discarded. Nothing is saved.', confirmText: 'Skip file', danger: true })) return;
        try { await post({action: 'discard', prefix: d.res.prefix}); } catch (e) {}
        d.status = 'skipped'; d.res = {prefix: d.res.prefix};
        renderQueue(); updateStats(); advance();
    };

    $('prevBtn').onclick = () => {
        for (let i = cur - 1; i >= 0; i--) if (docs[i].status === 'ready') { openDoc(i); return; }
    };

    function advance() {
        // next document still waiting for verification, after the current one (then wrap to earlier ones)
        for (let i = cur + 1; i < docs.length; i++) if (docs[i].status === 'ready') { openDoc(i); return; }
        for (let i = 0; i < docs.length; i++) if (docs[i].status === 'ready') { openDoc(i); return; }
        const pending = docs.some(d => d.status === 'queued' || d.status === 'scanning');
        show('reviewBody', false);
        if (pending) {
            isWaiting = true;
            $('waiting').innerHTML = '<h2>Next file is being scanned…</h2><div>It opens here automatically.</div>';
            show('waiting', true);
        } else {
            isWaiting = false;
            finish();
        }
    }

    function finish() {
        const n = (s) => docs.filter(d => d.status === s).length;
        const crops = docs.reduce((a, d) => a + d.saved, 0);
        const bad = docs.filter(d => d.status === 'error');
        $('done').innerHTML = `<h2>✓ All documents verified</h2>
            <div><b>${n('saved')}</b> file(s) saved · <b>${crops}</b> cropped image(s) in <code>output/</code><br>
            ${n('skipped')} skipped · ${bad.length} failed to scan.</div>
            ${bad.length ? '<div style="margin-top:10px;font-size:.8rem;color:var(--err);text-align:left">' + bad.map(d => esc(d.rel) + ': ' + esc(d.error)).join('<br>') + '</div>' : ''}
            <div style="margin-top:18px;display:flex;gap:10px;justify-content:center;flex-wrap:wrap">
                <button class="btn primary" id="toAnnotate">✍️ Prepare dataset &amp; annotate</button>
                <a class="btn" href="batch.php">📁 Scan another folder</a></div>`;
        show('done', true);
        renderQueue();
        const b = $('toAnnotate');
        if (b) b.onclick = async () => {
            b.disabled = true; b.textContent = 'Preparing images…';
            try {
                const fd = new FormData(); fd.append('action', 'batch_process_htr');
                const j = await (await fetch('index.php', {method: 'POST', body: fd})).json();
                if (!j.success) throw new Error('failed');
                location.href = 'annotate.php';
            } catch (e) { b.disabled = false; b.textContent = 'Failed — try again'; }
        };
    }

    window.addEventListener('beforeunload', (e) => {
        if (started && docs.some(d => ['queued', 'scanning', 'ready'].includes(d.status))) { e.preventDefault(); e.returnValue = ''; }
    });
})();
</script>
</body>
</html>
