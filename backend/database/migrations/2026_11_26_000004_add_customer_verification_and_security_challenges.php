<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->timestamp('email_verified_at')->nullable()->after('email');
            $table->timestamp('phone_verified_at')->nullable()->after('phone');
        });
        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('phone_verified_at')->nullable()->after('phone');
        });

        Schema::create('account_security_challenges', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 64)->unique();
            $table->foreignId('customer_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('purpose', 32);
            $table->string('channel', 16);
            $table->char('recipient_hash', 64);
            $table->string('secret_hash', 255);
            $table->timestamp('expires_at');
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('consumed_at')->nullable();
            $table->unsignedTinyInteger('failed_attempts')->default(0);
            $table->timestamps();
            $table->index(['customer_id', 'purpose', 'channel', 'created_at'], 'security_customer_cooldown');
            $table->index(['user_id', 'purpose', 'channel', 'created_at'], 'security_user_cooldown');
            $table->index(['expires_at', 'consumed_at'], 'security_expiration_lookup');
        });

        Schema::create('account_security_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('event', 80);
            $table->string('channel', 16)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['customer_id', 'created_at'], 'security_customer_events');
            $table->index(['user_id', 'created_at'], 'security_user_events');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_security_events');
        Schema::dropIfExists('account_security_challenges');
        Schema::table('customers', function (Blueprint $table): void {
            $table->dropColumn(['email_verified_at', 'phone_verified_at']);
        });
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('phone_verified_at');
        });
    }
};
