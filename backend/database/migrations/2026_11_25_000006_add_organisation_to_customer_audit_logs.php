<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_audit_logs', function (Blueprint $table) {
            $table->foreignId('organisation_id')->nullable()->after('customer_id')->constrained('organisations')->restrictOnDelete();
            $table->index(['organisation_id', 'created_at'], 'customer_audit_org_created_index');
        });
    }

    public function down(): void
    {
        Schema::table('customer_audit_logs', function (Blueprint $table) {
            $table->dropIndex('customer_audit_org_created_index');
            $table->dropConstrainedForeignId('organisation_id');
        });
    }
};
