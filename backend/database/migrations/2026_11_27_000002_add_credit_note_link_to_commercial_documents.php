<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commercial_documents', function (Blueprint $table): void {
            $table->foreignId('related_document_id')->nullable()->after('document_type')
                ->constrained('commercial_documents')->restrictOnDelete();
            $table->index(['related_document_id', 'document_type'], 'commercial_related_type');
        });
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            Schema::table('commercial_documents', function (Blueprint $table): void {
                $table->dropForeign(['related_document_id']);
                $table->dropIndex('commercial_related_type');
            });
        }

        Schema::table('commercial_documents', function (Blueprint $table): void {
            $table->dropColumn('related_document_id');
        });
    }
};
