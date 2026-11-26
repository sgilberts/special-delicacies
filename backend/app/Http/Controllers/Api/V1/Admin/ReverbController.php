<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Events\ReverbPing;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class ReverbController extends Controller
{
    protected string $host = '0.0.0.0';
    protected int $port = 8080;

    public function __construct()
    {
        $this->host = (string) config('reverb.servers.reverb.host', '0.0.0.0');
        $this->port = (int) config('reverb.servers.reverb.port', 8080);
    }

    /**
     * Get operational status of Laravel Reverb server.
     */
    public function status(): JsonResponse
    {
        $driver = (string) config('broadcasting.default', 'null');
        $usesHostedBroadcaster = $driver !== 'reverb';
        $connection = (array) config("broadcasting.connections.{$driver}", []);
        $options = (array) ($connection['options'] ?? []);
        $pid = $usesHostedBroadcaster ? null : $this->getListeningPid();
        $isRunning = $usesHostedBroadcaster || $pid !== null || $this->isPortOpen();

        $memory = null;
        $uptime = null;

        if ($pid !== null) {
            $procInfo = $this->getProcessInfo($pid);
            $memory = $procInfo['memory'] ?? null;
            $uptime = $procInfo['uptime'] ?? null;
        }

        return response()->json([
            'success' => true,
            'data' => [
                'is_running' => $isRunning,
                'driver' => $driver,
                'pid' => $pid,
                'host' => $usesHostedBroadcaster ? ($options['host'] ?? 'hosted provider') : $this->host,
                'port' => $usesHostedBroadcaster ? ($options['port'] ?? 443) : $this->port,
                'scheme' => $usesHostedBroadcaster ? ($options['scheme'] ?? 'https') : config('reverb.apps.apps.0.options.scheme', 'http'),
                'app_id' => $usesHostedBroadcaster ? ($connection['app_id'] ?? 'Hosted') : config('reverb.apps.apps.0.app_id'),
                'app_key' => $usesHostedBroadcaster ? ($connection['key'] ?? '') : config('reverb.apps.apps.0.key'),
                'memory' => $memory,
                'uptime' => $uptime,
                'server_time' => now()->toIso8601String(),
            ],
        ]);
    }

    /**
     * Stop the Reverb WebSocket server.
     */
    public function stop(Request $request): JsonResponse
    {
        if (config('broadcasting.default') !== 'reverb') return $this->managedReverbOnly();
        // Guard against any attempt to supply arbitrary process IDs or commands
        if ($request->hasAny(['pid', 'host', 'port', 'cmd', 'command', 'args'])) {
            return response()->json([
                'success' => false,
                'message' => 'Process parameters cannot be supplied via request. Execution targets are strictly bound to system configuration.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $pid = $this->getListeningPid();
        if ($pid === null) {
            if ($this->isPortOpen()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Reverb is responding, but this host cannot identify its process. Stop it through the configured process manager.',
                ], Response::HTTP_SERVICE_UNAVAILABLE);
            }
            return response()->json([
                'success' => true,
                'message' => 'Reverb is not currently running.',
            ]);
        }

        // Validate that this PID is verified as an active PHP process
        if (! $this->isReverbProcess($pid)) {
            return response()->json([
                'success' => false,
                'message' => "Process PID {$pid} on port {$this->port} is not verified as a Reverb/PHP process. Aborting termination for safety.",
            ], Response::HTTP_FORBIDDEN);
        }

        // Stop only the process verified as the PHP Reverb listener.
        if (PHP_OS_FAMILY === 'Windows') {
            exec("taskkill /F /PID {$pid} 2>&1");
        } elseif (function_exists('posix_kill')) {
            @posix_kill($pid, SIGTERM);
        } else {
            exec("kill -TERM {$pid} 2>&1");
        }
        usleep(500000);

        $stillRunning = $this->getListeningPid() !== null || $this->isPortOpen();
        if ($stillRunning) {
            return response()->json([
                'success' => false,
                'message' => "Could not terminate process PID {$pid}.",
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return response()->json([
            'success' => true,
            'message' => "Reverb server (PID {$pid}) stopped successfully.",
        ]);
    }

    /**
     * Restart the Reverb WebSocket server.
     */
    public function restart(Request $request): JsonResponse
    {
        if (config('broadcasting.default') !== 'reverb') return $this->managedReverbOnly();
        // Guard against any attempt to supply arbitrary process IDs or commands
        if ($request->hasAny(['pid', 'host', 'port', 'cmd', 'command', 'args'])) {
            return response()->json([
                'success' => false,
                'message' => 'Process parameters cannot be supplied via request. Execution targets are strictly bound to system configuration.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $pid = $this->getListeningPid();
        if ($pid !== null) {
            if (! $this->isReverbProcess($pid)) {
                return response()->json([
                    'success' => false,
                    'message' => "Process PID {$pid} on port {$this->port} is not verified as a Reverb/PHP process. Aborting termination for safety.",
                ], Response::HTTP_FORBIDDEN);
            }

            if (PHP_OS_FAMILY === 'Windows') {
                exec("taskkill /F /PID {$pid} 2>&1");
            } elseif (function_exists('posix_kill')) {
                @posix_kill($pid, SIGTERM);
            } else {
                exec("kill -TERM {$pid} 2>&1");
            }
            usleep(800000);
        }

        return $this->start($request);
    }

    /**
     * Start the Reverb WebSocket server.
     */
    public function start(Request $request): JsonResponse
    {
        if (config('broadcasting.default') !== 'reverb') return $this->managedReverbOnly();
        // Guard against any attempt to supply arbitrary process IDs or commands
        if ($request->hasAny(['pid', 'host', 'port', 'cmd', 'command', 'args'])) {
            return response()->json([
                'success' => false,
                'message' => 'Process parameters cannot be supplied via request. Execution targets are strictly bound to system configuration.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $existingPid = $this->getListeningPid();
        if ($existingPid === null && $this->isPortOpen()) {
            return response()->json([
                'success' => false,
                'message' => 'The configured port is occupied by a process this application cannot verify. It was left untouched.',
            ], Response::HTTP_CONFLICT);
        }
        if ($existingPid !== null) {
            if (! $this->isReverbProcess($existingPid)) {
                return response()->json([
                    'success' => false,
                    'message' => "The configured port is occupied by unverified PID {$existingPid}. It was left untouched.",
                ], Response::HTTP_CONFLICT);
            }

            return response()->json([
                'success' => true,
                'message' => "Reverb is already running (PID {$existingPid}).",
                'data' => ['pid' => $existingPid],
            ]);
        }

        if (! $this->canExecuteCommands()) {
            return response()->json([
                'success' => false,
                'message' => 'This host does not allow background processes. Start Reverb with a process manager such as Supervisor, or configure a hosted Pusher-compatible broadcaster.',
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $basePath = base_path();
        $artisanPath = base_path('artisan');
        $artisan = escapeshellarg($artisanPath);
        $host = escapeshellarg($this->host);
        $command = "php {$artisan} reverb:start --host={$host} --port={$this->port}";
        if (PHP_OS_FAMILY === 'Windows') {
            $workingDirectory = str_replace("'", "''", $basePath);
            $phpBinary = str_replace("'", "''", PHP_BINARY);
            $argumentList = '"' . str_replace('"', '', $artisanPath) . '" reverb:start --host=' . $this->host . ' --port=' . $this->port;
            $argumentList = str_replace("'", "''", $argumentList);
            $logSuffix = now()->format('Ymd-His').'-'.bin2hex(random_bytes(3));
            $logPath = str_replace("'", "''", storage_path("logs/reverb-{$logSuffix}.log"));
            $errorLogPath = str_replace("'", "''", storage_path("logs/reverb-error-{$logSuffix}.log"));
            $environment = "\$env:DB_CONNECTION = 'sqlite'; \$env:DB_DATABASE = ':memory:'; \$env:DB_URL = ''; \$env:CACHE_STORE = 'array'; \$env:QUEUE_CONNECTION = 'sync'; \$env:SESSION_DRIVER = 'array'; ";
            $startCommand = $environment
                . "Start-Process -FilePath '{$phpBinary}' -ArgumentList '{$argumentList}' -WorkingDirectory '{$workingDirectory}' -WindowStyle Hidden -RedirectStandardOutput '{$logPath}' -RedirectStandardError '{$errorLogPath}'";
            $cmd = "powershell -NoProfile -Command \"{$startCommand}\"";
            pclose(popen($cmd, 'r'));
        } else {
            $logPath = escapeshellarg(storage_path('logs/reverb.log'));
            $runtimeEnvironment = 'DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= CACHE_STORE=array QUEUE_CONNECTION=sync SESSION_DRIVER=array';
            exec("cd " . escapeshellarg($basePath) . " && {$runtimeEnvironment} nohup {$command} >> {$logPath} 2>&1 < /dev/null &");
        }

        // Allow slower local hosts to finish PHP and TLS startup.
        $newPid = null;
        for ($i = 0; $i < 20; $i++) {
            usleep(500000); // 500ms
            $newPid = $this->getListeningPid();
            if ($newPid !== null) {
                break;
            }
        }

        if ($newPid !== null) {
            return response()->json([
                'success' => true,
                'message' => "Reverb server started successfully on port {$this->port} (PID {$newPid}).",
                'data' => ['pid' => $newPid],
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'Reverb did not become ready. Check the latest storage/logs/reverb*.log files and confirm that the host allows a persistent process on the configured port.',
        ], Response::HTTP_INTERNAL_SERVER_ERROR);
    }

    /**
     * Dispatch a real-time test event over Reverb.
     */
    public function testBroadcast(Request $request): JsonResponse
    {
        $driver = (string) config('broadcasting.default', 'null');
        if (! in_array($driver, ['reverb', 'pusher'], true)) {
            return response()->json(['success' => false, 'message' => 'No live WebSocket broadcaster is configured.'], Response::HTTP_SERVICE_UNAVAILABLE);
        }
        $connection = (array) config("broadcasting.connections.{$driver}", []);
        if ($driver === 'pusher' && (! filled($connection['key'] ?? null) || ! filled($connection['secret'] ?? null) || ! filled($connection['app_id'] ?? null))) {
            return response()->json(['success' => false, 'message' => 'The configured hosted broadcaster is missing credentials.'], Response::HTTP_SERVICE_UNAVAILABLE);
        }
        $available = $driver !== 'reverb' || $this->isPortOpen();
        if (! $available) {
            return response()->json([
                'success' => false,
                'message' => 'Reverb server is not running. Please start the server first.',
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $note = strip_tags(trim((string) $request->input('note', 'Admin test ping from Special Delicacies management console')));
        $note = substr($note, 0, 200);

        try {
            event(new ReverbPing(['note' => $note]));
        } catch (\Throwable $error) {
            Log::warning('Reverb monitor ping could not be broadcast.', ['exception' => $error::class]);
            return response()->json(['success' => false, 'message' => 'The live broadcaster rejected the test event. Check its connection and credentials.'], Response::HTTP_BAD_GATEWAY);
        }

        return response()->json([
            'success' => true,
            'message' => "Test broadcast event dispatched successfully over {$driver} channel [reverb.monitor].",
            'data' => [
                'driver' => $driver,
                'channel' => 'reverb.monitor',
                'event' => 'reverb.ping',
                'dispatched_at' => now()->toIso8601String(),
            ],
        ]);
    }

    protected function managedReverbOnly(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'The configured broadcaster is hosted and cannot be controlled by this server. Manage its service in the provider dashboard.',
        ], Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    /**
     * Detect PID listening on the configured Reverb port.
     */
    protected function getListeningPid(): ?int
    {
        $output = [];
        if (! $this->canExecuteCommands()) return null;

        if (PHP_OS_FAMILY === 'Windows') {
            exec("netstat -ano | findstr :{$this->port}", $output);
            foreach ($output as $line) {
                $trimmed = trim((string) $line);
                if (str_contains($trimmed, 'LISTENING')) {
                    $parts = preg_split('/\s+/', $trimmed);
                    $pid = (int) end($parts);
                    if ($pid > 0) return $pid;
                }
            }
            return null;
        }

        exec("lsof -ti tcp:{$this->port} -sTCP:LISTEN 2>/dev/null | head -n 1", $output);
        if (isset($output[0]) && ctype_digit(trim($output[0]))) return (int) trim($output[0]);
        $output = [];
        exec("ss -lptn 'sport = :{$this->port}' 2>/dev/null", $output);
        foreach ($output as $line) {
            if (preg_match('/pid=(\d+)/', $line, $matches)) return (int) $matches[1];
        }
        return null;
    }

    protected function canExecuteCommands(): bool
    {
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        return function_exists('exec') && ! in_array('exec', $disabled, true);
    }

    protected function isPortOpen(): bool
    {
        $listenHost = (string) config('reverb.servers.reverb.host', '127.0.0.1');
        if (in_array($listenHost, ['0.0.0.0', '::'], true)) $listenHost = '127.0.0.1';
        $socket = @fsockopen($listenHost, $this->port, $errorCode, $errorMessage, 0.5);
        if (! $socket) return false;
        fclose($socket);
        return true;
    }

    /**
     * Verify that the specified PID is a valid PHP process (and never a system process).
     */
    protected function isReverbProcess(int $pid): bool
    {
        if ($pid <= 4) {
            return false;
        }

        if (PHP_OS_FAMILY === 'Windows') {
            $nameOutput = [];
            $output = [];
            exec("powershell -NoProfile -Command \"(Get-Process -Id {$pid} -ErrorAction SilentlyContinue).ProcessName\"", $nameOutput);
            $name = strtolower(trim(implode('', $nameOutput)));
            if (! in_array($name, ['php', 'php.exe'], true)) return false;

            exec("powershell -NoProfile -Command \"(Get-CimInstance Win32_Process -Filter 'ProcessId = {$pid}').CommandLine\"", $output);
            return $this->isManagedReverbCommand(implode(' ', $output));
        }

        $output = [];
        exec("ps -p {$pid} -o args= 2>/dev/null", $output);
        return $this->isManagedReverbCommand(implode(' ', $output));
    }

    /**
     * Verify both the Reverb command and this project's Artisan entry point.
     */
    protected function isManagedReverbCommand(string $commandLine): bool
    {
        $normalizedCommand = strtolower(str_replace('\\', '/', $commandLine));
        $expectedArtisan = strtolower(str_replace('\\', '/', base_path('artisan')));

        return str_contains($normalizedCommand, $expectedArtisan)
            && preg_match('/(?:^|\s)reverb:start(?:\s|$)/', $normalizedCommand) === 1;
    }

    /**
     * Get process memory and start time information on Windows.
     */
    protected function getProcessInfo(int $pid): array
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            $output = [];
            exec("ps -p {$pid} -o rss=,lstart= 2>/dev/null", $output);
            $line = trim(implode(' ', $output));
            if (preg_match('/^(\d+)\s+(.*)$/', $line, $matches)) {
                return ['memory' => round(((int) $matches[1]) / 1024, 1) . ' MB', 'uptime' => trim($matches[2])];
            }
            return [];
        }

        $output = [];
        exec("powershell -NoProfile -Command \"Get-Process -Id {$pid} -ErrorAction SilentlyContinue | Select-Object -Property WorkingSet64, StartTime | ConvertTo-Json\"", $output);

        $json = implode('', $output);
        $data = json_decode($json, true);

        if (! is_array($data)) {
            return [];
        }

        $memMb = isset($data['WorkingSet64']) ? round($data['WorkingSet64'] / 1024 / 1024, 1) . ' MB' : null;
        $startTime = $data['StartTime'] ?? null;

        // Windows PowerShell serializes DateTime values as /Date(milliseconds)/,
        // which JavaScript's Date constructor does not parse.
        if (is_string($startTime) && preg_match('~^/Date\((-?\d+)(?:[+-]\d{4})?\)/$~', $startTime, $matches)) {
            $startTime = gmdate(DATE_ATOM, intdiv((int) $matches[1], 1000));
        }

        return [
            'memory' => $memMb,
            'uptime' => $startTime,
        ];
    }
}
