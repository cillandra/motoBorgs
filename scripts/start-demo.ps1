$ErrorActionPreference = 'Stop'
& (Join-Path $PSScriptRoot 'check-environment.ps1')
$motoRoot = Split-Path -Parent $PSScriptRoot
$motoPhp = 'C:\xampp\moto-runtime\php84\php.exe'
$motoClient = [Net.Sockets.TcpClient]::new()
try { $motoDatabaseReady = $motoClient.ConnectAsync('127.0.0.1',3307).Wait(1000) -and $motoClient.Connected } catch { $motoDatabaseReady = $false } finally { $motoClient.Dispose() }
if (-not $motoDatabaseReady) {
    Start-Process -FilePath 'C:\xampp\mysql\bin\mysqld.exe' -ArgumentList '--defaults-file=C:\xampp\moto-runtime\mysql-data\my.ini','--standalone' -WindowStyle Hidden
}
$env:PATH = 'C:\xampp\moto-runtime\php84;' + $env:PATH
Push-Location -LiteralPath $motoRoot
try { & $motoPhp artisan serve --host=127.0.0.1 --port=8000 --no-reload } finally { Pop-Location }
