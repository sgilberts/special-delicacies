<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('widget_enabled')->default(true);
            $table->boolean('accepting_conversations')->default(true);
            $table->json('eligible_role_ids')->nullable();
            $table->boolean('automatic_assignment')->default(true);
            $table->unsignedSmallInteger('max_active_per_agent')->default(5);
            $table->string('unavailable_message', 500)->default('Our team is away right now. Leave a message and we will get back to you.');
            $table->string('fallback_behavior', 20)->default('queue');
            $table->timestamps();
        });

        Schema::create('chat_agent_presence', function (Blueprint $table) {
            $table->foreignId('user_id')->primary()->constrained()->cascadeOnDelete();
            $table->string('status', 20)->default('offline');
            $table->timestamp('last_seen_at')->nullable()->index();
            $table->timestamps();
        });
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->string('thumbnail_path')->nullable()->after('attachment_path');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_agent_presence');
        Schema::dropIfExists('chat_settings');
        if (Schema::hasColumn('chat_messages', 'thumbnail_path')) {
            Schema::table('chat_messages', function (Blueprint $table) {
                $table->dropColumn('thumbnail_path');
            });
        }
    }
};
