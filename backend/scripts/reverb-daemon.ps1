# Special Delicacies — Laravel Reverb Persistent Daemon Monitor
# Keeps Reverb alive under Windows / ServBay and restarts it if it crashes.

$backendDir = Split-Path -Parent $PSScriptRoot
$artisanPath = Join-Path $backendDir "artisan"
$hostAddress = "0.0.0.0"
$port = 8080
$envFile = Join-Path $backendDir ".env"
if (Test-Path -LiteralPath $envFile) {
    foreach ($line in Get-Content -LiteralPath $envFile) {
        if ($line -match '^\s*REVERB_SERVER_HOST\s*=\s*(.+?)\s*$') {
            $hostAddress = $Matches[1].Trim().Trim('"').Trim("'")
        } elseif ($line -match '^\s*REVERB_SERVER_PORT\s*=\s*(\d+)\s*$') {
            $port = [int]$Matches[1]
        }
    }
}

# Acquire Named Mutex to guarantee only ONE monitor daemon runs concurrently
$mutexName = "Global\SpecialDelicacies_ReverbDaemon_Mutex"
$createdNew = $false
try {
    $mutex = New-Object System.Threading.Mutex($true, $mutexName, [ref]$createdNew)
} catch {
    Write-Host "[WARNING] Could not create global mutex, falling back to local mutex." -ForegroundColor Yellow
    $mutex = New-Object System.Threading.Mutex($true, "SpecialDelicacies_ReverbDaemon_Mutex", [ref]$createdNew)
}

if (-not $createdNew) {
    Write-Host "[WARNING] Another instance of the Reverb daemon monitor is already active. Exiting to prevent duplicates." -ForegroundColor Yellow
    exit 0
}

Write-Host "==========================================================" -ForegroundColor Cyan
Write-Host " Special Delicacies — Laravel Reverb Daemon Service" -ForegroundColor Cyan
Write-Host " Host: $hostAddress | Port: $port" -ForegroundColor Cyan
Write-Host " Working Directory: $backendDir" -ForegroundColor Cyan
Write-Host " Status: Single-instance lock acquired." -ForegroundColor Green
Write-Host "==========================================================" -ForegroundColor Cyan

Set-Location $backendDir

function Get-ReverbPids {
    $connections = Get-NetTCPConnection -LocalPort $port -State Listen -ErrorAction SilentlyContinue
    if ($connections) {
        return ($connections | Select-Object -ExpandProperty OwningProcess -Unique)
    }
    return @()
}

function Test-SpecialDelicaciesReverbProcess {
    param([int]$ProcessId)

    $process = Get-CimInstance Win32_Process -Filter "ProcessId = $ProcessId" -ErrorAction SilentlyContinue
    if (-not $process -or $process.Name -notin @("php.exe", "php" ) -or -not $process.CommandLine) {
        return $false
    }

    $commandLine = $process.CommandLine.Replace("/", "\").ToLowerInvariant()
    $expectedArtisan = $artisanPath.Replace("/", "\").ToLowerInvariant()

    return $commandLine.Contains($expectedArtisan) -and $commandLine -match "(?:^|\s)reverb:start(?:\s|$)"
}

try {
    while ($true) {
        $pids = Get-ReverbPids

        # Never stop an unknown process just because it owns the configured port.
        $unverified = @($pids | Where-Object { -not (Test-SpecialDelicaciesReverbProcess -ProcessId $_) })
        if ($unverified.Count -gt 0) {
            Write-Host "[$(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')] Port $port is occupied by unverified process(es) ($($unverified -join ', ')); leaving them untouched." -ForegroundColor Red
            Start-Sleep -Seconds 5
            continue
        }

        # If duplicate project-owned Reverb processes exist, keep one and stop
        # only the remaining processes already verified against this backend.
        if ($pids.Count -gt 1) {
            Write-Host "[$(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')] Multiple processes listening on port $port ($($pids -join ', ')). Consolidating..." -ForegroundColor Yellow
            $orderedPids = @($pids | Sort-Object {[int]$_})
            $keep = $orderedPids[0]
            for ($i = 1; $i -lt $orderedPids.Count; $i++) {
                Stop-Process -Id $orderedPids[$i] -Force -ErrorAction SilentlyContinue
            }
            $pids = @($keep)
        }

        if ($pids.Count -eq 0) {
            Write-Host "[$(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')] Port $port is not listening. Starting Reverb..." -ForegroundColor Yellow
            
            $proc = Start-Process -FilePath "php" `
                -ArgumentList "`"$artisanPath`" reverb:start --host=$hostAddress --port=$port" `
                -WorkingDirectory $backendDir `
                -WindowStyle Hidden `
                -PassThru

            Start-Sleep -Seconds 3

            $newPids = Get-ReverbPids
            if ($newPids.Count -gt 0) {
                Write-Host "[$(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')] Reverb is RUNNING (PID: $($newPids -join ', '))" -ForegroundColor Green
            } else {
                Write-Host "[$(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')] WARNING: Reverb failed to start or port not ready." -ForegroundColor Red
            }
        }

        Start-Sleep -Seconds 5
    }
} finally {
    if ($mutex) {
        $mutex.ReleaseMutex()
        $mutex.Dispose()
    }
}
