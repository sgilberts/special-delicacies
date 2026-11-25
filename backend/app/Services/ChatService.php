<?php

namespace App\Services;

use App\Events\ChatConversationAssigned;
use App\Events\ChatConversationStatusUpdated;
use App\Events\ChatMessageSent;
use App\Events\ChatReceiptUpdated;
use App\Models\ChatAuditLog;
use App\Models\ChatAgentPresence;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\ChatNotification;
use App\Models\ChatSetting;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ChatService
{
    public function start(string $visitorToken, string $name, string $email): ChatConversation
    {
        $hash = hash('sha256', $visitorToken);
        $lock = Cache::lock('chat-visitor-'.$hash, 10);
        $lock->block(5);
        try {
        $conversation = DB::transaction(function () use ($hash, $name, $email) {
            $existing = ChatConversation::where('visitor_key_hash', $hash)->where('status', 'open')->lockForUpdate()->latest('id')->first();
            if ($existing) return $existing;

            $settings = ChatSetting::current();
            abort_unless($settings->widget_enabled, 404, 'Customer chat is currently unavailable.');
            abort_unless($settings->accepting_conversations, 409, $settings->unavailable_message);

            $conversation = ChatConversation::create([
                'public_id' => (string) Str::uuid(),
                'visitor_key_hash' => $hash,
                'visitor_name' => $name,
                'visitor_email' => $email,
                'status' => 'open',
            ]);
            $conversation->auditLogs()->create(['action' => 'conversation.created', 'metadata' => ['visitor_email' => $email]]);

            $conversation->auditLogs()->create(['action' => 'conversation.unassigned', 'metadata' => []]);
            if (! $settings->automatic_assignment || ! $this->assignWaitingConversation($conversation)) $this->notifyWaitingAdmins($conversation);

            return $conversation;
        });

        return $conversation->load('assignee');
        } finally {
            $lock->release();
        }
    }

    public function eligibleAgentsQuery()
    {
        $settings = ChatSetting::current();
        return User::query()->where('users.is_active', true)
            ->whereHas('roles.permissions', fn ($query) => $query->where('permissions.slug', 'chat.receive'))
            ->when(is_array($settings->eligible_role_ids) && count($settings->eligible_role_ids) > 0,
                fn ($query) => $query->whereHas('roles', fn ($roles) => $roles->whereIn('roles.id', $settings->eligible_role_ids)));
    }

    public function heartbeat(User $user, string $status = 'online'): void
    {
        abort_unless($user->is_active && $user->hasPermission('chat.receive'), 403);
        ChatAgentPresence::query()->updateOrCreate(['user_id' => $user->id], ['status' => $status, 'last_seen_at' => now()]);
        if ($status === 'online') $this->drainWaitingQueue($user->id);
    }

    public function presenceState(User $user): string
    {
        if (! $user->is_active || ! $user->hasPermission('chat.receive')) return 'unavailable';
        $presence = ChatAgentPresence::find($user->id);
        if (! $presence || ! $presence->last_seen_at || $presence->last_seen_at->lt(now()->subSeconds(45)) || $presence->status === 'offline') return 'offline';
        if ($presence->status !== 'online') return 'unavailable';
        $limit = ChatSetting::current()->max_active_per_agent;
        return $limit > 0 && ChatConversation::where('assigned_to', $user->id)->where('status', 'open')->count() >= $limit ? 'busy' : 'online';
    }

    public function drainWaitingQueue(?int $onlyAgentId = null): void
    {
        $settings = ChatSetting::current();
        if (! $settings->accepting_conversations || ! $settings->automatic_assignment) return;
        $queue = ChatConversation::whereNull('assigned_to')->where('status', 'open')->orderBy('id')->limit(100)->pluck('id');
        foreach ($queue as $conversationId) {
            $this->assignWaitingConversation(ChatConversation::find($conversationId), $onlyAgentId);
        }
    }

    private function notifyWaitingAdmins(ChatConversation $conversation): void
    {
        $admins = User::query()->where('is_active', true)
            ->whereHas('roles.permissions', fn ($query) => $query->where('permissions.slug', 'chat.view_all'))
            ->get();
        foreach ($admins as $admin) {
            ChatNotification::firstOrCreate(['user_id' => $admin->id, 'conversation_id' => $conversation->id]);
            ChatConversationAssigned::dispatch($conversation, $admin->id);
        }
    }

    private function assignWaitingConversation(?ChatConversation $conversation, ?int $onlyAgentId = null): bool
    {
        if (! $conversation) return false;
        $settings = ChatSetting::current();
        if (! $settings->automatic_assignment || ! $settings->accepting_conversations) return false;
        return DB::transaction(function () use ($conversation, $settings, $onlyAgentId) {
            $lockedConversation = ChatConversation::whereKey($conversation->id)->lockForUpdate()->first();
            if (! $lockedConversation || $lockedConversation->assigned_to || $lockedConversation->status !== 'open') return false;

            $agents = $this->eligibleAgentsQuery()
                ->join('chat_agent_presence', 'chat_agent_presence.user_id', '=', 'users.id')
                ->where('chat_agent_presence.status', 'online')
                ->where('chat_agent_presence.last_seen_at', '>=', now()->subSeconds(45))
                ->when($onlyAgentId, fn ($q) => $q->where('users.id', $onlyAgentId))
                ->when(is_array($settings->eligible_role_ids) && count($settings->eligible_role_ids) > 0,
                    fn ($q) => $q->whereHas('roles', fn ($roles) => $roles->whereIn('roles.id', $settings->eligible_role_ids)))
                ->orderBy('users.id')
                ->select('users.*')->lockForUpdate()->get();

            foreach ($agents->shuffle() as $agent) {
                $presence = ChatAgentPresence::where('user_id', $agent->id)->lockForUpdate()->first();
                if (! $presence || $presence->status !== 'online' || $presence->last_seen_at?->lt(now()->subSeconds(45))) continue;
                $activeCount = ChatConversation::where('assigned_to', $agent->id)->where('status', 'open')->count();
                if ($settings->max_active_per_agent > 0 && $activeCount >= $settings->max_active_per_agent) continue;
                $lockedConversation->forceFill(['assigned_to' => $agent->id, 'assigned_at' => now()])->save();
                $lockedConversation->auditLogs()->create(['action' => 'conversation.assigned', 'metadata' => ['assignee_id' => $agent->id]]);
                ChatNotification::firstOrCreate(['user_id' => $agent->id, 'conversation_id' => $lockedConversation->id]);
                ChatConversationAssigned::dispatch($lockedConversation, $agent->id);
                return true;
            }
            return false;
        });
    }

    public function findVisitorConversation(string $publicId, string $visitorToken): ChatConversation
    {
        return ChatConversation::where('public_id', $publicId)
            ->where('visitor_key_hash', hash('sha256', $visitorToken))
            ->firstOrFail();
    }

    public function accessibleConversation(string $id, User $user, string $permission): ChatConversation
    {
        $conversation = ChatConversation::query()->whereKey($id)->firstOrFail();
        $hasPermission = $user->hasPermission($permission)
            || ($permission === 'chat.view_assigned' && $user->hasPermission('chat.view_all'));
        abort_unless($user->is_active && $hasPermission, 403);
        abort_unless($user->hasPermission('chat.view_all') || (int) $conversation->assigned_to === (int) $user->id, 403);
        return $conversation;
    }

    public function send(ChatConversation $conversation, string $authorKind, ?User $author, ?string $body, ?UploadedFile $file, ?string $clientId): ChatMessage
    {
        if ($conversation->status !== 'open') throw ValidationException::withMessages(['conversation' => 'This conversation is closed.']);
        if (! $body && ! $file) throw ValidationException::withMessages(['message' => 'Write a message or attach a file.']);

        if ($clientId) {
            $duplicate = ChatMessage::where('conversation_id', $conversation->id)->where('client_message_id', $clientId)->first();
            if ($duplicate) return $duplicate;
        }

        $storedPath = $file?->store('chat', 'local');
        $thumbnailPath = null;
        try {
            $mime = $file?->getMimeType();
            if ($file && str_starts_with((string) $mime, 'image/')) $thumbnailPath = $this->makeThumbnail($storedPath, $mime);
            $type = match (true) {
                $mime && str_starts_with($mime, 'image/') => 'image',
                $mime && str_starts_with($mime, 'video/') => 'video',
                $mime === 'application/pdf' => 'document',
                $mime && str_starts_with($mime, 'audio/') => 'voice',
                default => 'text',
            };

            $message = DB::transaction(function () use ($conversation, $authorKind, $author, $body, $file, $clientId, $storedPath, $thumbnailPath, $mime, $type) {
                $locked = ChatConversation::whereKey($conversation->id)->lockForUpdate()->firstOrFail();
                if ($locked->status !== 'open') throw ValidationException::withMessages(['conversation' => 'This conversation is closed.']);
                if ($clientId) {
                    $duplicate = ChatMessage::where('conversation_id', $locked->id)->where('client_message_id', $clientId)->first();
                    if ($duplicate) return $duplicate;
                }

                $message = ChatMessage::create([
                    'conversation_id' => $locked->id,
                    'client_message_id' => $clientId,
                    'author_kind' => $authorKind,
                    'author_id' => $author?->id,
                    'message_type' => $type,
                    'body' => $body,
                    'attachment_path' => $storedPath,
                    'thumbnail_path' => $thumbnailPath,
                    'attachment_name' => $file ? mb_substr($file->getClientOriginalName(), 0, 255) : null,
                    'attachment_mime' => $mime,
                    'attachment_size' => $storedPath ? Storage::disk('local')->size($storedPath) : null,
                    'delivered_at' => null,
                ]);
                $locked->forceFill(['last_message_at' => now()])->save();
                $locked->auditLogs()->create(['actor_id' => $author?->id, 'action' => 'message.created', 'metadata' => ['message_id' => $message->id, 'author_kind' => $authorKind]]);

                if ($authorKind === 'visitor' && $locked->assigned_to) {
                    ChatNotification::create(['user_id' => $locked->assigned_to, 'conversation_id' => $locked->id, 'message_id' => $message->id]);
                }
                ChatMessageSent::dispatch($message->load('conversation'));
                return $message;
            });
        } catch (\Throwable $exception) {
            if ($storedPath) Storage::disk('local')->delete($storedPath);
            if ($thumbnailPath) Storage::disk('local')->delete($thumbnailPath);
            throw $exception;
        }

        if ($storedPath && $message->attachment_path !== $storedPath) {
            Storage::disk('local')->delete($storedPath);
            if ($thumbnailPath) Storage::disk('local')->delete($thumbnailPath);
        }
        return $message;
    }

    private function makeThumbnail(string $originalPath, string $mime): string
    {
        $source = Storage::disk('local')->path($originalPath);
        $thumb = 'chat/thumbnails/'.Str::uuid().'.jpg';
        $target = Storage::disk('local')->path($thumb);
        if (! is_dir(dirname($target))) mkdir(dirname($target), 0700, true);
        try {
            if (class_exists(\Imagick::class)) {
                $image = new \Imagick();
                $image->readImage($source);
                if ($image->getNumberImages() !== 1) throw new \RuntimeException('Animated or multi-frame images are not supported.');
                $image->autoOrient();
                $format = match ($mime) { 'image/jpeg' => 'jpeg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif', default => throw new \RuntimeException('Unsupported image format.') };
                $optimized = clone $image;
                if (max($optimized->getImageWidth(), $optimized->getImageHeight()) > 3000) $optimized->resizeImage(3000, 3000, \Imagick::FILTER_LANCZOS, 0.86, true);
                $optimized->stripImage();
                $optimized->setImageFormat($format);
                if ($format === 'png') $optimized->setImageCompression(\Imagick::COMPRESSION_ZIP);
                else $optimized->setImageCompressionQuality(84);
                $temporary = $source.'.optimized';
                $optimized->writeImage($temporary);
                $optimized->clear();
                if (! copy($temporary, $source)) throw new \RuntimeException('Image optimization failed.');
                @unlink($temporary);
                $image->thumbnailImage(480, 480, true, true);
                $image->stripImage();
                $image->setImageFormat('jpeg');
                $image->setImageCompressionQuality(76);
                $image->writeImage($target);
                $image->clear();
            } else {
                $info = getimagesize($source);
                if (! $info || $info['mime'] !== $mime) throw new \RuntimeException('Invalid image content.');
                $input = match ($mime) {
                    'image/jpeg' => imagecreatefromjpeg($source), 'image/png' => imagecreatefrompng($source),
                    'image/gif' => imagecreatefromgif($source), 'image/webp' => imagecreatefromwebp($source),
                    default => false,
                };
                if (! $input) throw new \RuntimeException('Invalid image content.');
                if (max(imagesx($input), imagesy($input)) > 3000) {
                    $sourceScale = 3000 / max(imagesx($input), imagesy($input));
                    $originalOutput = imagecreatetruecolor(max(1, (int) (imagesx($input) * $sourceScale)), max(1, (int) (imagesy($input) * $sourceScale)));
                    imagecopyresampled($originalOutput, $input, 0, 0, 0, 0, imagesx($originalOutput), imagesy($originalOutput), imagesx($input), imagesy($input));
                    imagedestroy($input); $input = $originalOutput;
                }
                match ($mime) {
                    'image/jpeg' => imagejpeg($input, $source, 84), 'image/png' => imagepng($input, $source, 6),
                    'image/gif' => imagegif($input, $source), 'image/webp' => imagewebp($input, $source, 84),
                    default => false,
                };
                $scale = min(1, 480 / max(imagesx($input), imagesy($input)));
                $output = imagecreatetruecolor(max(1, (int) (imagesx($input) * $scale)), max(1, (int) (imagesy($input) * $scale)));
                imagecopyresampled($output, $input, 0, 0, 0, 0, imagesx($output), imagesy($output), imagesx($input), imagesy($input));
                imagejpeg($output, $target, 76); imagedestroy($input); imagedestroy($output);
            }
            if (! is_file($target) || filesize($target) === 0) throw new \RuntimeException('Image processing failed.');
        } catch (\Throwable $error) {
            @unlink($target);
            throw ValidationException::withMessages(['attachment' => 'This image is malformed or cannot be processed.']);
        }
        return $thumb;
    }

    public function acknowledgeReceipts(ChatConversation $conversation, string $readerKind, array $messageIds, string $status): array
    {
        $authorKind = $readerKind === 'visitor' ? 'staff' : 'visitor';
        $updatedIds = [];
        $staffRecipientIds = [];

        DB::transaction(function () use ($conversation, $readerKind, $authorKind, $messageIds, $status, &$updatedIds, &$staffRecipientIds) {
            $messages = ChatMessage::where('conversation_id', $conversation->id)
                ->whereIn('id', $messageIds)->where('author_kind', $authorKind)
                ->orderBy('id')->lockForUpdate()->get();

            foreach ($messages as $message) {
                if ($status === 'delivered' && ! $message->delivered_at) {
                    $message->forceFill(['delivered_at' => now()])->save();
                    $updatedIds[] = $message->id;
                } elseif ($status === 'read' && ! $message->read_at) {
                    $readAt = now();
                    $message->forceFill(['delivered_at' => $message->delivered_at ?? $readAt, 'read_at' => $readAt])->save();
                    $updatedIds[] = $message->id;
                }

                if ($message->author_kind === 'staff' && $message->author_id) $staffRecipientIds[] = (int) $message->author_id;
            }

            if ($updatedIds && $readerKind === 'staff' && $status === 'read') {
                ChatNotification::where('conversation_id', $conversation->id)->whereNull('read_at')->update(['read_at' => now()]);
            }
        });

        $updatedIds = array_values(array_unique($updatedIds));
        $staffRecipientIds = array_values(array_unique($staffRecipientIds));
        if ($updatedIds) event(new ChatReceiptUpdated($conversation, $readerKind, $status, $updatedIds, $staffRecipientIds));
        return $updatedIds;
    }

    public function reassign(ChatConversation $conversation, User $assignee, User $actor): void
    {
        $settings = ChatSetting::current();
        $eligible = $this->eligibleAgentsQuery()->whereKey($assignee->id)
            ->when(is_array($settings->eligible_role_ids) && count($settings->eligible_role_ids) > 0,
                fn ($query) => $query->whereHas('roles', fn ($roles) => $roles->whereIn('roles.id', $settings->eligible_role_ids)))
            ->exists();
        abort_unless($assignee->is_active && $assignee->hasPermission('chat.receive') && $eligible && $this->presenceState($assignee) === 'online', 422, 'The selected staff member is not available to receive chat.');
        DB::transaction(function () use ($conversation, $assignee, $actor) {
            $locked = ChatConversation::whereKey($conversation->id)->lockForUpdate()->firstOrFail();
            $presence = ChatAgentPresence::where('user_id', $assignee->id)->lockForUpdate()->first();
            abort_unless($presence && $presence->status === 'online' && $presence->last_seen_at?->gte(now()->subSeconds(45)), 422, 'The selected staff member is offline.');
            $activeCount = ChatConversation::where('assigned_to', $assignee->id)->where('status', 'open')->where('id', '<>', $locked->id)->count();
            abort_if(ChatSetting::current()->max_active_per_agent > 0 && $activeCount >= ChatSetting::current()->max_active_per_agent, 422, 'The selected staff member is at capacity.');
            $previous = $locked->assigned_to;
            $locked->forceFill(['assigned_to' => $assignee->id, 'assigned_at' => now(), 'status' => 'open', 'closed_at' => null])->save();
            $locked->auditLogs()->create(['actor_id' => $actor->id, 'action' => 'conversation.reassigned', 'metadata' => ['previous_assignee_id' => $previous, 'assignee_id' => $assignee->id]]);
            ChatNotification::firstOrCreate(['user_id' => $assignee->id, 'conversation_id' => $locked->id]);
            ChatConversationAssigned::dispatch($locked, $assignee->id);
        });
        $this->drainWaitingQueue();
    }

    public function setClosed(ChatConversation $conversation, bool $closed, User $actor): void
    {
        DB::transaction(function () use ($conversation, $closed, $actor) {
            $locked = ChatConversation::whereKey($conversation->id)->lockForUpdate()->firstOrFail();
            if (! $closed && $locked->assigned_to) {
                $settings = ChatSetting::current();
                $presence = ChatAgentPresence::where('user_id', $locked->assigned_to)->lockForUpdate()->first();
                $agentEligible = $this->eligibleAgentsQuery()->whereKey($locked->assigned_to)
                    ->when(is_array($settings->eligible_role_ids) && count($settings->eligible_role_ids) > 0,
                        fn ($query) => $query->whereHas('roles', fn ($roles) => $roles->whereIn('roles.id', $settings->eligible_role_ids)))
                    ->exists();
                $activeCount = ChatConversation::where('assigned_to', $locked->assigned_to)->where('status', 'open')->where('id', '<>', $locked->id)->count();
                $atCapacity = $settings->max_active_per_agent > 0 && $activeCount >= $settings->max_active_per_agent;
                if (! $presence || $presence->status !== 'online' || $presence->last_seen_at?->lt(now()->subSeconds(45)) || ! $agentEligible || $atCapacity) {
                    $locked->forceFill(['assigned_to' => null, 'assigned_at' => null])->save();
                }
            }
            $locked->forceFill(['status' => $closed ? 'closed' : 'open', 'closed_at' => $closed ? now() : null])->save();
            $locked->auditLogs()->create(['actor_id' => $actor->id, 'action' => $closed ? 'conversation.closed' : 'conversation.reopened', 'metadata' => []]);
        });
        $this->drainWaitingQueue();
        $updated = $conversation->fresh();
        event(new ChatConversationStatusUpdated($updated, $updated->status));
    }

    public function tombstone(ChatMessage $message, User $actor): void
    {
        DB::transaction(function () use ($message, $actor) {
            $locked = ChatMessage::withTrashed()->whereKey($message->id)->lockForUpdate()->firstOrFail();
            if ($locked->trashed()) return;
            $locked->forceFill(['deleted_by' => $actor->id, 'body' => null])->save();
            if ($locked->attachment_path) Storage::disk('local')->delete($locked->attachment_path);
            if ($locked->thumbnail_path) Storage::disk('local')->delete($locked->thumbnail_path);
            $locked->forceFill(['attachment_path' => null, 'thumbnail_path' => null])->save();
            $locked->delete();
            $locked->conversation->auditLogs()->create(['actor_id' => $actor->id, 'action' => 'message.deleted', 'metadata' => ['message_id' => $locked->id]]);
            ChatMessageSent::dispatch($locked->load('conversation'));
        });
    }
}
