<#
.SYNOPSIS
    Daftarkan Windows Task Scheduler untuk import VA harian (Senin–Sabtu).

.DESCRIPTION
    Membuat task "M2S VA Daily Import" dengan 3 trigger (09:30, 13:00, 17:00),
    berjalan sebagai user yang sedang login, dan StartWhenAvailable agar run yang
    terlewat (mesin mati) langsung dikejar saat mesin hidup lagi.

    Task ini menjalankan: php va_daily.php
    (booking otomatis + email laporan ke penerima di va_daily.config.php).

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File setup_va_daily_task.ps1

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File setup_va_daily_task.ps1 -PhpPath "C:\laragon\bin\php\php-8.3.31-nts-Win32-vs16-x64\php.exe"
#>
[CmdletBinding()]
param(
    [string]$PhpPath = '',
    [string]$ScriptPath = '',
    [string]$TaskName = 'M2S VA Daily Import',
    [string[]]$Times = @('09:30', '13:00', '17:00')
)

$ErrorActionPreference = 'Stop'

function Fail([string]$message) {
    Write-Host "GAGAL: $message" -ForegroundColor Red
    exit 1
}

# --- script & php ----------------------------------------------------------
if (-not $ScriptPath) { $ScriptPath = Join-Path $PSScriptRoot 'va_daily.php' }
if (-not (Test-Path -LiteralPath $ScriptPath)) {
    Fail "script tidak ditemukan: $ScriptPath"
}
$ScriptPath = (Resolve-Path -LiteralPath $ScriptPath).Path

if (-not $PhpPath) {
    $cmd = Get-Command php -ErrorAction SilentlyContinue
    if ($cmd) { $PhpPath = $cmd.Source }
}
if (-not $PhpPath -or -not (Test-Path -LiteralPath $PhpPath)) {
    $candidates = @()
    if (Test-Path 'C:\laragon\bin\php') {
        $candidates = Get-ChildItem 'C:\laragon\bin\php' -Directory |
            Sort-Object Name -Descending |
            ForEach-Object { Join-Path $_.FullName 'php.exe' } |
            Where-Object { Test-Path -LiteralPath $_ }
    }
    if ($candidates.Count -gt 0) { $PhpPath = $candidates[0] }
}
if (-not $PhpPath -or -not (Test-Path -LiteralPath $PhpPath)) {
    Fail 'php.exe tidak ditemukan. Jalankan ulang dengan -PhpPath "<path ke php.exe>".'
}
$PhpPath = (Resolve-Path -LiteralPath $PhpPath).Path

Write-Host "PHP    : $PhpPath"
Write-Host "Script : $ScriptPath"
Write-Host "Workdir: $PSScriptRoot"

# --- aksi & trigger --------------------------------------------------------
$action = New-ScheduledTaskAction -Execute $PhpPath -Argument "`"$ScriptPath`"" -WorkingDirectory $PSScriptRoot

$days = @('Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday')
$triggers = @($Times | ForEach-Object {
    New-ScheduledTaskTrigger -Weekly -DaysOfWeek $days -At $_
})

$settings = New-ScheduledTaskSettingsSet `
    -StartWhenAvailable `
    -MultipleInstances IgnoreNew `
    -ExecutionTimeLimit (New-TimeSpan -Hours 2) `
    -AllowStartIfOnBatteries `
    -DontStopIfGoingOnBatteries

# Berjalan hanya saat user login (sesuai keputusan: tanpa menyimpan password).
$principal = New-ScheduledTaskPrincipal -UserId "$env:USERDOMAIN\$env:USERNAME" -LogonType Interactive -RunLevel Limited

try {
    Register-ScheduledTask -TaskName $TaskName -Action $action -Trigger $triggers -Settings $settings `
        -Principal $principal -Description 'Import VA harian M2S: booking otomatis + email laporan (Senin-Sabtu).' `
        -Force | Out-Null
} catch {
    $msg = $_.Exception.Message
    if ($msg -match 'Access is denied|0x80070005|Akses ditolak') {
        Write-Host ''
        Write-Host 'Akses ditolak saat mendaftarkan task.' -ForegroundColor Yellow
        Write-Host 'Jalankan PowerShell sebagai Administrator, lalu ulangi:' -ForegroundColor Yellow
        Write-Host '  powershell -ExecutionPolicy Bypass -File setup_va_daily_task.ps1' -ForegroundColor Yellow
        exit 1
    }
    Fail $msg
}

# --- verifikasi ------------------------------------------------------------
$task = Get-ScheduledTask -TaskName $TaskName
Write-Host ''
Write-Host "Task '$TaskName' terdaftar." -ForegroundColor Green
$task | Format-List TaskName, State, Principal, Actions, Settings
Write-Host 'Trigger:'
$task.Triggers | ForEach-Object {
    '{0}  {1}' -f ($_.DaysOfWeek -join ','), $_.StartBoundary
}

$count = @($task.Triggers).Count
if ($count -ne $Times.Count) {
    Write-Host "PERINGATAN: jumlah trigger $count, seharusnya $($Times.Count)." -ForegroundColor Yellow
    exit 1
}
Write-Host ''
Write-Host 'Selesai. Uji tanpa membukukan pembayaran:' -ForegroundColor Green
Write-Host ("  Start-ScheduledTask -TaskName '{0}'" -f $TaskName)
Write-Host 'Log: data\va_daily\logs\<yyyy-mm-dd>.log'
exit 0
