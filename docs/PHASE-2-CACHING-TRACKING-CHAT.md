# Phase 2: Authenticated Caching, Tracking and Chat

## Migrations

Run `php artisan migrate` from `backend/`. The additive migrations create private
chat conversation, message, audit, and notification tables and add granular chat
permissions to RBAC. The permission migration attaches those permissions to the
existing Super Admin role; other roles do not gain chat access automatically.
Administrators can grant individual chat permissions from Roles & Permissions.
The `erm_db` connection is not used by this application migration.

Chat attachment files are stored on Laravel's private `local` disk under
`storage/app/private/chat` (Laravel's local disk root). They are served only by
authorized API download routes. The endpoints validate detected MIME types and
apply 5 MB image/voice, 10 MB PDF, and 20 MB video limits.

## API

Visitor endpoints use a random 256-bit token held by the browser and sent as
`X-Visitor-Token`. Only its SHA-256 hash is stored. The channel capability is
derived from that hash; the message and typing channels carry no visitor name,
email, address, or other order/customer data.

- `POST /api/v1/chat/conversations`
- `GET /api/v1/chat/conversations/{publicId}/messages`
- `POST /api/v1/chat/conversations/{publicId}/messages`
- `POST /api/v1/chat/conversations/{publicId}/receipts`
- `POST /api/v1/chat/conversations/{publicId}/typing`
- `GET /api/v1/chat/conversations/{publicId}/messages/{messageId}/attachment`
- Authenticated `/api/v1/admin/chat/*` endpoints provide inbox, assignment,
  replies, delivery/read receipts, close/reopen, audit, tombstoning, and private downloads.

Incoming conversations are assigned to an active `chat.receive` user with a
fresh online heartbeat, an eligible role, and capacity. If no eligible agent
exists, the conversation remains in the unassigned queue and authorized
`chat.view_all` users receive notifications. Assignment uses row locking and
visitor initialization uses a cache lock to avoid duplicate open sessions.
Messages are committed before their events are broadcast. Message deletion
preserves a soft-deleted row and audit entry.

## Broadcasting

Order tracking listens to a public capability channel derived from the
unguessable order reference. Tracking events contain only a status hint; the
client reloads the authoritative status history through the rate-limited API.
Payment verification and order changes both trigger progress refreshes.

Chat uses `chat.visitor.{visitor-token-hash}` for a visitor's capability
channel, `private-chat.conversation.{id}` for assigned agents, and
`private-chat.staff.{userId}` for staff inbox updates. Receipt updates are
broadcast as `chat.receipt.updated` after persisted state changes. Visitor
receipt updates return to the visitor capability channel; staff-authored
messages also notify their author on the private staff channel. Private channel
authorization checks active users, assigned-conversation scope, and chat RBAC.
The visitor channel name is only returned to a holder of the visitor token.

## Frontend performance

Fresh GET responses are held in memory for 5–30 seconds, deduplicated while a
request is in flight, and keyed by access token plus the cached user/permission
profile and request query. Successful writes and existing Reverb order/payment
updates clear the cache. Logout and account changes also clear it. Sidebar
hover prefetches a small set of likely destinations that are already visible to
the authenticated user. Large order and transaction collections remain
paginated. Route-level lazy loading reduces the initial JavaScript entry chunk.

## Chat theme, responsive behavior, and message receipts

Chat components consume the application's existing CSS custom properties for
primary/accent colors, background, surfaces, text, borders, inputs, focus,
success/error, and muted text. They do not persist a chat-specific theme. The
read tick uses a blue semantic status token so it remains recognizable in dark
mode; all other chat colors follow global Light, Dark, or System theme changes.
The public widget expands to a safe-area-aware panel on small screens and uses
the browser Visual Viewport API to keep its header and composer in view as the
mobile keyboard opens or the viewport changes. It uses a bounded floating panel
on larger screens. The staff inbox uses a two-panel desktop layout and switches
between list/detail views on mobile with a back control. Message history scrolls
independently from the header and composer; touch controls and composer padding
account for safe areas.

`sent_at` is the persisted message creation time. New messages start with a
null `delivered_at` and `read_at`. The sender sees one muted check after the
server returns the persisted message. The recipient acknowledges `delivered`
when the message is retrieved in an open client; the API writes `delivered_at`
before emitting `chat.receipt.updated`. The client acknowledges `read` only
when the page is visible and at least 60% of an incoming message is visible in
the conversation scroller. The API persists `read_at` and ensures delivery is
also set. Duplicate acknowledgements are idempotent, timestamps are preserved,
and read cannot downgrade to delivered. A failed send retains its client UUID
so retrying does not create another message.

Receipt endpoints accept up to 100 message IDs and only update opposite-party
messages in the addressed conversation. Visitor requests require the matching
unguessable visitor token; staff requests require existing chat view
permissions and assigned/all-conversation scope. Ordinary history GETs do not
mark anything read. Refresh and reconnect recovery use normal history GETs,
which return persisted sent/delivered/read values; clients also poll while a
conversation is open as a fallback for missed realtime events. No schema change
was required for receipts because existing `delivered_at` and `read_at` fields
are reused.

## Verification

From `backend/`, run `php artisan test`. From `frontend/`, run `npm run build`
and `npm run lint`. Migration rollback coverage includes the additive migrations.

## Final hardening: settings, presence and media

Migration `2026_11_24_000007_add_chat_availability_and_presence.php` adds
chat settings, agent heartbeat records, and a private image thumbnail path on
messages. It is additive and reversible and leaves prior chat records intact.

Super Admins can configure the public widget, pause new conversations, select
eligible roles that already have `chat.receive`, enable automatic assignment,
set a 1–100 active conversation limit per agent, and edit the unavailable
message at `/admin/chat/settings`. Backend validation and permission checks
protect these settings. Existing visitor conversations remain readable when
new chat creation is paused. The fallback is a persistent unassigned queue;
authorized `chat.view_all` staff receive notifications.

Opening the staff inbox heartbeats online presence every 15 seconds. Agents can
choose online, unavailable, or offline. Presence expires after 45 seconds
without a successful heartbeat; account activity alone never makes an agent
available. Logout records offline status. Automatic and manual assignment lock
the candidate presence row and check the active open conversation count before
assignment. When an online agent returns, the oldest waiting conversations are
assigned until capacity is reached. Busy is derived from capacity. Manual
accept/decline is not part of the existing inbox workflow.

Uploads use server-detected MIME types and limits of 5 MB for images and voice,
10 MB for PDF documents, and 20 MB for videos. The image is decoded server-side;
the private stored version is orientation-corrected, metadata-stripped and
re-encoded in its original format, downsampling dimensions above 3000 pixels.
The server generates a JPEG preview no larger than 480 by 480 pixels at quality 76.
Originals and thumbnails have separate authorization-checked endpoints.
Tombstoning deletes both files, clears their storage references, and preserves
the deleted message and audit evidence.

Automated feature coverage exercises settings authorization and role validation,
heartbeat expiry, waiting-queue recovery, capacity, protected thumbnails,
malformed images, size limits, tombstones, and migration rollback/reapplication.
Receipt feature coverage exercises both directions, unauthorized visitor and
staff acknowledgements, GET-without-read behavior, idempotency, monotonic state,
and persisted timestamps. It does not simulate two independent database
connections racing at the same instant. Browser-level Reverb scenarios,
responsive viewport checks, theme switching, microphone recording, order
tracking, and cache isolation remain unverified in this run. The available
Windows Computer Use integration previously reported that local browser URL
policy enforcement is unsupported and stopped before the site could be opened;
mocked or feature-test event dispatch is not evidence of a live Reverb
connection. No browser results are claimed here.

## Additional authorization, assignment and payment hardening

Staff role options now come from the database through the authorized
`/admin/staff/roles` endpoint. Non-Super Admins do not receive the Super Admin
role, and server-side create/update checks reject attempted assignment. They
also cannot modify or deactivate a protected Super Admin account. The role
selector is loaded afresh when its dialog opens, and saving role permissions
refreshes the active user's permission snapshot.

The Reverb monitor has a dedicated `reverb.monitor` permission and private
channel authorization. Its ping action refuses unsupported broadcasters and
reports dispatch failures rather than claiming success when no dispatch
occurred. A dispatched event is not proof that a separate subscriber received
it; no live Reverb receipt is claimed here.

Automatic chat assignment now randomizes among currently eligible online
agents within configured capacity, with the oldest waiting conversations
recovered when capacity returns. New `assigned_at` data makes the current
assignment visible in the staff inbox. Payment verification now repairs an
order that remained pending despite an already-verified successful transaction;
the order's fulfillment status is preserved and a safe payment-status event
refreshes the order list and an open inspector. Repeated failed/cancelled
gateway states no longer rewrite their verification timestamp.

Migrations `2026_11_24_000008_add_reverb_monitor_permission.php` and
`2026_11_24_000009_add_assigned_at_to_chat_conversations.php` are additive and
reversible. Before applying them, the active database was checked as
`special_delicacies_db`; both are now applied. No migration touched `erm_db`.

## Latest payment, queue and conversation-status hardening

Paystack webhook validation supports the current timestamped `Paystack-Signature`
format using the configured signing secret and exact raw request body, including
timestamp tolerance and multiple keyed signatures. The deprecated
`X-Paystack-Signature` form remains supported with the API secret. The signing
secret is stored encrypted and is never returned by the gateway management API.
Kora signatures now use the provider's default JSON encoding of the `data`
object before HMAC-SHA256 comparison. Hubtel verification accepts its documented
null `currencyCode` while rejecting a provided non-GHS currency; transaction
status, reference, and amount are still verified with the provider.

Chat reassignment now validates the configured eligible staff roles and drains
the waiting queue after a reassignment or close. Reopening a conversation
releases an assignee who is no longer eligible or has no remaining capacity,
then considers the waiting queue. Close/reopen also emits an after-commit
conversation status event to authorized staff and the visitor's capability
channel so both clients refresh without a simulated event.

Migration `2026_11_24_000010_add_paystack_webhook_signing_secret.php` adds a
nullable encrypted signing-secret column and is reversible. It was applied to
`special_delicacies_db` after verifying the configured database; the column is
present. No destructive migration ran and `erm_db` was not modified.

## Latest verification

On 24 November 2026, `php artisan test` passed **303 tests / 1,343 assertions**.
This includes bidirectional receipt persistence, unauthorized acknowledgement
rejection, idempotency/monotonicity, role assignment guards, Reverb channel
authorization and payment repair. `npx tsc --noEmit` and `npm run build` passed;
the final Vite entry is 407.59 kB (123.92 kB gzip). `npm run lint` exited 0
with non-fatal React effect-state warnings in admin screens (including staff
loading and Reverb connection-state synchronization) and other routes. A direct
Oxlint run on the customer chat, staff inbox, settings and receipt components
reported no warnings.

The database queue has 9 pending jobs and no failed jobs. No PHP queue worker
was running during inspection. Since chat message, assignment and conversation
status broadcasts use queued events, these events cannot be confirmed delivered
in this runtime until the pending jobs are reviewed and a worker is running.
The TCP port configured for Reverb (8081) accepted a local connection, but the
WebSocket handshake and event receipt were not verified. The browser automation
service timed out before opening the project.

The browser inventory reported that browser access is unavailable in this chat.
Therefore the specified viewport matrix, live Light/Dark/System switching,
mobile keyboard behavior, real customer/staff receipt exchange, attachment
flows, and live Reverb WebSocket receipt remain **implemented but not
browser-verified**. No browser-level result is claimed. Payment provider tests
use HTTP fakes; Paystack, Hubtel and Kora sandbox credentials and live sandbox
flows were not available or exercised. Backend automated tests
verify the persisted receipt state and authorization; the production build
verifies the frontend compiles, but neither substitutes for live two-session or
visual testing. There are no frontend component tests in the current test
commands.

## Queue and live Reverb follow-up

On 24 November 2026, the nine pre-existing `jobs` rows were audited before a
worker was started. All were on `default`, had zero attempts, were immediately
available (not delayed), and had distinct payload fingerprints. Three were
`ChatConversationAssigned` broadcasts and six were `ChatMessageSent`
broadcasts, created between 18:49 and 19:08 UTC. These event jobs only notify
clients; the listeners refresh persisted conversation data, so they do not
repeat payment or order mutations. No queued job payload or customer content is
included here.

One `queue:work database --queue=default --tries=3 --timeout=60 --memory=128`
worker drained all nine successfully. The `jobs` and `failed_jobs` tables both
then contained zero rows, and the worker remained running in its dedicated
session. Database queue retry-after is 90 seconds; `default` is the only
configured queue.

### 25 November 2026 root-cause correction

The earlier `Application does not exist` result was produced by the diagnostic
PowerShell probe, not by a Reverb application-registration defect. The probe
interpolated `$appKey?protocol=...` inside a double-quoted string; PowerShell
treated the question mark as part of the variable reference, so the actual
WebSocket path omitted the configured app key. Reverb correctly rejected that
malformed path. Building the URL by concatenating the key and query string
produced `/app/<configured-key>` and returned `pusher:connection_established`
over TLS on port 8082. The socket remained open and no Pusher error frame was
received. This is a real Pusher protocol-level connection result; it does not
prove a browser Echo subscription or event receipt.

The installed source confirms the cause: `Laravel\Reverb\Protocols\Pusher\Http\Controllers\PusherController::connection()` catches `InvalidApplication` and sends Pusher error code 4001; its lookup calls `findByKey($key)`. The installed
`Laravel\Reverb\ConfigApplicationProvider::findByKey()` searches the
configured application collection by key. The running configuration uses the
static `config` provider, has one configured application, and resolves that
application by key to its configured ID. The frontend and backend keys were
compared without printing either value and match. Laravel configuration is not
cached, so no cache invalidation or credential change was necessary.

The current 8082 listener is PID 22972, launched with ServBay PHP and the
Special Delicacies backend's absolute `artisan reverb:start --debug` command;
its command line matches this project and it owns `0.0.0.0:8082`. PID 4396
continues to own port 8081. Its command and executable path remain inaccessible,
so it was preserved without termination. The project watchdog and controller
were hardened to recognize only a `reverb:start` command containing this
project's absolute Artisan path, and to leave unverified listeners alone.
There is no `backend/storage/logs/reverb.log`; the Laravel log contains older
connection-refused attempts to 8081, not the malformed direct probe. Startup
output confirms the current secure server is bound to 8082. No environment
secrets are included here.

The database queue worker remains running as PID 21560. A current database
check reports zero rows in both `jobs` and `failed_jobs`; no queue data was
cleared, and `erm_db` was not accessed. The installed Reverb authorization
feature tests pass for the monitor permission rules. A headless Node Echo probe
timed out before connecting, and Windows browser automation could not be used
because its URL policy enforcement is unsupported in this environment. Thus
browser Echo, authorized private-channel subscription, the monitor test-button
broadcast and receipt, unauthorized browser rejection, and two-client chat
receipt remain unverified. No event was simulated or inserted into the monitor
stream. The protocol connection is verified; browser acceptance is not.

### 25 November 2026 Echo and runtime acceptance follow-up

The React app has one shared Echo singleton in `frontend/src/lib/echo.ts`,
imported by the chat, order, delivery, financial, and Reverb monitor screens.
Installed versions are Laravel Echo 2.6.3 and pusher-js 8.6.0. The effective
frontend environment selects the Reverb broadcaster and points to
`api.special-delicacies.local` on TLS port 8082; the masked frontend/backend
key comparison matched. Its auth URL resolves to
`https://api.special-delicacies.local/api/v1/broadcasting/auth`, which is
registered with `auth:sanctum`. The frontend sends its existing bearer token
from local storage. Backend broadcasting uses Reverb. No active frontend
configuration points to 8081; the `.env.example` contains a generic 443
example/default only. DNS for the API hostname resolves locally to 127.0.0.1.

The previous headless Echo attempt happened after PID 22972 had exited and
while port 8082 was no longer listening. With the verified Reverb process
restored, the same Echo/pusher-js configuration emitted Pusher `connected`.
That confirms the Echo transport reaches the current server. It does not prove
private-channel authorization. A separate attempt to construct Pusher directly
omitted the `cluster` option and failed at option validation; that was a probe
setup error. Echo's installed Reverb connector supplies the empty cluster
value itself.

An HTTP request to the actual Sanctum broadcast-auth endpoint using a
deliberately invalid bearer value and `Accept: application/json` returned JSON
401 `Unauthenticated.`. The Echo auth headers now explicitly include
`Accept: application/json` alongside the existing bearer-token getter so an
unauthenticated auth request is handled as JSON rather than routed to a web
login redirect. An authorized live private subscription was not tested because
no authenticated browser session was available; the Node invalid-auth channel
probe did not provide a reliable subscription result. The supported Windows
browser automation path remains unavailable here.

The monitor UI now reports the WebSocket transport state separately from the
`private-reverb.monitor` channel authorization state. Transport becomes
connected only on Pusher's connection state event; the private channel becomes
authorized only after Echo's subscription-success callback, and displays
authentication failure separately. This avoids treating an established
WebSocket as proof of private-channel authorization. This is code behavior,
not a live subscription result.

The endpoint route listing confirms Sanctum middleware. Feature tests passed
for active monitor permission rules (authorized Super Admin with explicit
permission; waiter, kitchen, inactive admin and admin without permission
denied) and unauthenticated Reverb endpoint rejection. These are automated
authorization checks, not actual Reverb subscription results. Conversation
channel policies remain private and enforce active-user, assigned-user or
`chat.view_all` checks. No authenticated browser session was available to test
them against a live Reverb subscription. No live monitor-button broadcast or
customer/staff message, delivery receipt, read receipt, assignment or status
event was verified in a browser.

On recheck the previous Reverb and worker processes had exited. With 8082 free
and no existing project worker present, one Reverb process and one default
database queue worker were started; no duplicate worker was present. Reverb
was started using the absolute Special Delicacies Artisan path, now owns
8082, and again returned `pusher:connection_established` to the corrected TLS
probe. PID 4396 was not changed and continues to own 8081. The queue worker is
running; the latest counts remain zero pending and zero failed. No synthetic
test job was inserted because that would not verify browser receipt.

Manual browser acceptance remains: sign in as a Super Admin permitted for
`reverb.monitor`, open Admin → Reverb, wait for both transport `connected` and
channel `authorized`, then use “Send Test Broadcast Ping” and confirm the
event appears only after receipt. Repeat the ping; verify an unauthorized
staff role receives a channel auth failure; then repeat with two independent
customer/staff sessions for chat messages and delivery/read receipts. Record
the actual browser and server timestamps. Until those checks complete, live
Echo and realtime acceptance remain unverified.
