<?php
// Annotation tool: type the text of every prepared word image.
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Annotate — Kraken Cropper</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        :root { --bg:#050507; --panel:#0d0d10; --card:#121216; --surface:#18181d; --border:#26262b; --muted:#8b8b96; --dim:#52525b;
                --ok:#4ade80; --warn:#fbbf24; --err:#f87171; }
        html, body { height:100%; background:var(--bg); color:#fff; font-family:'Inter',sans-serif; }
        body { display:flex; flex-direction:column; overflow:hidden; }
        button, input { font-family:inherit; }
        .navbar { flex:0 0 56px; background:var(--panel); border-bottom:1px solid var(--border); display:flex; align-items:center; justify-content:space-between; padding:0 20px; gap:12px; }
        .brand { font-weight:800; display:flex; gap:10px; align-items:center; }
        .pill { padding:3px 10px; background:var(--surface); border:1px solid var(--border); border-radius:99px; font-size:.7rem; font-weight:700; text-transform:uppercase; letter-spacing:.5px; }
        .btn { display:inline-flex; align-items:center; gap:6px; padding:8px 14px; background:var(--surface); border:1px solid var(--border); border-radius:8px; color:#fff; font-size:.82rem; font-weight:600; cursor:pointer; text-decoration:none; transition:all .15s; }
        .btn:hover { border-color:#fff; }
        .btn:disabled { opacity:.4; cursor:not-allowed; border-color:var(--border); }
        .btn.primary { background:#fff; color:#000; border-color:#fff; font-weight:800; }
        .btn.danger:hover { border-color:var(--err); color:var(--err); }
        .topstats { background:var(--panel); border-bottom:1px solid var(--border); padding:10px 20px; display:flex; align-items:center; gap:18px; flex-wrap:wrap; }
        .meter { flex:1; min-width:160px; height:8px; background:var(--surface); border-radius:99px; overflow:hidden; display:flex; }
        .meter i { display:block; height:100%; transition:width .25s; }
        .meter .l { background:var(--ok); } .meter .u { background:var(--warn); }
        .stat { font-size:.8rem; color:var(--muted); white-space:nowrap; } .stat b { color:#fff; }

        .layout { flex:1; min-height:0; display:grid; grid-template-columns:300px 1fr; }
        @media (max-width: 820px) { .layout { grid-template-columns:1fr; } #side { display:none; } }
        #side { background:var(--panel); border-right:1px solid var(--border); display:flex; flex-direction:column; min-height:0; }
        .filters { padding:10px; display:flex; gap:6px; flex-wrap:wrap; border-bottom:1px solid var(--border); }
        .filters button { padding:4px 10px; font-size:.72rem; }
        .filters button.on { background:#fff; color:#000; border-color:#fff; }
        #search { margin:10px; padding:7px 10px; background:var(--surface); border:1px solid var(--border); border-radius:8px; color:#fff; font-size:.82rem; }
        #list { flex:1; overflow:auto; }
        .row { display:flex; align-items:center; gap:10px; padding:7px 12px; border-bottom:1px solid var(--border); cursor:pointer; font-size:.78rem; }
        .row:hover { background:var(--surface); }
        .row.cur { background:var(--surface); box-shadow:inset 3px 0 0 #fff; }
        .row img { height:26px; max-width:90px; object-fit:contain; background:#fff; border-radius:3px; }
        .row .t { flex:1; min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; color:var(--muted); }
        .dot { width:9px; height:9px; border-radius:50%; background:var(--dim); flex:0 0 auto; }
        .dot.labeled { background:var(--ok); } .dot.unreadable { background:var(--warn); }

        #main { min-width:0; min-height:0; display:flex; flex-direction:column; align-items:center; justify-content:center; padding:24px; gap:18px; overflow:auto; }
        #empty { text-align:center; color:var(--muted); line-height:1.7; max-width:440px; }
        #empty h2 { color:#fff; margin-bottom:8px; }
        #work { width:100%; max-width:860px; display:none; flex-direction:column; gap:16px; }
        .meta { display:flex; justify-content:space-between; color:var(--muted); font-size:.8rem; gap:10px; flex-wrap:wrap; }
        .meta b { color:#fff; }
        .stage { background:#fff; border-radius:14px; min-height:170px; display:flex; align-items:center; justify-content:center; padding:20px; overflow:auto; border:2px solid var(--border); }
        .stage img { height:130px; max-width:100%; object-fit:contain; image-rendering:auto; }
        .stage { cursor:zoom-in; }
        #label { width:100%; font-size:1.7rem; padding:14px 18px; background:var(--card); color:#fff; border:2px solid var(--border); border-radius:12px; outline:none; font-weight:600; }
        #label:focus { border-color:#fff; }
        .acwrap { position:relative; width:100%; }
        #sug { display:none; position:absolute; left:0; right:0; top:calc(100% + 4px); z-index:20; background:var(--panel); border:1px solid var(--border); border-radius:10px; overflow:hidden; box-shadow:0 10px 30px rgba(0,0,0,.6); }
        #sug div { padding:9px 18px; font-size:1.15rem; font-weight:600; cursor:pointer; display:flex; justify-content:space-between; gap:10px; }
        #sug div small { color:var(--dim); font-size:.7rem; font-weight:500; align-self:center; }
        #sug div b { color:var(--ok); }
        #sug div.on, #sug div:hover { background:var(--surface); }
        .bar { display:flex; gap:10px; flex-wrap:wrap; align-items:center; justify-content:space-between; }
        .keys { color:var(--dim); font-size:.74rem; line-height:1.6; }
        kbd { background:var(--surface); border:1px solid var(--border); border-radius:4px; padding:0 5px; font-size:.72rem; color:var(--muted); }
        #msg { min-height:1.2em; font-size:.78rem; color:var(--ok); }
        #links a { color:#fff; font-weight:700; margin-right:14px; font-size:.82rem; }
    </style>
    <link rel="stylesheet" href="sneat.css">
    <script src="confirm.js?v=<?= @filemtime(__DIR__ . '/confirm.js') ?>"></script>
    <script src="labels-io.js?v=<?= @filemtime(__DIR__ . '/labels-io.js') ?>"></script>
</head>
<body>
    <header class="navbar">
        <div class="brand"><span>Word Cropper</span><span class="pill">Annotate</span></div>
        <div style="display:flex;gap:8px">
            <button class="btn" id="pendDl">⬇ Not annotated</button>
            <button class="btn" id="pendUp">⬆ Upload to annotate</button>
            <button class="btn" id="impBtn">⬆ Import annotated</button>
            <button class="btn" id="exportBtn">⬇ Export labels</button>
            <a class="btn" href="index.php">← Back</a>
        </div>
    </header>

    <div class="topstats">
        <div class="stat">Labeled <b id="sL">0</b> / <b id="sT">0</b></div>
        <div class="meter"><i class="l" id="mL"></i><i class="u" id="mU"></i></div>
        <div class="stat">Unreadable <b id="sU">0</b> · Left <b id="sP">0</b></div>
        <div id="links" class="stat"></div>
    </div>

    <div class="layout">
        <aside id="side">
            <div class="filters" id="filters">
                <button class="btn on" data-f="all">All</button>
                <button class="btn" data-f="pending">To label</button>
                <button class="btn" data-f="labeled">Labeled</button>
                <button class="btn" data-f="unreadable">Unreadable</button>
            </div>
            <input id="search" placeholder="Search label or file…">
            <div id="list"></div>
        </aside>

        <main id="main">
            <div id="empty">
                <h2>No images to annotate yet</h2>
                Verify and save cropped images first, then run <b>Batch Process for HTR</b> on the main page.
                <div style="margin-top:14px"><a class="btn primary" href="index.php">Go to main page</a></div>
            </div>

            <div id="work">
                <div class="meta"><span>Image <b id="pos">0</b> of <b id="cnt">0</b> · <b id="idn"></b></span><span id="orig"></span></div>
                <div class="stage" id="stage"><img id="img" alt=""></div>
                <div class="acwrap">
                    <input id="label" autocomplete="off" spellcheck="false" placeholder="Type the word you see…">
                    <div id="sug" role="listbox"></div>
                </div>
                <div class="bar">
                    <div style="display:flex;gap:8px;flex-wrap:wrap">
                        <button class="btn" id="prevBtn">← Previous</button>
                        <button class="btn danger" id="unreadBtn">🚫 Unreadable</button>
                        <button class="btn" id="zoomBtn">🔍 Zoom</button>
                    </div>
                    <button class="btn primary" id="saveBtn">✓ Save &amp; next ↵</button>
                </div>
                <div id="msg"></div>
                <div class="keys"><kbd>Enter</kbd> save &amp; next · <kbd>Alt</kbd>+<kbd>←</kbd>/<kbd>→</kbd> previous / next ·
                    <kbd>Alt</kbd>+<kbd>U</kbd> unreadable · type to get suggestions from words you already used: <kbd>Shift</kbd> or <kbd>Tab</kbd> accept · <kbd>↑</kbd>/<kbd>↓</kbd> choose · <kbd>Esc</kbd> close · <kbd>Mouse wheel</kbd> on the image to zoom in/out, double-click to reset</div>
            </div>
        </main>
    </div>

<script>
(() => {
    const $ = (id) => document.getElementById(id);
    const esc = (s) => String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const API = 'annotate_api.php';
    let items = [], cur = 0, filter = 'all', query = '';

    async function post(data) {
        const fd = new FormData(); Object.entries(data).forEach(([k, v]) => fd.append(k, v));
        const r = await fetch(API, {method: 'POST', body: fd});
        return r.json();
    }

    const visible = () => items.filter(r =>
        (filter === 'all' || r.status === filter) &&
        (!query || (r.transcription + ' ' + r.original_filename).toLowerCase().includes(query)));

    function stats() {
        const c = {labeled: 0, unreadable: 0, pending: 0};
        items.forEach(r => c[r.status]++);
        $('sT').textContent = items.length; $('sL').textContent = c.labeled;
        $('sU').textContent = c.unreadable; $('sP').textContent = c.pending;
        const t = items.length || 1;
        $('mL').style.width = c.labeled / t * 100 + '%'; $('mU').style.width = c.unreadable / t * 100 + '%';
    }

    function renderList() {
        const v = visible();
        $('list').innerHTML = v.map(r => `<div class="row${items[cur] && items[cur].id === r.id ? ' cur' : ''}" data-id="${r.id}">
            <span class="dot ${r.status}"></span><img src="${esc(r.relative_path)}" loading="lazy" alt="">
            <span class="t">${r.status === 'unreadable' ? '— unreadable —' : esc(r.transcription || r.original_filename)}</span></div>`).join('')
            || '<div style="padding:16px;color:var(--dim);font-size:.8rem">Nothing here.</div>';
        $('list').querySelectorAll('.row').forEach(el => el.onclick = () => goTo(items.findIndex(r => r.id === +el.dataset.id)));
        const c = $('list').querySelector('.row.cur'); if (c) c.scrollIntoView({block: 'nearest'});
    }

    // ---- autocomplete: words already used, matched by prefix, most-used first ----
    let sugList = [], sugIdx = -1;
    function hideSug() { sugList = []; sugIdx = -1; $('sug').style.display = 'none'; }
    function renderSug() {
        const box = $('sug');
        if (!sugList.length) { hideSug(); return; }
        const n = $('label').value.trimStart().length;
        box.innerHTML = sugList.map(([t, c], i) =>
            `<div role="option" class="${i === sugIdx ? 'on' : ''}" data-i="${i}"><span><b>${esc(t.slice(0, n))}</b>${esc(t.slice(n))}</span><small>${c ? c + '×' : 'learned'}</small></div>`).join('');
        box.style.display = 'block';
        box.querySelectorAll('div').forEach(el => el.onmousedown = (e) => { e.preventDefault(); acceptSug(+el.dataset.i); });
    }
    // What the tool learns from you (kept in this browser): how often you pick a suggestion and when a word was last used.
    const LKEY = 'annotate_learned_v1';
    let learned = {};
    try { learned = JSON.parse(localStorage.getItem(LKEY) || '{}') || {}; } catch (e) { learned = {}; }
    function learn(text, picked) {
        text = (text || '').trim(); if (!text) return;
        // learn the whole label and each word in it
        const keys = new Set([text, ...text.split(/\s+/)]);
        keys.forEach(k => {
            const e = learned[k] || {p: 0, t: 0};
            if (picked) e.p++;
            e.t = Date.now();
            learned[k] = e;
        });
        try { localStorage.setItem(LKEY, JSON.stringify(learned)); } catch (e) {}
    }

    function updateSug() {
        const raw = $('label').value.trimStart();
        if (!raw) { hideSug(); return; }
        // complete the last word you are typing; earlier words stay as they are
        const cut = raw.lastIndexOf(' ') + 1, head = raw.slice(0, cut), part = raw.slice(cut).toLowerCase();
        if (!part) { hideSug(); return; }
        const freq = new Map();
        const add = (t) => freq.set(t, (freq.get(t) || 0) + 1);
        items.forEach(r => {
            if (r.status !== 'labeled' || !r.transcription) return;
            if (!head) add(r.transcription);
            r.transcription.split(/\s+/).forEach(add);
        });
        Object.keys(learned).forEach(k => { if (!freq.has(k)) freq.set(k, 0); });
        const now = Date.now();
        const score = (t, c) => { const l = learned[t] || {p: 0, t: 0}; return c + l.p * 3 + (l.t ? Math.max(0, 2 - (now - l.t) / 864e5 / 7) : 0); };
        sugList = Array.from(freq)
            .filter(([t]) => t.toLowerCase().startsWith(part) && t.toLowerCase() !== part)
            .map(([t, c]) => [head + t, c, score(t, c)])
            .sort((a, b) => b[2] - a[2] || a[0].localeCompare(b[0])).slice(0, 8);
        sugIdx = -1;
        renderSug();
    }
    function acceptSug(i) {
        if (!sugList[i]) return false;
        $('label').value = sugList[i][0];
        learn(sugList[i][0].split(/\s+/).pop(), true);
        hideSug(); $('label').focus();
        return true;
    }
    function renderSeen() { /* suggestions are computed live while typing */ }

    function show() {
        const r = items[cur];
        if (!r) return;
        $('pos').textContent = cur + 1; $('cnt').textContent = items.length; $('idn').textContent = r.processed_filename;
        $('orig').textContent = r.original_filename;
        $('img').src = r.relative_path;
        $('label').value = r.status === 'labeled' ? r.transcription : '';
        $('msg').textContent = r.status === 'unreadable' ? 'Marked as unreadable.' : '';
        hideSug();
        renderList();
        $('label').focus(); $('label').select();
    }

    async function commit(status) {
        const r = items[cur];
        const text = $('label').value;
        const j = await post({action: 'save', id: r.id, text, status});
        if (!j.ok) { $('msg').style.color = 'var(--err)'; $('msg').textContent = j.error || 'Could not save'; throw new Error(j.error); }
        r.status = j.status; r.transcription = j.text;
        if (j.status === 'labeled') learn(j.text, false);
        stats(); renderSeen();
        return j;
    }

    // keep what was typed when moving away without pressing Enter
    async function autosave() {
        const r = items[cur]; if (!r) return;
        const t = $('label').value.trim();
        if (t && (r.status !== 'labeled' || r.transcription !== t.replace(/\s+/g, ' '))) { try { await commit('labeled'); } catch (e) {} }
    }

    async function goTo(i) {
        if (i < 0 || i >= items.length) return;
        await autosave();
        cur = i; show();
    }

    function nextIndex(from) {
        // next image that still needs a label, otherwise simply the following one
        for (let i = from + 1; i < items.length; i++) if (items[i].status === 'pending') return i;
        for (let i = 0; i < from; i++) if (items[i].status === 'pending') return i;
        return from + 1 < items.length ? from + 1 : from;
    }

    async function saveNext() {
        if (!$('label').value.trim()) { $('msg').style.color = 'var(--warn)'; $('msg').textContent = 'Type the word first (or mark it unreadable).'; return; }
        await commit('labeled');
        const n = nextIndex(cur);
        if (n === cur) { $('msg').style.color = 'var(--ok)'; $('msg').textContent = '✓ Saved — that was the last image.'; renderList(); return; }
        cur = n; show();
    }

    $('saveBtn').onclick = saveNext;
    $('unreadBtn').onclick = async () => { $('label').value = ''; await commit('unreadable'); const n = nextIndex(cur); if (n !== cur) { cur = n; show(); } else renderList(); };
    $('prevBtn').onclick = () => goTo(cur - 1);
    // Zoom: mouse wheel over the image (up = in, down = out), double-click or the button to reset/toggle.
    let zoomH = 130;
    const setZoom = (h) => { zoomH = Math.max(30, Math.min(900, Math.round(h))); $('img').style.height = zoomH + 'px'; $('img').style.maxWidth = zoomH > 260 ? 'none' : '100%'; };
    $('stage').addEventListener('wheel', (e) => {
        e.preventDefault();
        setZoom(zoomH * (e.deltaY < 0 ? 1.12 : 1 / 1.12));
    }, {passive: false});
    $('stage').addEventListener('dblclick', () => setZoom(130));
    $('zoomBtn').onclick = () => setZoom(zoomH === 130 ? 260 : 130);
    $('label').addEventListener('input', updateSug);
    $('label').addEventListener('blur', hideSug);
    // Tapping Shift on its own accepts the suggestion; Shift used for a capital letter does not.
    let shiftAlone = false;
    $('label').addEventListener('keyup', (e) => {
        if (e.key === 'Shift' && shiftAlone && sugList.length) acceptSug(sugIdx >= 0 ? sugIdx : 0);
        if (e.key === 'Shift') shiftAlone = false;
    });
    $('label').addEventListener('keydown', (e) => {
        if (e.key === 'Shift') { if (!e.repeat) shiftAlone = true; }
        else shiftAlone = false;
        const open = sugList.length > 0;
        if (open && e.key === 'ArrowDown') { e.preventDefault(); sugIdx = (sugIdx + 1) % sugList.length; renderSug(); }
        else if (open && e.key === 'ArrowUp') { e.preventDefault(); sugIdx = (sugIdx <= 0 ? sugList.length : sugIdx) - 1; renderSug(); }
        else if (open && e.key === 'Tab') { e.preventDefault(); acceptSug(sugIdx >= 0 ? sugIdx : 0); }
        else if (open && e.key === 'Escape') { e.preventDefault(); hideSug(); }
        else if (e.key === 'Enter') {
            e.preventDefault();
            if (open && sugIdx >= 0) acceptSug(sugIdx); // an arrow-selected suggestion is used as typed
            hideSug(); saveNext();
        }
    });
    document.addEventListener('keydown', (e) => {
        if (!e.altKey) return;
        if (e.key === 'ArrowLeft') { e.preventDefault(); goTo(cur - 1); }
        else if (e.key === 'ArrowRight') { e.preventDefault(); goTo(cur + 1); }
        else if (e.key.toLowerCase() === 'u') { e.preventDefault(); $('unreadBtn').click(); }
    });

    $('filters').querySelectorAll('button').forEach(b => b.onclick = () => {
        filter = b.dataset.f;
        $('filters').querySelectorAll('button').forEach(x => x.classList.toggle('on', x === b));
        renderList();
    });
    $('search').oninput = (e) => { query = e.target.value.trim().toLowerCase(); renderList(); };

    $('exportBtn').onclick = async () => {
        if (!await askConfirm({ title: 'Export the labels?', message: 'dataset_labels.xlsx, labels.csv and labels.tsv are rewritten with the current labels.', confirmText: 'Export' })) return;
        await autosave();
        const j = await post({action: 'export'});
        if (j.ok) {
            $('links').innerHTML = j.files.map(f => `<a href="${f}" download>${f.split('/').pop()}</a>`).join('');
            $('msg').style.color = 'var(--ok)'; $('msg').textContent = '✓ Exported ' + j.labeled + ' labeled image(s).';
        }
    };

    const amsg = (t, ok) => { $('msg').style.color = ok ? 'var(--ok)' : 'var(--err)'; $('msg').textContent = t; };
    $('pendDl').onclick = async () => {
        if (!await askConfirm({ title: 'Download the not-annotated images?', message: 'A .zip with every image that still has no label, plus a labels template to fill in.', confirmText: 'Download' })) return;
        try { await LabelsIO.downloadPending(amsg); } catch (err) { amsg(err.message, false); }
    };
    $('pendUp').onclick = () => LabelsIO.pickAndUploadImages({ onMsg: amsg, onDone: () => setTimeout(() => location.reload(), 1500) });

    $('impBtn').onclick = () => LabelsIO.pickAndImport({
        onMsg: (t, ok) => { $('msg').style.color = ok ? 'var(--ok)' : 'var(--err)'; $('msg').textContent = t; },
        onMerged: () => setTimeout(() => location.reload(), 1500)
    });

    (async () => {
        const j = await post({action: 'list'});
        items = j.items || [];
        stats();
        if (!items.length) return;
        $('empty').style.display = 'none'; $('work').style.display = 'flex';
        const first = items.findIndex(r => r.status === 'pending');
        cur = first >= 0 ? first : 0;
        renderSeen(); show();
    })();
})();
</script>
</body>
</html>
