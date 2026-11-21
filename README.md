# Image & Word Cropper Suite

A PHP web app for manual multi-region image cropping, Kraken-powered word segmentation, batch export, and handwritten text annotation.

## Requirements

- PHP 8.2+ with `gd`, `fileinfo`, and `zip` enabled.
- Python 3.10+ with Kraken, PyTorch, scikit-image, Pillow, and NumPy for automatic word cropping.
- A writable project directory for generated previews, crops, and annotation data.

The manual cropper and annotation pages work without the Kraken Python packages. PDF and spreadsheet support use the included `pylibs` directory.

## Run on Linux

On Debian or Ubuntu, install PHP and Python:

```bash
sudo apt update
sudo apt install php-cli php-gd php-zip php-mbstring python3 python3-venv python3-pip
```

Confirm PHP has the needed extensions enabled:

```bash
php -m | grep -E '^(gd|fileinfo|zip)$'
```

For automatic word cropping, create and populate the Python environment from the project directory:

```bash
python3 -m venv .venv-kraken
source .venv-kraken/bin/activate
python -m pip install --upgrade pip
python -m pip install torch torchvision  # CPU build; use the PyTorch selector for CUDA
python -m pip install kraken scikit-image pillow numpy
```

Start PHP's built-in web server with the app's 50 MB per-file upload limit:

```bash
./run-linux.sh
```

Stop any old PHP server first with `Ctrl+C`; changing the command does not change limits in a server that is already running.

Open [http://127.0.0.1:8000/](http://127.0.0.1:8000/) for the manual cropper, or [http://127.0.0.1:8000/autocrop.php](http://127.0.0.1:8000/autocrop.php) for automatic word cropping.

The app looks for Python in `.venv/bin/python`, `.venv-kraken/bin/python`, the equivalent Windows virtual-environment paths, and then the system `python` command. It creates `uploads/`, `output/`, and `htr_dataset/` at runtime as needed; those folders are ignored by Git so personal data remains local.

## Run on Windows

Install PHP 8.2+ with the required extensions, then run `php -S localhost:8000` from the project directory. Use the same pages at `http://localhost:8000/` and `http://localhost:8000/autocrop.php`.

The optional Electron packaging scripts under `desktop/` currently target a Windows installer.

## Project files

- `index.php` — manual cropper and application home page
- `autocrop.php` / `process.php` — Kraken upload and segmentation workflow
- `batch.php` — batch crop workflow
- `annotate.php` — handwritten text annotation interface
- `kraken_segment.py`, `table_cells.py`, `pdf_to_pages.py` — image processing helpers
- `pylibs/` — bundled PDF and spreadsheet support libraries

## License

MIT License. Free for personal and commercial use.
