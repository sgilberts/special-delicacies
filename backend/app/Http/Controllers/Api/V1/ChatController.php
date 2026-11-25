<?php

namespace App\Http\Controllers\Api\V1;

use App\Events\ChatTyping;
use App\Http\Controllers\Controller;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\ChatSetting;
use App\Services\ChatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class ChatController extends Controller
{
    public function __construct(private ChatService $chat) {}

    public function start(Request $request): JsonResponse
    {
        $data = $request->validate([
            'visitor_token' => ['required', 'regex:/^[a-f0-9]{64}$/i'],
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'email', 'max:254'],
        ]);
        $conversation = $this->chat->start($data['visitor_token'], $data['name'], $data['email']);
        return response()->json(['success' => true, 'data' => $this->conversationData($conversation)], 201);
    }

    public function availability(): JsonResponse
    {
        $settings = ChatSetting::current();
        return response()->json(['success' => true, 'data' => [
            'enabled' => $settings->widget_enabled,
            'accepting' => $settings->accepting_conversations,
            'message' => $settings->unavailable_message,
        ]]);
    }

    public function messages(string $publicId, Request $request): JsonResponse
    {
        $conversation = $this->visitorConversation($publicId, $request);
        $messages = $conversation->messages()->withTrashed()->latest('id')->limit(200)->get()->reverse()->values();
        return response()->json(['success' => true, 'data' => [
            'conversation' => $this->conversationData($conversation),
            'messages' => $messages->map(fn (ChatMessage $message) => $this->messageData($message, $publicId)),
        ]]);
    }

    public function acknowledge(string $publicId, Request $request): JsonResponse
    {
        $conversation = $this->visitorConversation($publicId, $request);
        $data = $request->validate([
            'message_ids' => ['required', 'array', 'min:1', 'max:100'],
            'message_ids.*' => ['required', 'integer', 'distinct'],
            'status' => ['required', 'in:delivered,read'],
        ]);
        $updated = $this->chat->acknowledgeReceipts($conversation, 'visitor', $data['message_ids'], $data['status']);
        return response()->json(['success' => true, 'data' => ['updated_message_ids' => $updated]]);
    }

    public function send(string $publicId, Request $request): JsonResponse
    {
        $conversation = $this->visitorConversation($publicId, $request);
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
            if (($file->getSize() ?? 0) > $limit) {
                throw ValidationException::withMessages(['attachment' => 'This attachment exceeds its allowed size.']);
            }
        }
        $message = $this->chat->send($conversation, 'visitor', null, $data['body'] ?? null, $file, $data['client_message_id'] ?? null);
        return response()->json(['success' => true, 'data' => $this->messageData($message, $publicId)], 201);
    }

    public function typing(string $publicId, Request $request): JsonResponse
    {
        $conversation = $this->visitorConversation($publicId, $request);
        abort_unless($conversation->status === 'open', 422);
        event(new ChatTyping($conversation, 'visitor'));
        return response()->json(['success' => true]);
    }

    public function attachment(string $publicId, int $messageId, Request $request)
    {
        $conversation = $this->visitorConversation($publicId, $request);
        $message = ChatMessage::withTrashed()->where('conversation_id', $conversation->id)->findOrFail($messageId);
        abort_if($message->trashed() || ! $message->attachment_path || ! Storage::disk('local')->exists($message->attachment_path), 404);
        return Storage::disk('local')->download($message->attachment_path, $message->attachment_name, ['X-Content-Type-Options' => 'nosniff']);
    }

    public function thumbnail(string $publicId, int $messageId, Request $request)
    {
        $conversation = $this->visitorConversation($publicId, $request);
        $message = ChatMessage::where('conversation_id', $conversation->id)->findOrFail($messageId);
        abort_if(! $message->thumbnail_path || ! Storage::disk('local')->exists($message->thumbnail_path), 404);
        return response()->file(Storage::disk('local')->path($message->thumbnail_path), ['Content-Type' => 'image/jpeg', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }

    private function visitorConversation(string $publicId, Request $request): ChatConversation
    {
        $token = (string) $request->header('X-Visitor-Token');
        abort_unless(preg_match('/^[a-f0-9]{64}$/i', $token) === 1, 401);
        return $this->chat->findVisitorConversation($publicId, $token);
    }

    private function conversationData(ChatConversation $conversation): array
    {
        return ['public_id' => $conversation->public_id, 'visitor_channel' => 'chat.visitor.'.$conversation->visitor_key_hash, 'status' => $conversation->status, 'assigned' => (bool) $conversation->assigned_to, 'created_at' => $conversation->created_at];
    }

    private function messageData(ChatMessage $message, string $publicId): array
    {
        return [
            'id' => $message->id,
            'client_message_id' => $message->client_message_id,
            'author_kind' => $message->author_kind,
            'message_type' => $message->message_type,
            'body' => $message->trashed() ? null : $message->body,
            'deleted' => $message->trashed(),
            'attachment_name' => $message->trashed() ? null : $message->attachment_name,
            'attachment_url' => $message->attachment_path && ! $message->trashed() ? route('chat.visitor.attachment', ['publicId' => $publicId, 'messageId' => $message->id]) : null,
            'thumbnail_url' => $message->thumbnail_path && ! $message->trashed() ? route('chat.visitor.thumbnail', ['publicId' => $publicId, 'messageId' => $message->id]) : null,
            'created_at' => $message->created_at,
            'sent_at' => $message->created_at,
            'delivered_at' => $message->delivered_at,
            'read_at' => $message->read_at,
        ];
    }
}
