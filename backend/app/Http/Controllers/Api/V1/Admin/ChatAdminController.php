<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Events\ChatTyping;
use App\Http\Controllers\Controller;
use App\Models\ChatConversation;
use App\Models\ChatAgentPresence;
use App\Models\ChatMessage;
use App\Models\ChatSetting;
use App\Models\Role;
use App\Models\User;
use App\Services\ChatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ChatAdminController extends Controller
{
    public function __construct(private ChatService $chat) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->hasPermission('chat.view_assigned') || $user->hasPermission('chat.view_all'), 403);
        $query = ChatConversation::query()->with(['assignee:id,name'])->withCount([
            'messages as unread_count' => fn ($messages) => $messages->where('author_kind', 'visitor')->whereNull('read_at'),
        ]);
        if (! $user->hasPermission('chat.view_all')) $query->where('assigned_to', $user->id);
        if ($request->filled('status') && in_array($request->query('status'), ['open', 'closed'], true)) $query->where('status', $request->query('status'));
        if ($request->filled('search')) {
            $needle = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $request->string('search')->trim()->toString()).'%';
            $query->where(fn ($q) => $q->where('visitor_name', 'like', $needle)->orWhere('visitor_email', 'like', $needle));
        }
        $conversations = $query->orderByDesc('last_message_at')->orderByDesc('id')->paginate(min(50, max(1, (int) $request->query('per_page', 20))));
        return response()->json(['success' => true, 'data' => $conversations->items(), 'meta' => [
            'current_page' => $conversations->currentPage(), 'last_page' => $conversations->lastPage(), 'per_page' => $conversations->perPage(), 'total' => $conversations->total(),
        ]]);
    }

    public function show(int $id, Request $request): JsonResponse
    {
        $conversation = $this->chat->accessibleConversation((string) $id, $request->user(), 'chat.view_assigned');
        $messages = $conversation->messages()->withTrashed()->latest('id')->limit(200)->get()->reverse()->values();
        return response()->json(['success' => true, 'data' => ['conversation' => $conversation->load('assignee:id,name'), 'messages' => $messages->map(fn ($message) => $this->messageData($message))]]);
    }

    public function acknowledge(int $id, Request $request): JsonResponse
    {
        $conversation = $this->chat->accessibleConversation((string) $id, $request->user(), 'chat.view_assigned');
        $data = $request->validate([
            'message_ids' => ['required', 'array', 'min:1', 'max:100'],
            'message_ids.*' => ['required', 'integer', 'distinct'],
            'status' => ['required', 'in:delivered,read'],
        ]);
        $updated = $this->chat->acknowledgeReceipts($conversation, 'staff', $data['message_ids'], $data['status']);
        return response()->json(['success' => true, 'data' => ['updated_message_ids' => $updated]]);
    }

    public function send(int $id, Request $request): JsonResponse
    {
        $user = $request->user();
        $conversation = $this->chat->accessibleConversation((string) $id, $user, 'chat.reply');
        $data = $request->validate([
            'body' => ['nullable', 'string', 'max:5000'],
            'client_message_id' => ['nullable', 'uuid'],
            'attachment' => ['nullable', 'file', 'max:20480', 'mimetypes:image/jpeg,image/png,image/webp,image/gif,video/mp4,video/webm,video/quicktime,application/pdf,audio/webm,audio/ogg,audio/wav,audio/x-wav,audio/mpeg,audio/mp4'],
        ]);
        $file = $request->file('attachment');
        if ($file) {
            $mime = $file->getMimeType();
            $limit = str_starts_with((string) $mime, 'image/') || str_starts_with((string) $mime, 'audio/') ? 5 * 1024 * 1024
                : ($mime === 'application/pdf' ? 10 * 1024 * 1024 : 20 * 1024 * 1024);
            if (($file->getSize() ?? 0) > $limit) abort(422, 'This attachment exceeds its allowed size.');
        }
        $message = $this->chat->send($conversation, 'staff', $user, $data['body'] ?? null, $file, $data['client_message_id'] ?? null);
        return response()->json(['success' => true, 'data' => $this->messageData($message)], 201);
    }

    public function typing(int $id, Request $request): JsonResponse
    {
        $conversation = $this->chat->accessibleConversation((string) $id, $request->user(), 'chat.reply');
        event(new ChatTyping($conversation, 'staff'));
        return response()->json(['success' => true]);
    }

    public function assign(int $id, Request $request): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor->hasPermission('chat.view_all'), 403);
        $conversation = ChatConversation::findOrFail($id);
        abort_unless($actor->hasPermission($conversation->assigned_to ? 'chat.reassign' : 'chat.assign'), 403);
        $data = $request->validate(['user_id' => ['required', 'integer', 'exists:users,id']]);
        $this->chat->reassign($conversation, User::findOrFail($data['user_id']), $actor);
        return response()->json(['success' => true]);
    }

    public function eligibleStaff(Request $request): JsonResponse
    {
        $staff = $this->chat->eligibleAgentsQuery()->select(['users.id', 'users.name'])->orderBy('users.name')->get()
            ->map(fn (User $agent) => ['id' => $agent->id, 'name' => $agent->name, 'status' => $this->chat->presenceState($agent)]);
        return response()->json(['success' => true, 'data' => $staff]);
    }

    public function heartbeat(Request $request): JsonResponse
    {
        $data = $request->validate(['status' => ['sometimes', 'in:online,unavailable,offline']]);
        $this->chat->heartbeat($request->user(), $data['status'] ?? 'online');
        return response()->json(['success' => true, 'data' => ['status' => $this->chat->presenceState($request->user())]]);
    }

    public function settings(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('chat.manage_settings'), 403);
        $settings = ChatSetting::current();
        return response()->json(['success' => true, 'data' => [
            'widget_enabled' => $settings->widget_enabled,
            'accepting_conversations' => $settings->accepting_conversations,
            'eligible_role_ids' => $settings->eligible_role_ids ?? [],
            'automatic_assignment' => $settings->automatic_assignment,
            'max_active_per_agent' => $settings->max_active_per_agent,
            'unavailable_message' => $settings->unavailable_message,
            'fallback_behavior' => $settings->fallback_behavior,
            'roles' => Role::whereHas('permissions', fn ($query) => $query->where('permissions.slug', 'chat.receive'))->orderBy('name')->get(['id', 'name']),
        ]]);
    }

    public function updateSettings(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('chat.manage_settings'), 403);
        $data = $request->validate([
            'widget_enabled' => ['required', 'boolean'],
            'accepting_conversations' => ['required', 'boolean'],
            'eligible_role_ids' => ['present', 'array'],
            'eligible_role_ids.*' => ['integer', 'distinct', 'exists:roles,id'],
            'automatic_assignment' => ['required', 'boolean'],
            'max_active_per_agent' => ['required', 'integer', 'between:1,100'],
            'unavailable_message' => ['required', 'string', 'max:500'],
            'fallback_behavior' => ['required', 'in:queue'],
        ]);
        $permittedRoleIds = Role::whereHas('permissions', fn ($query) => $query->where('permissions.slug', 'chat.receive'))->whereIn('id', $data['eligible_role_ids'])->pluck('id')->all();
        abort_unless(count($permittedRoleIds) === count($data['eligible_role_ids']), 422, 'Only roles with chat receive permission may be selected.');
        ChatSetting::current()->fill($data)->save();
        if ($data['automatic_assignment'] && $data['accepting_conversations']) $this->chat->drainWaitingQueue();
        return response()->json(['success' => true, 'message' => 'Chat settings saved.']);
    }

    public function audit(int $id, Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->hasPermission('chat.view_all'), 403);
        $conversation = ChatConversation::with('auditLogs.actor:id,name')->findOrFail($id);
        $logs = $conversation->auditLogs()->with('actor:id,name')->orderByDesc('created_at')->limit(200)->get();
        return response()->json(['success' => true, 'data' => $logs]);
    }

    public function close(int $id, Request $request): JsonResponse
    {
        $user = $request->user();
        $conversation = $this->chat->accessibleConversation((string) $id, $user, 'chat.close');
        $this->chat->setClosed($conversation, true, $user);
        return response()->json(['success' => true]);
    }

    public function reopen(int $id, Request $request): JsonResponse
    {
        $user = $request->user();
        $conversation = $this->chat->accessibleConversation((string) $id, $user, 'chat.close');
        $this->chat->setClosed($conversation, false, $user);
        return response()->json(['success' => true]);
    }

    public function destroyMessage(int $messageId, Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->hasPermission('chat.delete_message'), 403);
        $message = ChatMessage::withTrashed()->findOrFail($messageId);
        $conversation = $this->chat->accessibleConversation((string) $message->conversation_id, $user, 'chat.delete_message');
        $this->chat->tombstone($message, $user);
        return response()->json(['success' => true, 'conversation_id' => $conversation->id]);
    }

    public function attachment(int $messageId, Request $request)
    {
        $user = $request->user();
        abort_unless($user->hasPermission('chat.view_assigned') || $user->hasPermission('chat.view_all'), 403);
        $message = ChatMessage::withTrashed()->findOrFail($messageId);
        $this->chat->accessibleConversation((string) $message->conversation_id, $user, 'chat.view_assigned');
        abort_if($message->trashed() || ! $message->attachment_path || ! Storage::disk('local')->exists($message->attachment_path), 404);
        return Storage::disk('local')->download($message->attachment_path, $message->attachment_name, ['X-Content-Type-Options' => 'nosniff']);
    }

    public function thumbnail(int $messageId, Request $request)
    {
        $user = $request->user();
        abort_unless($user->hasPermission('chat.view_assigned') || $user->hasPermission('chat.view_all'), 403);
        $message = ChatMessage::findOrFail($messageId);
        $this->chat->accessibleConversation((string) $message->conversation_id, $user, 'chat.view_assigned');
        abort_if(! $message->thumbnail_path || ! Storage::disk('local')->exists($message->thumbnail_path), 404);
        return response()->file(Storage::disk('local')->path($message->thumbnail_path), ['Content-Type' => 'image/jpeg', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }

    private function messageData(ChatMessage $message): array
    {
        return [
            'id' => $message->id, 'client_message_id' => $message->client_message_id,
            'author_kind' => $message->author_kind, 'author_name' => $message->author?->name,
            'message_type' => $message->message_type, 'body' => $message->trashed() ? null : $message->body,
            'deleted' => $message->trashed(), 'attachment_name' => $message->trashed() ? null : $message->attachment_name,
            'attachment_url' => $message->attachment_path && ! $message->trashed() ? route('chat.staff.attachment', ['messageId' => $message->id]) : null,
            'thumbnail_url' => $message->thumbnail_path && ! $message->trashed() ? route('chat.staff.thumbnail', ['messageId' => $message->id]) : null,
            'created_at' => $message->created_at, 'sent_at' => $message->created_at, 'delivered_at' => $message->delivered_at, 'read_at' => $message->read_at,
        ];
    }
}
