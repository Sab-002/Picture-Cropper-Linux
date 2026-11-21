// Electron shell: runs PHP's built-in server on a free local port and shows the app in a window.
const { app, BrowserWindow, dialog, shell } = require('electron');
const { spawn } = require('child_process');
const net = require('net');
const path = require('path');
const fs = require('fs');
const http = require('http');

const res = app.isPackaged ? process.resourcesPath : path.join(__dirname, 'build');
const phpExe = path.join(res, 'php', 'php.exe');
const pyExe = path.join(res, 'python', 'python.exe');
const codeSrc = path.join(res, 'www');

let phpProc = null;
let win = null;

// The install dir is read-only, so the PHP code is synced into a writable folder that also
// holds the user's data (uploads/, output/, htr_dataset/). Data folders are never overwritten.
function syncWww() {
  const dest = path.join(app.getPath('userData'), 'www');
  fs.mkdirSync(dest, { recursive: true });
  fs.cpSync(codeSrc, dest, { recursive: true, force: true });
  for (const d of ['uploads/previews', 'output', 'htr_dataset/images', 'htr_dataset/exports']) {
    fs.mkdirSync(path.join(dest, d), { recursive: true });
  }
  return dest;
}

function freePort() {
  return new Promise((resolve, reject) => {
    const s = net.createServer();
    s.listen(0, '127.0.0.1', () => { const p = s.address().port; s.close(() => resolve(p)); });
    s.on('error', reject);
  });
}

function waitForServer(port, tries = 60) {
  return new Promise((resolve, reject) => {
    const attempt = (n) => {
      http.get({ host: '127.0.0.1', port, path: '/index.php' }, (r) => { r.resume(); resolve(); })
        .on('error', () => (n <= 0 ? reject(new Error('PHP server did not start')) : setTimeout(() => attempt(n - 1), 250)));
    };
    attempt(tries);
  });
}

async function start() {
  if (!fs.existsSync(phpExe)) {
    dialog.showErrorBox('Cropper Suite', 'PHP runtime not found at:\n' + phpExe + '\n\nRun "npm run prepare-runtime" first.');
    return app.quit();
  }
  const root = syncWww();
  const port = await freePort();
  const php = path.dirname(phpExe);
  phpProc = spawn(phpExe, [
    '-d', 'extension_dir=' + path.join(php, 'ext'),
    '-d', 'extension=gd', '-d', 'extension=zip', '-d', 'extension=fileinfo', '-d', 'extension=mbstring',
    '-d', 'upload_max_filesize=512M', '-d', 'post_max_size=512M',
    '-d', 'max_file_uploads=1000', '-d', 'max_execution_time=0', '-d', 'memory_limit=2G',
    '-S', '127.0.0.1:' + port, '-t', root,
  ], {
    cwd: root,
    windowsHide: true,
    env: { ...process.env, KRAKEN_PYTHON: fs.existsSync(pyExe) ? pyExe : '' },
  });
  phpProc.on('exit', (c) => {
    if (!app.isQuitting && c) dialog.showErrorBox('Cropper Suite', 'PHP server stopped (code ' + c + ').');
  });

  try { await waitForServer(port); } catch (e) { dialog.showErrorBox('Cropper Suite', e.message); return app.quit(); }

  win = new BrowserWindow({ width: 1400, height: 900, autoHideMenuBar: true, title: 'Cropper Suite' });
  win.loadURL('http://127.0.0.1:' + port + '/index.php');
  win.webContents.setWindowOpenHandler(({ url }) => {
    if (!url.startsWith('http://127.0.0.1:' + port)) { shell.openExternal(url); return { action: 'deny' }; }
    return { action: 'allow' };
  });
}

function stopPhp() { if (phpProc) { try { phpProc.kill(); } catch (_) {} phpProc = null; } }

if (!app.requestSingleInstanceLock()) app.quit();
else {
  app.whenReady().then(start);
  app.on('before-quit', () => { app.isQuitting = true; stopPhp(); });
  app.on('window-all-closed', () => app.quit());
}
