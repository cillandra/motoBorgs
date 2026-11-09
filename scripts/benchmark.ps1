param([string]$ProjectRoot='C:\xampp\htdocs\moto',[string]$Php='C:\xampp\moto-runtime\php84\php.exe',[int]$Iterations=20)
$ErrorActionPreference='Stop'
if($Iterations -lt 1 -or $Iterations -gt 100){throw 'Iterations must be 1–100.'}
$project=(Resolve-Path -LiteralPath $ProjectRoot).Path
if(-not(Test-Path -LiteralPath (Join-Path $project '.env.benchmark'))){throw 'Create the private .env.benchmark using only the disposable test database account; see the environment guide.'}
$directory=Join-Path $project ('.runtime\benchmark-'+[guid]::NewGuid().ToString())
New-Item -ItemType Directory -Path $directory | Out-Null
$previousEnvironment=$env:APP_ENV
$servers=@();$workers=@()
try {
    $env:APP_ENV='benchmark'
    Push-Location $project
    try { & $Php artisan moto:benchmark-seed --variants=10000 --sales=100000 *> (Join-Path $directory 'seed.log'); if($LASTEXITCODE -ne 0){throw 'Benchmark seed failed; inspect the private seed log.'} } finally { Pop-Location }
    for($number=1;$number -le 5;$number++) {
        $port=8100+$number
        $probe=[Net.Sockets.TcpClient]::new()
        try { $attempt=$probe.ConnectAsync('127.0.0.1',$port); if($attempt.Wait(200) -and $probe.Connected){throw "Benchmark port $port is already occupied."} } catch [AggregateException] {} finally {$probe.Dispose()}
        $servers+=Start-Process -FilePath $Php -ArgumentList @('-S',"127.0.0.1:$port",'-t','.',(Join-Path $project 'vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php')) -WorkingDirectory (Join-Path $project 'public') -WindowStyle Hidden -PassThru -RedirectStandardOutput (Join-Path $directory "server-$number.log") -RedirectStandardError (Join-Path $directory "server-$number-error.log")
    }
    $deadline=(Get-Date).AddSeconds(20)
    for($number=1;$number -le 5;$number++) {
        $ready=$false
        do { try { $response=Invoke-WebRequest -Uri "http://127.0.0.1:$(8100+$number)/health" -UseBasicParsing -TimeoutSec 2; $ready=$response.StatusCode -eq 200 } catch { Start-Sleep -Milliseconds 200 } } while(-not $ready -and (Get-Date) -lt $deadline)
        if(-not $ready){throw 'Benchmark HTTP server failed to start.'}
        $arguments='"'+(Join-Path $project 'scripts\benchmark-worker.php')+'" "http://127.0.0.1:'+(8100+$number)+'" '+$number+' '+$Iterations+' "'+(Join-Path $directory "worker-$number.json")+'" "'+(Join-Path $directory 'start')+'"'
        $workers+=Start-Process -FilePath $Php -ArgumentList $arguments -WorkingDirectory $project -WindowStyle Hidden -PassThru -RedirectStandardOutput (Join-Path $directory "worker-$number.log") -RedirectStandardError (Join-Path $directory "worker-$number-error.log")
    }
    $deadline=(Get-Date).AddSeconds(60)
    while((Get-ChildItem -LiteralPath $directory -Filter 'start.ready.*').Count -lt 5 -and (Get-Date) -lt $deadline){Start-Sleep -Milliseconds 100}
    if((Get-ChildItem -LiteralPath $directory -Filter 'start.ready.*').Count -ne 5){throw 'Five independent signed-in sessions did not reach the start barrier.'}
    [IO.File]::WriteAllText((Join-Path $directory 'start.go'),'go')
    foreach($worker in $workers){$worker.WaitForExit();if($worker.ExitCode -ne 0){throw 'Benchmark request worker failed; inspect the private worker error log.'}}
    $results=@(1..5|ForEach-Object{Get-Content -LiteralPath (Join-Path $directory "worker-$_.json") -Raw|ConvertFrom-Json})
    function Measure-Latencies($values){$sorted=@($values|Sort-Object);return @{samples=$sorted.Count;p50_ms=[Math]::Round($sorted[[Math]::Ceiling($sorted.Count*.5)-1],2);p95_ms=[Math]::Round($sorted[[Math]::Ceiling($sorted.Count*.95)-1],2);max_ms=[Math]::Round($sorted[-1],2)}}
    $verification=& $Php (Join-Path $project 'scripts\benchmark-worker.php') --verify
    if($LASTEXITCODE -ne 0){throw 'Benchmark reconciliation query failed.'}
    $reconciliation=$verification|ConvertFrom-Json
    if($reconciliation.variants -ne 10000 -or $reconciliation.sales -ne (100000+5*$Iterations) -or $reconciliation.signed_in_users -ne 5 -or $reconciliation.stock_mismatches -ne 0){throw 'Benchmark database/session/stock reconciliation failed.'}
    $allSaleIds=@($results.sales|Select-Object -Unique)
    if($allSaleIds.Count -ne (5*$Iterations)){throw 'Benchmark checkout IDs are not unique.'}
    $cpu=Get-CimInstance Win32_Processor|Select-Object -First 1
    $os=Get-CimInstance Win32_OperatingSystem
    $summary=@{recorded_at=(Get-Date).ToString('o');dataset=@{variants=10000;sales_before=100000;sales_created=@($results.sales).Count};workers=5;reconciliation=$reconciliation;distinct_sessions=@($results.session_hash|Select-Object -Unique).Count;transport='Five independent loopback PHP HTTP servers sharing one MariaDB; real CSRF, database sessions and login; warmup excluded; no network adapter changes';machine=@{os=$os.Caption;cpu=$cpu.Name;logical_processors=$cpu.NumberOfLogicalProcessors;ram_gb=[Math]::Round($os.TotalVisibleMemorySize/1MB,1)};page=(Measure-Latencies @($results.page_ms));search=(Measure-Latencies @($results.search_ms));checkout_http=(Measure-Latencies @($results.checkout_http_ms));checkout_commit=(Measure-Latencies @($results.checkout_commit_ms))}
    $summary|ConvertTo-Json -Depth 8|Set-Content -LiteralPath (Join-Path $project 'docs\benchmark-results.json')
    $summary|ConvertTo-Json -Depth 8
} finally {
    foreach($process in @($workers)+@($servers)){if(-not $process.HasExited){Stop-Process -Id $process.Id -Force}}
    $env:APP_ENV=$previousEnvironment
}
