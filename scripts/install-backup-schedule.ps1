param([string]$ProjectRoot='C:\xampp\htdocs\moto',[string]$Php='C:\xampp\moto-runtime\php84\php.exe')
$ErrorActionPreference='Stop'
$project=(Resolve-Path -LiteralPath $ProjectRoot).Path
$phpPath=(Resolve-Path -LiteralPath $Php).Path
if (-not (Test-Path -LiteralPath (Join-Path $project 'artisan'))) { throw 'Select the independent MOTO project.' }
$action=New-ScheduledTaskAction -Execute $phpPath -Argument ('"'+(Join-Path $project 'artisan')+'" schedule:run') -WorkingDirectory $project
$trigger=New-ScheduledTaskTrigger -Once -At (Get-Date).AddMinutes(1) -RepetitionInterval (New-TimeSpan -Minutes 1)
$settings=New-ScheduledTaskSettingsSet -MultipleInstances IgnoreNew -ExecutionTimeLimit (New-TimeSpan -Minutes 10) -StartWhenAvailable
Register-ScheduledTask -TaskName 'MOTO-Local-Backups' -Action $action -Trigger $trigger -Settings $settings -Description 'MOTO local scheduler heartbeat and daily verified backups; requires local database running.' -Force | Out-Null
Start-ScheduledTask -TaskName 'MOTO-Local-Backups'
Push-Location $project
try { & $phpPath artisan moto:backup-scheduler --verify } finally { Pop-Location }
Write-Output 'Verify the heartbeat after one minute, then enable automatic backups in Manager Settings. No password is stored by this installer.'
