# QR ordering and real-time deployment

## QR ordering URLs

New table QR codes use the canonical frontend URL `/table/?token=<token>` (for example, `https://special-delicacies.local/table/?token=...`). The token is resolved by the public table page; the menu and ordering form load from the API. The frontend must be deployed at the host configured by backend `FRONTEND_URL`. Reprint QR codes after changing that origin. Rebuild the frontend after changing its `.env` values.

For Nginx, use this location inside the frontend site's server block:

```nginx
location / {
    try_files $uri $uri/ /index.html;
}
```


## Reverb on a local server

Configure the backend with `BROADCAST_CONNECTION=reverb`, matching `REVERB_APP_ID`, `REVERB_APP_KEY`, and `REVERB_APP_SECRET` values, and set `REVERB_SERVER_HOST=0.0.0.0` with an available `REVERB_SERVER_PORT` (8080 by default). Point `REVERB_HOST` and frontend `VITE_REVERB_HOST` at a hostname that resolves to this server. Set the frontend `VITE_REVERB_APP_KEY` to the same key. For an HTTPS frontend, Reverb must also use TLS or sit behind a TLS reverse proxy; configure `REVERB_SERVER_TLS_CERT` and `REVERB_SERVER_TLS_KEY` for direct TLS, then set `REVERB_SCHEME=https`, frontend `VITE_REVERB_SCHEME=https`, and matching ports. Use `VITE_REVERB_SCHEME=http` only when the page itself is served over HTTP. Rebuild the frontend and start Reverb from the Admin Reverb page or run it with process-local runtime stores:

```powershell
$env:DB_CONNECTION = 'sqlite'
$env:DB_DATABASE = ':memory:'
$env:DB_URL = ''
$env:CACHE_STORE = 'array'
$env:QUEUE_CONNECTION = 'sync'
$env:SESSION_DRIVER = 'array'
php artisan reverb:start --host=0.0.0.0 --port=8080
```

These overrides apply only to the Reverb process and keep it independent from database-backed cache, queue, and session settings used by the web application. The Admin Reverb start endpoint applies equivalent process-local overrides automatically. On Linux, prefix the same variables on the `php artisan reverb:start` command.

The listener port must be reachable from staff and customer devices. The Admin Reverb controls detect and start/stop the listener on Windows and Linux. The Windows watchdog reads its host and port from `.env`. On Linux, keep it alive with a process manager such as Supervisor in production; shared hosting providers often disable shell execution or terminate background processes.

## Shared hosting

For a VPS or hosting plan that permits long-running processes, bind Reverb to localhost and proxy a public TLS WebSocket hostname to it. Set `REVERB_HOST` to the public WebSocket hostname, `REVERB_PORT=443`, and `REVERB_SCHEME=https`; set the frontend's matching `VITE_REVERB_HOST`, port `443`, and scheme `https`. Bind the Reverb process to `127.0.0.1:8080` behind the proxy and keep Laravel's `REVERB_HOST` values consistent with the published Reverb app configuration.

For shared hosting that cannot keep Reverb running or proxy WebSockets, use a hosted Pusher-compatible broadcaster: set backend `BROADCAST_CONNECTION=pusher` and its `PUSHER_APP_*` credentials, then set frontend `VITE_BROADCAST_DRIVER=pusher`, `VITE_PUSHER_APP_KEY`, and `VITE_PUSHER_APP_CLUSTER`. The same private channel authorization endpoint remains on the Laravel API. A host that blocks both WebSockets and hosted broadcast connections cannot provide live broadcasts; use a WebSocket-enabled host or provider.

After changing Laravel environment settings, run `php artisan config:clear` (or rebuild the config cache). After changing frontend settings, run `npm run build` and redeploy `frontend/dist`.

## Test broadcast and monitor troubleshooting

1. Confirm the backend uses `BROADCAST_CONNECTION=reverb` (or a supported hosted Pusher-compatible connection) and that backend credentials match the frontend `VITE_REVERB_*`/`VITE_PUSHER_*` values.
2. Start the Reverb process, or confirm the hosted broadcast provider is connected. On production, use a process manager for a Reverb process rather than relying on an admin HTTP request to keep it alive.
3. Sign in and open **Admin → Reverb**. Wait for the client status to show connected to the `private-reverb.monitor` channel.
4. Select **Send Test Broadcast Ping**. A successful API response confirms event dispatch; confirm that the event appears in the Live Broadcast Event Stream and client echo state records the ping.
5. If the client stays unavailable, check browser console/network WebSocket errors, `/api/broadcasting/auth` authorization responses, public DNS/TLS/proxy configuration, and that the browser build has the same app key/host/port/scheme as Laravel. Rebuild and redeploy the frontend after changing any `VITE_*` setting.

The monitor is private and requires an authenticated admin session. A backend can dispatch successfully while the browser cannot subscribe if the WebSocket hostname, TLS, credentials, or private-channel authorization is incorrect.
