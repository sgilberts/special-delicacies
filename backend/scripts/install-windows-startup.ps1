# Special Delicacies — Windows Startup Registration Script for Laravel Reverb
# Creates a Windows Startup Shortcut or Scheduled Task to start Reverb automatically on boot/login.

param (
    [ValidateSet('Shortcut', 'Task')]
    [string]$Method = 'Shortcut'
)

$scriptDir = $PSScriptRoot
$batPath = Join-Path $scriptDir "reverb-service.bat"

Write-Host "==========================================================" -ForegroundColor Cyan
Write-Host " Special Delicacies — Reverb Windows Startup Installer" -ForegroundColor Cyan
Write-Host " Target Service: $batPath" -ForegroundColor Cyan
Write-Host " Method: $Method" -ForegroundColor Cyan
Write-Host "==========================================================" -ForegroundColor Cyan

if (-not (Test-Path $batPath)) {
    Write-Host "[ERROR] Cannot find $batPath" -ForegroundColor Red
    exit 1
}

if ($Method -eq 'Shortcut') {
    # Method 1: User Startup Folder (No admin elevation required)
    $startupFolder = [System.Environment]::GetFolderPath('Startup')
    $shortcutPath = Join-Path $startupFolder "SpecialDelicacies-Reverb.lnk"

    $wscript = New-Object -ComObject WScript.Shell
    $shortcut = $wscript.CreateShortcut($shortcutPath)
    $shortcut.TargetPath = "powershell.exe"
    $shortcut.Arguments = "-ExecutionPolicy Bypass -WindowStyle Hidden -File `"$scriptDir\reverb-daemon.ps1`""
    $shortcut.WorkingDirectory = (Split-Path -Parent $scriptDir)
    $shortcut.Description = "Special Delicacies Laravel Reverb Real-Time Daemon"
    $shortcut.Save()

    Write-Host "[SUCCESS] Startup shortcut created in: $shortcutPath" -ForegroundColor Green
    Write-Host "Reverb daemon will automatically start whenever your Windows user logs in." -ForegroundColor Green
} else {
    # Method 2: Scheduled Task (Runs at system startup)
    $taskName = "SpecialDelicacies_Reverb"
    $action = New-ScheduledTaskAction -Execute "powershell.exe" -Argument "-ExecutionPolicy Bypass -WindowStyle Hidden -File `"$scriptDir\reverb-daemon.ps1`"" -WorkingDirectory (Split-Path -Parent $scriptDir)
    $trigger = New-ScheduledTaskTrigger -AtLogOn
    $settings = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -ExecutionTimeLimit (New-TimeSpan -Days 365) -RestartCount 3 -RestartInterval (New-TimeSpan -Minutes 1)

    try {
        Register-ScheduledTask -TaskName $taskName -Action $action -Trigger $trigger -Settings $settings -Description "Special Delicacies Laravel Reverb Daemon" -Force
        Write-Host "[SUCCESS] Scheduled Task '$taskName' registered successfully." -ForegroundColor Green
    } catch {
        Write-Host "[ERROR] Failed to register scheduled task: $_" -ForegroundColor Red
        Write-Host "Tip: Method 'Shortcut' does not require elevated administrator rights." -ForegroundColor Yellow
    }
}
