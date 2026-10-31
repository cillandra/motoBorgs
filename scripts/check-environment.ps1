$ErrorActionPreference = 'Stop'
$motoRoot = Split-Path -Parent $PSScriptRoot
$motoPhp = 'C:\xampp\moto-runtime\php84\php.exe'
if (-not (Test-Path -LiteralPath $motoPhp)) { throw 'Install MOTO PHP 8.4 in C:\xampp\moto-runtime\php84 first.' }
& $motoPhp (Join-Path $PSScriptRoot 'check-environment.php')
if ($LASTEXITCODE -ne 0) { throw 'MOTO PHP environment check failed.' }
foreach ($motoRequired in @('.env','vendor\autoload.php','public\build\manifest.json')) {
    if (-not (Test-Path -LiteralPath (Join-Path $motoRoot $motoRequired))) { throw "Missing $motoRequired. Follow README setup instructions." }
}
