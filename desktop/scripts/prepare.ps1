# Stages everything the Electron app bundles into desktop/build:
#   build/www     PHP app code (no user data)
#   build/php     portable PHP (copied from -PhpDir; default: newest Laragon PHP)
#   build/python  embeddable Python 3.10 + kraken/torch (CPU) deps   (several GB)
param(
  [string]$PhpDir = '',
  [switch]$SkipPython,
  [string]$TorchIndex = 'https://download.pytorch.org/whl/cpu'
)
$ErrorActionPreference = 'Stop'
$desktop = Split-Path $PSScriptRoot -Parent
$root = Split-Path $desktop -Parent
$build = Join-Path $desktop 'build'

if (-not $PhpDir) {
  $PhpDir = (Get-ChildItem 'C:\laragon\bin\php' -Directory | Where-Object { Test-Path (Join-Path $_.FullName 'php.exe') } | Sort-Object Name -Descending | Select-Object -First 1).FullName
}
if (-not (Test-Path (Join-Path $PhpDir 'php.exe'))) { throw "php.exe not found in $PhpDir (pass -PhpDir)" }

# --- PHP app code (no uploads/output/dataset) ---
$www = Join-Path $build 'www'
if (Test-Path $www) { Remove-Item $www -Recurse -Force }
New-Item -ItemType Directory $www -Force | Out-Null
Get-ChildItem $root -File | Where-Object { $_.Extension -in '.php','.py','.js','.css' } | Copy-Item -Destination $www
Copy-Item (Join-Path $root 'pylibs') (Join-Path $www 'pylibs') -Recurse
Write-Host 'www staged'

# --- PHP runtime ---
$php = Join-Path $build 'php'
if (Test-Path $php) { Remove-Item $php -Recurse -Force }
Copy-Item $PhpDir $php -Recurse
Write-Host "php staged from $PhpDir"

$py = Join-Path $build 'python'
if ($SkipPython) { New-Item -ItemType Directory $py -Force | Out-Null; Write-Host 'Skipped python'; exit 0 }

# --- Embeddable Python 3.10 ---
if (Test-Path $py) { Remove-Item $py -Recurse -Force }
New-Item -ItemType Directory $py | Out-Null
$zip = Join-Path $env:TEMP 'python-embed.zip'
Invoke-WebRequest 'https://www.python.org/ftp/python/3.10.11/python-3.10.11-embed-amd64.zip' -OutFile $zip
Expand-Archive $zip $py -Force
# enable site-packages so pip-installed packages are importable
$pth = Get-ChildItem $py -Filter 'python3*._pth' | Select-Object -First 1
(Get-Content $pth.FullName) -replace '^#import site','import site' | Set-Content $pth.FullName
$getpip = Join-Path $env:TEMP 'get-pip.py'
Invoke-WebRequest 'https://bootstrap.pypa.io/get-pip.py' -OutFile $getpip
$pyExe = Join-Path $py 'python.exe'
& $pyExe $getpip --no-warn-script-location
& $pyExe -m pip install --no-warn-script-location torch torchvision --index-url $TorchIndex
& $pyExe -m pip install --no-warn-script-location kraken scikit-image pillow numpy
Write-Host 'python staged'
