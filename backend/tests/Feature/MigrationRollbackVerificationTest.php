<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MigrationRollbackVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_chat_availability_migration_rolls_back_and_reapplies_without_touching_existing_chat_data(): void
    {
        $this->assertTrue(Schema::hasTable('chat_conversations'));
        $this->assertTrue(Schema::hasTable('chat_messages'));
        $this->assertTrue(Schema::hasTable('chat_settings'));
        $this->assertTrue(Schema::hasTable('chat_agent_presence'));
        $this->assertTrue(Schema::hasColumn('chat_messages', 'thumbnail_path'));

        $rollbackCode = Artisan::call('migrate:rollback', [
            '--path' => 'database/migrations/2026_11_24_000007_add_chat_availability_and_presence.php',
            '--realpath' => false,
        ]);
        $this->assertSame(0, $rollbackCode, Artisan::output());
        $this->assertFalse(Schema::hasTable('chat_settings'));
        $this->assertFalse(Schema::hasTable('chat_agent_presence'));
        $this->assertFalse(Schema::hasColumn('chat_messages', 'thumbnail_path'));
        $this->assertTrue(Schema::hasTable('chat_conversations'));
        $this->assertTrue(Schema::hasTable('chat_messages'));

        $migrateCode = Artisan::call('migrate', [
            '--path' => 'database/migrations/2026_11_24_000007_add_chat_availability_and_presence.php',
            '--realpath' => false,
        ]);
        $this->assertSame(0, $migrateCode, Artisan::output());
        $this->assertTrue(Schema::hasTable('chat_settings'));
        $this->assertTrue(Schema::hasTable('chat_agent_presence'));
        $this->assertTrue(Schema::hasColumn('chat_messages', 'thumbnail_path'));
    }
}
