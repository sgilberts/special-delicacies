<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private array $permissions = [
        'chat.receive' => ['Receive Chat Conversations', 'Receive automatically assigned customer conversations'],
        'chat.view_assigned' => ['View Assigned Chats', 'View conversations assigned to the current staff member'],
        'chat.view_all' => ['View All Chats', 'View all customer conversations'],
        'chat.reply' => ['Reply to Chats', 'Send replies in authorized conversations'],
        'chat.assign' => ['Assign Chats', 'Assign unassigned conversations to eligible staff'],
        'chat.reassign' => ['Reassign Chats', 'Change the assigned staff member'],
        'chat.close' => ['Close Chats', 'Close or reopen conversations'],
        'chat.delete_message' => ['Delete Chat Messages', 'Tombstone messages while preserving audit evidence'],
        'chat.manage_settings' => ['Manage Chat Settings', 'Manage chat availability and assignment settings'],
        'chat.view_audit_logs' => ['View Chat Audit Logs', 'Review conversation audit history'],
    ];

    public function up(): void
    {
        foreach ($this->permissions as $slug => [$name, $description]) {
            DB::table('permissions')->updateOrInsert(['slug' => $slug], [
                'name' => $name,
                'description' => $description,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $superAdminId = DB::table('roles')->where('slug', 'super-admin')->value('id');
        if ($superAdminId) {
            foreach (array_keys($this->permissions) as $slug) {
                $permissionId = DB::table('permissions')->where('slug', $slug)->value('id');
                DB::table('permission_role')->updateOrInsert(
                    ['role_id' => $superAdminId, 'permission_id' => $permissionId],
                    ['created_at' => now(), 'updated_at' => now()],
                );
            }
        }
    }

    public function down(): void
    {
        $ids = DB::table('permissions')->whereIn('slug', array_keys($this->permissions))->pluck('id');
        DB::table('permission_role')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
    }
};
