/* Download / import of the annotated dataset (used by index.php and annotate.php). */
(function () {
    const API = 'annotate_api.php';

    async function call(fd) {
        const r = await fetch(API, { method: 'POST', body: fd });
        let j;
        try { j = await r.json(); } catch (e) { throw new Error('unexpected server response'); }
        if (!j.ok) throw new Error(j.error || 'request failed');
        return j;
    }
    const form = (o) => { const fd = new FormData(); Object.entries(o).forEach(([k, v]) => fd.append(k, v)); return fd; };

    // rewrite the files from the current annotations, then download one of them
    async function download(file) {
        const j = await call(form({ action: 'export' }));
        if (!j.stats || !j.stats.total) throw new Error('There is no dataset yet. Run "Batch Process for HTR" first.');
        if (!j.labeled) throw new Error('Nothing to download yet: no image has been annotated.');
        const a = document.createElement('a');
        a.href = file + '?v=' + Date.now();
        a.download = file.split('/').pop();
        document.body.appendChild(a); a.click(); a.remove();
        return j;
    }

    function summary(m) {
        const parts = [`${m.updated} label(s) added or updated`];
        if (m.same) parts.push(`${m.same} already identical`);
        if (m.kept) parts.push(`${m.kept} kept as they were (you already had a label)`);
        if (m.missing) parts.push(`${m.missing} not in this dataset`);
        if (m.conflict) parts.push(`${m.conflict} skipped: same file name but a different picture`);
        if (m.by_name) parts.push(`${m.by_name} matched by file name only (no uid column)`);
        if (m.empty) parts.push(`${m.empty} without a label`);
        return parts.join(' · ');
    }

    function pickAndImport(o) {
        const input = document.createElement('input');
        input.type = 'file';
        input.accept = '.xlsx,.csv,.tsv,.json,.txt';
        input.onchange = async () => {
            const f = input.files[0];
            if (!f) return;
            const ok = await askConfirm({
                title: 'Merge this annotated file?',
                message: `${f.name} is read and its labels are merged into your dataset by each image's unique uid (file name only when the file has no uid column). Images you already labeled are kept.`,
                confirmText: 'Merge', checkbox: 'Replace my existing labels when they differ'
            });
            if (!ok) return;
            const overwrite = askConfirm.checked ? '1' : '0';
            o.onMsg('Importing…', true);
            try {
                const j = await call(form({ action: 'import', overwrite, file: f }));
                o.onMsg('✓ ' + summary(j.merge), true);
                if (j.merge.updated > 0 && o.onMerged) o.onMerged(j);
            } catch (err) {
                o.onMsg('Import failed: ' + err.message, false);
            }
        };
        input.click();
    }

    // download the images that still have no label (+ a template to annotate them elsewhere)
    async function downloadPending(onMsg) {
        onMsg('Packing the not-annotated images…', true);
        const j = await call(form({ action: 'build_pending_zip' }));
        const a = document.createElement('a');
        a.href = j.file + '?v=' + Date.now(); a.download = 'not_annotated.zip';
        document.body.appendChild(a); a.click(); a.remove();
        onMsg(`✓ ${j.count} not-annotated image(s) downloaded (${(j.size / 1048576).toFixed(2)} MB) with labels_template.csv / .xlsx.`, true);
        return j;
    }

    // upload images that were not annotated: they are added to the dataset as "to label"
    function pickAndUploadImages(o) {
        const input = document.createElement('input');
        input.type = 'file'; input.multiple = true;
        input.accept = 'image/png,image/jpeg,image/webp,.png,.jpg,.jpeg,.webp,.zip';
        input.onchange = async () => {
            const list = Array.from(input.files || []);
            if (!list.length) return;
            if (!await askConfirm({ title: `Add ${list.length} file(s) to annotate?`,
                message: 'The pictures are added to the dataset as "to label" (original pixels, unchanged). A .zip of images is unpacked.', confirmText: 'Upload' })) return;
            const tot = { added: 0, duplicate: 0, invalid: 0, labels: 0, errors: [] };
            try {
                // Keep each request below the documented 256 MB post_max_size, and
                // below PHP's max_file_uploads even when many images are selected.
                const MAX_FILES = 15, MAX_BYTES = 190 * 1024 * 1024;
                const batches = [];
                let batch = [], batchBytes = 0;
                for (const file of list) {
                    if (file.size > 50 * 1024 * 1024) {
                        tot.invalid++;
                        tot.errors.push(`${file.name} is larger than the 50 MB per-file upload limit.`);
                        continue;
                    }
                    if (batch.length && (batch.length >= MAX_FILES || batchBytes + file.size > MAX_BYTES)) {
                        batches.push(batch); batch = []; batchBytes = 0;
                    }
                    batch.push(file); batchBytes += file.size;
                }
                if (batch.length) batches.push(batch);
                let sent = 0;
                for (const group of batches) {
                    sent += group.length;
                    o.onMsg(`Uploading ${sent} / ${list.length}…`, true);
                    const fd = new FormData(); fd.append('action', 'upload_images');
                    group.forEach(f => fd.append('files[]', f, f.name));
                    const j = await call(fd);
                    tot.added += j.added; tot.duplicate += j.duplicate; tot.invalid += j.invalid; tot.labels += j.labels || 0;
                    tot.errors.push(...(j.upload_errors || []));
                }
                const p = [`${tot.added} image(s) added`];
                if (tot.labels) p.push(`${tot.labels} label(s) imported with them`);
                if (tot.duplicate) p.push(`${tot.duplicate} already in the dataset`);
                if (tot.invalid) p.push(`${tot.invalid} not readable / not an image`);
                if (tot.errors.length) p.push(tot.errors.slice(0, 3).join(' '));
                const hasWarnings = tot.invalid > 0 || tot.errors.length > 0;
                o.onMsg((hasWarnings ? '⚠ ' : '✓ ') + p.join(' · '), !hasWarnings || tot.added > 0);
                if (tot.added > 0 && o.onDone) o.onDone(tot);
            } catch (err) { o.onMsg('Upload failed: ' + err.message, false); }
        };
        input.click();
    }

    window.LabelsIO = {
        downloadPending,
        pickAndUploadImages,
        pickAndImport,
        // index.php wiring
        // build and download the training zip (index.php)
        wireZip(c) {
            const $ = (id) => document.getElementById(id);
            const msg = (html, ok) => { const el = $(c.msg); el.innerHTML = html; el.className = 'kc-io-msg ' + (ok ? 'ok' : 'err'); };
            const esc = (s) => String(s).replace(/[&<>"']/g, ch => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[ch]));
            $(c.btn).addEventListener('click', async () => {
                const split = $(c.split).value;
                if (!await askConfirm({ title: 'Build the training dataset?',
                    message: `Only finished (labeled) images are packed, split ${split.replace(/-/g, ' / ')} into train / val / test, and a new dataset_NNN.zip is created.`,
                    confirmText: 'Build zip' })) return;
                const btn = $(c.btn); btn.disabled = true; msg('Building the zip…', true);
                try {
                    const j = await call(form({ action: 'build_zip', split }));
                    const k = j.counts, mb = (j.size / 1048576).toFixed(2);
                    let h = `✓ <b>${esc(j.name)}.zip</b> created (${mb} MB): ${k.total} images · train ${k.train} · val ${k.val} · test ${k.test}` +
                            (k.skipped ? ` · ${k.skipped} not annotated, left out` : '') + '.';
                    (j.warnings || []).forEach(w => { h += `<br><span style="color:#e69500">⚠ ${esc(w)}</span>`; });
                    h += `<div class="kc-result-btns"><a class="kc-btn primary" href="${esc(j.file)}" download>⬇ Download ${esc(j.name)}.zip</a></div>`;
                    msg(h, true);
                } catch (err) { msg('Could not build the zip: ' + esc(err.message), false); }
                finally { btn.disabled = false; }
            });
        },

        wire(c) {
            const $ = (id) => document.getElementById(id);
            const msg = (t, ok) => { const el = $(c.msg); el.textContent = t; el.className = 'kc-io-msg ' + (ok ? 'ok' : 'err'); };
            const run = (btnId, file, label) => $(btnId).addEventListener('click', async () => {
                if (!await askConfirm({ title: 'Download annotated data?', message: `Only the images you finished annotating go into ${label}; images still to label are left out.`, confirmText: 'Download' })) return;
                try { const j = await download(file); msg(`✓ Downloaded ${label} (${j.labeled} labeled image(s)).`, true); }
                catch (err) { msg(err.message, false); }
            });
            run(c.download[0], 'htr_dataset/annotated_labels.xlsx', 'annotated_labels.xlsx');
            run(c.download[1], 'htr_dataset/labels.csv', 'labels.csv');
            if (c.zipBtn && $(c.zipBtn)) $(c.zipBtn).addEventListener('click', async () => {
                if (!await askConfirm({ title: 'Download the annotated dataset?', message: 'A .zip with the annotated images and their labels (labels.csv / labels.xlsx). Images still to label are left out.', confirmText: 'Download' })) return;
                try {
                    msg('Packing the annotated dataset…', true);
                    const j = await call(form({ action: 'build_annotated_zip' }));
                    const a = document.createElement('a');
                    a.href = j.file + '?v=' + Date.now(); a.download = 'annotated.zip';
                    document.body.appendChild(a); a.click(); a.remove();
                    msg(`✓ Downloaded annotated.zip: ${j.count} image(s) with their labels (${(j.size / 1048576).toFixed(2)} MB).`, true);
                } catch (err) { msg(err.message, false); }
            });
            $(c.importBtn).addEventListener('click', () => pickAndImport({ onMsg: msg, onMerged: c.onMerged }));
        },

        // "images to annotate": download the unlabelled ones / upload new ones
        wirePending(c) {
            const $ = (id) => document.getElementById(id);
            const msg = (t, ok) => { const el = $(c.msg); el.textContent = t; el.className = 'kc-io-msg ' + (ok ? 'ok' : 'err'); };
            $(c.download).addEventListener('click', async () => {
                if (!await askConfirm({ title: 'Download the not-annotated images?', message: 'A .zip with every image that still has no label, plus a labels template to fill in.', confirmText: 'Download' })) return;
                try { await downloadPending(msg); } catch (err) { msg(err.message, false); }
            });
            $(c.upload).addEventListener('click', () => pickAndUploadImages({ onMsg: msg, onDone: c.onDone }));
        }
    };
})();
