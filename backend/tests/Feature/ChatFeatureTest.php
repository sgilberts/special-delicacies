<?php

namespace Tests\Feature;

use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class ChatFeatureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
    }

    public function test_chat_permissions_are_not_granted_to_operational_roles_by_default(): void
    {
        $waiter = Role::where('slug', 'waiter-waitress')->firstOrFail();
        $superAdmin = Role::where('slug', 'super-admin')->firstOrFail();

        $this->assertFalse($waiter->permissions()->where('slug', 'chat.receive')->exists());
        $this->assertFalse($waiter->permissions()->where('slug', 'chat.view_assigned')->exists());
        $this->assertTrue($superAdmin->permissions()->where('slug', 'chat.receive')->exists());
    }

    private function staff(array $permissions): User
    {
        $role = Role::create(['name' => 'Chat Agent '.Str::random(6), 'slug' => 'chat-agent-'.Str::random(6), 'description' => 'Test chat role']);
        foreach ($permissions as $permission) $role->givePermissionTo($permission);
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach($role);
        return $user;
    }

    private function start(string $token): array
    {
        $response = $this->postJson('/api/v1/chat/conversations', [
            'visitor_token' => $token,
            'name' => 'Chat Visitor',
            'email' => 'visitor@example.test',
        ])->assertCreated()->assertJsonPath('success', true);
        return $response->json('data');
    }

    public function test_visitor_conversation_is_assigned_to_chat_eligible_staff_and_message_persists(): void
    {
        $agent = $this->staff(['chat.receive', 'chat.view_assigned', 'chat.reply', 'chat.close']);
        $this->actingAs($agent, 'sanctum')->postJson('/api/v1/admin/chat/presence/heartbeat')->assertOk();
        $token = bin2hex(random_bytes(32));
        $conversation = $this->start($token);

        $this->assertDatabaseHas('chat_conversations', ['public_id' => $conversation['public_id'], 'assigned_to' => $agent->id]);
        $this->assertSame('chat.visitor.'.hash('sha256', $token), $conversation['visitor_channel']);
        $this->assertDatabaseMissing('chat_conversations', ['visitor_key_hash' => $token]);

        $clientMessageId = (string) Str::uuid();
        $this->postJson('/api/v1/chat/conversations/'.$conversation['public_id'].'/messages', [
            'body' => 'Hello, I need help.', 'client_message_id' => $clientMessageId,
        ], ['X-Visitor-Token' => $token])->assertCreated();
        $this->postJson('/api/v1/chat/conversations/'.$conversation['public_id'].'/messages', [
            'body' => 'Hello, I need help.', 'client_message_id' => $clientMessageId,
        ], ['X-Visitor-Token' => $token])->assertCreated();
        $this->assertDatabaseHas('chat_messages', ['conversation_id' => ChatConversation::where('public_id', $conversation['public_id'])->value('id'), 'body' => 'Hello, I need help.']);
        $this->assertDatabaseCount('chat_messages', 1);

        $this->actingAs($agent, 'sanctum')->getJson('/api/v1/admin/chat/conversations')->assertOk()->assertJsonPath('data.0.visitor_name', 'Chat Visitor');
    }

    public function test_visitor_capability_is_required_to_read_and_send_messages(): void
    {
        $token = bin2hex(random_bytes(32));
        $conversation = $this->start($token);

        $this->getJson('/api/v1/chat/conversations/'.$conversation['public_id'].'/messages')->assertUnauthorized();
        $this->postJson('/api/v1/chat/conversations/'.$conversation['public_id'].'/messages', ['body' => 'No capability'])->assertUnauthorized();
        $this->getJson('/api/v1/chat/conversations/'.$conversation['public_id'].'/messages', ['X-Visitor-Token' => bin2hex(random_bytes(32))])->assertNotFound();
    }

    public function test_conversation_stays_in_queue_when_no_chat_agent_is_eligible(): void
    {
        $conversation = $this->start(bin2hex(random_bytes(32)));
        $this->assertFalse($conversation['assigned']);
        $this->assertDatabaseHas('chat_conversations', ['public_id' => $conversation['public_id'], 'assigned_to' => null]);
        $this->assertDatabaseHas('chat_audit_logs', ['action' => 'conversation.unassigned']);
    }

    public function test_agent_heartbeat_recovers_waiting_conversations_and_expired_presence_is_not_assignable(): void
    {
        $agent = $this->staff(['chat.receive', 'chat.view_assigned', 'chat.reply']);
        $waiting = $this->start(bin2hex(random_bytes(32)));
        $this->assertDatabaseHas('chat_conversations', ['public_id' => $waiting['public_id'], 'assigned_to' => null]);
        $this->actingAs($agent, 'sanctum')->postJson('/api/v1/admin/chat/presence/heartbeat')->assertOk()->assertJsonPath('data.status', 'online');
        $this->assertDatabaseHas('chat_conversations', ['public_id' => $waiting['public_id'], 'assigned_to' => $agent->id]);

        $this->travel(46)->seconds();
        $this->actingAs($agent, 'sanctum')->postJson('/api/v1/admin/chat/presence/heartbeat', ['status' => 'offline'])->assertOk()->assertJsonPath('data.status', 'offline');
        $another = $this->start(bin2hex(random_bytes(32)));
        $this->assertDatabaseHas('chat_conversations', ['public_id' => $another['public_id'], 'assigned_to' => null]);
    }

    public function test_agent_capacity_marks_busy_and_keeps_extra_conversations_in_queue(): void
    {
        $agent = $this->staff(['chat.receive', 'chat.view_assigned', 'chat.reply']);
        $this->actingAs($agent, 'sanctum')->postJson('/api/v1/admin/chat/presence/heartbeat')->assertOk();
        \App\Models\ChatSetting::current()->update(['max_active_per_agent' => 1]);
        $first = $this->start(bin2hex(random_bytes(32)));
        $second = $this->start(bin2hex(random_bytes(32)));
        $this->assertDatabaseHas('chat_conversations', ['public_id' => $first['public_id'], 'assigned_to' => $agent->id]);
        $this->assertDatabaseHas('chat_conversations', ['public_id' => $second['public_id'], 'assigned_to' => null]);
        $this->actingAs($agent, 'sanctum')->postJson('/api/v1/admin/chat/presence/heartbeat')->assertOk()->assertJsonPath('data.status', 'busy');
    }

    public function test_closing_conversation_drains_queue_and_reopening_respects_agent_capacity(): void
    {
        $agent = $this->staff(['chat.receive', 'chat.view_assigned', 'chat.reply', 'chat.close']);
        $this->actingAs($agent, 'sanctum')->postJson('/api/v1/admin/chat/presence/heartbeat')->assertOk();
        \App\Models\ChatSetting::current()->update(['max_active_per_agent' => 1]);

        $first = $this->start(bin2hex(random_bytes(32)));
        $waiting = $this->start(bin2hex(random_bytes(32)));
        $firstId = ChatConversation::where('public_id', $first['public_id'])->value('id');
        $waitingId = ChatConversation::where('public_id', $waiting['public_id'])->value('id');
        $this->assertDatabaseHas('chat_conversations', ['id' => $firstId, 'assigned_to' => $agent->id, 'status' => 'open']);
        $this->assertDatabaseHas('chat_conversations', ['id' => $waitingId, 'assigned_to' => null, 'status' => 'open']);

        $this->actingAs($agent, 'sanctum')->patchJson("/api/v1/admin/chat/conversations/{$firstId}/close")->assertOk();
        $this->assertDatabaseHas('chat_conversations', ['id' => $firstId, 'status' => 'closed']);
        $this->assertDatabaseHas('chat_conversations', ['id' => $waitingId, 'assigned_to' => $agent->id, 'status' => 'open']);

        $this->actingAs($agent, 'sanctum')->patchJson("/api/v1/admin/chat/conversations/{$firstId}/reopen")->assertOk();
        $this->assertDatabaseHas('chat_conversations', ['id' => $firstId, 'assigned_to' => null, 'status' => 'open']);
        $this->assertSame(1, ChatConversation::where('assigned_to', $agent->id)->where('status', 'open')->count());
    }

    public function test_delivery_and_read_receipts_are_authorized_idempotent_and_monotonic_in_both_directions(): void
    {
        $agent = $this->staff(['chat.receive', 'chat.view_assigned', 'chat.reply']);
        $outsider = $this->staff(['chat.view_assigned', 'chat.reply']);
        $this->actingAs($agent, 'sanctum')->postJson('/api/v1/admin/chat/presence/heartbeat')->assertOk();
        $token = bin2hex(random_bytes(32));
        $conversation = $this->start($token);
        $conversationId = ChatConversation::where('public_id', $conversation['public_id'])->value('id');

        $visitorMessage = $this->postJson('/api/v1/chat/conversations/'.$conversation['public_id'].'/messages', [
            'body' => 'Customer to staff', 'client_message_id' => (string) Str::uuid(),
        ], ['X-Visitor-Token' => $token])->assertCreated()->json('data');
        $this->assertNull($visitorMessage['delivered_at']);
        $this->actingAs($agent, 'sanctum')->getJson('/api/v1/admin/chat/conversations/'.$conversationId)->assertOk();
        $visitorMessageModel = ChatMessage::findOrFail($visitorMessage['id']);
        $this->assertNull($visitorMessageModel->delivered_at);
        $this->assertNull($visitorMessageModel->read_at);

        $staffReceipts = '/api/v1/admin/chat/conversations/'.$conversationId.'/receipts';
        $this->actingAs($outsider, 'sanctum')->postJson($staffReceipts, ['message_ids' => [$visitorMessage['id']], 'status' => 'delivered'])->assertForbidden();
        $this->actingAs($agent, 'sanctum')->postJson($staffReceipts, ['message_ids' => [$visitorMessage['id']], 'status' => 'delivered'])->assertOk();
        $deliveredAt = ChatMessage::findOrFail($visitorMessage['id'])->delivered_at;
        $this->assertNotNull($deliveredAt);
        $this->assertNull(ChatMessage::findOrFail($visitorMessage['id'])->read_at);
        $this->travel(2)->seconds();
        $this->actingAs($agent, 'sanctum')->postJson($staffReceipts, ['message_ids' => [$visitorMessage['id']], 'status' => 'delivered'])->assertOk();
        $this->assertEquals($deliveredAt->toISOString(), ChatMessage::findOrFail($visitorMessage['id'])->delivered_at->toISOString());
        $this->actingAs($agent, 'sanctum')->postJson($staffReceipts, ['message_ids' => [$visitorMessage['id']], 'status' => 'read'])->assertOk();
        $readAt = ChatMessage::findOrFail($visitorMessage['id'])->read_at;
        $this->assertNotNull($readAt);
        $this->travel(2)->seconds();
        $this->actingAs($agent, 'sanctum')->postJson($staffReceipts, ['message_ids' => [$visitorMessage['id']], 'status' => 'read'])->assertOk();
        $this->assertEquals($readAt->toISOString(), ChatMessage::findOrFail($visitorMessage['id'])->read_at->toISOString());
        $this->actingAs($agent, 'sanctum')->postJson($staffReceipts, ['message_ids' => [$visitorMessage['id']], 'status' => 'delivered'])->assertOk();
        $this->assertEquals($readAt->toISOString(), ChatMessage::findOrFail($visitorMessage['id'])->read_at->toISOString());

        $staffRecovery = $this->actingAs($agent, 'sanctum')->getJson('/api/v1/admin/chat/conversations/'.$conversationId)->assertOk()->json('data.messages');
        $this->assertNotNull(collect($staffRecovery)->firstWhere('id', $visitorMessage['id'])['read_at']);

        $staffMessage = $this->actingAs($agent, 'sanctum')->postJson('/api/v1/admin/chat/conversations/'.$conversationId.'/messages', [
            'body' => 'Staff to customer', 'client_message_id' => (string) Str::uuid(),
        ])->assertCreated()->json('data');
        $this->assertNull($staffMessage['delivered_at']);
        $this->getJson('/api/v1/chat/conversations/'.$conversation['public_id'].'/messages', ['X-Visitor-Token' => $token])->assertOk();
        $this->assertNull(ChatMessage::findOrFail($staffMessage['id'])->read_at);
        $visitorReceipts = '/api/v1/chat/conversations/'.$conversation['public_id'].'/receipts';
        $this->postJson($visitorReceipts, ['message_ids' => [$staffMessage['id']], 'status' => 'delivered'], ['X-Visitor-Token' => $token])->assertOk();
        $this->assertNotNull(ChatMessage::findOrFail($staffMessage['id'])->delivered_at);
        $this->postJson($visitorReceipts, ['message_ids' => [$staffMessage['id']], 'status' => 'read'], ['X-Visitor-Token' => $token])->assertOk();
        $this->assertNotNull(ChatMessage::findOrFail($staffMessage['id'])->read_at);
        $visitorRecovery = $this->getJson('/api/v1/chat/conversations/'.$conversation['public_id'].'/messages', ['X-Visitor-Token' => $token])->assertOk()->json('data.messages');
        $this->assertNotNull(collect($visitorRecovery)->firstWhere('id', $staffMessage['id'])['read_at']);
        $this->postJson('/api/v1/chat/conversations/'.$conversation['public_id'].'/receipts', ['message_ids' => [$staffMessage['id']], 'status' => 'read'], ['X-Visitor-Token' => bin2hex(random_bytes(32))])->assertNotFound();
    }

    public function test_chat_settings_require_authorization_and_validate_roles(): void
    {
        $ordinary = $this->staff(['chat.view_assigned']);
        $this->actingAs($ordinary, 'sanctum')->putJson('/api/v1/admin/chat/settings', [])->assertForbidden();

        $adminRole = Role::where('slug', 'super-admin')->firstOrFail();
        $admin = User::factory()->create(['is_active' => true]);
        $admin->roles()->attach($adminRole);
        $eligibleRole = Role::where('slug', 'waiter-waitress')->firstOrFail();
        $this->actingAs($admin, 'sanctum')->putJson('/api/v1/admin/chat/settings', [
            'widget_enabled' => true, 'accepting_conversations' => false, 'eligible_role_ids' => [$eligibleRole->id],
            'automatic_assignment' => true, 'max_active_per_agent' => 3, 'unavailable_message' => 'Try again soon.', 'fallback_behavior' => 'queue',
        ])->assertUnprocessable();
        $this->assertDatabaseMissing('chat_settings', ['accepting_conversations' => false]);
    }

    public function test_image_attachment_gets_private_thumbnail_and_invalid_image_is_rejected(): void
    {
        Storage::fake('local');
        $token = bin2hex(random_bytes(32));
        $conversation = $this->start($token);
        $image = UploadedFile::fake()->image('dish.jpg', 1600, 1200)->size(700);
        $created = $this->postJson('/api/v1/chat/conversations/'.$conversation['public_id'].'/messages', ['attachment' => $image], ['X-Visitor-Token' => $token])->assertCreated();
        $message = ChatMessage::findOrFail($created->json('data.id'));
        $this->assertNotNull($message->thumbnail_path);
        Storage::disk('local')->assertExists($message->thumbnail_path);
        $this->get($created->json('data.thumbnail_url'), ['X-Visitor-Token' => $token])->assertOk()->assertHeader('content-type', 'image/jpeg');
        $this->get($created->json('data.thumbnail_url'), ['X-Visitor-Token' => bin2hex(random_bytes(32))])->assertNotFound();
        $invalid = UploadedFile::fake()->createWithContent('fake.jpg', 'not an image');
        $this->postJson('/api/v1/chat/conversations/'.$conversation['public_id'].'/messages', ['attachment' => $invalid], ['X-Visitor-Token' => $token])->assertUnprocessable();
    }

    public function test_staff_can_only_open_assigned_conversations_and_messages_are_audited(): void
    {
        $agent = $this->staff(['chat.receive', 'chat.view_assigned', 'chat.reply', 'chat.close']);
        $other = $this->staff(['chat.receive', 'chat.view_assigned', 'chat.reply']);
        $token = bin2hex(random_bytes(32));
        $conversation = $this->start($token);
        $id = ChatConversation::where('public_id', $conversation['public_id'])->value('id');
        ChatConversation::whereKey($id)->update(['assigned_to' => $agent->id]);

        $this->actingAs($other, 'sanctum')->getJson('/api/v1/admin/chat/conversations/'.$id)->assertForbidden();
        $this->actingAs($agent, 'sanctum')->getJson('/api/v1/admin/chat/conversations/'.$id)->assertOk();
        $this->actingAs($agent, 'sanctum')->postJson('/api/v1/admin/chat/conversations/'.$id.'/messages', ['body' => 'We can help.', 'client_message_id' => (string) Str::uuid()])->assertCreated();
        $this->assertDatabaseHas('chat_audit_logs', ['conversation_id' => $id, 'action' => 'message.created']);
    }

    public function test_chat_inbox_endpoints_are_forbidden_without_chat_permissions(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $this->actingAs($user, 'sanctum')->getJson('/api/v1/admin/chat/conversations')->assertForbidden();
    }

    public function test_attachments_are_private_size_limited_and_deletion_keeps_a_tombstone(): void
    {
        Storage::fake('local');
        $agent = $this->staff(['chat.receive', 'chat.view_assigned', 'chat.reply', 'chat.delete_message']);
        $this->actingAs($agent, 'sanctum')->postJson('/api/v1/admin/chat/presence/heartbeat')->assertOk();
        $token = bin2hex(random_bytes(32));
        $conversation = $this->start($token);

        $oversized = UploadedFile::fake()->create('large.pdf', 10_241, 'application/pdf');
        $this->postJson('/api/v1/chat/conversations/'.$conversation['public_id'].'/messages', ['attachment' => $oversized], ['X-Visitor-Token' => $token])->assertUnprocessable();

        $file = UploadedFile::fake()->create('menu.pdf', 120, 'application/pdf');
        $created = $this->postJson('/api/v1/chat/conversations/'.$conversation['public_id'].'/messages', ['attachment' => $file], ['X-Visitor-Token' => $token])->assertCreated();
        $messageId = $created->json('data.id');
        $message = ChatMessage::findOrFail($messageId);
        Storage::disk('local')->assertExists($message->attachment_path);

        $this->actingAs($agent, 'sanctum')->deleteJson('/api/v1/admin/chat/messages/'.$messageId)->assertOk();
        $this->assertSoftDeleted('chat_messages', ['id' => $messageId]);
        $this->assertDatabaseHas('chat_audit_logs', ['action' => 'message.deleted']);
    }
}
