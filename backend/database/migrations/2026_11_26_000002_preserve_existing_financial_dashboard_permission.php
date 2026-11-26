<?php

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        // financials.view already belongs to the staff dashboard; preserve its
        // established label/description while membership grants use their own scope.
        Permission::updateOrCreate(
            ['slug' => 'financials.view'],
            ['name' => 'View Financial Dashboard', 'description' => 'View financial dashboard and aggregates'],
        );
    }

    public function down(): void
    {
        // Preserve the pre-existing staff financial permission on rollback.
    }
};
