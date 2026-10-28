<#
.SYNOPSIS
    Daftarkan Windows Task Scheduler untuk import VA harian + watchdog (Senin–Sabtu).

.DESCRIPTION
    Membuat dua task:

      1. "M2S VA Daily Import"    - 09:30, 13:00, 17:00 -> php va_daily.php
         (booking otomatis + email laporan ke penerima di va_daily.config.php)

      2. "M2S VA Daily Watchdog"  - 09:50, 13:20, 17:20 -> php va_daily_watchdog.php
         (memeriksa run yang gagal/terhenti, catch-up otomatis, email + Telegram)

    Keduanya berjalan sebagai user yang sedang login, dengan StartWhenAvailable agar
    run yang terlewat (mesin mati) langsung dikejar saat mesin hidup lagi.

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File setup_va_daily_task.ps1

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File setup_va_daily_task.ps1 -PhpPath "C:\laragon\bin\php\php-8.3.31-nts-Win32-vs16-x64\php.exe"

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File setup_va_daily_task.ps1 -SkipWatchdog
#>
[CmdletBinding()]
param(
    [string]$PhpPath = '',
    [string]$ScriptPath = '',
    [string]$TaskName = 'M2S VA Daily Import',
    [string[]]$Times = @('09:30', '13:00', '17:00'),
    [string]$WatchdogScriptPath = '',
    [string]$WatchdogTaskName = 'M2S VA Daily Watchdog',
    [string[]]$WatchdogTimes = @('09:50', '13:20', '17:20'),
    [switch]$SkipWatchdog
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

if (-not $SkipWatchdog) {
    if (-not $WatchdogScriptPath) { $WatchdogScriptPath = Join-Path $PSScriptRoot 'va_daily_watchdog.php' }
    if (-not (Test-Path -LiteralPath $WatchdogScriptPath)) {
        Fail "script watchdog tidak ditemukan: $WatchdogScriptPath"
    }
    $WatchdogScriptPath = (Resolve-Path -LiteralPath $WatchdogScriptPath).Path
}

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
if (-not $SkipWatchdog) { Write-Host "Watchdog: $WatchdogScriptPath" }
Write-Host "Workdir: $PSScriptRoot"

$days = @('Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday')

# Berjalan hanya saat user login (sesuai keputusan: tanpa menyimpan password).
$principal = New-ScheduledTaskPrincipal -UserId "$env:USERDOMAIN\$env:USERNAME" -LogonType Interactive -RunLevel Limited

function Register-VaTask {
    param(
        [string]$Name,
        [string]$Script,
        [string[]]$Schedule,
        [string]$Description
    )

    $action = New-ScheduledTaskAction -Execute $PhpPath -Argument "`"$Script`"" -WorkingDirectory $PSScriptRoot
    $triggers = @($Schedule | ForEach-Object {
        New-ScheduledTaskTrigger -Weekly -DaysOfWeek $days -At $_
    })

    $settings = New-ScheduledTaskSettingsSet `
        -StartWhenAvailable `
        -MultipleInstances IgnoreNew `
        -ExecutionTimeLimit (New-TimeSpan -Hours 2) `
        -AllowStartIfOnBatteries `
        -DontStopIfGoingOnBatteries

    try {
        Register-ScheduledTask -TaskName $Name -Action $action -Trigger $triggers -Settings $settings `
            -Principal $principal -Description $Description -Force | Out-Null
    } catch {
        $msg = $_.Exception.Message
        if ($msg -match 'Access is denied|0x80070005|Akses ditolak') {
            Write-Host ''
            Write-Host "Akses ditolak saat mendaftarkan task '$Name'." -ForegroundColor Yellow
            Write-Host 'Jalankan PowerShell sebagai Administrator, lalu ulangi:' -ForegroundColor Yellow
            Write-Host '  powershell -ExecutionPolicy Bypass -File setup_va_daily_task.ps1' -ForegroundColor Yellow
            exit 1
        }
        Fail $msg
    }

    $task = Get-ScheduledTask -TaskName $Name
    Write-Host ''
    Write-Host "Task '$Name' terdaftar." -ForegroundColor Green
    $task | Format-List TaskName, State, Actions, Settings
    Write-Host 'Trigger:'
    $task.Triggers | ForEach-Object {
        '{0}  {1}' -f ($_.DaysOfWeek -join ','), $_.StartBoundary
    }

    $count = @($task.Triggers).Count
    if ($count -ne $Schedule.Count) {
        Write-Host "PERINGATAN: task '$Name' punya $count trigger, seharusnya $($Schedule.Count)." -ForegroundColor Yellow
        return $false
    }
    return $true
}

# --- task 1: import -------------------------------------------------------
if (-not (Register-VaTask -Name $TaskName -Script $ScriptPath -Schedule $Times `
        -Description 'Import VA harian M2S: booking otomatis + email laporan (Senin-Sabtu).')) {
    exit 1
}

# --- task 2: watchdog -----------------------------------------------------
if (-not $SkipWatchdog) {
    if (-not (Register-VaTask -Name $WatchdogTaskName -Script $WatchdogScriptPath -Schedule $WatchdogTimes `
            -Description 'Watchdog import VA harian M2S: deteksi run gagal/terhenti, catch-up otomatis, email + Telegram (Senin-Sabtu).')) {
        exit 1
    }
}

Write-Host ''
Write-Host 'Selesai. Uji tanpa membukukan pembayaran:' -ForegroundColor Green
Write-Host ("  Start-ScheduledTask -TaskName '{0}'" -f $TaskName)
if (-not $SkipWatchdog) {
    Write-Host ("  Start-ScheduledTask -TaskName '{0}'" -f $WatchdogTaskName)
}
Write-Host 'Log import  : data\va_daily\logs\<yyyy-mm-dd>.log'
if (-not $SkipWatchdog) { Write-Host 'Log watchdog: data\va_daily\logs\watchdog-<yyyy-mm-dd>.log' }
exit 0
