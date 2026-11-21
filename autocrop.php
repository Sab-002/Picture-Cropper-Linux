<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kraken Word Cropper — Black & White Edition</title>
    <meta name="description" content="Automatic single-word scanner and cropper powered by Kraken BLLA neural segmentation.">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --bg:          #050507;
            --card:        #0d0d10;
            --surface:     #141418;
            --border:      #26262b;
            --border-hover:#3f3f46;
            --text-primary:#ffffff;
            --text-muted:  #8b8b96;
            --text-dim:    #52525b;
            --btn-bg:      #ffffff;
            --btn-text:    #000000;
            --glow:        rgba(255, 255, 255, 0.18);
        }

        html, body { min-height: 100vh; }

        body {
            font-family: 'Inter', sans-serif;
            background: var(--bg);
            color: var(--text-primary);
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 48px 20px 80px;
            background-image: 
                radial-gradient(circle at 50% 0%, rgba(255, 255, 255, 0.05) 0%, transparent 60%),
                linear-gradient(to bottom, #070709, #030304);
            background-attachment: fixed;
        }

        /* ── Header ── */
        .header {
            text-align: center;
            margin-bottom: 40px;
        }

        .engine-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 6px 16px;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 999px;
            font-size: .78rem;
            font-weight: 700;
            letter-spacing: .8px;
            text-transform: uppercase;
            color: var(--text-primary);
            margin-bottom: 18px;
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
            0%, 100% { opacity: 1; transform: scale(1); }
            50% { opacity: .4; transform: scale(.85); }
        }

        h1 {
            font-size: 2.3rem;
            font-weight: 900;
            letter-spacing: -1px;
            color: #ffffff;
            line-height: 1.15;
            margin-bottom: 8px;
        }

        .subtitle {
            color: var(--text-muted);
            font-size: .95rem;
            max-width: 480px;
            line-height: 1.5;
            margin: 0 auto;
        }

        /* ── Main Form Card ── */
        .form-card {
            width: 100%;
            max-width: 580px;
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 24px;
            padding: 36px;
            display: flex;
            flex-direction: column;
            gap: 26px;
            box-shadow: 0 20px 50px rgba(0, 0, 0, 0.6), 0 0 0 1px rgba(255, 255, 255, 0.03);
        }

        /* ── Drop Zone ── */
        .dropzone {
            border: 2px dashed var(--border);
            border-radius: 18px;
            padding: 38px 24px;
            text-align: center;
            cursor: pointer;
            transition: all .25s ease;
            background: var(--surface);
            position: relative;
            overflow: hidden;
        }

        .dropzone:hover,
        .dropzone.dragover {
            border-color: #ffffff;
            background: rgba(255, 255, 255, 0.04);
            box-shadow: 0 0 24px var(--glow);
        }

        .dropzone input[type="file"] {
            position: absolute;
            inset: 0;
            opacity: 0;
            cursor: pointer;
            width: 100%;
            height: 100%;
        }

        .drop-icon {
            font-size: 2.6rem;
            margin-bottom: 12px;
            filter: grayscale(1);
        }

        .drop-title {
            font-size: 1rem;
            font-weight: 700;
            color: #ffffff;
            margin-bottom: 4px;
        }

        .drop-sub {
            font-size: .8rem;
            color: var(--text-muted);
        }

        /* ── Preview Box ── */
        .preview-box {
            display: none;
            position: relative;
            border-radius: 14px;
            overflow: hidden;
            border: 1px solid var(--border);
            background: #000000;
            max-height: 220px;
        }

        .preview-box img {
            width: 100%;
            height: 100%;
            max-height: 220px;
            object-fit: contain;
            display: block;
        }

        .preview-meta {
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            background: rgba(0, 0, 0, 0.85);
            backdrop-filter: blur(8px);
            padding: 8px 14px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: .75rem;
            color: var(--text-muted);
            border-top: 1px solid var(--border);
        }

        .preview-meta .fname {
            color: #ffffff;
            font-weight: 600;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 280px;
        }

        .remove-img-btn {
            background: transparent;
            border: none;
            color: #f87171;
            cursor: pointer;
            font-size: .8rem;
            font-weight: 600;
            padding: 2px 6px;
        }
        .remove-img-btn:hover { text-decoration: underline; }

        /* ── Settings Controls ── */
        .settings-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }

        .field-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .field-group label {
            font-size: .78rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .6px;
            color: var(--text-muted);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .field-group label span.val-badge {
            color: #ffffff;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 6px;
            padding: 2px 8px;
            font-size: .75rem;
        }

        .slider-wrap {
            display: flex;
            align-items: center;
            gap: 10px;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 10px 14px;
        }

        input[type="range"] {
            -webkit-appearance: none;
            appearance: none;
            width: 100%;
            height: 4px;
            background: var(--border);
            border-radius: 2px;
            outline: none;
        }

        input[type="range"]::-webkit-slider-thumb {
            -webkit-appearance: none;
            appearance: none;
            width: 16px;
            height: 16px;
            border-radius: 50%;
            background: #ffffff;
            cursor: pointer;
            box-shadow: 0 0 10px rgba(255, 255, 255, 0.4);
            transition: transform .15s;
        }

        input[type="range"]::-webkit-slider-thumb:hover {
            transform: scale(1.2);
        }

        select {
            width: 100%;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 12px;
            color: #ffffff;
            font-size: .85rem;
            font-family: inherit;
            padding: 11px 14px;
            outline: none;
            cursor: pointer;
            transition: border-color .2s;
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' fill='%23ffffff' viewBox='0 0 16 16'%3E%3Cpath d='M7.247 11.14 2.451 5.658C1.885 5.013 2.345 4 3.204 4h9.592a1 1 0 0 1 .753 1.659l-4.796 5.48a1 1 0 0 1-1.506 0z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 14px center;
        }

        select:focus {
            border-color: #ffffff;
        }

        /* ── Submit Button ── */
        .submit-btn {
            width: 100%;
            padding: 16px;
            background: var(--btn-bg);
            color: var(--btn-text);
            border: none;
            border-radius: 14px;
            font-size: .95rem;
            font-weight: 800;
            font-family: inherit;
            letter-spacing: .5px;
            text-transform: uppercase;
            cursor: pointer;
            transition: all .2s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            box-shadow: 0 4px 24px rgba(255, 255, 255, 0.2);
        }

        .submit-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 32px rgba(255, 255, 255, 0.35);
        }

        .submit-btn:active {
            transform: translateY(0);
        }

        .submit-btn:disabled {
            opacity: .4;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }

        /* ── Spinner on submit ── */
        .spinner {
            display: none;
            width: 18px;
            height: 18px;
            border: 2px solid #000000;
            border-top-color: transparent;
            border-radius: 50%;
            animation: spin .7s linear infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        /* ── Footer ── */
        footer {
            margin-top: 48px;
            color: var(--text-dim);
            font-size: .8rem;
            letter-spacing: .4px;
            display: flex;
            align-items: center;
            gap: 16px;
        }

        footer a {
            color: var(--text-muted);
            text-decoration: none;
            transition: color .2s;
        }
        footer a:hover { color: #ffffff; }
    </style>
</head>
<body>

    <div class="header">
        <div class="engine-badge">
            <div class="pulse"></div>
            Kraken BLLA Engine Only
        </div>
        <h1>Word Cropper</h1>
        <p class="subtitle">Automatic single-word detection and cropping with Kraken neural segmentation and instant batch selection export.</p>
    </div>

    <form class="form-card" id="cropForm" action="process.php" method="POST" enctype="multipart/form-data">
        
        <!-- Hidden engine input: Always Kraken -->
        <input type="hidden" name="engine" value="kraken">

        <!-- Dropzone Area -->
        <div class="dropzone" id="dropzone">
            <input type="file" name="image" id="fileInput" accept="image/png,image/jpeg,image/webp" required>
            <div class="drop-icon">📄</div>
            <div class="drop-title">Drop your image here or click to browse</div>
            <div class="drop-sub">Supports PNG, JPG, WEBP • Max 50MB</div>
        </div>

        <!-- Preview Area -->
        <div class="preview-box" id="previewBox">
            <img id="previewImg" src="" alt="Upload preview">
            <div class="preview-meta">
                <span class="fname" id="previewName"></span>
                <button type="button" class="remove-img-btn" id="removeImgBtn">Remove</button>
            </div>
        </div>

        <!-- Settings Grid -->
        <div class="settings-grid">
            <!-- Padding -->
            <div class="field-group">
                <label for="paddingSlider">
                    Crop Padding
                    <span class="val-badge" id="paddingVal">4 px</span>
                </label>
                <div class="slider-wrap">
                    <input type="range" name="padding" id="paddingSlider" min="0" max="24" value="4">
                </div>
            </div>

            <!-- Output Format -->
            <div class="field-group">
                <label for="formatSelect">Output Format</label>
                <select name="output_format" id="formatSelect">
                    <option value="png" selected>PNG (Lossless)</option>
                    <option value="webp">WEBP (Optimized)</option>
                    <option value="jpeg">JPEG (Standard)</option>
                </select>
            </div>
        </div>

        <!-- Submit Button -->
        <button type="submit" class="submit-btn" id="submitBtn">
            <span class="spinner" id="spinner"></span>
            <span id="btnText">Scan &amp; Crop Words</span>
        </button>

    </form>

    <footer>
        <span>Word Cropper · Monochrome Edition</span>
        <span>•</span>
        <a href="index.php">Manual Canvas Cropper</a>
    </footer>

    <script>
        const dropzone = document.getElementById('dropzone');
        const fileInput = document.getElementById('fileInput');
        const previewBox = document.getElementById('previewBox');
        const previewImg = document.getElementById('previewImg');
        const previewName = document.getElementById('previewName');
        const removeImgBtn = document.getElementById('removeImgBtn');
        const paddingSlider = document.getElementById('paddingSlider');
        const paddingVal = document.getElementById('paddingVal');
        const cropForm = document.getElementById('cropForm');
        const submitBtn = document.getElementById('submitBtn');
        const spinner = document.getElementById('spinner');
        const btnText = document.getElementById('btnText');

        // Padding slider live update
        paddingSlider.addEventListener('input', (e) => {
            paddingVal.textContent = e.target.value + ' px';
        });

        // Drag & Drop events
        ['dragenter', 'dragover'].forEach(eventName => {
            dropzone.addEventListener(eventName, (e) => {
                e.preventDefault();
                dropzone.classList.add('dragover');
            });
        });

        ['dragleave', 'drop'].forEach(eventName => {
            dropzone.addEventListener(eventName, (e) => {
                e.preventDefault();
                dropzone.classList.remove('dragover');
            });
        });

        // File Selection Handlers
        fileInput.addEventListener('change', handleFile);

        function handleFile() {
            const file = fileInput.files[0];
            if (!file) return;

            const reader = new FileReader();
            reader.onload = (e) => {
                previewImg.src = e.target.result;
                previewName.textContent = file.name + ' (' + (file.size / 1024).toFixed(1) + ' KB)';
                dropzone.style.display = 'none';
                previewBox.style.display = 'block';
            };
            reader.readAsDataURL(file);
        }

        removeImgBtn.addEventListener('click', () => {
            fileInput.value = '';
            previewImg.src = '';
            previewBox.style.display = 'none';
            dropzone.style.display = 'block';
        });

        // Form Submit Loading State
        cropForm.addEventListener('submit', () => {
            submitBtn.disabled = true;
            spinner.style.display = 'inline-block';
            btnText.textContent = 'Scanning with Kraken...';
        });
    </script>
</body>
</html>
