<?php

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Permission::updateOrCreate(
            ['slug' => 'reverb.monitor'],
            ['name' => 'View Reverb Monitor', 'description' => 'View live broadcast diagnostics and send test pings']
        );
    }

    public function down(): void
    {
        $permissionId = DB::table('permissions')->where('slug', 'reverb.monitor')->value('id');
        if ($permissionId) {
            DB::table('permission_role')->where('permission_id', $permissionId)->delete();
            DB::table('permissions')->where('id', $permissionId)->delete();
        }
    }
};
