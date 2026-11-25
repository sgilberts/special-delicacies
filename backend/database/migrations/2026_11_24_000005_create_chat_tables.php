<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_conversations', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->char('visitor_key_hash', 64)->index();
            $table->string('visitor_name', 120);
            $table->string('visitor_email', 254);
            $table->string('status', 20)->default('open')->index();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_message_at')->nullable()->index();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'assigned_to', 'last_message_at']);
        });

        Schema::create('chat_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('chat_conversations')->cascadeOnDelete();
            $table->uuid('client_message_id')->nullable();
            $table->string('author_kind', 20);
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('message_type', 20)->default('text');
            $table->text('body')->nullable();
            $table->string('attachment_path')->nullable();
            $table->string('attachment_name')->nullable();
            $table->string('attachment_mime', 120)->nullable();
            $table->unsignedBigInteger('attachment_size')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['conversation_id', 'client_message_id']);
            $table->index(['conversation_id', 'created_at']);
        });

        Schema::create('chat_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('chat_conversations')->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 60);
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['conversation_id', 'created_at']);
        });

        Schema::create('chat_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained('chat_conversations')->cascadeOnDelete();
            $table->foreignId('message_id')->nullable()->constrained('chat_messages')->nullOnDelete();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_notifications');
        Schema::dropIfExists('chat_audit_logs');
        Schema::dropIfExists('chat_messages');
        Schema::dropIfExists('chat_conversations');
    }
};
