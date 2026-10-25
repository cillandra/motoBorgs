$ErrorActionPreference = 'Stop'
$motoRoot = Split-Path -Parent $PSScriptRoot
$motoPhp = 'C:\xampp\moto-runtime\php84\php.exe'
if (-not (Test-Path -LiteralPath $motoPhp)) { throw 'Install MOTO PHP 8.4 in C:\xampp\moto-runtime\php84 first.' }
& $motoPhp -r 'if (PHP_VERSION_ID < 80400) { fwrite(STDERR, "PHP 8.4 or newer is required.\n"); exit(1); } foreach (["pdo_mysql", "mbstring", "openssl", "fileinfo", "gd", "intl", "zip"] as $extension) { if (!extension_loaded($extension)) { fwrite(STDERR, "Missing extension: ".$extension."\n"); exit(1); } } echo "MOTO PHP extensions ready.\n";'
if ($LASTEXITCODE -ne 0) { throw 'MOTO PHP environment check failed.' }
foreach ($motoRequired in @('.env','vendor\autoload.php','public\build\manifest.json')) {
    if (-not (Test-Path -LiteralPath (Join-Path $motoRoot $motoRequired))) { throw "Missing $motoRequired. Follow README setup instructions." }
}
